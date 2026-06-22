<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutineLog extends Model
{
    use HasFactory;

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
            'log_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
