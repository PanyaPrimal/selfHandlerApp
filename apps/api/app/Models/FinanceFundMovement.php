<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class FinanceFundMovement extends Model
{
    use HasFactory, UserOwned;

    public const ACTIONS = ['top_up', 'draw_down', 'reverse'];

    protected $fillable = ['user_id', 'finance_saving_fund_id', 'action', 'delta_amount', 'currency_code',
        'occurred_on', 'idempotency_key', 'payload_hash', 'transaction_group_id', 'reverses_movement_id', 'note'];

    protected static function booted(): void
    {
        static::creating(function (FinanceFundMovement $movement): void {
            $fund = FinanceSavingFund::query()->find($movement->finance_saving_fund_id);
            $group = $movement->transaction_group_id === null ? null : FinanceTransactionGroup::query()->find($movement->transaction_group_id);
            $reverses = $movement->reverses_movement_id === null ? null : self::query()->find($movement->reverses_movement_id);
            if (! $fund || (int) $fund->user_id !== (int) $movement->user_id
                || $fund->currency_code !== $movement->currency_code
                || ($movement->transaction_group_id !== null && (! $group || (int) $group->user_id !== (int) $movement->user_id))
                || ($movement->reverses_movement_id !== null && (! $reverses || (int) $reverses->user_id !== (int) $movement->user_id))) {
                throw new RuntimeException('A fund movement requires same-owner matching references.');
            }
        });
        static::updating(fn (): never => throw new RuntimeException('A fund movement is immutable.'));
        static::deleting(fn (): never => throw new RuntimeException('A fund movement cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['delta_amount' => 'decimal:4', 'occurred_on' => 'date:Y-m-d'];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(FinanceSavingFund::class, 'finance_saving_fund_id');
    }

    public function transactionGroup(): BelongsTo
    {
        return $this->belongsTo(FinanceTransactionGroup::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_movement_id');
    }
}
