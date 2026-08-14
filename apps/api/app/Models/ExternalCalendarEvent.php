<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ExternalCalendarEvent extends Model
{
    use HasFactory, UserOwned;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_TENTATIVE = 'tentative';

    protected $fillable = [
        'user_id', 'integration_id', 'external_id_hash', 'summary', 'starts_at', 'ends_at',
        'start_date', 'end_date', 'is_all_day', 'status',
    ];

    protected $hidden = ['user_id', 'integration_id', 'external_id_hash', 'summary'];

    protected static function booted(): void
    {
        static::saving(function (ExternalCalendarEvent $event): void {
            $integrationOwner = Integration::query()->whereKey($event->integration_id)->value('user_id');
            $timed = $event->starts_at !== null && $event->ends_at !== null
                && $event->start_date === null && $event->end_date === null
                && $event->ends_at->greaterThan($event->starts_at);
            $allDay = $event->is_all_day && $event->starts_at === null && $event->ends_at === null
                && $event->start_date !== null && $event->end_date !== null
                && $event->end_date->greaterThan($event->start_date);

            if ((int) $integrationOwner !== (int) $event->user_id
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $event->external_id_hash)
                || ! in_array($event->status, [self::STATUS_CONFIRMED, self::STATUS_TENTATIVE], true)
                || ($event->is_all_day ? ! $allDay : ! $timed)) {
                throw new LogicException('External calendar event is outside the supported owner/time contract.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'summary' => 'encrypted',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'start_date' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d',
            'is_all_day' => 'boolean',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
