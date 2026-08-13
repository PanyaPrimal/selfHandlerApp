<?php

namespace App\Models;

use App\Support\UserOwned;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, UserOwned;

    public const UPDATED_AT = null;

    public const KIND_PHOTO = 'photo';

    public const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    protected $fillable = [
        'user_id', 'attachable_type', 'attachable_id', 'disk', 'path', 'original_name', 'mime_type',
        'size_bytes', 'kind', 'width', 'height', 'sha256', 'upload_key', 'meta',
    ];

    protected $hidden = [
        'user_id', 'attachable_type', 'attachable_id', 'disk', 'path', 'sha256', 'upload_key', 'meta',
    ];

    protected static function booted(): void
    {
        static::creating(function (Attachment $attachment): void {
            $extension = self::MIME_EXTENSIONS[$attachment->mime_type] ?? null;
            $pathPattern = '#^attachments/'.preg_quote((string) $attachment->user_id, '#')
                .'/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\.'
                .preg_quote((string) $extension, '#').'$#';
            $originalName = (string) $attachment->original_name;
            $uploadKey = (string) $attachment->upload_key;
            $disk = (string) $attachment->disk;
            if (! in_array($attachment->attachable_type, array_keys(self::parentClasses()), true)
                || $attachment->kind !== self::KIND_PHOTO
                || $extension === null
                || $disk !== config('attachments.disk') || $disk === '' || strlen($disk) > 64
                || ! preg_match($pathPattern, (string) $attachment->path)
                || $originalName === '' || mb_strlen($originalName) > 255
                || basename(str_replace('\\', '/', $originalName)) !== $originalName
                || preg_match('/[\x00-\x1F\x7F]/u', $originalName)
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $attachment->sha256)
                || $uploadKey === '' || mb_strlen($uploadKey) > 100 || trim($uploadKey) !== $uploadKey
                || (int) $attachment->size_bytes < 1
                || (int) $attachment->size_bytes > (int) config('attachments.max_stored_bytes')
                || (int) $attachment->width < 1 || (int) $attachment->height < 1
                || (int) $attachment->width > (int) config('attachments.max_dimension')
                || (int) $attachment->height > (int) config('attachments.max_dimension')) {
                throw new LogicException('Attachment metadata is outside the supported private photo contract.');
            }

            $parent = self::parentClasses()[$attachment->attachable_type]::query()
                ->find($attachment->attachable_id);
            if (! $parent || (int) $parent->user_id !== (int) $attachment->user_id) {
                throw new LogicException('An attachment requires a current same-owner supported parent.');
            }
        });
        static::updating(fn (): never => throw new LogicException('Attachment metadata is immutable.'));
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'attachable_id' => 'integer', 'size_bytes' => 'integer',
            'width' => 'integer', 'height' => 'integer',
            'meta' => 'array', 'created_at' => 'immutable_datetime',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return array<string, class-string<BodyMeasurement|Meal>> */
    public static function parentClasses(): array
    {
        return [
            'body_measurement' => BodyMeasurement::class,
            'meal' => Meal::class,
        ];
    }

    public static function aliasFor(Model $parent): string
    {
        return array_search($parent::class, self::parentClasses(), true)
            ?: throw new LogicException('Unsupported attachment parent.');
    }
}
