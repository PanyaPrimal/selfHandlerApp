<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class FinanceExchangeRate extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'from_currency', 'to_currency', 'rate_date', 'rate', 'source',
    ];

    protected $attributes = ['source' => 'manual'];

    protected static function booted(): void
    {
        static::saving(function (FinanceExchangeRate $rate): void {
            $rate->from_currency = strtoupper((string) $rate->from_currency);
            $rate->to_currency = strtoupper((string) $rate->to_currency);
            if ($rate->from_currency === $rate->to_currency) {
                throw new RuntimeException('An exchange-rate pair must use different currencies.');
            }
            if (bccomp((string) $rate->rate, '0', 12) <= 0) {
                throw new RuntimeException('An exchange rate must be positive.');
            }
            if ($rate->source !== 'manual') {
                throw new RuntimeException('Only manual exchange rates are supported.');
            }
        });
    }

    protected function casts(): array
    {
        return ['rate_date' => 'date:Y-m-d', 'rate' => 'decimal:12'];
    }
}
