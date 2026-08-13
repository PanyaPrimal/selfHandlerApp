<?php

namespace App\Models;

use App\Support\UserOwned;
use App\ValueObjects\WeekdayCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The shared recurrence rule.
 *
 * This is the authoritative schedule for whatever owns it. Feature 006 gives it
 * one owner type, `routine`, and two frequencies; the design in
 * `docs/design/recurrence-engine.md` describes the rest, which arrives with the
 * feature that needs it.
 */
class RecurringRule extends Model
{
    use HasFactory, UserOwned;

    public const OWNER_ROUTINE = 'routine';

    public const OWNER_HABIT = 'habit';

    public const OWNER_SLEEP_PLAN = 'sleep_plan';

    public const OWNER_WORKOUT_PROGRAM = 'workout_program';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    protected $fillable = [
        'user_id',
        'owner_type',
        'owner_id',
        'frequency',
        'starts_on',
        'ends_on',
        'timezone',
        'slot_time',
        'last_materialized_until',
    ];

    /** @var list<string> */
    protected $appends = ['weekdays'];

    /** @var list<string> */
    protected $hidden = ['ruleWeekdays'];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'last_materialized_until' => 'date:Y-m-d',
        ];
    }

    public function ruleWeekdays(): HasMany
    {
        return $this->hasMany(RecurringRuleWeekday::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(PlannedOccurrence::class);
    }

    /**
     * The selected weekdays as codes in calendar order.
     *
     * @return Attribute<list<string>, never>
     */
    protected function weekdays(): Attribute
    {
        return Attribute::get(fn (): array => WeekdayCode::normalizeList(
            $this->ruleWeekdays->pluck('weekday'),
        ));
    }

    /**
     * Replace the stored weekdays with the given codes.
     *
     * @param  iterable<mixed>  $codes
     */
    public function syncWeekdays(iterable $codes): void
    {
        $weekdays = WeekdayCode::normalizeList($codes);

        $this->ruleWeekdays()->whereNotIn('weekday', $weekdays ?: ['__none__'])->delete();

        $stored = WeekdayCode::normalizeList(
            $this->ruleWeekdays()->get()->pluck('weekday'),
        );

        foreach (array_diff($weekdays, $stored) as $weekday) {
            $this->ruleWeekdays()->create([
                'user_id' => $this->user_id,
                'weekday' => $weekday,
            ]);
        }

        $this->unsetRelation('ruleWeekdays');
    }

    /** The `daily`/`weekdays` vocabulary the routine API has always used. */
    public function scheduleType(): string
    {
        return $this->frequency === self::FREQUENCY_WEEKLY ? 'weekdays' : 'daily';
    }

    /**
     * Translate the routine API vocabulary into a frequency.
     *
     * An unrecognised value is passed through rather than coerced to `daily`, so
     * an unsupported schedule expands to nothing instead of silently becoming an
     * everyday one.
     */
    public static function frequencyForScheduleType(string $scheduleType): string
    {
        return match ($scheduleType) {
            'weekdays' => self::FREQUENCY_WEEKLY,
            'daily' => self::FREQUENCY_DAILY,
            default => $scheduleType,
        };
    }
}
