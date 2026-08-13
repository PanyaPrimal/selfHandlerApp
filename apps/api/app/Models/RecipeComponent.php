<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class RecipeComponent extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'recipe_id', 'food_item_id', 'sort_order', 'quantity_grams'];

    protected static function booted(): void
    {
        static::saving(function (RecipeComponent $component): void {
            $recipeOwner = Recipe::query()->whereKey($component->recipe_id)->value('user_id');
            $food = FoodItem::query()->find($component->food_item_id);
            if ((int) $recipeOwner !== (int) $component->user_id || ! $food
                || ($food->user_id !== null && (int) $food->user_id !== (int) $component->user_id)
                || $food->is_beverage || $food->basis_unit !== FoodItem::BASIS_GRAM) {
                throw new RuntimeException('A recipe component must be an accessible solid food of the same owner.');
            }
        });
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'quantity_grams' => 'decimal:3'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(FoodItem::class, 'food_item_id');
    }
}
