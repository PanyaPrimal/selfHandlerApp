<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class NutritionDailyTarget extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'target_date', 'status', 'formula', 'bmr_kcal', 'baseline_kcal',
        'goal_adjustment_kcal', 'planned_workout_kcal', 'calorie_target', 'protein_target_grams',
        'fat_target_grams', 'carbs_target_grams', 'water_target_ml', 'quality_target', 'calculation_basis',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Nutrition daily targets are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Nutrition daily targets are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'target_date' => 'date:Y-m-d', 'bmr_kcal' => 'decimal:2', 'baseline_kcal' => 'decimal:2',
            'goal_adjustment_kcal' => 'integer', 'planned_workout_kcal' => 'integer',
            'calorie_target' => 'integer', 'protein_target_grams' => 'decimal:2',
            'fat_target_grams' => 'decimal:2', 'carbs_target_grams' => 'decimal:2',
            'water_target_ml' => 'integer', 'quality_target' => 'decimal:2', 'calculation_basis' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
