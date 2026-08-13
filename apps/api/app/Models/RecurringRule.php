<?php

namespace App\Models;

use App\Support\UserOwned;
use App\ValueObjects\WeekdayCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

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

    public const OWNER_SUPPLEMENT_COURSE = 'supplement_course';

    public const OWNER_FINANCE_RECURRING_OPERATION = 'finance_recurring_operation';

    public const OWNER_FINANCE_DEBT = 'finance_debt';

    public const OWNER_FINANCE_SAVING_FUND = 'finance_saving_fund';

    public const OWNER_TYPES = [
        self::OWNER_ROUTINE, self::OWNER_HABIT, self::OWNER_SLEEP_PLAN, self::OWNER_WORKOUT_PROGRAM,
        self::OWNER_SUPPLEMENT_COURSE, self::OWNER_FINANCE_RECURRING_OPERATION,
        self::OWNER_FINANCE_DEBT, self::OWNER_FINANCE_SAVING_FUND,
    ];

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    protected $fillable = [
        'user_id',
        'owner_type',
        'owner_id',
        'frequency',
        'interval_count',
        'cycle_on_days',
        'cycle_off_days',
        'starts_on',
        'ends_on',
        'timezone',
        'slot_time',
        'last_materialized_until',
    ];

    /** @var list<string> */
    protected $appends = ['weekdays', 'monthdays'];

    /** @var list<string> */
    protected $hidden = ['ruleWeekdays', 'ruleMonthdays'];

    protected static function booted(): void
    {
        static::saving(function (RecurringRule $rule): void {
            if (! in_array($rule->owner_type, [self::OWNER_FINANCE_RECURRING_OPERATION,
                self::OWNER_FINANCE_DEBT, self::OWNER_FINANCE_SAVING_FUND], true)) {
                return;
            }
            $ownerId = match ($rule->owner_type) {
                self::OWNER_FINANCE_RECURRING_OPERATION => FinanceRecurringOperation::query()->whereKey($rule->owner_id)->value('user_id'),
                self::OWNER_FINANCE_DEBT => FinanceDebt::query()->whereKey($rule->owner_id)->value('user_id'),
                self::OWNER_FINANCE_SAVING_FUND => FinanceSavingFund::query()->whereKey($rule->owner_id)->value('user_id'),
            };
            if ((int) $ownerId !== (int) $rule->user_id) {
                throw new RuntimeException('A Finance recurrence rule must have the same owner as its aggregate.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'interval_count' => 'integer',
            'cycle_on_days' => 'integer',
            'cycle_off_days' => 'integer',
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

    public function ruleSlots(): HasMany
    {
        return $this->hasMany(RecurringRuleSlot::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function ruleMonthdays(): HasMany
    {
        return $this->hasMany(RecurringRuleMonthday::class)
            ->orderBy('monthday')
            ->orderBy('id');
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

    /** @return Attribute<list<int>, never> */
    protected function monthdays(): Attribute
    {
        return Attribute::get(fn (): array => $this->ruleMonthdays
            ->pluck('monthday')->map(fn ($day): int => (int) $day)->unique()->sort()->values()->all());
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

    /** @param iterable<mixed> $days */
    public function syncMonthdays(iterable $days): void
    {
        $monthdays = collect($days)->map(fn ($day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 31)
            ->unique()->sort()->values()->all();

        $this->ruleMonthdays()->whereNotIn('monthday', $monthdays ?: [0])->delete();
        $stored = $this->ruleMonthdays()->pluck('monthday')->map(fn ($day): int => (int) $day)->all();
        foreach (array_diff($monthdays, $stored) as $monthday) {
            $this->ruleMonthdays()->create(['user_id' => $this->user_id, 'monthday' => $monthday]);
        }
        $this->unsetRelation('ruleMonthdays');
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
