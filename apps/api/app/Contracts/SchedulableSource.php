<?php

namespace App\Contracts;

use App\Models\User;
use App\Support\PlannerEntry;

/**
 * How a module appears in the day.
 *
 * This is the answer to open question 6 of `docs/design/recurrence-engine.md`:
 * Planner aggregates a calendar day by asking each registered source what it has,
 * rather than reading other modules' tables itself or having them push copies in.
 *
 * A source only reads. Planner routes every action back to the endpoint that owns
 * the record, so each module keeps enforcing its own rules.
 */
interface SchedulableSource
{
    /** Stable identifier of this source, as it appears in a planner entry. */
    public function name(): string;

    /**
     * Everything this module has for the given user on the given calendar day.
     *
     * @param  string  $date  `Y-m-d` in the user's own time zone
     * @return list<PlannerEntry>
     */
    public function entriesFor(User $user, string $date): array;
}
