<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class FinanceTransactionGroup extends Model
{
    use HasFactory, UserOwned;

    public const KINDS = ['income', 'expense', 'transfer', 'adjustment'];

    public const SOURCE_PURCHASE_ITEM = 'purchase_item';

    public const SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL = 'supplement_restock_proposal';

    public const SOURCE_TYPES = [self::SOURCE_PURCHASE_ITEM, self::SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL];

    protected $fillable = [
        'user_id', 'public_id', 'kind', 'occurred_on', 'idempotency_key', 'payload_hash',
        'note', 'tag', 'reverses_group_id', 'reversal_reason', 'fx_from_currency',
        'fx_to_currency', 'effective_rate',
        'source_type', 'source_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (FinanceTransactionGroup $group): void {
            $hasType = $group->source_type !== null;
            $hasId = $group->source_id !== null;
            if ($hasType !== $hasId || ($hasType && ($group->kind !== 'expense'
                || $group->reverses_group_id !== null || ! in_array($group->source_type, self::SOURCE_TYPES, true)))) {
                throw new RuntimeException('A Finance source must be one valid pair on an original expense.');
            }
            if (! $hasType) {
                return;
            }
            $ownerId = match ($group->source_type) {
                self::SOURCE_PURCHASE_ITEM => Item::query()->whereKey($group->source_id)->value('user_id'),
                self::SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL => SupplementRestockProposal::query()
                    ->whereKey($group->source_id)->value('user_id'),
            };
            if ((int) $ownerId !== (int) $group->user_id) {
                throw new RuntimeException('A Finance source must have the same owner as its transaction.');
            }
        });
        static::updating(fn (): never => throw new RuntimeException('Finance transaction groups are immutable.'));
        static::deleting(fn (): never => throw new RuntimeException('Finance transaction groups cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d', 'effective_rate' => 'decimal:12'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinanceLedgerEntry::class, 'transaction_group_id')->orderBy('id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_group_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_group_id');
    }

    public function financeOccurrenceFact(): HasOne
    {
        return $this->hasOne(FinanceOccurrenceFact::class, 'transaction_group_id');
    }

    public function debtPaymentFact(): HasOne
    {
        return $this->hasOne(FinanceDebtPaymentFact::class, 'transaction_group_id');
    }

    public function fundMovement(): HasOne
    {
        return $this->hasOne(FinanceFundMovement::class, 'transaction_group_id');
    }

    public function sourcePurchaseItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'source_id');
    }

    public function sourceRestockProposal(): BelongsTo
    {
        return $this->belongsTo(SupplementRestockProposal::class, 'source_id');
    }
}
