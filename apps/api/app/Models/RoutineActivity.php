<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class RoutineActivity extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'routine_id', 'name', 'sort_order', 'preferred_time', 'progress_total',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'progress_total' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RoutineActivity $activity): void {
            if (blank($activity->user_id)) {
                return;
            }

            $routineOwner = Routine::withTrashed()->whereKey($activity->routine_id)->value('user_id');
            if ((int) $routineOwner !== (int) $activity->user_id) {
                throw new RuntimeException('An activity must have the same owner as its routine.');
            }
        });
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(RoutineActivityLog::class);
    }
}
