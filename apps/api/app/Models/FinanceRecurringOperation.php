<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class FinanceRecurringOperation extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'name', 'direction', 'account_id', 'category_id', 'amount', 'currency_code',
        'is_mandatory', 'is_active', 'is_archived', 'archived_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (FinanceRecurringOperation $operation): void {
            $operation->name = trim((string) $operation->name);
            $account = FinanceAccount::query()->find($operation->account_id);
            $category = FinanceCategory::query()->find($operation->category_id);
            if (! $account || ! $category || (int) $account->user_id !== (int) $operation->user_id
                || (int) $category->user_id !== (int) $operation->user_id
                || $category->direction !== $operation->direction
                || $account->currency_code !== $operation->currency_code
                || ($operation->direction === 'income' && $operation->is_mandatory)) {
                throw new RuntimeException('A Finance operation requires matching owned references.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function recurringRule(): HasOne
    {
        return $this->hasOne(RecurringRule::class, 'owner_id')
            ->where('owner_type', RecurringRule::OWNER_FINANCE_RECURRING_OPERATION);
    }

    public function occurrenceDetails(): HasMany
    {
        return $this->hasMany(FinanceOccurrenceDetail::class, 'finance_recurring_operation_id');
    }
}
