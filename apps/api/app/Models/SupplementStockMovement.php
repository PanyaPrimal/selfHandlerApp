<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SupplementStockMovement extends Model
{
    use HasFactory, UserOwned;

    public const KIND_RESTOCK = 'restock';

    public const KIND_CORRECTION = 'correction';

    public const KINDS = [self::KIND_RESTOCK, self::KIND_CORRECTION];

    protected $fillable = [
        'user_id', 'supplement_id', 'kind', 'quantity_delta', 'effective_on', 'reason', 'note',
    ];

    protected static function booted(): void
    {
        static::saving(function (SupplementStockMovement $movement): void {
            if (blank($movement->user_id)) {
                return;
            }
            if (! Supplement::query()->whereKey($movement->supplement_id)
                ->where('user_id', $movement->user_id)->exists()) {
                throw new RuntimeException('A stock movement supplement must belong to the same owner.');
            }
            if ($movement->exists) {
                throw new RuntimeException('Stock movements are immutable; add a correction instead.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:6',
            'effective_on' => 'date:Y-m-d',
        ];
    }

    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }
}
