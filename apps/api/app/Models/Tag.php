<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Storage-local label.
 *
 * Deliberately not an application-wide mechanism yet: the design marks that as a
 * candidate, and the roadmap defers extraction until a second module needs
 * compatible behaviour.
 */
class Tag extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'name'];

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class)->withPivot('user_id')->withTimestamps();
    }
}
