<?php

namespace App\Models;

use App\Support\UserOwned;
use App\ValueObjects\WeekdayCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Routine extends Model
{
    use HasFactory, SoftDeletes, UserOwned;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'kind',
        'schedule_type',
        'preferred_time',
        'sort_order',
        'is_active',
        'is_archived',
        'archived_at',
        'starts_on',
        'ends_on',
    ];

    /**
     * The normalized weekday rows are exposed as a plain list of codes.
     *
     * @var list<string>
     */
    protected $appends = ['weekdays'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
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

    public function scheduleWeekdays(): HasMany
    {
        return $this->hasMany(RoutineWeekday::class);
    }

    /**
     * The schedule weekdays as codes in calendar order.
     *
     * @return Attribute<list<string>, never>
     */
    protected function weekdays(): Attribute
    {
        return Attribute::get(fn (): array => WeekdayCode::normalizeList(
            $this->scheduleWeekdays->pluck('weekday'),
        ));
    }

    /**
     * Replace the stored schedule weekdays with the given codes.
     *
     * @param  iterable<mixed>  $codes
     */
    public function syncWeekdays(iterable $codes): void
    {
        $weekdays = WeekdayCode::normalizeList($codes);

        $this->scheduleWeekdays()->whereNotIn('weekday', $weekdays)->delete();

        $stored = WeekdayCode::normalizeList(
            $this->scheduleWeekdays()->get()->pluck('weekday'),
        );

        foreach (array_diff($weekdays, $stored) as $weekday) {
            $this->scheduleWeekdays()->create([
                'user_id' => $this->user_id,
                'weekday' => $weekday,
            ]);
        }

        $this->unsetRelation('scheduleWeekdays');
    }
}
