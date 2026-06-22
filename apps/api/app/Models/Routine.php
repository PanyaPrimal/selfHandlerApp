<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Routine extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'kind',
        'schedule_type',
        'weekdays',
        'preferred_time',
        'sort_order',
        'is_active',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function goals(): BelongsToMany
    {
        return $this->belongsToMany(Goal::class)
            ->withPivot('user_id')
            ->withTimestamps();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(RoutineLog::class);
    }

    public function isScheduledFor(CarbonInterface $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_on && $this->starts_on->isAfter($date)) {
            return false;
        }

        if ($this->ends_on && $this->ends_on->isBefore($date)) {
            return false;
        }

        if ($this->schedule_type === 'daily') {
            return true;
        }

        if ($this->schedule_type === 'weekdays') {
            return in_array($this->weekdayCode($date), $this->weekdays ?? [], true);
        }

        return false;
    }

    private function weekdayCode(CarbonInterface $date): string
    {
        return match ($date->dayOfWeekIso) {
            1 => 'MO',
            2 => 'TU',
            3 => 'WE',
            4 => 'TH',
            5 => 'FR',
            6 => 'SA',
            7 => 'SU',
        };
    }
}
