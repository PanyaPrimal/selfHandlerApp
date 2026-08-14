<?php

namespace App\Services\Portability;

use App\Exceptions\Attachments\AttachmentStorageException;
use App\Exceptions\PortabilityException;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Attachments\FileStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

class PortableBackupExporter
{
    public function __construct(private readonly FileStorage $storage) {}

    public function export(User $user): PortableBackupFile
    {
        [$records, $idMap, $counts] = $this->records($user);
        $profile = $this->profile($user);
        $profileJson = $this->json($profile);
        $recordsJson = $this->json(['schema_version' => PortabilitySchemaV1::VERSION, 'tables' => $records]);
        $this->guardJsonSize($profileJson);
        $this->guardJsonSize($recordsJson);

        $path = tempnam(sys_get_temp_dir(), 'selfhandler-backup-');
        if (! is_string($path)) {
            throw new PortabilityException('temporary_archive_unavailable');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new PortabilityException('temporary_archive_unavailable');
        }

        try {
            $members = [];
            $this->addMember($zip, $members, 'data/profile.json', 'profile', $profileJson);
            $this->addMember($zip, $members, 'data/records.json', 'records', $recordsJson);
            $attachments = $this->attachments($zip, $members, $user, $idMap);
            $totalBytes = array_sum(array_column($members, 'size_bytes'));
            if ($totalBytes > (int) config('portability.max_uncompressed_bytes')) {
                throw new PortabilityException('archive_too_large');
            }
            $manifest = [
                'format' => 'selfhandler-backup', 'schema_version' => PortabilitySchemaV1::VERSION,
                'backup_id' => (string) Str::uuid(), 'created_at' => now('UTC')->toIso8601String(),
                'members' => $members, 'attachments' => $attachments,
                'counts' => [
                    'records_by_table' => $counts, 'total_records' => array_sum($counts),
                    'attachments' => count($attachments), 'total_bytes' => $totalBytes,
                ],
                'exclusions' => PortabilitySchemaV1::exclusionCodes(),
                'limits' => [
                    'records' => (int) config('portability.max_records'),
                    'attachments' => (int) config('portability.max_attachments'),
                    'members' => (int) config('portability.max_members'),
                    'json_member_bytes' => (int) config('portability.max_json_member_bytes'),
                    'attachment_bytes' => (int) config('portability.max_attachment_bytes'),
                    'total_uncompressed_bytes' => (int) config('portability.max_uncompressed_bytes'),
                ],
            ];
            if (! $zip->addFromString('manifest.json', $this->json($manifest))) {
                throw new PortabilityException('archive_write_failed');
            }
            if (! $zip->close()) {
                throw new PortabilityException('archive_write_failed');
            }
            $zip = null;

            return new PortableBackupFile(
                $path,
                'selfhandler-backup-'.now('UTC')->format('Ymd-His\Z').'.zip',
            );
        } catch (\Throwable $exception) {
            if ($zip instanceof ZipArchive) {
                $zip->close();
            }
            @unlink($path);
            throw $exception;
        }
    }

    /** @return array{array<string,list<array<string,mixed>>>,array<string,array<int,string>>,array<string,int>} */
    private function records(User $user): array
    {
        $raw = [];
        $idMap = [];
        $counts = [];
        $total = 0;
        foreach (PortabilitySchemaV1::tables() as $table => $definition) {
            $rows = DB::table($table)->where('user_id', $user->id)->orderBy('id')->get();
            $raw[$table] = $rows;
            $counts[$table] = $rows->count();
            $total += $rows->count();
            if ($total > (int) config('portability.max_records')) {
                throw new PortabilityException('record_limit_exceeded');
            }
            foreach ($rows->values() as $index => $row) {
                $idMap[$table][(int) $row->id] = PortabilitySchemaV1::portableId($table, $index + 1);
            }
        }

        $records = [];
        foreach (PortabilitySchemaV1::tables() as $table => $definition) {
            $records[$table] = [];
            foreach ($raw[$table] as $row) {
                $attributes = [];
                foreach ($definition['attributes'] as $column) {
                    $value = $row->{$column};
                    $attributes[$column] = in_array($column, $definition['json'], true)
                        ? $this->decodeJson($value) : $value;
                }
                $references = [];
                foreach ($definition['references'] as $column => $reference) {
                    $references[$column] = $this->reference($row, $column, $reference, $idMap);
                }
                $records[$table][] = [
                    'id' => $idMap[$table][(int) $row->id],
                    'attributes' => $attributes,
                    'references' => $references,
                ];
            }
        }

        return [$records, $idMap, $counts];
    }

    /** @param array<string,mixed> $reference @param array<string,array<int,string>> $idMap */
    private function reference(object $row, string $column, array $reference, array $idMap): mixed
    {
        $sourceId = $row->{$column};
        if ($sourceId === null) {
            return null;
        }
        $target = $reference['table'] ?? null;
        if (isset($reference['polymorphic'])) {
            $discriminator = $row->{$reference['polymorphic']};
            $target = PortabilitySchemaV1::polymorphicMaps()[$reference['polymorphic']][$discriminator] ?? null;
        }
        if (! is_string($target)) {
            throw new PortabilityException('unsupported_polymorphic_reference');
        }
        if (isset($idMap[$target][(int) $sourceId])) {
            return $idMap[$target][(int) $sourceId];
        }
        if (in_array($target, ['exercises', 'food_items'], true)) {
            $systemKey = DB::table($target)->whereNull('user_id')->where('id', $sourceId)->value('system_key');
            if (is_string($systemKey) && $systemKey !== '') {
                return ['system' => $systemKey];
            }
        }

        throw new PortabilityException('dangling_owned_reference');
    }

