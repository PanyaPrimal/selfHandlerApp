<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceCounterparty extends Model
{
    use HasFactory, UserOwned;

    public const KINDS = ['person', 'bank', 'store', 'other'];

    protected $fillable = ['user_id', 'name', 'kind', 'note', 'is_archived', 'archived_at'];

    protected $attributes = ['kind' => 'other', 'is_archived' => false];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    public function debts(): HasMany
    {
        return $this->hasMany(FinanceDebt::class);
    }
}
