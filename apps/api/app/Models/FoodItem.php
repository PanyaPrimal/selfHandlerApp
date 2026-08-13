<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class FoodItem extends Model
{
    use HasFactory, UserOwned;

    public const BASIS_GRAM = 'gram';

    public const BASIS_MILLILITRE = 'millilitre';

    protected $fillable = [
        'user_id', 'system_key', 'name', 'basis_unit', 'is_beverage', 'calories_per_100',
        'protein_per_100', 'fat_per_100', 'carbs_per_100', 'quality_score', 'hydration_ratio',
        'is_archived', 'archived_at',
    ];

    protected $attributes = ['is_archived' => false, 'hydration_ratio' => 0];

    protected static function booted(): void
    {
        static::updating(function (FoodItem $food): void {
            if ($food->user_id === null) {
                throw new RuntimeException('Public food references are immutable.');
            }
        });
        static::deleting(function (FoodItem $food): void {
            if ($food->user_id === null) {
                throw new RuntimeException('Public food references are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_beverage' => 'boolean', 'calories_per_100' => 'decimal:3',
            'protein_per_100' => 'decimal:3', 'fat_per_100' => 'decimal:3',
            'carbs_per_100' => 'decimal:3', 'quality_score' => 'decimal:2',
            'hydration_ratio' => 'decimal:4', 'is_archived' => 'boolean', 'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipeComponents(): HasMany
    {
        return $this->hasMany(RecipeComponent::class);
    }

    public function mealEntries(): HasMany
    {
        return $this->hasMany(MealEntry::class);
    }

    public function applyLifecycle(bool $archived): void
    {
        $wasArchived = $this->is_archived;
        $this->is_archived = $archived;
        $this->archived_at = $archived ? ($wasArchived ? $this->archived_at : now()) : null;
    }
}
