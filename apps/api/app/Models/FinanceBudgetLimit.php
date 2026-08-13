<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FinanceBudgetLimit extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'category_id', 'budget_month', 'limit_amount', 'currency_code'];

    protected static function booted(): void
    {
        static::saving(function (FinanceBudgetLimit $budget): void {
            $category = FinanceCategory::query()->find($budget->category_id);
            if (! $category || (int) $category->user_id !== (int) $budget->user_id
                || $category->direction !== 'expense') {
                throw new RuntimeException('A budget requires a same-owner expense category.');
            }
        });
    }

    protected function casts(): array
    {
        return ['budget_month' => 'date:Y-m-d', 'limit_amount' => 'decimal:4'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }
}
