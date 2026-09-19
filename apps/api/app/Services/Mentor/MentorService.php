<?php

namespace App\Services\Mentor;

use App\Exceptions\AiAssistantException;
use App\Jobs\ProcessMentorTurn;
use App\Models\LlmConnection;
use App\Models\User;
use App\Services\Ai\LlmConnectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class MentorService
{
    public const MAX_CALLS = 3;

    public const RESERVATION = self::MAX_CALLS * (MentorGateway::INPUT_BYTES_LIMIT + MentorGateway::OUTPUT_LIMIT);

    public function __construct(private readonly LlmConnectionService $connections,
        private readonly PersonalContext $context, private readonly MentorGateway $gateway,
        private readonly MentorActions $actions) {}

    public function settings(User $user): array
    {
        $preferences = DB::table('mentor_preferences')->where('user_id', $user->id)->first();
        $usage = DB::table('mentor_turns')->where('user_id', $user->id)->where('created_at', '>=', now('UTC')->startOfMonth())
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens),0) as tokens, COALESCE(SUM(reserved_tokens),0) as reserved, COALESCE(SUM(estimated_usd),0) as estimated_usd, COALESCE(SUM(CASE WHEN estimated_usd IS NULL THEN 1 ELSE 0 END),0) as unpriced_requests')->first();

        return ['enabled' => (bool) ($preferences->enabled ?? false), 'memory' => $preferences->memory ?? '',
            'auth_mode' => $preferences->auth_mode ?? 'api', 'chatgpt_model' => $preferences->chatgpt_model ?? null,
            'chatgpt_available' => app(ChatGptBridge::class)->available(),
            'monthly_token_limit' => $preferences->monthly_token_limit ?? 1000000,
            'usage' => $usage, 'budget_month' => now('UTC')->format('Y-m'), 'price_date' => '2026-09-18',
            'active_connection_id' => $this->connections->active($user)?->id];
    }

    public function ask(User $user, string $operation, string $question): array
    {
        $existing = DB::table('mentor_turns')->where('user_id', $user->id)->where('operation_id', $operation)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->question, $question), 409, 'Operation belongs to a different question.');

            return $this->present($existing);
        }
        $connection = $this->selectedConnection($user);
        if (! $connection || $connection->status !== 'ready') {
            throw AiAssistantException::activeRequired();
        }
        $id = DB::transaction(function () use ($user, $operation, $question, $connection): int {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $settings = $this->settings($user);
            if (! $settings['enabled']) {
                throw AiAssistantException::consentRequired();
            }
            if (DB::table('mentor_turns')->where('user_id', $user->id)->where('operation_id', $operation)->exists()) {
                throw new AiAssistantException('mentor_in_progress', 409);
            }
            if ($settings['usage']->tokens + $settings['usage']->reserved + self::RESERVATION > $settings['monthly_token_limit']) {
                throw new AiAssistantException('mentor_budget_exceeded', 429);
            }

            $turnId = DB::table('mentor_turns')->insertGetId([
                'user_id' => $user->id, 'operation_id' => $operation, 'question' => $question,
                'provider' => $connection->provider, 'model' => $connection->model,
                'connection_id' => $connection->id,
                'reserved_tokens' => self::RESERVATION, 'base_revision' => $this->revision($user),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // Database queue uses the same connection: job and turn commit atomically.
            ProcessMentorTurn::dispatch($turnId)->beforeCommit();

            return $turnId;
        });

        return $this->present(DB::table('mentor_turns')->find($id));
    }

    public function processRound(int $id): void
    {
        // Claim without holding a transaction during the external request.
        if (! DB::table('mentor_turns')->where('id', $id)->where('status', 'pending')
            ->update(['status' => 'processing', 'updated_at' => now()])) {
            return;
        }
        $turn = DB::table('mentor_turns')->find($id);
        $user = User::query()->find($turn->user_id);
        if (! $user) {
            return;
        }
        $connection = $this->selectedConnection($user);
        $usage = array_intersect_key((array) $turn, array_flip(['input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'reasoning_tokens']));
        $sources = json_decode($turn->sources ?? '[]', true);
        $attempted = false;
        try {
            if (! $connection || $connection->id !== $turn->connection_id || $connection->status !== 'ready'
                || $connection->model !== $turn->model || $connection->provider !== $turn->provider) {
                throw AiAssistantException::activeRequired();
            }
            $settings = $this->settings($user);
            if (! $settings['enabled']) {
                throw AiAssistantException::consentRequired();
            }
            $history = DB::table('mentor_turns')->where('user_id', $user->id)->where('status', 'completed')
                ->orderByDesc('id')->limit(4)->get(['question', 'answer'])->reverse()->values()->all();
            $context = $turn->context ? json_decode($turn->context, true) : ['workspace' => $this->context->overview($user, $settings['memory']),
                'recent_conversation' => $history, 'question' => $turn->question, 'tool_results' => []];
            $round = (int) $turn->round;
            if ($round < self::MAX_CALLS) {
                if (! $this->settings($user)['enabled'] || $this->selectedConnection($user)?->provider !== $connection->provider
                    || $this->selectedConnection($user)?->id !== $connection->id) {
                    throw AiAssistantException::consentRequired();
                }
                $attempted = true;
                $response = $this->gateway->call($connection, $context);
                foreach ($usage as $key => $value) {
                    $usage[$key] += max(0, $response['usage'][$key]);
                }
                DB::table('mentor_turns')->where('id', $id)->update([...$usage,
                    'reserved_tokens' => max(0, self::RESERVATION - $usage['input_tokens'] - $usage['output_tokens'])]);
                if (! $response['valid']) {
                    throw AiAssistantException::invalidResponse();
                }
                if ($response['name'] === 'finish') {
                    $final = Validator::make($response['arguments'], ['answer' => ['required', 'string', 'max:8000'], 'actions' => ['present', 'array', 'max:5']])->validate();
                    $actions = $this->actions->normalize($final['actions']);
                    DB::table('mentor_turns')->where('id', $id)->update([
                        'status' => 'completed', 'answer' => $final['answer'], 'actions' => json_encode($actions),
                        'sources' => json_encode($sources), 'reserved_tokens' => 0,
                        'context' => null,
                        'estimated_usd' => $connection->provider === 'chatgpt' ? 0 : $this->gateway->estimatedCost($connection->model, $usage), 'updated_at' => now(),
                    ]);

                    return;
                }
                $result = $this->context->read($user, $response['arguments']);
                $sources[] = ['dataset' => $result['dataset'] ?? $response['arguments']['dataset'],
                    'ids' => array_column($result['records'], 'id'), 'matched' => $result['matched'] ?? 0,
                    'has_more' => $result['has_more'] ?? false];
                $context['tool_results'][] = ['request' => $response['arguments'], 'result' => $result];
                if ($round === self::MAX_CALLS - 2) {
                    $context['instruction'] = 'This is the last model call. Use finish, disclose any missing information and request a narrower follow-up if needed.';
                }
                if ($round < self::MAX_CALLS - 1) {
                    DB::transaction(function () use ($id, $context, $sources, $round): void {
                        DB::table('mentor_turns')->where('id', $id)->update(['status' => 'pending',
                            'context' => json_encode($context, JSON_THROW_ON_ERROR), 'sources' => json_encode($sources),
                            'round' => $round + 1, 'updated_at' => now()]);
                        ProcessMentorTurn::dispatch($id)->beforeCommit();
                    });

                    return;
                }
            }
            throw new AiAssistantException('mentor_step_limit', 422);
        } catch (Throwable $error) {
            $uncertain = $attempted && $error instanceof AiAssistantException && in_array($error->httpStatus, [503], true);
            DB::table('mentor_turns')->where('id', $id)->update([
                'status' => 'failed', 'error_code' => $error instanceof AiAssistantException ? $error->errorCode : 'mentor_request_failed',
                'context' => null,
                'reserved_tokens' => $uncertain ? max(0, self::RESERVATION - $usage['input_tokens'] - $usage['output_tokens']) : 0,
                'estimated_usd' => $turn->provider === 'chatgpt' ? 0 : ($uncertain ? null : $this->gateway->estimatedCost($turn->model, $usage)), 'updated_at' => now(),
            ]);
            throw $error instanceof AiAssistantException ? $error : new AiAssistantException('mentor_request_failed', 422);
        }
    }

    public function confirm(User $user, int $turnId, int $index): array
    {
        return DB::transaction(function () use ($user, $turnId, $index): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $turn = DB::table('mentor_turns')->where('user_id', $user->id)->where('id', $turnId)->lockForUpdate()->first();
            abort_unless($turn && $turn->status === 'completed', 404);
            $actions = json_decode($turn->actions, true);
            abort_unless(isset($actions[$index]), 404);
            if ($actions[$index]['status'] === 'applied') {
                return $this->present($turn);
            }
            if (! $this->settings($user)['enabled']) {
                throw AiAssistantException::consentRequired();
            }
            if ($this->revision($user) !== (int) $turn->base_revision || now()->diffInMinutes($turn->created_at, true) > 60) {
                throw AiAssistantException::confirmationStale();
            }
            $actions[$index]['result'] = $this->actions->execute($user, $actions[$index], $turn->operation_id.'-'.$index);
            $actions[$index]['status'] = 'applied';
            $revision = $this->revision($user) + 1;
            DB::table('workspace_revisions')->updateOrInsert(['user_id' => $user->id], ['revision' => $revision]);
            DB::table('mentor_turns')->where('id', $turn->id)->update(['actions' => json_encode($actions), 'base_revision' => $revision, 'updated_at' => now()]);

            return $this->present(DB::table('mentor_turns')->find($turn->id));
        });
    }

    public function present(object $turn): array
    {
        return ['id' => $turn->id, 'operation_id' => $turn->operation_id, 'status' => $turn->status,
            'question' => $turn->question, 'answer' => $turn->answer,
            'sources' => json_decode($turn->sources ?? '[]', true), 'actions' => json_decode($turn->actions ?? '[]', true),
            'provider' => $turn->provider, 'model' => $turn->model, 'created_at' => $turn->created_at,
            'usage' => ['input' => $turn->input_tokens, 'output' => $turn->output_tokens,
                'reasoning' => $turn->reasoning_tokens, 'cached' => $turn->cached_tokens,
                'cache_write' => $turn->cache_write_tokens, 'reserved' => $turn->reserved_tokens],
            'estimated_usd' => $turn->estimated_usd, 'error_code' => $turn->error_code];
    }

    private function revision(User $user): int
    {
        return (int) DB::table('workspace_revisions')->where('user_id', $user->id)->value('revision');
    }

    private function selectedConnection(User $user): ?LlmConnection
    {
        $preferences = DB::table('mentor_preferences')->where('user_id', $user->id)->first();
        if (($preferences->auth_mode ?? 'api') !== 'chatgpt') {
            return $this->connections->active($user);
        }
        if (! $preferences->chatgpt_model || ! app(ChatGptBridge::class)->available()) {
            return null;
        }

        // Transient descriptor only: OAuth credentials never enter the application DB or jobs.
        return new LlmConnection(['user_id' => $user->id, 'provider' => 'chatgpt',
            'model' => $preferences->chatgpt_model, 'status' => 'ready',
            'parameters' => ['max_output_tokens' => MentorGateway::OUTPUT_LIMIT]]);
    }
}
