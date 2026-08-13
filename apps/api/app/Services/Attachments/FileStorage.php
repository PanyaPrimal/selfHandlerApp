<?php

namespace App\Services\Attachments;

use App\Exceptions\Attachments\AttachmentStorageException;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileStorage
{
    public function diskName(): string
    {
        $disk = (string) config('attachments.disk');
        $configuration = config("filesystems.disks.{$disk}");

        if ($disk === '' || $disk === 'public' || ! is_array($configuration)
            || ($configuration['visibility'] ?? null) === 'public') {
            throw new AttachmentStorageException('The attachment disk is not configured as private.');
        }

        return $disk;
    }

    public function pathFor(User $user, string $extension): string
    {
        if (! in_array($extension, Attachment::MIME_EXTENSIONS, true)) {
            throw new AttachmentStorageException('Unsupported attachment storage extension.');
        }
        $this->diskName();

        return "attachments/{$user->id}/".Str::uuid().".{$extension}";
    }

    public function put(string $path, string $sourcePath): void
    {
        $stream = @fopen($sourcePath, 'rb');
        if (! is_resource($stream)) {
            throw new AttachmentStorageException('Normalized attachment input is unavailable.');
        }

        try {
            if (! $this->disk()->writeStream($path, $stream, ['visibility' => 'private'])) {
                throw new AttachmentStorageException('Private attachment write failed.');
            }
        } catch (AttachmentStorageException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new AttachmentStorageException('Private attachment write failed.');
        } finally {
            fclose($stream);
        }
    }

    /** @return resource */
    public function readStream(string $path)
    {
        try {
            $stream = $this->disk()->readStream($path);
        } catch (\Throwable) {
            throw new AttachmentStorageException('Private attachment read failed.');
        }
        if (! is_resource($stream)) {
            throw new AttachmentStorageException('Private attachment read failed.');
        }

        return $stream;
    }

    public function exists(string $path): bool
    {
        try {
            return $this->disk()->exists($path);
        } catch (\Throwable) {
            throw new AttachmentStorageException('Private attachment check failed.');
        }
    }

    public function size(string $path): int
    {
        try {
            return $this->disk()->size($path);
        } catch (\Throwable) {
            throw new AttachmentStorageException('Private attachment size check failed.');
        }
    }

    public function delete(string $path): void
    {
        try {
            if ($this->disk()->exists($path) && ! $this->disk()->delete($path)) {
                throw new AttachmentStorageException('Private attachment deletion failed.');
            }
        } catch (AttachmentStorageException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new AttachmentStorageException('Private attachment deletion failed.');
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }
}
