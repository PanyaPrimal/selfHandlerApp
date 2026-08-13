<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'name', 'description', 'is_archived', 'archived_at'];

    protected $attributes = ['is_archived' => false];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(RecipeComponent::class)->orderBy('sort_order')->orderBy('id');
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
