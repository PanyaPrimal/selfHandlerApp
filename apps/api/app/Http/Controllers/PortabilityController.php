<?php

namespace App\Http\Controllers;

use App\Exceptions\PortabilityException;
use App\Http\Requests\RestorePortableBackupRequest;
use App\Http\Requests\ValidatePortableBackupRequest;
use App\Services\Portability\PortableBackupExporter;
use App\Services\Portability\PortableBackupReader;
use App\Services\Portability\PortableBackupRestorer;
use App\Services\Portability\RestoreEligibilityService;
use App\Services\Portability\RestoreTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PortabilityController extends Controller
{
    public function __construct(
        private readonly PortableBackupExporter $exporter,
        private readonly PortableBackupReader $reader,
        private readonly RestoreEligibilityService $eligibility,
        private readonly RestoreTokenService $tokens,
        private readonly PortableBackupRestorer $restorer,
    ) {}

    public function backup(Request $request): BinaryFileResponse
    {
        try {
            $backup = $this->exporter->export($request->user());

            return response()->download($backup->path, $backup->filename, [
                'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache', 'Expires' => '0', 'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        } catch (PortabilityException $exception) {
            throw $this->validation($exception);
        }
    }

    public function validateBackup(ValidatePortableBackupRequest $request): JsonResponse
    {
        try {
            $backup = $this->reader->read($request->file('backup'));
            $eligible = $this->eligibility->isEmpty($request->user());
            $token = $eligible ? $this->tokens->issue($request->user(), $backup->archiveSha256) : null;

            return response()->json(['data' => [
                'valid' => true, 'eligible' => $eligible,
                'schema_version' => $backup->manifest['schema_version'],
                'archive_sha256' => $backup->archiveSha256, 'backup_id' => $backup->manifest['backup_id'],
                'created_at' => $backup->manifest['created_at'], 'counts' => $backup->manifest['counts'],
                'exclusions' => $backup->manifest['exclusions'],
                'issues' => $eligible ? [] : ['target_not_empty'],
                'restore_token' => $token['token'] ?? null, 'expires_at' => $token['expires_at'] ?? null,
            ]]);
        } catch (PortabilityException $exception) {
            throw $this->validation($exception);
        }
    }

    public function restore(RestorePortableBackupRequest $request): JsonResponse
    {
        try {
            $backup = $this->reader->read($request->file('backup'));
            $result = $this->restorer->restore($request->user(), $backup, (string) $request->validated('restore_token'));

            return response()->json(['data' => $result]);
        } catch (PortabilityException $exception) {
            if ($exception->issue === 'target_not_empty') {
                abort(409, __('messages.portability_target_not_empty'));
            }
            throw $this->validation($exception);
        }
    }

    private function validation(PortabilityException $exception): ValidationException
    {
        return ValidationException::withMessages([
            'backup' => [__('messages.portability_archive_invalid')." [{$exception->issue}]"],
        ]);
    }
}
