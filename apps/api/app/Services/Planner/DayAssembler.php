<?php

namespace App\Services\Planner;

use App\Models\RecurringRule;
use App\Models\User;
use App\Support\PlannerEntry;
use Carbon\CarbonImmutable;

/**
 * Assembles one calendar day out of the registered sources.
 *
 * Nothing is stored: a day is built on every read, because a cached day would
 * start drifting from the modules the user actually edits — which is the whole
 * reason Planner reads through a contract instead of keeping its own copies.
 */
class DayAssembler
{
    public function __construct(private readonly SourceRegistry $sources) {}

    /**
     * @return array{
     *     date: string,
     *     today: string,
     *     entries: list<array<string, mixed>>,
     *     window: array{materialized_until: string|null, beyond: bool}
     * }
     */
    public function assemble(User $user, string $date): array
    {
        $entries = [];

        foreach ($this->sources->all() as $source) {
            foreach ($source->entriesFor($user, $date) as $entry) {
                $entries[] = $entry;
            }
        }

        usort(
            $entries,
            static fn (PlannerEntry $left, PlannerEntry $right): int => $left->sortKey() <=> $right->sortKey(),
        );

        $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();

        return [
            'date' => $date,
            'today' => $today,
            'entries' => array_map(static fn (PlannerEntry $entry): array => $entry->toArray(), $entries),
            'window' => $this->window($user, $date),
        ];
    }

    /**
     * How far routine days have been expanded, and whether this day is past it.
     *
     * "Nothing is planned" and "we have not expanded that far" are different
     * answers, and a day beyond the window must say which one it is rather than
     * looking convincingly empty.
     *
     * @return array{materialized_until: string|null, beyond: bool}
     */
    private function window(User $user, string $date): array
    {
        $until = RecurringRule::query()
            ->ownedBy($user)
            ->max('last_materialized_until');

        $until = is_string($until) ? substr($until, 0, 10) : null;

        return [
            'materialized_until' => $until,
            'beyond' => $until !== null && $date > $until,
        ];
    }
}
