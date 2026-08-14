<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SyncedItem extends Model
{
    use HasFactory, UserOwned;

    public const ORIGIN_SELFHANDLER = 'selfhandler';

    public const ORIGIN_PROVIDER = 'provider';

    public const LOCAL_TIME_BLOCK = 'time_block';

    public const LOCAL_PLANNED_OCCURRENCE = 'planned_occurrence';

    public const LOCAL_EXTERNAL_EVENT = 'external_event';

    protected $fillable = [
        'user_id', 'integration_id', 'origin', 'local_type', 'local_id', 'external_id',
        'external_id_hash', 'external_etag', 'remote_updated_at', 'local_fingerprint', 'last_synced_at',
    ];

    protected $hidden = ['user_id', 'integration_id', 'external_id', 'external_id_hash'];

    protected static function booted(): void
    {
        static::saving(function (SyncedItem $item): void {
            $integrationOwner = Integration::query()->whereKey($item->integration_id)->value('user_id');
            $class = self::localClasses()[$item->local_type] ?? null;
            $local = $class ? $class::query()->find($item->local_id) : null;
            $expectedOrigin = $item->local_type === self::LOCAL_EXTERNAL_EVENT
                ? self::ORIGIN_PROVIDER : self::ORIGIN_SELFHANDLER;
            $localOwner = $local?->user_id;
            if ($local instanceof ExternalCalendarEvent && (int) $local->integration_id !== (int) $item->integration_id) {
                $localOwner = null;
            }

            if ((int) $integrationOwner !== (int) $item->user_id
                || (int) $localOwner !== (int) $item->user_id
                || $item->origin !== $expectedOrigin
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $item->external_id_hash)) {
                throw new LogicException('Synced item requires a current same-owner supported local reference.');
            }
        });
    }

    /** @return array<string, class-string<TimeBlock|PlannedOccurrence|ExternalCalendarEvent>> */
    public static function localClasses(): array
    {
        return [
            self::LOCAL_TIME_BLOCK => TimeBlock::class,
            self::LOCAL_PLANNED_OCCURRENCE => PlannedOccurrence::class,
            self::LOCAL_EXTERNAL_EVENT => ExternalCalendarEvent::class,
        ];
    }

    public static function externalHash(string $externalId): string
    {
        return hash_hmac('sha256', $externalId, (string) config('app.key'));
    }

    protected function casts(): array
    {
        return [
            'external_id' => 'encrypted',
            'remote_updated_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
