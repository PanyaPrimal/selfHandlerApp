<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FinanceOccurrenceDetail extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'planned_occurrence_id', 'finance_recurring_operation_id', 'operation_name',
        'direction', 'account_id', 'category_id', 'amount', 'currency_code', 'is_mandatory',
    ];

    protected static function booted(): void
    {
        static::saving(function (FinanceOccurrenceDetail $detail): void {
            $owners = [
                PlannedOccurrence::query()->whereKey($detail->planned_occurrence_id)->value('user_id'),
                FinanceRecurringOperation::query()->whereKey($detail->finance_recurring_operation_id)->value('user_id'),
                FinanceAccount::query()->whereKey($detail->account_id)->value('user_id'),
                FinanceCategory::query()->whereKey($detail->category_id)->value('user_id'),
            ];
            if (collect($owners)->contains(fn ($id): bool => (int) $id !== (int) $detail->user_id)) {
                throw new RuntimeException('A Finance occurrence snapshot must use same-owner references.');
            }
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'is_mandatory' => 'boolean'];
    }

    public function plannedOccurrence(): BelongsTo
    {
        return $this->belongsTo(PlannedOccurrence::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(FinanceRecurringOperation::class, 'finance_recurring_operation_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }
}
