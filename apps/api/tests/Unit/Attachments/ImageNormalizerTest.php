<?php

namespace Tests\Unit\Attachments;

use App\Exceptions\Attachments\InvalidAttachmentImage;
use App\Services\Attachments\ImageNormalizer;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AttachmentTestCase;

class ImageNormalizerTest extends AttachmentTestCase
{
    public function test_jpeg_is_oriented_bounded_and_source_metadata_is_removed(): void
    {
        $normalizer = app(ImageNormalizer::class);
        $upload = $this->orientedJpeg(6);
        $sourcePath = $upload->getPathname();
        $sourceDigest = hash_file('sha256', $sourcePath);
        $normalized = $normalizer->normalize($upload);

        try {
            $this->assertSame('image/jpeg', $normalized->mimeType);
            $this->assertSame('jpg', $normalized->extension);
            $this->assertSame(20, $normalized->width);
            $this->assertSame(40, $normalized->height);
            $this->assertSame(64, strlen($normalized->sha256));
            $bytes = file_get_contents($normalized->path);
            $this->assertStringNotContainsString('Exif', $bytes);
            $this->assertStringNotContainsString('GPS_SECRET', $bytes);
            $this->assertSame($sourceDigest, hash_file('sha256', $sourcePath));
        } finally {
            $normalized->release();
            @unlink($sourcePath);
        }
    }

    #[DataProvider('orientationProvider')]
    public function test_every_exif_orientation_is_applied_to_image_pixels(
        int $orientation,
        array $expectedCorners,
        array $expectedDimensions,
    ): void {
        $upload = $this->orientedJpeg($orientation);
        $normalized = app(ImageNormalizer::class)->normalize($upload);

        try {
            $this->assertSame($expectedDimensions, [$normalized->width, $normalized->height]);
            $this->assertSame($expectedCorners, $this->cornerLabels($normalized->path));
        } finally {
            $normalized->release();
            @unlink($upload->getPathname());
        }
    }

    public static function orientationProvider(): array
    {
        return [
            'normal' => [1, ['red', 'green', 'blue', 'yellow'], [40, 20]],
            'mirror horizontal' => [2, ['green', 'red', 'yellow', 'blue'], [40, 20]],
            'rotate 180' => [3, ['yellow', 'blue', 'green', 'red'], [40, 20]],
            'mirror vertical' => [4, ['blue', 'yellow', 'red', 'green'], [40, 20]],
            'transpose' => [5, ['red', 'blue', 'green', 'yellow'], [20, 40]],
            'rotate clockwise' => [6, ['blue', 'red', 'yellow', 'green'], [20, 40]],
            'transverse' => [7, ['yellow', 'green', 'blue', 'red'], [20, 40]],
            'rotate counter-clockwise' => [8, ['green', 'yellow', 'red', 'blue'], [20, 40]],
        ];
    }

    public function test_png_is_resized_without_enlargement_and_keeps_transparency(): void
    {
        config(['attachments.max_dimension' => 32]);
        $path = tempnam(sys_get_temp_dir(), 'attachment-png-');
        $image = imagecreatetruecolor(64, 32);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 10, 20, 30, 127);
        imagefill($image, 0, 0, $transparent);
        imagepng($image, $path);
        imagedestroy($image);
        $upload = new UploadedFile($path, 'alpha.png', 'image/png', null, true);

