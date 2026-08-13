<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FinanceDebtOccurrenceDetail extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'planned_occurrence_id', 'finance_debt_id', 'debt_name',
        'direction', 'account_id', 'category_id', 'amount', 'currency_code'];

    protected static function booted(): void
    {
        static::saving(function (FinanceDebtOccurrenceDetail $detail): void {
            if ($detail->exists) {
                throw new RuntimeException('A debt occurrence snapshot is immutable.');
            }
            $occurrence = PlannedOccurrence::query()->find($detail->planned_occurrence_id);
            $debt = FinanceDebt::query()->find($detail->finance_debt_id);
            if (! $occurrence || ! $debt || (int) $occurrence->user_id !== (int) $detail->user_id
                || (int) $debt->user_id !== (int) $detail->user_id || $debt->currency_code !== $detail->currency_code) {
                throw new RuntimeException('A debt occurrence snapshot requires same-owner references.');
            }
        });
        static::deleting(fn (): never => throw new RuntimeException('A debt occurrence snapshot cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(PlannedOccurrence::class, 'planned_occurrence_id');
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(FinanceDebt::class, 'finance_debt_id');
    }
}
