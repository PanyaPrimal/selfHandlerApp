<?php

namespace App\Services\Ai;

use App\Exceptions\AiAssistantException;
use App\Models\LlmAuditEvent;
use App\Models\LlmConnection;
use App\Models\LlmSetting;
use App\Models\LlmToolConfirmation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LlmConnectionService
{
    public function __construct(
        private readonly LlmProviderRegistry $providers,
        private readonly LlmAuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(User $user, array $data): LlmConnection
    {
        $key = trim((string) $data['api_key']);

        return DB::transaction(function () use ($data, $key, $user): LlmConnection {
            $connection = LlmConnection::query()->create([
                'user_id' => $user->id,
                ...$data,
                'api_key' => $key,
                'key_hint' => mb_substr($key, -4),
                'status' => LlmConnection::STATUS_UNTESTED,
            ]);
            $this->audit->record($user, LlmAuditEvent::EVENT_CONNECTION_CREATED, connection: $connection);

            return $connection;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(User $user, LlmConnection $connection, array $data): LlmConnection
    {
        return DB::transaction(function () use ($connection, $data, $user): LlmConnection {
            $resetsReadiness = collect(['provider', 'model', 'api_key', 'parameters'])
                ->contains(fn (string $field): bool => array_key_exists($field, $data));
            if (array_key_exists('api_key', $data)) {
                $key = trim((string) $data['api_key']);
                $data['api_key'] = $key;
                $data['key_hint'] = mb_substr($key, -4);
            }
            if ($resetsReadiness) {
                $data = [
                    ...$data,
                    'status' => LlmConnection::STATUS_UNTESTED,
                    'last_tested_at' => null,
                    'last_error_code' => null,
                ];
                LlmSetting::query()->where('user_id', $user->id)
                    ->where('active_connection_id', $connection->id)
                    ->update(['active_connection_id' => null, 'updated_at' => now()]);
                $this->rejectPending($connection);
            }
            $connection->fill($data)->save();
            $this->audit->record($user, LlmAuditEvent::EVENT_CONNECTION_UPDATED, connection: $connection);

            return $connection->fresh();
        });
    }

    public function test(User $user, LlmConnection $connection): LlmConnection
    {
        try {
            $this->providers->for($connection->provider)->test($connection);
            DB::transaction(function () use ($connection, $user): void {
                $connection->forceFill([
                    'status' => LlmConnection::STATUS_READY,
                    'last_tested_at' => now(),
                    'last_error_code' => null,
                ])->save();
                $this->audit->record($user, LlmAuditEvent::EVENT_CONNECTION_TESTED, connection: $connection);
            });
        } catch (AiAssistantException $exception) {
            DB::transaction(function () use ($connection, $exception, $user): void {
                $connection->forceFill([
                    'status' => LlmConnection::STATUS_INVALID,
                    'last_tested_at' => now(),
                    'last_error_code' => $exception->errorCode,
                ])->save();
                $this->audit->record(
                    $user,
                    LlmAuditEvent::EVENT_CONNECTION_TESTED,
                    LlmAuditEvent::OUTCOME_REJECTED,
                    $connection,
                    errorCode: $exception->errorCode,
                );
            });
            throw $exception;
        }

        return $connection->fresh();
    }

    public function activate(User $user, LlmConnection $connection): LlmSetting
    {
        if ($connection->status !== LlmConnection::STATUS_READY) {
            throw AiAssistantException::notReady();
        }

        $setting = DB::transaction(function () use ($connection, $user): LlmSetting {
            $setting = LlmSetting::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $setting) {
                $setting = new LlmSetting(['user_id' => $user->id]);
            }
            $setting->active_connection_id = $connection->id;
            $setting->save();

            $this->audit->record($user, LlmAuditEvent::EVENT_CONNECTION_ACTIVATED, connection: $connection);

            return $setting;
        });

        return $setting;
    }

    public function delete(User $user, LlmConnection $connection): void
    {
        DB::transaction(function () use ($connection, $user): void {
            LlmSetting::query()->where('user_id', $user->id)
                ->where('active_connection_id', $connection->id)
                ->update(['active_connection_id' => null, 'updated_at' => now()]);
            $this->rejectPending($connection);
            $this->audit->record($user, LlmAuditEvent::EVENT_CONNECTION_DELETED, connection: $connection);
            $connection->delete();
        });
    }

    public function active(User $user): ?LlmConnection
    {
        return LlmSetting::query()->ownedBy($user)->with('activeConnection')->first()?->activeConnection;
    }

    private function rejectPending(LlmConnection $connection): void
    {
        LlmToolConfirmation::query()
            ->where('llm_connection_id', $connection->id)
            ->where('status', LlmToolConfirmation::STATUS_PENDING)
            ->update([
                'status' => LlmToolConfirmation::STATUS_REJECTED,
                'rejected_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
