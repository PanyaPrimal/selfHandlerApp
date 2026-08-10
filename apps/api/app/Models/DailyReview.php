<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyReview extends Model
{
    use HasFactory, UserOwned;

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
            'review_date' => 'date:Y-m-d',
            'mood' => 'integer',
            'energy' => 'integer',
            'stress' => 'integer',
            'day_rating' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
