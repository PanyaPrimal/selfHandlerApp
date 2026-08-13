<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Meal extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'consumed_on', 'name', 'category', 'consumed_at_local', 'note', 'submission_key',
    ];

    protected function casts(): array
    {
        return ['consumed_on' => 'date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(MealEntry::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')
            ->orderBy('created_at')->orderBy('id');
    }
}