    /** @return array<string,mixed> */
    private function profile(User $user): array
    {
        $profile = DB::table('user_profiles')->where('user_id', $user->id)->first();
        if (! $profile) {
            $user->ensureProfile();
            $profile = DB::table('user_profiles')->where('user_id', $user->id)->firstOrFail();
        }
        $profileFields = ['timezone', 'locale', 'unit_system', 'base_currency', 'date_of_birth', 'sex',
            'height_meters', 'weight_grams', 'body_fat_percentage', 'baseline_activity', 'recommendation_tone',
            'bmr_formula', 'created_at', 'updated_at', 'theme_preferences'];
        $profileData = [];
        foreach ($profileFields as $field) {
            $profileData[$field] = $field === 'theme_preferences'
                ? $this->decodeJson($profile->{$field}) : $profile->{$field};
        }
        $settings = DB::table('notification_settings')->where('user_id', $user->id)->first();
        $settingsData = null;
        if ($settings) {
            $settingsData = [];
            foreach (['quiet_hours_enabled', 'quiet_starts_at', 'quiet_ends_at', 'digest_enabled', 'digest_time',
                'categories', 'created_at', 'updated_at'] as $field) {
                $settingsData[$field] = $field === 'categories'
                    ? $this->decodeJson($settings->{$field}) : $settings->{$field};
            }
        }

        return ['schema_version' => PortabilitySchemaV1::VERSION, 'name' => $user->name,
            'profile' => $profileData, 'notification_settings' => $settingsData];
    }

    /** @param array<string,array<int,string>> $idMap @param list<array<string,mixed>> $members @return list<array<string,mixed>> */
    private function attachments(ZipArchive $zip, array &$members, User $user, array $idMap): array
    {
        $rows = Attachment::query()->ownedBy($user)->orderBy('id')->get();
        if ($rows->count() > (int) config('portability.max_attachments')) {
            throw new PortabilityException('attachment_limit_exceeded');
        }
        $manifest = [];
        $parentTables = ['body_measurement' => 'body_measurements', 'meal' => 'meals'];
        foreach ($rows->values() as $index => $attachment) {
            $parentTable = $parentTables[$attachment->attachable_type] ?? null;
            $parentId = $parentTable ? ($idMap[$parentTable][(int) $attachment->attachable_id] ?? null) : null;
            if (! is_string($parentId)) {
                throw new PortabilityException('attachment_parent_missing');
            }
            try {
                $stream = $this->storage->readStream($attachment->path);
                try {
                    $bytes = stream_get_contents($stream);
                } finally {
                    fclose($stream);
                }
            } catch (AttachmentStorageException) {
                throw new PortabilityException('attachment_content_mismatch');
            }
            if (! is_string($bytes) || strlen($bytes) !== (int) $attachment->size_bytes
                || ! hash_equals($attachment->sha256, hash('sha256', $bytes))) {
                throw new PortabilityException('attachment_content_mismatch');
            }
            $image = @getimagesizefromstring($bytes);
            if (! is_array($image) || ($image['mime'] ?? null) !== $attachment->mime_type
                || (int) $image[0] !== (int) $attachment->width || (int) $image[1] !== (int) $attachment->height) {
                throw new PortabilityException('attachment_content_mismatch');
            }
            $portable = PortabilitySchemaV1::portableId('attachments', $index + 1);
            $extension = Attachment::MIME_EXTENSIONS[$attachment->mime_type];
            $memberPath = 'attachments/'.str_replace(':', '-', $portable).'.'.$extension;
            $this->addMember($zip, $members, $memberPath, 'attachment', $bytes);
            $manifest[] = [
                'id' => $portable, 'path' => $memberPath, 'parent_type' => $attachment->attachable_type,
                'parent_id' => $parentId, 'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type, 'size_bytes' => (int) $attachment->size_bytes,
                'kind' => $attachment->kind, 'width' => (int) $attachment->width,
                'height' => (int) $attachment->height, 'sha256' => $attachment->sha256,
                'created_at' => $attachment->created_at->toIso8601String(),
            ];
        }

        return $manifest;
    }

    /** @param list<array<string,mixed>> $members */
    private function addMember(ZipArchive $zip, array &$members, string $path, string $role, string $content): void
    {
        if (! $zip->addFromString($path, $content)) {
            throw new PortabilityException('archive_write_failed');
        }
        $members[] = ['path' => $path, 'role' => $role, 'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content)];
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        try {
            return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PortabilityException('stored_json_invalid');
        }
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        } catch (\JsonException) {
            throw new PortabilityException('archive_json_failed');
        }
    }

    private function guardJsonSize(string $json): void
    {
        if (strlen($json) > (int) config('portability.max_json_member_bytes')) {
            throw new PortabilityException('json_member_too_large');
        }
    }
}
