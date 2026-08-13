<?php

namespace Tests\Unit\Attachments;

use App\Exceptions\Attachments\AttachmentStorageException;
use App\Services\Attachments\FileStorage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\AttachmentTestCase;

class FileStorageTest extends AttachmentTestCase
{
    public function test_private_storage_uses_opaque_owner_paths_and_exact_stream_bytes(): void
    {
        $owner = $this->user();
        $storage = app(FileStorage::class);
        $source = tempnam(sys_get_temp_dir(), 'attachment-test-');
        file_put_contents($source, 'private-bytes');

        try {
            $path = $storage->pathFor($owner, 'jpg');
            $this->assertMatchesRegularExpression("#^attachments/{$owner->id}/[0-9a-f-]{36}\\.jpg$#", $path);
            $this->assertStringNotContainsString('owner@example.test', $path);
            $storage->put($path, $source);
            $this->assertTrue($storage->exists($path));
            $this->assertSame(13, $storage->size($path));
            $stream = $storage->readStream($path);
            $this->assertSame('private-bytes', stream_get_contents($stream));
            fclose($stream);
            $storage->delete($path);
            $this->assertFalse($storage->exists($path));
        } finally {
            @unlink($source);
        }
    }

    public function test_path_rejects_unsupported_extensions_and_public_disk_configuration(): void
    {
        config(['attachments.disk' => 'public']);

        $this->expectException(RuntimeException::class);
        app(FileStorage::class)->pathFor($this->user(), 'svg');
    }

    public function test_adapter_failures_do_not_propagate_private_paths_tokens_or_metadata(): void
    {
        Storage::shouldReceive('disk')->once()->with('local')->andThrow(
            new RuntimeException('C:\\private\\owner.jpg Bearer secret-token Exif GPS_SECRET'),
        );

        try {
            app(FileStorage::class)->exists('attachments/1/private.jpg');
            $this->fail('The storage adapter failure was not normalized.');
        } catch (AttachmentStorageException $exception) {
            $rendered = (string) $exception;
            $this->assertStringNotContainsString('owner.jpg', $rendered);
            $this->assertStringNotContainsString('secret-token', $rendered);
            $this->assertStringNotContainsString('Exif', $rendered);
            $this->assertNull($exception->getPrevious());
        }
    }
}
