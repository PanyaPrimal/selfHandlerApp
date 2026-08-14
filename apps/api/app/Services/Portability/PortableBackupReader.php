<?php

namespace App\Services\Portability;

use App\Exceptions\PortabilityException;
use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use ZipArchive;

class PortableBackupReader
{
    public function read(UploadedFile $upload): ValidatedPortableBackup
    {
        if (! $upload->isValid()) {
            throw new PortabilityException('upload_invalid');
        }
        $path = $upload->getRealPath();
        if (! is_string($path) || ! is_file($path) || filesize($path) === false
            || filesize($path) > (int) config('portability.max_archive_bytes')) {
            throw new PortabilityException('archive_too_large');
        }
        $archiveSha = hash_file('sha256', $path);
        if (! is_string($archiveSha)) {
            throw new PortabilityException('archive_unreadable');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new PortabilityException('archive_invalid');
        }

        try {
            $stats = $this->validateEntries($zip);
            $manifest = $this->jsonMember($zip, 'manifest.json');
            $this->validateManifest($manifest, $stats, $zip);
            $profile = $this->jsonMember($zip, 'data/profile.json');
            $records = $this->jsonMember($zip, 'data/records.json');
            $this->validateProfile($profile);
            $portableIds = $this->validateRecords($records);
            $this->validateAttachments($manifest['attachments'], $portableIds, $zip);
            $this->validateCounts($manifest, $records, $stats);

            return new ValidatedPortableBackup($path, $archiveSha, $manifest, $profile, $records);
        } finally {
            $zip->close();
        }
    }

