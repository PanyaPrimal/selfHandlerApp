<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class RoutineDaySelection extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'selection_date', 'period', 'routine_id'];

    protected function casts(): array
    {
        return ['selection_date' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::saving(function (RoutineDaySelection $selection): void {
            if (blank($selection->user_id) || $selection->routine_id === null) {
                return;
            }

            $routineOwner = Routine::withTrashed()->whereKey($selection->routine_id)->value('user_id');
            if ((int) $routineOwner !== (int) $selection->user_id) {
                throw new RuntimeException('A selection must have the same owner as its routine.');
            }
        });
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
