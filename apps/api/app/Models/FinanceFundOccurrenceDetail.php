<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FinanceFundOccurrenceDetail extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'planned_occurrence_id', 'finance_saving_fund_id', 'fund_name',
        'fund_type', 'storage_mode', 'account_id', 'funding_account_id', 'category_id', 'amount',
        'currency_code', 'top_up_mode', 'calculation_basis', 'complete', 'missing_currencies'];

    protected static function booted(): void
    {
        static::saving(function (FinanceFundOccurrenceDetail $detail): void {
            if ($detail->exists) {
                throw new RuntimeException('A fund occurrence snapshot is immutable.');
            }
            $occurrence = PlannedOccurrence::query()->find($detail->planned_occurrence_id);
            $fund = FinanceSavingFund::query()->find($detail->finance_saving_fund_id);
            if (! $occurrence || ! $fund || (int) $occurrence->user_id !== (int) $detail->user_id
                || (int) $fund->user_id !== (int) $detail->user_id || $fund->currency_code !== $detail->currency_code) {
                throw new RuntimeException('A fund occurrence snapshot requires same-owner references.');
            }
        });
        static::deleting(fn (): never => throw new RuntimeException('A fund occurrence snapshot cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'complete' => 'boolean', 'missing_currencies' => 'array'];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(PlannedOccurrence::class, 'planned_occurrence_id');
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(FinanceSavingFund::class, 'finance_saving_fund_id');
    }
}
