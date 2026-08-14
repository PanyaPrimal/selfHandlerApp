<?php

namespace App\Services\Portability;

class ValidatedPortableBackup
{
    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $records
     */
    public function __construct(
        public readonly string $archivePath,
        public readonly string $archiveSha256,
        public readonly array $manifest,
        public readonly array $profile,
        public readonly array $records,
    ) {}
}