    public function member(ValidatedPortableBackup $backup, string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($backup->archivePath, ZipArchive::RDONLY) !== true) {
            throw new PortabilityException('archive_unreadable');
        }
        try {
            $content = $zip->getFromName($path);
            if (! is_string($content)) {
                throw new PortabilityException('member_unreadable');
            }

            return $content;
        } finally {
            $zip->close();
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function validateEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles < 3 || $zip->numFiles > (int) config('portability.max_members')) {
            throw new PortabilityException('member_limit_invalid');
        }
        $stats = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (! is_array($stat) || ! is_string($stat['name'] ?? null)) {
                throw new PortabilityException('member_unreadable');
            }
            $name = $stat['name'];
            if (! $this->safePath($name) || isset($stats[$name])) {
                throw new PortabilityException('member_path_invalid');
            }
            if (! in_array((int) ($stat['comp_method'] ?? -1), [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new PortabilityException('member_compression_invalid');
            }
            $encryption = $stat['encryption_method'] ?? $stat['encryption_name'] ?? null;
            $encryptionName = method_exists($zip, 'getEncryptionName') ? $zip->getEncryptionName($index) : false;
            if (($encryption !== null && $encryption !== 0 && $encryption !== 'none' && $encryption !== 'None')
                || (is_string($encryptionName) && strtolower($encryptionName) !== 'none')) {
                throw new PortabilityException('member_encrypted');
            }
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)
                && $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                throw new PortabilityException('member_symlink');
            }
            $size = (int) ($stat['size'] ?? -1);
            if ($size < 0) {
                throw new PortabilityException('member_size_invalid');
            }
            $total += $size;
            if ($total > (int) config('portability.max_uncompressed_bytes')) {
                throw new PortabilityException('archive_too_large');
            }
            $stats[$name] = $stat;
        }
        if (! isset($stats['manifest.json'], $stats['data/profile.json'], $stats['data/records.json'])) {
            throw new PortabilityException('required_member_missing');
        }

        return $stats;
    }

    /** @param array<string,mixed> $manifest @param array<string,array<string,mixed>> $stats */
    private function validateManifest(array $manifest, array $stats, ZipArchive $zip): void
    {
        $this->exactKeys($manifest, ['format', 'schema_version', 'backup_id', 'created_at', 'members',
            'attachments', 'counts', 'exclusions', 'limits']);
        if (($manifest['format'] ?? null) !== 'selfhandler-backup'
            || ($manifest['schema_version'] ?? null) !== PortabilitySchemaV1::VERSION
            || ! is_string($manifest['backup_id'] ?? null) || ! Str::isUuid($manifest['backup_id'])
            || ! is_string($manifest['created_at'] ?? null) || strtotime($manifest['created_at']) === false
            || ! is_array($manifest['members'] ?? null) || ! is_array($manifest['attachments'] ?? null)
            || ! is_array($manifest['counts'] ?? null) || ! is_array($manifest['exclusions'] ?? null)
            || ! is_array($manifest['limits'] ?? null)) {
            throw new PortabilityException('manifest_invalid');
        }
        if ($manifest['exclusions'] !== PortabilitySchemaV1::exclusionCodes()) {
            throw new PortabilityException('manifest_invalid');
        }
        $expectedLimits = [
            'records' => (int) config('portability.max_records'),
            'attachments' => (int) config('portability.max_attachments'),
            'members' => (int) config('portability.max_members'),
            'json_member_bytes' => (int) config('portability.max_json_member_bytes'),
            'attachment_bytes' => (int) config('portability.max_attachment_bytes'),
            'total_uncompressed_bytes' => (int) config('portability.max_uncompressed_bytes'),
        ];
        if ($manifest['limits'] !== $expectedLimits) {
            throw new PortabilityException('manifest_invalid');
        }
        $declared = [];
        foreach ($manifest['members'] as $member) {
            if (! is_array($member)) {
                throw new PortabilityException('manifest_invalid');
            }
            $this->exactKeys($member, ['path', 'role', 'size_bytes', 'sha256']);
            $memberPath = $member['path'] ?? null;
            if (! is_string($memberPath) || ! $this->safePath($memberPath) || $memberPath === 'manifest.json'
                || isset($declared[$memberPath]) || ! isset($stats[$memberPath])
                || ! in_array($member['role'] ?? null, ['profile', 'records', 'attachment'], true)
                || ! is_int($member['size_bytes'] ?? null) || $member['size_bytes'] !== (int) $stats[$memberPath]['size']
                || ! is_string($member['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $member['sha256'])) {
                throw new PortabilityException('manifest_member_invalid');
            }
            $content = $zip->getFromName($memberPath);
            if (! is_string($content) || ! hash_equals($member['sha256'], hash('sha256', $content))) {
                throw new PortabilityException('member_checksum_invalid');
            }
            $declared[$memberPath] = $member['role'];
        }
        $actual = array_diff(array_keys($stats), ['manifest.json']);
        sort($actual);
        $declaredPaths = array_keys($declared);
        sort($declaredPaths);
        if ($actual !== $declaredPaths || ($declared['data/profile.json'] ?? null) !== 'profile'
            || ($declared['data/records.json'] ?? null) !== 'records') {
            throw new PortabilityException('undeclared_member');
        }
        $declaredAttachmentPaths = array_keys(array_filter(
            $declared, fn (string $role): bool => $role === 'attachment',
        ));
        $manifestAttachmentPaths = array_column($manifest['attachments'], 'path');
        sort($declaredAttachmentPaths);
        sort($manifestAttachmentPaths);
        if ($declaredAttachmentPaths !== $manifestAttachmentPaths) {
            throw new PortabilityException('attachment_manifest_invalid');
        }
    }

    /** @param array<string,mixed> $profile */
    private function validateProfile(array $profile): void
    {
        $this->exactKeys($profile, ['schema_version', 'name', 'profile', 'notification_settings']);
        if (($profile['schema_version'] ?? null) !== PortabilitySchemaV1::VERSION
            || ! is_string($profile['name'] ?? null) || trim($profile['name']) === '' || mb_strlen($profile['name']) > 255
            || ! is_array($profile['profile'] ?? null)) {
            throw new PortabilityException('profile_invalid');
        }
        $profileFields = ['timezone', 'locale', 'unit_system', 'base_currency', 'date_of_birth', 'sex',
            'height_meters', 'weight_grams', 'body_fat_percentage', 'baseline_activity', 'recommendation_tone',
            'bmr_formula', 'created_at', 'updated_at', 'theme_preferences'];
        $this->exactKeys($profile['profile'], $profileFields);
        if (($profile['profile']['theme_preferences'] ?? null) !== null
            && ! is_array($profile['profile']['theme_preferences'])) {
            throw new PortabilityException('profile_invalid');
        }
        $validator = Validator::make($profile, [
            'name' => ['required', 'string', 'max:255'],
            'profile.timezone' => ['required', 'string', 'timezone:all'],
            'profile.locale' => ['required', 'in:'.implode(',', config('selfhandler.profile.locales'))],
            'profile.unit_system' => ['required', 'in:'.implode(',', config('selfhandler.profile.unit_systems'))],
            'profile.base_currency' => ['required', 'in:'.implode(',', config('selfhandler.profile.currencies'))],
            'profile.date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'profile.sex' => ['nullable', 'in:'.implode(',', config('selfhandler.profile.sexes'))],
            'profile.height_meters' => ['nullable', 'decimal:0,3', 'between:0.5,3'],
            'profile.weight_grams' => ['nullable', 'integer', 'between:1000,1000000'],
            'profile.body_fat_percentage' => ['nullable', 'decimal:0,2', 'between:0,100'],
            'profile.baseline_activity' => ['nullable', 'in:'.implode(',', config('selfhandler.profile.baseline_activities'))],
            'profile.recommendation_tone' => ['required', 'in:'.implode(',', config('selfhandler.profile.recommendation_tones'))],
            'profile.bmr_formula' => ['required', 'in:'.implode(',', config('selfhandler.profile.bmr_formulas'))],
            'profile.created_at' => ['required', 'date'], 'profile.updated_at' => ['required', 'date'],
            'profile.theme_preferences' => ['nullable', 'array:scheme,accent,accent_hex,background,background_hex,texture,mono_numerals,motion'],
            'profile.theme_preferences.scheme' => ['required_with:profile.theme_preferences', 'in:light,dark,system'],
            'profile.theme_preferences.accent' => ['required_with:profile.theme_preferences', 'in:forest,slate,gold,brick,custom'],
            'profile.theme_preferences.accent_hex' => ['required_with:profile.theme_preferences', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'profile.theme_preferences.background' => ['required_with:profile.theme_preferences', 'in:paper,sand,mist,sage,custom'],
            'profile.theme_preferences.background_hex' => ['required_with:profile.theme_preferences', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'profile.theme_preferences.texture' => ['required_with:profile.theme_preferences', 'boolean'],
            'profile.theme_preferences.mono_numerals' => ['required_with:profile.theme_preferences', 'boolean'],
            'profile.theme_preferences.motion' => ['required_with:profile.theme_preferences', 'in:system,reduce'],
        ]);
        if ($validator->fails()) {
            throw new PortabilityException('profile_invalid');
        }
        if ($profile['notification_settings'] !== null) {
            if (! is_array($profile['notification_settings'])) {
                throw new PortabilityException('profile_invalid');
            }
            $this->exactKeys($profile['notification_settings'], ['quiet_hours_enabled', 'quiet_starts_at',
                'quiet_ends_at', 'digest_enabled', 'digest_time', 'categories', 'created_at', 'updated_at']);
            if (! is_array($profile['notification_settings']['categories'] ?? null)) {
                throw new PortabilityException('profile_invalid');
            }
            $settingsValidator = Validator::make($profile['notification_settings'], [
                'quiet_hours_enabled' => ['required', 'boolean'], 'quiet_starts_at' => ['required', 'date_format:H:i,H:i:s'],
                'quiet_ends_at' => ['required', 'date_format:H:i,H:i:s'], 'digest_enabled' => ['required', 'boolean'],
                'digest_time' => ['required', 'date_format:H:i,H:i:s'], 'categories' => ['required', 'array'],
                'created_at' => ['required', 'date'], 'updated_at' => ['required', 'date'],
            ]);
            if ($settingsValidator->fails()) {
                throw new PortabilityException('profile_invalid');
            }
        }
    }

    /** @param array<string,mixed> $records @return array<string,true> */
    private function validateRecords(array $records): array
    {
        $this->exactKeys($records, ['schema_version', 'tables']);
        if (($records['schema_version'] ?? null) !== PortabilitySchemaV1::VERSION || ! is_array($records['tables'] ?? null)) {
            throw new PortabilityException('records_invalid');
        }
        $definitions = PortabilitySchemaV1::tables();
        $this->exactKeys($records['tables'], array_keys($definitions));
        $ids = [];
        $count = 0;
        foreach ($definitions as $table => $definition) {
            $rows = $records['tables'][$table];
            $columnMetadata = collect(Schema::getColumns($table))->keyBy('name');
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new PortabilityException('records_invalid');
            }
            foreach ($rows as $row) {
                $count++;
                if ($count > (int) config('portability.max_records') || ! is_array($row)) {
                    throw new PortabilityException('record_limit_exceeded');
                }
                $this->exactKeys($row, ['id', 'attributes', 'references']);
                if (! is_string($row['id'] ?? null) || ! preg_match('/^'.preg_quote($table, '/').':[0-9]{6}$/', $row['id'])
                    || isset($ids[$row['id']]) || ! is_array($row['attributes'] ?? null)
                    || ! is_array($row['references'] ?? null)) {
                    throw new PortabilityException('record_invalid');
                }
                $this->exactKeys($row['attributes'], $definition['attributes']);
                $this->exactKeys($row['references'], array_keys($definition['references']));
                foreach ($row['attributes'] as $column => $value) {
                    $metadata = $columnMetadata->get($column);
                    if (! is_array($metadata) || ($value === null && ! ($metadata['nullable'] ?? false))) {
                        throw new PortabilityException('record_type_invalid');
                    }
                    if (in_array($column, $definition['json'], true)) {
                        if ($value !== null && ! is_array($value)) {
                            throw new PortabilityException('record_type_invalid');
                        }
                    } elseif (! $this->scalarMatchesColumn($value, (string) ($metadata['type_name'] ?? ''))) {
                        throw new PortabilityException('record_type_invalid');
                    }
                    if (is_string($value) && strlen($value) > 1_000_000) {
                        throw new PortabilityException('record_type_invalid');
                    }
                }
                $ids[$row['id']] = true;
            }
        }
        foreach ($definitions as $table => $definition) {
            foreach ($records['tables'][$table] as $row) {
                foreach ($definition['references'] as $column => $reference) {
                    $value = $row['references'][$column];
                    if ($value === null && ($reference['nullable'] ?? false)) {
                        continue;
                    }
                    $target = $reference['table'] ?? null;
                    if (isset($reference['polymorphic'])) {
                        $discriminator = $row['attributes'][$reference['polymorphic']] ?? null;
                        $target = PortabilitySchemaV1::polymorphicMaps()[$reference['polymorphic']][$discriminator] ?? null;
                    }
                    if (! is_string($target)) {
                        throw new PortabilityException('polymorphic_reference_invalid');
                    }
                    if (is_array($value) && $this->systemReference($target, $value)) {
                        continue;
                    }
                    if (! is_string($value) || ! isset($ids[$value]) || ! str_starts_with($value, $target.':')) {
                        throw new PortabilityException('reference_invalid');
                    }
                }
            }
        }

        return $ids;
    }

    /** @param list<array<string,mixed>> $attachments @param array<string,true> $ids */
    private function validateAttachments(array $attachments, array $ids, ZipArchive $zip): void
    {
        if (! array_is_list($attachments) || count($attachments) > (int) config('portability.max_attachments')) {
            throw new PortabilityException('attachment_limit_exceeded');
        }
        $seen = [];
        $parentTables = ['body_measurement' => 'body_measurements', 'meal' => 'meals'];
        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                throw new PortabilityException('attachment_manifest_invalid');
            }
            $this->exactKeys($attachment, ['id', 'path', 'parent_type', 'parent_id', 'original_name', 'mime_type',
                'size_bytes', 'kind', 'width', 'height', 'sha256', 'created_at']);
            $parentTable = $parentTables[$attachment['parent_type'] ?? ''] ?? null;
            $expectedExtension = Attachment::MIME_EXTENSIONS[$attachment['mime_type'] ?? ''] ?? null;
            if (! is_string($attachment['id'] ?? null) || ! preg_match('/^attachments:[0-9]{6}$/', $attachment['id'])
                || isset($seen[$attachment['id']]) || ! is_string($attachment['path'] ?? null)
                || $attachment['path'] !== 'attachments/'.str_replace(':', '-', $attachment['id']).'.'.$expectedExtension
                || ! is_string($attachment['parent_id'] ?? null) || ! $parentTable
                || ! str_starts_with($attachment['parent_id'], $parentTable.':') || ! isset($ids[$attachment['parent_id']])
                || ! is_string($attachment['original_name'] ?? null) || $attachment['original_name'] === ''
                || mb_strlen($attachment['original_name']) > 255
                || basename(str_replace('\\', '/', $attachment['original_name'])) !== $attachment['original_name']
                || preg_match('/[\x00-\x1F\x7F]/u', $attachment['original_name'])
                || $attachment['kind'] !== 'photo' || ! is_int($attachment['size_bytes'] ?? null)
                || $attachment['size_bytes'] < 1 || $attachment['size_bytes'] > (int) config('portability.max_attachment_bytes')
                || ! is_int($attachment['width'] ?? null) || ! is_int($attachment['height'] ?? null)
                || $attachment['width'] < 1 || $attachment['height'] < 1
                || $attachment['width'] > (int) config('attachments.max_dimension')
                || $attachment['height'] > (int) config('attachments.max_dimension')
                || ! is_string($attachment['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $attachment['sha256'])
                || ! is_string($attachment['created_at'] ?? null) || strtotime($attachment['created_at']) === false) {
                throw new PortabilityException('attachment_manifest_invalid');
            }
            $bytes = $zip->getFromName($attachment['path']);
            $image = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes) || strlen($bytes) !== $attachment['size_bytes']
                || ! hash_equals($attachment['sha256'], hash('sha256', $bytes)) || ! is_array($image)
                || ($image['mime'] ?? null) !== $attachment['mime_type']
                || (int) $image[0] !== $attachment['width'] || (int) $image[1] !== $attachment['height']) {
                throw new PortabilityException('attachment_content_mismatch');
            }
            $seen[$attachment['id']] = true;
        }
        if (array_sum(array_column($attachments, 'size_bytes')) > (int) config('attachments.max_bytes_per_user')) {
            throw new PortabilityException('attachment_quota_invalid');
        }
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $records @param array<string,array<string,mixed>> $stats */
    private function validateCounts(array $manifest, array $records, array $stats): void
    {
        $this->exactKeys($manifest['counts'], ['records_by_table', 'total_records', 'attachments', 'total_bytes']);
        $counts = [];
        foreach (PortabilitySchemaV1::tables() as $table => $_definition) {
            $counts[$table] = count($records['tables'][$table]);
        }
        $memberBytes = array_sum(array_map(fn (array $stat): int => (int) $stat['size'],
            array_filter($stats, fn (string $path): bool => $path !== 'manifest.json', ARRAY_FILTER_USE_KEY)));
        if (($manifest['counts']['records_by_table'] ?? null) !== $counts
            || ($manifest['counts']['total_records'] ?? null) !== array_sum($counts)
            || ($manifest['counts']['attachments'] ?? null) !== count($manifest['attachments'])
            || ($manifest['counts']['total_bytes'] ?? null) !== $memberBytes) {
            throw new PortabilityException('count_mismatch');
        }
    }

    /** @return array<string,mixed> */
    private function jsonMember(ZipArchive $zip, string $path): array
    {
        $stat = $zip->statName($path);
        if (! is_array($stat) || (int) $stat['size'] > (int) config('portability.max_json_member_bytes')) {
            throw new PortabilityException('json_member_too_large');
        }
        $content = $zip->getFromName($path);
        if (! is_string($content)) {
            throw new PortabilityException('member_unreadable');
        }
        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PortabilityException('json_invalid');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new PortabilityException('json_invalid');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $reference */
    private function systemReference(string $table, array $reference): bool
    {
        if (! in_array($table, ['exercises', 'food_items'], true)) {
            return false;
        }
        try {
            $this->exactKeys($reference, ['system']);
        } catch (PortabilityException) {
            return false;
        }
        $key = $reference['system'] ?? null;

        return is_string($key) && $key !== ''
            && DB::table($table)->whereNull('user_id')->where('system_key', $key)->exists();
    }

    private function safePath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 255 && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:/', $path)
            && ! preg_match('/[\x00-\x1F\x7F]/', $path) && ! str_ends_with($path, '/')
            && ! in_array('..', explode('/', $path), true) && ! in_array('.', explode('/', $path), true);
    }

    private function scalarMatchesColumn(mixed $value, string $type): bool
    {
        if ($value === null) {
            return true;
        }
        $type = strtolower($type);
        if (preg_match('/int|bool/', $type)) {
            return is_int($value) || is_bool($value)
                || (is_string($value) && preg_match('/^-?[0-9]+$/', $value));
        }
        if (preg_match('/decimal|numeric|float|double|real/', $type)) {
            return is_int($value) || is_float($value)
                || (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $value));
        }

        return is_string($value);
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new PortabilityException('schema_fields_invalid');
        }
    }
}
