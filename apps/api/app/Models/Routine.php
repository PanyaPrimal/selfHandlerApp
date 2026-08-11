<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Routine extends Model
{
    use HasFactory, SoftDeletes, UserOwned;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'kind',
        'sort_order',
        'is_active',
        'is_archived',
        'archived_at',
    ];

    /**
     * The schedule lives on the recurrence rule, but the routine API has always
     * presented it inline, so it is appended rather than reshaped.
     *
     * @var list<string>
     */
    protected $appends = ['schedule_type', 'weekdays', 'preferred_time', 'starts_on', 'ends_on'];

    /**
     * The rule is an implementation detail behind the schedule accessors.
     *
     * @var list<string>
     */
    protected $hidden = ['recurringRule'];

    /**
     * Mirror the column defaults so a freshly created instance knows its own
     * lifecycle without a re-read. Materialization asks the routine whether its
     * schedule should be live, and an unread `is_active` would read as "no".
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'is_archived' => false,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function goals(): BelongsToMany
    {
        return $this->belongsToMany(Goal::class)
            ->withPivot('user_id')
            ->withTimestamps();
    }

    public function logs(): HasMany
    {
        return $this->hasMany(RoutineLog::class);
    }

    public function recurringRule(): HasOne
    {
        return $this->hasOne(RecurringRule::class, 'owner_id')
            ->where('owner_type', RecurringRule::OWNER_ROUTINE);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function scheduleType(): Attribute
    {
        return Attribute::get(fn (): string => $this->recurringRule?->scheduleType() ?? 'daily');
    }

    /**
     * @return Attribute<list<string>, never>
     */
    protected function weekdays(): Attribute
    {
        return Attribute::get(fn (): array => $this->recurringRule?->weekdays ?? []);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function preferredTime(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->recurringRule?->slot_time);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function startsOn(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->recurringRule?->starts_on?->format('Y-m-d'));
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function endsOn(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->recurringRule?->ends_on?->format('Y-m-d'));
    }
}
