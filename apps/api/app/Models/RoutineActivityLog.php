<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class RoutineActivityLog extends Model
{
    use HasFactory, UserOwned;

    public const STATUS_DONE = 'done';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id', 'routine_activity_id', 'log_date', 'status', 'progress_value', 'note', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'log_date' => 'date:Y-m-d',
            'progress_value' => 'decimal:3',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RoutineActivityLog $log): void {
            if (blank($log->user_id)) {
                return;
            }

            $activityOwner = RoutineActivity::query()
                ->whereKey($log->routine_activity_id)
                ->value('user_id');
            if ((int) $activityOwner !== (int) $log->user_id) {
                throw new RuntimeException('An activity log must have the same owner as its activity.');
            }
        });
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(RoutineActivity::class, 'routine_activity_id');
    }
}
