<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FinanceLedgerEntry extends Model
{
    use HasFactory, UserOwned;

    public const ROLES = ['primary', 'source', 'destination'];

    protected $fillable = [
        'user_id', 'transaction_group_id', 'account_id', 'category_id', 'role',
        'delta_amount', 'currency_code',
    ];

    protected static function booted(): void
    {
        static::saving(function (FinanceLedgerEntry $entry): void {
            if ($entry->exists) {
                throw new RuntimeException('Finance ledger entries are immutable.');
            }

            $group = FinanceTransactionGroup::query()->find($entry->transaction_group_id);
            $account = FinanceAccount::query()->find($entry->account_id);
            if (! $group || ! $account || $group->user_id !== $entry->user_id
                || $account->user_id !== $entry->user_id
                || $account->currency_code !== $entry->currency_code) {
                throw new RuntimeException('A ledger entry group, account, currency, and owner must agree.');
            }
            if (bccomp((string) $entry->delta_amount, '0', 4) === 0) {
                throw new RuntimeException('A ledger entry delta cannot be zero.');
            }
            if ($entry->category_id !== null) {
                $category = FinanceCategory::query()->find($entry->category_id);
                if (! $category || $category->user_id !== $entry->user_id
                    || ! in_array($group->kind, ['income', 'expense'], true)
                    || $category->direction !== $group->kind) {
                    throw new RuntimeException('A ledger entry category must match its owner and direction.');
                }
            }
        });

        static::deleting(fn (): never => throw new RuntimeException('Finance ledger entries cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['delta_amount' => 'decimal:4'];
    }

    public function transactionGroup(): BelongsTo
    {
        return $this->belongsTo(FinanceTransactionGroup::class, 'transaction_group_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }
}
