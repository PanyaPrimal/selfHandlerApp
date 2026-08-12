<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A titled span on one calendar day, belonging to no module.
 *
 * The only thing Planner owns. Overlap is deliberately unconstrained: noting a
 * conflict you intend to resolve is normal use, and refusing it would make the
 * planner argue with the user about their own day.
 */
class TimeBlock extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'title', 'note', 'block_date', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['block_date' => 'date:Y-m-d'];
    }
}
