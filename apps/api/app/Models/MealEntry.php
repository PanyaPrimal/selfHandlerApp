<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class MealEntry extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'meal_id', 'food_item_id', 'recipe_id', 'sort_order', 'reference_name',
        'basis_unit', 'quantity', 'calories', 'protein_grams', 'fat_grams', 'carbs_grams',
        'hydration_ml', 'quality_numerator', 'quality_denominator',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Accepted meal entry snapshots are immutable.'));
        static::saving(function (MealEntry $entry): void {
            $mealOwner = Meal::query()->whereKey($entry->meal_id)->value('user_id');
            if ((int) $mealOwner !== (int) $entry->user_id
                || (($entry->food_item_id === null) === ($entry->recipe_id === null))) {
                throw new RuntimeException('A meal entry must have one reference and the same owner as its meal.');
            }
            if ($entry->food_item_id !== null) {
                $food = FoodItem::query()->find($entry->food_item_id);
                if (! $food || ($food->user_id !== null && (int) $food->user_id !== (int) $entry->user_id)
                    || $food->basis_unit !== $entry->basis_unit) {
                    throw new RuntimeException('A food snapshot must use an accessible matching-basis reference.');
                }
            } else {
                $recipeOwner = Recipe::query()->whereKey($entry->recipe_id)->value('user_id');
                if ((int) $recipeOwner !== (int) $entry->user_id || $entry->basis_unit !== FoodItem::BASIS_GRAM) {
                    throw new RuntimeException('A recipe snapshot must use an owned gram-based reference.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer', 'quantity' => 'decimal:3', 'calories' => 'decimal:3',
            'protein_grams' => 'decimal:3', 'fat_grams' => 'decimal:3',
            'carbs_grams' => 'decimal:3', 'hydration_ml' => 'decimal:3',
            'quality_numerator' => 'decimal:4', 'quality_denominator' => 'decimal:3',
        ];
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    public function foodItem(): BelongsTo
    {
        return $this->belongsTo(FoodItem::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
