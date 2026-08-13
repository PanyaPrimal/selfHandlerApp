<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class RoutineLog extends Model
{
    use HasFactory, UserOwned;

    public const STATUS_DONE = 'done';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id',
        'routine_id',
        'log_date',
        'status',
        'note',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'log_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RoutineLog $log): void {
            if (blank($log->user_id)) {
                return;
            }

            $routineOwnerId = Routine::withTrashed()
                ->whereKey($log->routine_id)
                ->value('user_id');

            if ((int) $routineOwnerId !== (int) $log->user_id) {
                throw new RuntimeException('A routine log must have the same owner as its routine.');
            }
        });
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
