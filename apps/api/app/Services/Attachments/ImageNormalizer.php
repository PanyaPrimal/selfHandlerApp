<?php

namespace App\Services\Attachments;

use App\Exceptions\Attachments\AttachmentStorageException;
use App\Exceptions\Attachments\InvalidAttachmentImage;
use finfo;
use GdImage;
use Illuminate\Http\UploadedFile;

class ImageNormalizer
{
    /** @var array<string, array{type: int, extension: string, decoder: string, encoder: string}> */
    private const FORMATS = [
        'image/jpeg' => ['type' => IMAGETYPE_JPEG, 'extension' => 'jpg', 'decoder' => 'imagecreatefromjpeg', 'encoder' => 'imagejpeg'],
        'image/png' => ['type' => IMAGETYPE_PNG, 'extension' => 'png', 'decoder' => 'imagecreatefrompng', 'encoder' => 'imagepng'],
        'image/webp' => ['type' => IMAGETYPE_WEBP, 'extension' => 'webp', 'decoder' => 'imagecreatefromwebp', 'encoder' => 'imagewebp'],
    ];

    public function normalize(UploadedFile $upload): NormalizedImage
    {
        $sourcePath = $upload->getPathname();
        $sourceSize = $upload->getSize();
        if (! $upload->isValid() || ! is_int($sourceSize) || $sourceSize < 1
            || $sourceSize > (int) config('attachments.max_source_bytes')) {
            throw new InvalidAttachmentImage('Attachment source size is invalid.');
        }

        if (! class_exists(finfo::class)) {
            throw new AttachmentStorageException('The server file type detector is unavailable.');
        }
        $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        $probe = @getimagesize($sourcePath);
        if (! is_array($probe) || ! isset($probe[0], $probe[1], $probe[2], $probe['mime'])) {
            throw new InvalidAttachmentImage('Attachment is not a decodable supported image.');
        }
        $mime = strtolower((string) $probe['mime']);
        $format = self::FORMATS[$mime] ?? null;
        $width = (int) $probe[0];
        $height = (int) $probe[1];
        if (! is_string($detectedMime) || $detectedMime !== $mime
            || ! $format || $format['type'] !== (int) $probe[2] || $width < 1 || $height < 1
            || ($width * $height) > (int) config('attachments.max_source_pixels')) {
            throw new InvalidAttachmentImage('Attachment format or dimensions are unsupported.');
        }
        if (! function_exists($format['decoder']) || ! function_exists($format['encoder'])) {
            throw new AttachmentStorageException('The server image codec is unavailable.');
        }

        $image = @$format['decoder']($sourcePath);
        if (! $image instanceof GdImage) {
            throw new InvalidAttachmentImage('Attachment could not be decoded safely.');
        }

        $temporary = null;
        try {
            if ($mime === 'image/jpeg') {
                $image = $this->orient($image, $this->orientation($sourcePath));
            }
            $image = $this->resize($image, $mime);
            $temporary = tempnam(sys_get_temp_dir(), 'selfhandler-attachment-');
            if (! is_string($temporary)) {
                throw new AttachmentStorageException('Normalized attachment temp file is unavailable.');
            }
            $this->encode($image, $temporary, $mime, $format['encoder']);
            imagedestroy($image);
            $image = null;

            $normalizedProbe = @getimagesize($temporary);
            $normalizedMime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
            $size = @filesize($temporary);
            if (! is_array($normalizedProbe) || $normalizedMime !== $mime
                || (string) ($normalizedProbe['mime'] ?? '') !== $mime
                || (int) ($normalizedProbe[2] ?? 0) !== $format['type']
                || ! is_int($size) || $size < 1 || $size > (int) config('attachments.max_stored_bytes')) {
                throw new InvalidAttachmentImage('Normalized attachment verification failed.');
            }
            $digest = hash_file('sha256', $temporary);
            if (! is_string($digest)) {
                throw new AttachmentStorageException('Normalized attachment digest failed.');
            }

            return new NormalizedImage(
                $temporary, $mime, $format['extension'], $size,
                (int) $normalizedProbe[0], (int) $normalizedProbe[1], $digest,
            );
        } catch (\Throwable $exception) {
            if ($image instanceof GdImage) {
                imagedestroy($image);
            }
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            throw $exception;
        }
    }

    private function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        $data = @exif_read_data($path, 'IFD0', true, false);

        return max(1, min(8, (int) ($data['IFD0']['Orientation'] ?? $data['Orientation'] ?? 1)));
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            if (! imageflip($image, IMG_FLIP_HORIZONTAL)) {
                throw new InvalidAttachmentImage('Attachment orientation failed.');
            }
        } elseif ($orientation === 4) {
            if (! imageflip($image, IMG_FLIP_VERTICAL)) {
                throw new InvalidAttachmentImage('Attachment orientation failed.');
            }
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated instanceof GdImage) {
            throw new InvalidAttachmentImage('Attachment orientation failed.');
        }
        imagedestroy($image);

        return $rotated;
    }

    private function resize(GdImage $image, string $mime): GdImage
    {
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        $maximum = (int) config('attachments.max_dimension');
        $scale = min(1, $maximum / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $output = imagecreatetruecolor($width, $height);
        if (! $output instanceof GdImage) {
            throw new AttachmentStorageException('Normalized attachment canvas failed.');
        }
        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($output, false);
            imagesavealpha($output, true);
            $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
            imagefill($output, 0, 0, $transparent);
        }
        if (! imagecopyresampled($output, $image, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight)) {
            imagedestroy($output);
            throw new InvalidAttachmentImage('Attachment resize failed.');
        }
        imagedestroy($image);

        return $output;
    }

    private function encode(GdImage $image, string $path, string $mime, string $encoder): void
    {
        $result = match ($mime) {
            'image/jpeg' => $encoder($image, $path, (int) config('attachments.jpeg_quality')),
            'image/png' => $encoder($image, $path, (int) config('attachments.png_compression')),
            'image/webp' => $encoder($image, $path, (int) config('attachments.webp_quality')),
        };
        if (! $result) {
            throw new AttachmentStorageException('Normalized attachment encoding failed.');
        }
    }
}
