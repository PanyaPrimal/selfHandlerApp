<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SupplementRestockProposal extends Model
{
    use HasFactory, UserOwned;

    public const STATUS_OPEN = 'open';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'user_id', 'supplement_id', 'active_supplement_id', 'shortage_fingerprint',
        'forecast_runout_on', 'needed_by', 'suggested_quantity', 'stock_unit', 'status',
        'dismissed_at', 'resolved_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (SupplementRestockProposal $proposal): void {
            if (blank($proposal->user_id)) {
                return;
            }

            $owned = Supplement::query()->whereKey($proposal->supplement_id)
                ->where('user_id', $proposal->user_id)->exists();
            if (! $owned || ($proposal->active_supplement_id !== null
                && (int) $proposal->active_supplement_id !== (int) $proposal->supplement_id)) {
                throw new RuntimeException('A restock proposal must reference one owned supplement.');
            }
            if ($proposal->status === self::STATUS_OPEN && $proposal->active_supplement_id === null) {
                throw new RuntimeException('An open proposal requires its active supplement key.');
            }
            if ($proposal->status !== self::STATUS_OPEN && $proposal->active_supplement_id !== null) {
                throw new RuntimeException('A terminal proposal cannot retain an active supplement key.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'forecast_runout_on' => 'date:Y-m-d',
            'needed_by' => 'date:Y-m-d',
            'suggested_quantity' => 'decimal:6',
            'dismissed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }
}
