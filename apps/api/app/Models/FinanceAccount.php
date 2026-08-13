<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class FinanceAccount extends Model
{
    use HasFactory, UserOwned;

    public const TYPES = ['cash', 'card', 'savings', 'currency'];

    protected $fillable = ['user_id', 'name', 'type', 'currency_code', 'archived_at'];

    protected static function booted(): void
    {
        static::updating(function (FinanceAccount $account): void {
            if ($account->isDirty('currency_code') && $account->entries()->exists()) {
                throw new RuntimeException('An account currency cannot change after ledger history exists.');
            }
        });
    }

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinanceLedgerEntry::class, 'account_id');
    }

    public function recurringOperations(): HasMany
    {
        return $this->hasMany(FinanceRecurringOperation::class, 'account_id');
    }

    public function occurrenceDetails(): HasMany
    {
        return $this->hasMany(FinanceOccurrenceDetail::class, 'account_id');
    }
}
