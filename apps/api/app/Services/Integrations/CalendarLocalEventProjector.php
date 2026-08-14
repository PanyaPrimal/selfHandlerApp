<?php

namespace App\Services\Integrations;

use App\Data\Calendar\CalendarEventEnvelope;
use App\Data\Calendar\LocalCalendarProjection;
use App\Models\FinanceDebt;
use App\Models\FinanceRecurringOperation;
use App\Models\FinanceSavingFund;
use App\Models\Habit;
use App\Models\Integration;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\SleepPlan;
use App\Models\SupplementCourse;
use App\Models\SyncedItem;
use App\Models\TimeBlock;
use App\Models\User;
use App\Models\WorkoutProgram;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CalendarLocalEventProjector
{
    /** @return list<LocalCalendarProjection> */
    public function project(
        Integration $integration,
        User $user,
        string $fromDate,
        string $toDate,
    ): array {
        $categories = Integration::normalizeSettings($integration->settings)['export_categories'];
        $result = [];
        if (in_array(Integration::EXPORT_TIME_BLOCK, $categories, true)) {
            foreach (TimeBlock::query()->ownedBy($user)->whereBetween('block_date', [$fromDate, $toDate])
                ->orderBy('id')->get() as $block) {
                $result[] = $this->timeBlock($integration, $user, $block);
            }
        }

        $ownerTypes = collect($categories)->flatMap(fn (string $category): array => $this->ownerTypes($category))
            ->unique()->values()->all();
        if ($ownerTypes === []) {
            return $result;
        }
        $occurrences = PlannedOccurrence::query()->ownedBy($user)
            ->where(function ($query) use ($fromDate, $toDate): void {
                $query->where(function ($original) use ($fromDate, $toDate): void {
                    $original->whereBetween('occurrence_date', [$fromDate, $toDate])->whereNull('rescheduled_to');
                })->orWhereBetween('rescheduled_to', [$fromDate, $toDate]);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()->ownedBy($user)
                ->whereIn('owner_type', $ownerTypes)->select('id'))
            ->with(['recurringRule', 'financeDetail', 'financeDebtDetail', 'financeFundDetail'])
            ->orderBy('id')->get();
        $owners = $this->owners($user, $occurrences);
        foreach ($occurrences as $occurrence) {
            $rule = $occurrence->recurringRule;
            if (! $rule || ! ($title = $this->title($rule, $occurrence, $owners))) {
                continue;
            }
            $category = $this->category($rule->owner_type);
            if ($category === null || ! in_array($category, $categories, true)) {
                continue;
            }
            $date = ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');
            $result[] = new LocalCalendarProjection(
                SyncedItem::LOCAL_PLANNED_OCCURRENCE,
                (int) $occurrence->id,
                $category,
                $this->stableId($integration, SyncedItem::LOCAL_PLANNED_OCCURRENCE, (int) $occurrence->id),
                $this->envelope(
                    'local:planned_occurrence:'.$occurrence->id,
                    $title,
                    $date,
                    $occurrence->occurrence_time ? substr((string) $occurrence->occurrence_time, 0, 5) : null,
                    null,
                    $user->calendarTimezone(),
                ),
            );
        }

        return $result;
    }

    private function timeBlock(Integration $integration, User $user, TimeBlock $block): LocalCalendarProjection
    {
        return new LocalCalendarProjection(
            SyncedItem::LOCAL_TIME_BLOCK,
            (int) $block->id,
            Integration::EXPORT_TIME_BLOCK,
            $this->stableId($integration, SyncedItem::LOCAL_TIME_BLOCK, (int) $block->id),
            $this->envelope(
                'local:time_block:'.$block->id,
                $block->title,
                $block->block_date->format('Y-m-d'),
                $block->starts_at ? substr((string) $block->starts_at, 0, 5) : null,
                $block->ends_at ? substr((string) $block->ends_at, 0, 5) : null,
                $user->calendarTimezone(),
            ),
        );
    }

    private function envelope(
        string $externalId,
        string $title,
        string $date,
        ?string $startsAt,
        ?string $endsAt,
        string $timezone,
    ): CalendarEventEnvelope {
        if ($startsAt === null) {
            return CalendarEventEnvelope::allDay(
                $externalId, $title, $date,
                CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->addDay()->format('Y-m-d'),
                'confirmed', null, null,
            );
        }
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$startsAt, $timezone);
        $end = $endsAt === null
            ? $start->addHour()
            : CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$endsAt, $timezone);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addHour();
        }

        return CalendarEventEnvelope::timed($externalId, $title, $start, $end, 'confirmed', null, null);
    }

    private function stableId(Integration $integration, string $type, int $id): string
    {
        return substr(hash_hmac('sha256', $integration->id.'|'.$type.'|'.$id, (string) config('app.key')), 0, 48);
    }

    /** @return list<string> */
    private function ownerTypes(string $category): array
    {
        return match ($category) {
            Integration::EXPORT_ROUTINE => [RecurringRule::OWNER_ROUTINE],
            Integration::EXPORT_SLEEP => [RecurringRule::OWNER_SLEEP_PLAN],
            Integration::EXPORT_HABIT => [RecurringRule::OWNER_HABIT],
            Integration::EXPORT_WORKOUT => [RecurringRule::OWNER_WORKOUT_PROGRAM],
            Integration::EXPORT_SUPPLEMENT => [RecurringRule::OWNER_SUPPLEMENT_COURSE],
            Integration::EXPORT_FINANCE => [
                RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
                RecurringRule::OWNER_FINANCE_DEBT,
                RecurringRule::OWNER_FINANCE_SAVING_FUND,
            ],
            default => [],
        };
    }

    private function category(string $ownerType): ?string
    {
        return match ($ownerType) {
            RecurringRule::OWNER_ROUTINE => Integration::EXPORT_ROUTINE,
            RecurringRule::OWNER_SLEEP_PLAN => Integration::EXPORT_SLEEP,
            RecurringRule::OWNER_HABIT => Integration::EXPORT_HABIT,
            RecurringRule::OWNER_WORKOUT_PROGRAM => Integration::EXPORT_WORKOUT,
            RecurringRule::OWNER_SUPPLEMENT_COURSE => Integration::EXPORT_SUPPLEMENT,
            RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
            RecurringRule::OWNER_FINANCE_DEBT,
            RecurringRule::OWNER_FINANCE_SAVING_FUND => Integration::EXPORT_FINANCE,
            default => null,
        };
    }

    /** @return array<string,Collection<int,Model>> */
    private function owners(User $user, Collection $occurrences): array
    {
        $ids = fn (string $type): array => $occurrences
            ->filter(fn (PlannedOccurrence $occurrence): bool => $occurrence->recurringRule?->owner_type === $type)
            ->pluck('recurringRule.owner_id')->filter()->unique()->values()->all();

        return [
            RecurringRule::OWNER_ROUTINE => Routine::query()->ownedBy($user)
                ->whereIn('id', $ids(RecurringRule::OWNER_ROUTINE))->where('is_archived', false)->get()->keyBy('id'),
            RecurringRule::OWNER_SLEEP_PLAN => SleepPlan::query()->ownedBy($user)
                ->whereIn('id', $ids(RecurringRule::OWNER_SLEEP_PLAN))->where('is_archived', false)->get()->keyBy('id'),
            RecurringRule::OWNER_HABIT => Habit::query()->ownedBy($user)
                ->whereIn('id', $ids(RecurringRule::OWNER_HABIT))->where('is_archived', false)
                ->where('is_active', true)->get()->keyBy('id'),
            RecurringRule::OWNER_WORKOUT_PROGRAM => WorkoutProgram::query()->ownedBy($user)
                ->whereIn('id', $ids(RecurringRule::OWNER_WORKOUT_PROGRAM))->where('is_archived', false)->get()->keyBy('id'),
            RecurringRule::OWNER_SUPPLEMENT_COURSE => SupplementCourse::query()->ownedBy($user)
                ->whereIn('id', $ids(RecurringRule::OWNER_SUPPLEMENT_COURSE))->with('supplement')->get()->keyBy('id'),
            RecurringRule::OWNER_FINANCE_RECURRING_OPERATION => FinanceRecurringOperation::query()->ownedBy($user)
                ->whereIn('id', $ids(RecurringRule::OWNER_FINANCE_RECURRING_OPERATION))->get()->keyBy('id'),
            RecurringRule::OWNER_FINANCE_DEBT => FinanceDebt::query()->ownedBy($user)
                ->whereIn('id', $ids(RecurringRule::OWNER_FINANCE_DEBT))->get()->keyBy('id'),
            RecurringRule::OWNER_FINANCE_SAVING_FUND => FinanceSavingFund::query()->ownedBy($user)
                ->whereIn('id', $ids(RecurringRule::OWNER_FINANCE_SAVING_FUND))->get()->keyBy('id'),
        ];
    }

    /** @param array<string,Collection<int,Model>> $owners */
    private function title(RecurringRule $rule, PlannedOccurrence $occurrence, array $owners): ?string
    {
        $owner = $owners[$rule->owner_type]->get($rule->owner_id) ?? null;
        if (! $owner) {
            return null;
        }

        return match ($rule->owner_type) {
            RecurringRule::OWNER_SUPPLEMENT_COURSE => $owner->name ?: $owner->supplement?->name,
            default => $owner->name,
        };
    }
}
