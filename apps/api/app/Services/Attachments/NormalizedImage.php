<?php

namespace App\Services\Attachments;

final readonly class NormalizedImage
{
    public function __construct(
        public string $path,
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public int $width,
        public int $height,
        public string $sha256,
    ) {}

    public function release(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