        $normalized = app(ImageNormalizer::class)->normalize($upload);
        try {
            $this->assertSame([32, 16], [$normalized->width, $normalized->height]);
            $decoded = imagecreatefrompng($normalized->path);
            $this->assertGreaterThan(100, (imagecolorat($decoded, 0, 0) >> 24) & 0x7F);
            imagedestroy($decoded);
        } finally {
            $normalized->release();
            @unlink($path);
        }
    }

    public function test_webp_is_reencoded_bounded_and_keeps_transparency(): void
    {
        config(['attachments.max_dimension' => 32]);
        $path = tempnam(sys_get_temp_dir(), 'attachment-webp-');
        $image = imagecreatetruecolor(64, 32);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 10, 20, 30, 127);
        imagefill($image, 0, 0, $transparent);
        imagewebp($image, $path, 95);
        imagedestroy($image);
        $upload = new UploadedFile($path, 'alpha.webp', 'image/webp', null, true);

        $normalized = app(ImageNormalizer::class)->normalize($upload);
        try {
            $this->assertSame('image/webp', $normalized->mimeType);
            $this->assertSame([32, 16], [$normalized->width, $normalized->height]);
            $decoded = imagecreatefromwebp($normalized->path);
            $this->assertGreaterThan(100, (imagecolorat($decoded, 0, 0) >> 24) & 0x7F);
            imagedestroy($decoded);
        } finally {
            $normalized->release();
            @unlink($path);
        }
    }

    public function test_source_stored_and_pixel_boundaries_are_exact(): void
    {
        $maximum = 5 * 1024 * 1024;
        $exactUpload = $this->paddedPng($maximum);
        $exact = app(ImageNormalizer::class)->normalize($exactUpload);
        $exact->release();
        @unlink($exactUpload->getPathname());
        $this->addToAssertionCount(1);

        $tooLarge = $this->paddedPng($maximum + 1);
        try {
            app(ImageNormalizer::class)->normalize($tooLarge);
            $this->fail('The source byte ceiling accepted one extra byte.');
        } catch (InvalidAttachmentImage) {
            $this->addToAssertionCount(1);
        } finally {
            @unlink($tooLarge->getPathname());
        }

        $probeUpload = $this->image();
        $probe = app(ImageNormalizer::class)->normalize($probeUpload);
        $storedSize = $probe->sizeBytes;
        $probe->release();
        @unlink($probeUpload->getPathname());

        config(['attachments.max_stored_bytes' => $storedSize]);
        $storedExactUpload = $this->image();
        $storedExact = app(ImageNormalizer::class)->normalize($storedExactUpload);
        $this->assertSame($storedSize, $storedExact->sizeBytes);
        $storedExact->release();
        @unlink($storedExactUpload->getPathname());

        config(['attachments.max_stored_bytes' => $storedSize - 1]);
        $storedTooLarge = $this->image();
        try {
            app(ImageNormalizer::class)->normalize($storedTooLarge);
            $this->fail('The normalized byte ceiling accepted one extra byte.');
        } catch (InvalidAttachmentImage) {
            $this->addToAssertionCount(1);
        } finally {
            @unlink($storedTooLarge->getPathname());
        }

        config(['attachments.max_stored_bytes' => $maximum, 'attachments.max_source_pixels' => 9_599]);
        $pixelOverflow = $this->image(width: 120, height: 80);
        try {
            app(ImageNormalizer::class)->normalize($pixelOverflow);
            $this->fail('The decoded pixel ceiling accepted one extra pixel.');
        } catch (InvalidAttachmentImage) {
            $this->addToAssertionCount(1);
        } finally {
            @unlink($pixelOverflow->getPathname());
        }
    }

    public function test_magic_mismatch_malformed_and_oversized_sources_are_rejected(): void
    {
        foreach ([
            new UploadedFile($this->tempFile('plain text'), 'fake.jpg', 'image/jpeg', null, true),
            new UploadedFile($this->tempFile("\xFF\xD8broken"), 'broken.jpg', 'image/jpeg', null, true),
        ] as $upload) {
            try {
                app(ImageNormalizer::class)->normalize($upload);
                $this->fail('Invalid image bytes were accepted.');
            } catch (InvalidAttachmentImage) {
                $this->addToAssertionCount(1);
            } finally {
                @unlink($upload->getPathname());
            }
        }

        config(['attachments.max_source_bytes' => 3]);
        $this->expectException(InvalidAttachmentImage::class);
        app(ImageNormalizer::class)->normalize($this->image());
    }

    private function orientedJpeg(int $orientation): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'attachment-jpeg-');
        $image = imagecreatetruecolor(40, 20);
        $red = imagecolorallocate($image, 255, 0, 0);
        $green = imagecolorallocate($image, 0, 255, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);
        $yellow = imagecolorallocate($image, 255, 255, 0);
        imagefilledrectangle($image, 0, 0, 19, 9, $red);
        imagefilledrectangle($image, 20, 0, 39, 9, $green);
        imagefilledrectangle($image, 0, 10, 19, 19, $blue);
        imagefilledrectangle($image, 20, 10, 39, 19, $yellow);
        imagejpeg($image, $path, 95);
        imagedestroy($image);

        $bytes = file_get_contents($path);
        $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation)."\0\0"
            .pack('V', 0);
        $payload = "Exif\0\0".$tiff.'GPS_SECRET';
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        file_put_contents($path, substr($bytes, 0, 2).$segment.substr($bytes, 2));

        return new UploadedFile($path, 'phone.jpg', 'image/jpeg', null, true);
    }

    /** @return list<string> */
    private function cornerLabels(string $path): array
    {
        $image = imagecreatefromjpeg($path);
        $width = imagesx($image);
        $height = imagesy($image);
        $labels = [];
        foreach ([[0.25, 0.25], [0.75, 0.25], [0.25, 0.75], [0.75, 0.75]] as [$x, $y]) {
            $rgb = imagecolorat($image, (int) floor($width * $x), (int) floor($height * $y));
            $color = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
            $palette = [
                'red' => [255, 0, 0], 'green' => [0, 255, 0],
                'blue' => [0, 0, 255], 'yellow' => [255, 255, 0],
            ];
            uasort($palette, static fn (array $left, array $right): int => array_sum(array_map(static fn (int $a, int $b): int => ($a - $b) ** 2, $left, $color))
                <=> array_sum(array_map(static fn (int $a, int $b): int => ($a - $b) ** 2, $right, $color))
            );
            $labels[] = array_key_first($palette);
        }
        imagedestroy($image);

        return $labels;
    }

    private function paddedPng(int $bytes): UploadedFile
    {
        $upload = $this->image('boundary.png', 2, 2);
        $path = $upload->getPathname();
        $current = filesize($path);
        file_put_contents($path, str_repeat("\0", $bytes - $current), FILE_APPEND);

        return new UploadedFile($path, 'boundary.png', 'image/png', null, true);
    }

    private function tempFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'attachment-invalid-');
        file_put_contents($path, $bytes);

        return $path;
    }
}
