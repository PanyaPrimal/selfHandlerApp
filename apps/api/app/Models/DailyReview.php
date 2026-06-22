<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'review_date',
        'mood',
        'energy',
        'stress',
        'day_rating',
        'went_well',
        'improve_tomorrow',
        'notes',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'review_date' => 'date',
            'mood' => 'integer',
            'energy' => 'integer',
            'stress' => 'integer',
            'day_rating' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
