<?php

namespace App\Models;

use App\Support\UserOwned;
use App\ValueObjects\SupplementQuantity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Supplement extends Model
{
    use HasFactory, UserOwned;

    public const CATEGORIES = ['vitamin', 'sports_nutrition', 'nootropic', 'medication', 'other'];

    public const FORMS = ['capsule', 'tablet', 'powder', 'liquid', 'injection', 'other'];

    protected $fillable = [
        'user_id', 'name', 'category', 'form', 'stock_unit', 'preferred_display_unit',
        'usual_dose_quantity', 'package_quantity', 'restock_lead_days', 'note',
        'is_archived', 'archived_at',
    ];

    protected $attributes = ['restock_lead_days' => 7, 'is_archived' => false];

    protected static function booted(): void
    {
        static::saving(function (Supplement $supplement): void {
            if (! SupplementQuantity::compatible(
                (string) $supplement->preferred_display_unit,
                (string) $supplement->stock_unit,
            )) {
                throw new RuntimeException('The supplement display and stock units are incompatible.');
            }

            if ($supplement->exists && $supplement->isDirty('stock_unit')
                && ($supplement->courses()->exists()
                    || $supplement->intakes()->exists()
                    || $supplement->stockMovements()->exists())) {
                throw new RuntimeException('A supplement stock unit cannot change after dependent facts exist.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'usual_dose_quantity' => 'decimal:6',
            'package_quantity' => 'decimal:6',
            'restock_lead_days' => 'integer',
            'is_archived' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function courses(): HasMany
    {
        return $this->hasMany(SupplementCourse::class);
    }

    public function intakes(): HasMany
    {
        return $this->hasMany(SupplementIntake::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(SupplementStockMovement::class);
    }

    public function restockProposals(): HasMany
    {
        return $this->hasMany(SupplementRestockProposal::class);
    }

    /** @param array<string, mixed> $attributes */
    public function applyLifecycle(array $attributes): void
    {
        $wasArchived = $this->is_archived;
        $this->fill($attributes);
        if ($this->is_archived) {
            if (! $wasArchived || $this->archived_at === null) {
                $this->archived_at = now();
            }
        } else {
            $this->archived_at = null;
        }
    }
}
