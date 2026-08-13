<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class FinanceDebt extends Model
{
    use HasFactory, UserOwned;

    public const DIRECTIONS = ['owe', 'owed_to_me'];

    public const REPAYMENT_MODES = ['fixed', 'flexible'];

    protected $fillable = [
        'user_id', 'finance_counterparty_id', 'purchase_item_id', 'name', 'direction',
        'repayment_mode', 'original_amount', 'currency_code', 'originated_on', 'deadline',
        'account_id', 'category_id', 'installment_amount', 'installment_count', 'interval_months',
        'monthday', 'first_due_on', 'reminder_time', 'note', 'is_active', 'is_archived', 'archived_at',
    ];

    protected $attributes = ['is_active' => true, 'is_archived' => false];

    protected static function booted(): void
    {
        static::saving(function (FinanceDebt $debt): void {
            if (blank($debt->user_id)) {
                return;
            }
            foreach ([
                FinanceCounterparty::class => $debt->finance_counterparty_id,
                FinanceAccount::class => $debt->account_id,
                FinanceCategory::class => $debt->category_id,
                Item::class => $debt->purchase_item_id,
            ] as $model => $id) {
                if ($id !== null && (int) $model::query()->whereKey($id)->value('user_id') !== (int) $debt->user_id) {
                    throw new RuntimeException('A debt reference must have the same owner.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:4', 'installment_amount' => 'decimal:4',
            'originated_on' => 'date:Y-m-d', 'deadline' => 'date:Y-m-d', 'first_due_on' => 'date:Y-m-d',
            'installment_count' => 'integer', 'interval_months' => 'integer', 'monthday' => 'integer',
            'is_active' => 'boolean', 'is_archived' => 'boolean', 'archived_at' => 'immutable_datetime',
        ];
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(FinanceCounterparty::class, 'finance_counterparty_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'purchase_item_id');
    }

    public function recurringRule(): HasOne
    {
        return $this->hasOne(RecurringRule::class, 'owner_id')->where('owner_type', RecurringRule::OWNER_FINANCE_DEBT);
    }

    public function paymentFacts(): HasMany
    {
        return $this->hasMany(FinanceDebtPaymentFact::class);
    }

    public function occurrenceDetails(): HasMany
    {
        return $this->hasMany(FinanceDebtOccurrenceDetail::class);
    }

    public function goalDetail(): HasOne
    {
        return $this->hasOne(FinanceGoalDetail::class);
    }
}
