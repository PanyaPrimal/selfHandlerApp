<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class FinanceCategory extends Model
{
    use HasFactory, UserOwned;

    public const DIRECTIONS = ['income', 'expense'];

    protected $fillable = [
        'user_id', 'direction', 'parent_id', 'parent_scope', 'builtin_key', 'name',
        'name_normalized', 'archived_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (FinanceCategory $category): void {
            $category->parent_scope = $category->parent_id ?: 0;
            $category->name = $category->name === null ? null : trim((string) $category->name);
            $category->name_normalized = $category->builtin_key
                ? strtolower((string) $category->builtin_key)
                : mb_strtolower((string) $category->name);

            if ($category->exists && $category->isDirty('direction')) {
                throw new RuntimeException('A category direction is immutable.');
            }
            if ($category->exists && $category->isDirty('parent_id') && $category->entries()->exists()) {
                throw new RuntimeException('A used category cannot be reparented.');
            }
            if ($category->parent_id === null) {
                return;
            }

            $parent = self::query()->find($category->parent_id);
            if (! $parent || $parent->user_id !== $category->user_id
                || $parent->direction !== $category->direction || $parent->parent_id !== null) {
                throw new RuntimeException('A category parent must be a same-owner, same-direction root.');
            }
        });
    }

    protected function casts(): array
    {
        return ['parent_scope' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinanceLedgerEntry::class, 'category_id');
    }

    public function displayLabel(): string
    {
        return $this->builtin_key
            ? __('messages.finance_category_'.$this->builtin_key)
            : (string) $this->name;
    }
}
