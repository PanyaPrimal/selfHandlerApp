<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class Currency extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['code', 'decimal_places', 'is_active'];

    protected static function booted(): void
    {
        static::saving(fn (): never => throw new RuntimeException('Currencies are immutable reference data.'));
        static::deleting(fn (): never => throw new RuntimeException('Currencies are immutable reference data.'));
    }

    protected function casts(): array
    {
        return ['decimal_places' => 'integer', 'is_active' => 'boolean'];
    }
}
