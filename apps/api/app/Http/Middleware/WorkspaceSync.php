<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\WorkspaceRevision;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Serializes personal writes and deduplicates acknowledged offline operations. */
class WorkspaceSync
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $request->hasHeader('X-Workspace-Account') && $request->header('X-Workspace-Account') !== (string) $user->id) {
            return response()->json(['code' => 'sync_account_changed', 'message' => __('messages.sync_account_changed')], 409);
        }
        $localConfirmation = $request->is('api/ai/scenarios/storage-inbox/confirm');
        if (! $user || (! $localConfirmation && $request->is('api/ai/*', 'api/mentor/*', 'api/mobile/*', 'api/integrations/*', 'api/portability/*', 'api/attachments*'))) {
            return $next($request);
        }
        $revision = fn (): int => (int) DB::table('workspace_revisions')->where('user_id', $user->id)->value('revision');
        if ($request->isMethodSafe()) {
            return DB::transaction(function () use ($request, $next, $user, $revision): Response {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $response = $next($request);
                $response->headers->set('X-Workspace-Revision', (string) $revision());

                return $response;
            });
        }

        $operation = $request->header('X-Workspace-Operation');
        $base = $request->header('X-Workspace-Base');
        if (($operation !== null && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $operation))
            || ($base !== null && ! preg_match('/^\d{1,16}$/', $base))
            || ($operation !== null && (! $request->isJson() && $request->getContent() !== ''))) {
            return response()->json(['code' => 'sync_invalid_command', 'message' => 'Invalid synchronization command.'], 422);
        }
        $body = $request->getContent();
        if ($operation !== null && $body !== '') {
            try {
                $body = json_encode($this->canonical(json_decode($body, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return response()->json(['code' => 'sync_invalid_command', 'message' => 'Invalid JSON command.'], 422);
            }
        }
        $fingerprint = $operation === null ? '' : hash('sha256', $request->method().' '.$request->getRequestUri().' '.$body);

        return DB::transaction(function () use ($request, $next, $user, $operation, $base, $fingerprint, $revision): Response {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = $revision();
            if ($operation !== null) {
                $receipt = DB::table('workspace_receipts')->where('user_id', $user->id)->where('operation_id', $operation)->first();
                if ($receipt) {
                    if (! hash_equals($receipt->fingerprint, $fingerprint)) {
                        return response()->json(['code' => 'sync_operation_reused', 'message' => 'This operation belongs to different input.'], 409);
                    }

                    return response($receipt->response, $receipt->status, [
                        'Content-Type' => 'application/json',
                        'X-Workspace-Revision' => (string) $receipt->revision,
                        'X-Workspace-Replayed' => 'true',
                    ]);
                }
            }
            if ($base !== null && (int) $base !== $current) {
                return response()->json([
                    'code' => 'sync_conflict', 'message' => __('messages.sync_conflict'),
                    'revision' => $current,
                ], 409);
            }
            $response = $next($request);
            if ($response->isSuccessful()) {
                $current = WorkspaceRevision::advance($user);
                if ($operation !== null) {
                    DB::table('workspace_receipts')->insert([
                        'user_id' => $user->id, 'operation_id' => $operation,
                        'fingerprint' => $fingerprint, 'status' => $response->getStatusCode(),
                        'response' => $response->getContent(), 'revision' => $current, 'created_at' => now(),
                    ]);
                }
            }
            $response->headers->set('X-Workspace-Revision', (string) $current);

            return $response;
        });
    }

    /** Native and browser serializers may reorder JSON object fields; array order remains significant. */
    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->canonical(...), $value);
        }
        if (is_object($value)) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map($this->canonical(...), $properties);
        }

        return $value;
    }
}
