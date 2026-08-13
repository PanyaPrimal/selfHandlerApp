<?php

namespace App\Services\Attachments;

use App\Exceptions\Attachments\AttachmentConflict;
use App\Exceptions\Attachments\InvalidAttachmentImage;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AttachmentService
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly ImageNormalizer $images,
    ) {}

    public function upload(
        User $user,
        string $parentType,
        int $parentId,
        string $uploadKey,
        UploadedFile $file,
    ): AttachmentUploadResult {
        $uploadKey = trim($uploadKey);
        if ($uploadKey === '' || Str::length($uploadKey) > 100) {
            throw ValidationException::withMessages(['upload_key' => [__('validation.between.string', [
                'attribute' => 'upload key', 'min' => 1, 'max' => 100,
            ])]]);
        }
        $this->resolveParent($user, $parentType, $parentId);

        try {
            $normalized = $this->images->normalize($file);
        } catch (InvalidAttachmentImage) {
            throw ValidationException::withMessages(['file' => [__('messages.attachment_image_invalid')]]);
        }

        $storedPath = null;
        try {
            $result = DB::transaction(function () use (
                $user, $parentType, $parentId, $uploadKey, $file, $normalized, &$storedPath,
            ): AttachmentUploadResult {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $parent = $this->resolveParent($user, $parentType, $parentId, true);
                $existing = Attachment::query()->ownedBy($user)
                    ->where('upload_key', $uploadKey)->lockForUpdate()->first();

                if ($existing) {
                    if ($existing->attachable_type !== $parentType
                        || (int) $existing->attachable_id !== (int) $parent->getKey()
                        || ! hash_equals($existing->sha256, $normalized->sha256)) {
                        throw new AttachmentConflict('Upload identity has different private content.');
                    }

                    return new AttachmentUploadResult($existing, false);
                }

                $parentCount = Attachment::query()
                    ->where('attachable_type', $parentType)->where('attachable_id', $parent->getKey())
                    ->count();
                if ($parentCount >= (int) config('attachments.max_per_parent')) {
                    throw ValidationException::withMessages([
                        'file' => [__('messages.attachment_parent_quota')],
                    ]);
                }

                $ownerBytes = (int) Attachment::query()->ownedBy($user)->sum('size_bytes');
                if ($ownerBytes + $normalized->sizeBytes > (int) config('attachments.max_bytes_per_user')) {
                    throw ValidationException::withMessages([
                        'file' => [__('messages.attachment_owner_quota')],
                    ]);
                }

                $storedPath = $this->storage->pathFor($user, $normalized->extension);
                $this->storage->put($storedPath, $normalized->path);
                $attachment = Attachment::query()->create([
                    'user_id' => $user->id,
                    'attachable_type' => $parentType,
                    'attachable_id' => $parent->getKey(),
                    'disk' => $this->storage->diskName(),
                    'path' => $storedPath,
                    'original_name' => $this->safeName($file->getClientOriginalName(), $normalized->extension),
                    'mime_type' => $normalized->mimeType,
                    'size_bytes' => $normalized->sizeBytes,
                    'kind' => Attachment::KIND_PHOTO,
                    'width' => $normalized->width,
                    'height' => $normalized->height,
                    'sha256' => $normalized->sha256,
                    'upload_key' => $uploadKey,
                    'meta' => null,
                ]);

                return new AttachmentUploadResult($attachment, true);
            });
            $storedPath = null;

            return $result;
        } catch (Throwable $exception) {
            if (is_string($storedPath)) {
                try {
                    $this->storage->delete($storedPath);
                } catch (Throwable) {
                    // Preserve the original safe failure. The path is opaque and is never logged here.
                }
            }
            throw $exception;
        } finally {
            $normalized->release();
        }
    }

    public function owned(User $user, int $attachmentId, bool $lock = false): Attachment
    {
        $query = Attachment::query()->ownedBy($user)->whereKey($attachmentId);
        $attachment = $query->firstOrFail();
        $this->resolveParent(
            $user,
            $attachment->attachable_type,
            (int) $attachment->attachable_id,
            $lock,
        );
        if ($lock) {
            $attachment = Attachment::query()->ownedBy($user)->whereKey($attachmentId)
                ->lockForUpdate()->firstOrFail();
        }

        return $attachment;
    }

    public function delete(User $user, int $attachmentId): void
    {
        DB::transaction(function () use ($user, $attachmentId): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $attachment = $this->owned($user, $attachmentId, true);
            $this->storage->delete($attachment->path);
            $attachment->delete();
        }, 3);
    }

    public function deleteForParent(Model $parent): void
    {
        $type = Attachment::aliasFor($parent);
        DB::transaction(function () use ($parent, $type): void {
            User::query()->whereKey($parent->user_id)->lockForUpdate()->firstOrFail();
            $parent::query()->whereKey($parent->getKey())->lockForUpdate()->firstOrFail();
            $this->deleteInBatches(Attachment::query()
                ->where('user_id', $parent->user_id)
                ->where('attachable_type', $type)
                ->where('attachable_id', $parent->getKey()));
        }, 3);
    }

    public function deleteForUser(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->deleteInBatches(Attachment::query()->ownedBy($user));
        }, 3);
    }

    /** @param Builder<Attachment> $query */
    private function deleteInBatches(Builder $query): void
    {
        $batchSize = max(1, min(1000, (int) config('attachments.cleanup_batch_size', 100)));
        $lastId = 0;
        do {
            $attachments = (clone $query)->where('id', '>', $lastId)
                ->orderBy('id')->limit($batchSize)->lockForUpdate()->get();
            foreach ($attachments as $attachment) {
                $this->storage->delete($attachment->path);
            }
            Attachment::query()->whereKey($attachments->modelKeys())->delete();
            $lastId = (int) ($attachments->last()?->id ?? $lastId);
        } while ($attachments->count() === $batchSize);
    }

    /** @return Model&object{user_id: int} */
    private function resolveParent(User $user, string $type, int $id, bool $lock = false): Model
    {
        $class = Attachment::parentClasses()[$type] ?? null;
        if (! $class) {
            throw (new ModelNotFoundException)->setModel(Attachment::class);
        }
        $query = $class::query()->ownedBy($user)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function safeName(string $original, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $original));
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $stem = preg_replace('/[\x00-\x1F\x7F]+/u', '', $stem) ?? '';
        $stem = trim($stem, " .\t\n\r\0\x0B");
        $stem = Str::limit($stem === '' ? 'photo' : $stem, 220, '');

        return "{$stem}.{$extension}";
    }
}
