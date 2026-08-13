<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class FinanceSavingFund extends Model
{
    use HasFactory, UserOwned;

    public const TYPES = ['regular', 'emergency'];

    public const STORAGE_MODES = ['virtual', 'linked_account'];

    public const TARGET_MODES = ['explicit', 'expense_months'];

    public const TOP_UP_MODES = ['none', 'fixed', 'income_percent', 'expense_months'];

    protected $fillable = ['user_id', 'name', 'fund_type', 'storage_mode', 'account_id', 'linked_account_key',
        'funding_account_id', 'category_id', 'currency_code', 'target_mode', 'target_amount', 'deadline',
        'top_up_mode', 'fixed_amount', 'income_percent', 'expense_months', 'build_months', 'starts_on',
        'monthday', 'reminder_time', 'note', 'is_active', 'is_archived', 'archived_at', 'spent_at'];

    protected $attributes = ['fund_type' => 'regular', 'storage_mode' => 'virtual', 'target_mode' => 'explicit',
        'top_up_mode' => 'none', 'is_active' => true, 'is_archived' => false];

    protected static function booted(): void
    {
        static::saving(function (FinanceSavingFund $fund): void {
            if (blank($fund->user_id)) {
                return;
            }
            foreach ([[FinanceAccount::class, $fund->account_id], [FinanceAccount::class, $fund->funding_account_id],
                [FinanceCategory::class, $fund->category_id]] as [$model, $id]) {
                if ($id !== null && (int) $model::query()->whereKey($id)->value('user_id') !== (int) $fund->user_id) {
                    throw new RuntimeException('A fund reference must have the same owner.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['target_amount' => 'decimal:4', 'fixed_amount' => 'decimal:4', 'income_percent' => 'decimal:4',
            'expense_months' => 'integer', 'build_months' => 'integer', 'monthday' => 'integer',
            'deadline' => 'date:Y-m-d', 'starts_on' => 'date:Y-m-d', 'is_active' => 'boolean',
            'is_archived' => 'boolean', 'archived_at' => 'immutable_datetime', 'spent_at' => 'immutable_datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class);
    }

    public function fundingAccount(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'funding_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(FinanceFundMovement::class);
    }

    public function recurringRule(): HasOne
    {
        return $this->hasOne(RecurringRule::class, 'owner_id')->where('owner_type', RecurringRule::OWNER_FINANCE_SAVING_FUND);
    }

    public function occurrenceDetails(): HasMany
    {
        return $this->hasMany(FinanceFundOccurrenceDetail::class);
    }

    public function goalDetail(): HasOne
    {
        return $this->hasOne(FinanceGoalDetail::class);
    }
}
