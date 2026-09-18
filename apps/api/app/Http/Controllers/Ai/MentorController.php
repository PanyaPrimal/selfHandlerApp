<?php

namespace App\Http\Controllers\Ai;

use App\Exceptions\AiAssistantException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ai\LlmConnectionService;
use App\Services\Mentor\MentorService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MentorController extends Controller
{
    public function __construct(private readonly MentorService $mentor) {}

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->mentor->settings($request->user())]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'memory' => ['present', 'nullable', 'string', 'max:3000'],
            'monthly_token_limit' => ['required', 'integer', 'min:250000', 'max:10000000']]);
        abort_if(array_diff(array_keys($request->all()), array_keys($data)) !== [], 422);
        DB::transaction(function () use ($request, $data): void {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            DB::table('mentor_preferences')->updateOrInsert(['user_id' => $request->user()->id], [
                ...$data, 'updated_at' => now(), 'created_at' => now(),
            ]);
        });

        return $this->settings($request);
    }

    public function history(Request $request): JsonResponse
    {
        $turns = DB::table('mentor_turns')->where('user_id', $request->user()->id)->where('model', '!=', 'gpt-4o-mini-transcribe')
            ->orderByDesc('id')->limit(40)->get()->reverse()->values()->map(fn ($row) => $this->mentor->present($row));

        return response()->json(['data' => $turns]);
    }

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate(['operation_id' => ['required', 'uuid'], 'question' => ['required', 'string', 'max:4000']]);
        abort_if(array_diff(array_keys($request->all()), ['operation_id', 'question']) !== [], 422);

        return response()->json(['data' => $this->mentor->ask($request->user(), $data['operation_id'], trim($data['question']))]);
    }

    public function confirm(Request $request, int $turn, int $action): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        return response()->json(['data' => $this->mentor->confirm($request->user(), $turn, $action)]);
    }

    public function transcribe(Request $request, LlmConnectionService $connections): JsonResponse
    {
        $request->validate(['operation_id' => ['required', 'uuid'],
            'audio' => ['required', 'file', 'max:2048', 'mimetypes:audio/webm,video/webm,audio/mp4,video/mp4,audio/ogg,audio/wav,audio/x-wav,audio/mpeg']]);
        $user = $request->user();
        if (! $this->mentor->settings($user)['enabled']) {
            throw AiAssistantException::consentRequired();
        }
        $connection = $connections->active($user);
        if (! $connection || $connection->provider !== 'openai' || $connection->status !== 'ready') {
            throw new AiAssistantException('mentor_voice_openai', 409);
        }
        $file = $request->file('audio');
        $hash = hash_file('sha256', $file->getRealPath());
        $operation = $request->string('operation_id')->toString();
        $existing = DB::table('mentor_turns')->where('user_id', $user->id)->where('operation_id', $operation)->first();
        if ($existing) {
            abort_unless($existing->model === 'gpt-4o-mini-transcribe' && hash_equals($existing->question, $hash), 409);
            abort_unless($existing->status === 'transcribed', 409, 'This recording was already submitted; its result is unavailable.');

            return response()->json(['text' => $existing->answer]);
        }
        $id = DB::transaction(function () use ($user, $operation, $hash): int {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            // Voice has an explicit separate monthly request quota; no hidden retries.
            abort_if(DB::table('mentor_turns')->where('user_id', $user->id)->where('model', 'gpt-4o-mini-transcribe')
                ->where('created_at', '>=', now('UTC')->startOfMonth())->count() >= 300, 429);
            abort_if(DB::table('mentor_turns')->where('user_id', $user->id)->where('operation_id', $operation)->exists(), 409);

            return DB::table('mentor_turns')->insertGetId(['user_id' => $user->id, 'operation_id' => $operation,
                'provider' => 'openai', 'model' => 'gpt-4o-mini-transcribe', 'question' => $hash,
                'created_at' => now(), 'updated_at' => now()]);
        });
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            if (! is_resource($stream)) {
                throw new AiAssistantException('mentor_voice_failed', 503);
            }
            $extension = match ($file->getMimeType()) {
                'audio/mp4', 'video/mp4' => 'mp4', 'audio/ogg' => 'ogg',
                'audio/wav', 'audio/x-wav' => 'wav', 'audio/mpeg' => 'mp3', default => 'webm'
            };
            $response = Http::withToken($connection->api_key)->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20)
                ->attach('file', $stream, 'voice.'.$extension)->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'gpt-4o-mini-transcribe', 'response_format' => 'json',
                ]);
            if (! $response->successful() || ! is_string($response->json('text'))) {
                throw AiAssistantException::providerUnavailable();
            }
            $text = mb_substr($response->json('text'), 0, 4000);
            DB::table('mentor_turns')->where('id', $id)->update(['status' => 'transcribed', 'answer' => $text, 'updated_at' => now()]);

            return response()->json(['text' => $text]);
        } catch (ConnectionException|AiAssistantException) {
            DB::table('mentor_turns')->where('id', $id)->update(['status' => 'failed', 'error_code' => 'mentor_voice_failed', 'updated_at' => now()]);
            throw new AiAssistantException('mentor_voice_failed', 503);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
