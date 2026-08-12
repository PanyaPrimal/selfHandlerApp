<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A named grouping of Storage items. */
class Project extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'name', 'description', 'is_archived', 'archived_at'];

    protected $attributes = ['is_archived' => false];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
