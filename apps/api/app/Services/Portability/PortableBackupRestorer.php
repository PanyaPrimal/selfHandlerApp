<?php

namespace App\Services\Portability;

use App\Exceptions\PortabilityException;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Attachments\FileStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PortableBackupRestorer
{
    public function __construct(
        private readonly RestoreEligibilityService $eligibility,
        private readonly RestoreTokenService $tokens,
        private readonly PortableBackupReader $reader,
        private readonly FileStorage $storage,
    ) {}

    /** @return array<string,mixed> */
    public function restore(User $user, ValidatedPortableBackup $backup, string $token): array
    {
        $this->tokens->verify($token, $user, $backup->archiveSha256);
        $writtenPaths = [];
        try {
            return DB::transaction(function () use ($user, $backup, &$writtenPaths): array {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (! $this->eligibility->isEmpty($locked)) {
                    throw new PortabilityException('target_not_empty');
                }
                $idMap = $this->insertRecords($locked, $backup->records['tables']);
                $this->restoreProfile($locked, $backup->profile);
                $attachmentCount = $this->restoreAttachments($locked, $backup, $idMap, $writtenPaths);

                return [
                    'archive_sha256' => $backup->archiveSha256,
                    'records_by_table' => $backup->manifest['counts']['records_by_table'],
                    'total_records' => $backup->manifest['counts']['total_records'],
                    'attachments' => $attachmentCount,
                ];
            }, 1);
        } catch (\Throwable $exception) {
            foreach (array_reverse($writtenPaths) as $path) {
                try {
                    $this->storage->delete($path);
                } catch (\Throwable) {
                    // Preserve the original safe failure; paths never leave this boundary.
                }
            }
            throw $exception;
        }
    }

    /** @param array<string,list<array<string,mixed>>> $tables @return array<string,int> */
    private function insertRecords(User $user, array $tables): array
    {
        $definitions = PortabilitySchemaV1::tables();
        $idMap = [];
        $deferred = [];
        foreach (PortabilitySchemaV1::restoreOrder() as $table) {
            $definition = $definitions[$table];
            foreach ($tables[$table] as $record) {
                $values = ['user_id' => $user->id];
                foreach ($definition['attributes'] as $column) {
                    $value = $record['attributes'][$column];
                    $values[$column] = in_array($column, $definition['json'], true) && $value !== null
                        ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : $value;
                }
                foreach ($definition['references'] as $column => $reference) {
                    $value = $record['references'][$column];
                    if (($reference['nullable'] ?? false) === true) {
                        $values[$column] = null;
                        if ($value !== null) {
                            $deferred[] = [$table, $record['id'], $column, $value, $reference,
                                $record['attributes']];
                        }
                    } else {
                        $values[$column] = $this->resolveReference($value, $reference, $record['attributes'], $idMap);
                    }
                }
                $idMap[$record['id']] = (int) DB::table($table)->insertGetId($values);
            }
        }
        foreach ($deferred as [$table, $portableId, $column, $value, $reference, $attributes]) {
            DB::table($table)->where('id', $idMap[$portableId])->update([
                $column => $this->resolveReference($value, $reference, $attributes, $idMap),
            ]);
        }

        return $idMap;
    }

    /** @param array<string,mixed> $reference @param array<string,mixed> $attributes @param array<string,int> $idMap */
    private function resolveReference(mixed $value, array $reference, array $attributes, array $idMap): int
    {
        $target = $reference['table'] ?? null;
        if (isset($reference['polymorphic'])) {
            $target = PortabilitySchemaV1::polymorphicMaps()[$reference['polymorphic']][$attributes[$reference['polymorphic']] ?? ''] ?? null;
        }
        if (is_array($value) && is_string($target) && in_array($target, ['exercises', 'food_items'], true)) {
            $id = DB::table($target)->whereNull('user_id')->where('system_key', $value['system'])->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }
        if (is_string($value) && isset($idMap[$value])) {
            return $idMap[$value];
        }

        throw new PortabilityException('reference_invalid');
    }

    /** @param array<string,mixed> $portable */
    private function restoreProfile(User $user, array $portable): void
    {
        DB::table('users')->where('id', $user->id)->update(['name' => $portable['name']]);
        $profile = $portable['profile'];
        $profile['theme_preferences'] = $profile['theme_preferences'] === null ? null : json_encode(
            $profile['theme_preferences'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        DB::table('user_profiles')->updateOrInsert(['user_id' => $user->id], $profile);
        if ($portable['notification_settings'] !== null) {
            $settings = $portable['notification_settings'];
            $settings['categories'] = json_encode($settings['categories'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            DB::table('notification_settings')->updateOrInsert(['user_id' => $user->id], $settings);
        }
    }

    /** @param array<string,int> $idMap @param list<string> $writtenPaths */
    private function restoreAttachments(
        User $user,
        ValidatedPortableBackup $backup,
        array $idMap,
        array &$writtenPaths,
    ): int {
        foreach ($backup->manifest['attachments'] as $index => $attachment) {
            $bytes = $this->reader->member($backup, $attachment['path']);
            $extension = Attachment::MIME_EXTENSIONS[$attachment['mime_type']];
            $storagePath = $this->storage->pathFor($user, $extension);
            $temporary = tempnam(sys_get_temp_dir(), 'selfhandler-restore-');
            if (! is_string($temporary) || file_put_contents($temporary, $bytes) !== strlen($bytes)) {
                if (is_string($temporary)) {
                    @unlink($temporary);
                }
                throw new PortabilityException('temporary_attachment_unavailable');
            }
            try {
                $this->storage->put($storagePath, $temporary);
            } finally {
                @unlink($temporary);
            }
            $writtenPaths[] = $storagePath;
            $parentId = $idMap[$attachment['parent_id']] ?? null;
            if (! is_int($parentId)) {
                throw new PortabilityException('attachment_parent_missing');
            }
            DB::table('attachments')->insert([
                'user_id' => $user->id, 'attachable_type' => $attachment['parent_type'],
                'attachable_id' => $parentId, 'disk' => $this->storage->diskName(), 'path' => $storagePath,
                'original_name' => $attachment['original_name'], 'mime_type' => $attachment['mime_type'],
                'size_bytes' => $attachment['size_bytes'], 'kind' => $attachment['kind'],
                'width' => $attachment['width'], 'height' => $attachment['height'], 'sha256' => $attachment['sha256'],
                'upload_key' => 'restore:'.$backup->manifest['backup_id'].':'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'meta' => null, 'created_at' => Carbon::parse($attachment['created_at'])->utc()->format('Y-m-d H:i:s'),
            ]);
        }

        return count($backup->manifest['attachments']);
    }
}
