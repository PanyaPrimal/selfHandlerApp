<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class FinanceDebtPaymentFact extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'finance_debt_id', 'planned_occurrence_id',
        'transaction_group_id', 'principal_amount', 'currency_code', 'occurred_on'];

    protected static function booted(): void
    {
        static::creating(function (FinanceDebtPaymentFact $fact): void {
            $debt = FinanceDebt::query()->find($fact->finance_debt_id);
            $occurrence = $fact->planned_occurrence_id === null ? null : PlannedOccurrence::query()->find($fact->planned_occurrence_id);
            $group = FinanceTransactionGroup::query()->find($fact->transaction_group_id);
            if (! $debt || ! $group || (int) $debt->user_id !== (int) $fact->user_id
                || (int) $group->user_id !== (int) $fact->user_id
                || ($occurrence && (int) $occurrence->user_id !== (int) $fact->user_id)
                || ($fact->planned_occurrence_id !== null && ! $occurrence)
                || $debt->currency_code !== $fact->currency_code) {
                throw new RuntimeException('A debt payment fact requires same-owner matching references.');
            }
        });
        static::updating(fn (): never => throw new RuntimeException('A debt payment fact is immutable.'));
        static::deleting(fn (): never => throw new RuntimeException('A debt payment fact cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['principal_amount' => 'decimal:4', 'occurred_on' => 'date:Y-m-d'];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(FinanceDebt::class, 'finance_debt_id');
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(PlannedOccurrence::class, 'planned_occurrence_id');
    }

    public function transactionGroup(): BelongsTo
    {
        return $this->belongsTo(FinanceTransactionGroup::class);
    }

    public function linkedOccurrence(): HasOne
    {
        return $this->hasOne(PlannedOccurrence::class, 'finance_debt_payment_fact_id');
    }
}
