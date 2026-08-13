<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FinanceGoalDetail extends Model
{
    use HasFactory, UserOwned;

    public const KINDS = ['save', 'pay_off'];

    protected $fillable = ['user_id', 'goal_id', 'kind', 'finance_saving_fund_id', 'finance_debt_id', 'currency_code'];

    protected static function booted(): void
    {
        static::saving(function (FinanceGoalDetail $detail): void {
            if (blank($detail->user_id)) {
                return;
            }
            $validPair = ($detail->kind === 'save' && $detail->finance_saving_fund_id !== null && $detail->finance_debt_id === null)
                || ($detail->kind === 'pay_off' && $detail->finance_debt_id !== null && $detail->finance_saving_fund_id === null);
            if (! $validPair) {
                throw new RuntimeException('A Finance goal detail requires exactly one matching aggregate.');
            }
            $goal = Goal::query()->whereKey($detail->goal_id)->first();
            $targetId = $detail->kind === 'save' ? $detail->finance_saving_fund_id : $detail->finance_debt_id;
            $target = $detail->kind === 'save' ? FinanceSavingFund::query()->find($targetId) : FinanceDebt::query()->find($targetId);
            if (! $goal || ! $target || (int) $goal->user_id !== (int) $detail->user_id
                || (int) $target->user_id !== (int) $detail->user_id || $goal->type !== Goal::TYPE_FINANCE) {
                throw new RuntimeException('A Finance goal detail requires one same-owner typed target.');
            }
        });
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function savingFund(): BelongsTo
    {
        return $this->belongsTo(FinanceSavingFund::class, 'finance_saving_fund_id');
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(FinanceDebt::class, 'finance_debt_id');
    }
}
