<?php

namespace App\Services\Portability;

class PortableBackupFile
{
    public function __construct(public readonly string $path, public readonly string $filename) {}

    public function release(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
