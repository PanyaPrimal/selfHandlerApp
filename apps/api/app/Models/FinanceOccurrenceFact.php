<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FinanceOccurrenceFact extends Model
{
    use HasFactory, UserOwned;

    public const OUTCOME_ACTUAL = 'actual';

    public const OUTCOME_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id', 'planned_occurrence_id', 'outcome', 'transaction_group_id', 'occurred_on',
    ];

    protected static function booted(): void
    {
        static::saving(function (FinanceOccurrenceFact $fact): void {
            $occurrenceOwner = PlannedOccurrence::query()->whereKey($fact->planned_occurrence_id)->value('user_id');
            $groupOwner = $fact->transaction_group_id === null ? $fact->user_id
                : FinanceTransactionGroup::query()->whereKey($fact->transaction_group_id)->value('user_id');
            $validShape = $fact->outcome === self::OUTCOME_ACTUAL
                ? $fact->transaction_group_id !== null && $fact->occurred_on !== null
                : $fact->outcome === self::OUTCOME_SKIPPED
                    && $fact->transaction_group_id === null && $fact->occurred_on === null;
            if ((int) $occurrenceOwner !== (int) $fact->user_id
                || (int) $groupOwner !== (int) $fact->user_id || ! $validShape) {
                throw new RuntimeException('A Finance occurrence fact has an invalid owner or outcome shape.');
            }
            if ($fact->exists && $fact->isDirty()) {
                throw new RuntimeException('Finance occurrence facts are immutable.');
            }
        });
        static::deleting(function (FinanceOccurrenceFact $fact): void {
            if ($fact->getOriginal('outcome') === self::OUTCOME_ACTUAL) {
                throw new RuntimeException('Actual Finance occurrence facts cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d'];
    }

    public function plannedOccurrence(): BelongsTo
    {
        return $this->belongsTo(PlannedOccurrence::class);
    }

    public function transactionGroup(): BelongsTo
    {
        return $this->belongsTo(FinanceTransactionGroup::class);
    }
}
