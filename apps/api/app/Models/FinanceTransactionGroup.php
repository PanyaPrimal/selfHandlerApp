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

    protected $fillable = [
        'user_id', 'public_id', 'kind', 'occurred_on', 'idempotency_key', 'payload_hash',
        'note', 'tag', 'reverses_group_id', 'reversal_reason', 'fx_from_currency',
        'fx_to_currency', 'effective_rate',
    ];

    protected static function booted(): void
    {
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
}
