<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class FinanceFundOccurrenceFact extends Model
{
    use HasFactory, UserOwned;

    public const OUTCOME_ACTUAL = 'actual';

    public const OUTCOME_SKIPPED = 'skipped';

    protected $fillable = ['user_id', 'planned_occurrence_id', 'outcome', 'finance_fund_movement_id',
        'transaction_group_id', 'occurred_on'];

    protected static function booted(): void
    {
        static::creating(function (FinanceFundOccurrenceFact $fact): void {
            $occurrence = PlannedOccurrence::query()->find($fact->planned_occurrence_id);
            $movement = $fact->finance_fund_movement_id === null ? null : FinanceFundMovement::query()->find($fact->finance_fund_movement_id);
            $group = $fact->transaction_group_id === null ? null : FinanceTransactionGroup::query()->find($fact->transaction_group_id);
            $links = collect([$fact->finance_fund_movement_id, $fact->transaction_group_id])->filter()->count();
            $validLinks = $fact->outcome === self::OUTCOME_SKIPPED ? $links === 0 : $links === 1;
            if (! $occurrence || (int) $occurrence->user_id !== (int) $fact->user_id || ! $validLinks
                || ($movement && (int) $movement->user_id !== (int) $fact->user_id)
                || ($group && (int) $group->user_id !== (int) $fact->user_id)) {
                throw new RuntimeException('A fund occurrence fact requires one same-owner outcome link.');
            }
        });
        static::updating(fn (): never => throw new RuntimeException('A fund occurrence fact is immutable.'));
        static::deleting(function (FinanceFundOccurrenceFact $fact): void {
            if ($fact->outcome !== self::OUTCOME_SKIPPED) {
                throw new RuntimeException('An actual fund occurrence fact cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d'];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(PlannedOccurrence::class, 'planned_occurrence_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(FinanceFundMovement::class, 'finance_fund_movement_id');
    }

    public function transactionGroup(): BelongsTo
    {
        return $this->belongsTo(FinanceTransactionGroup::class);
    }

    public function linkedOccurrence(): HasOne
    {
        return $this->hasOne(PlannedOccurrence::class, 'finance_fund_occurrence_fact_id');
    }
}
