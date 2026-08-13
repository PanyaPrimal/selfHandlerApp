<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class NutritionSettings extends Model
{
    use HasFactory, UserOwned;

    protected $table = 'nutrition_settings';

    protected $fillable = [
        'user_id', 'body_goal_id', 'protein_percent', 'fat_percent', 'carbs_percent', 'water_override_ml',
    ];

    protected $attributes = ['protein_percent' => 20, 'fat_percent' => 30, 'carbs_percent' => 50];

    protected static function booted(): void
    {
        static::saving(function (NutritionSettings $settings): void {
            if ($settings->body_goal_id === null) {
                return;
            }

            $owner = Goal::query()->whereKey($settings->body_goal_id)->value('user_id');
            if ((int) $owner !== (int) $settings->user_id) {
                throw new RuntimeException('Nutrition settings must reference a goal of the same owner.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'protein_percent' => 'decimal:2', 'fat_percent' => 'decimal:2',
            'carbs_percent' => 'decimal:2', 'water_override_ml' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bodyGoal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'body_goal_id');
    }
}
