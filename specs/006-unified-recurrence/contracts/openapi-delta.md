# Contracts: Unified Recurrence with Routine Migration

**Feature ID**: `006-unified-recurrence`

## Public HTTP contract: unchanged

No endpoint is added, removed or reshaped. `specs/001-core-daily-loop/contracts/openapi.yaml` remains
accurate and this feature deliberately does not edit it.

That is the point of the increment: the schedule moves from `routines` columns and a `routine_weekdays`
table onto `recurring_rules`, while the wire format stays identical.

| Endpoint | Field | Before | After |
|---|---|---|---|
| `GET /api/routines` | `schedule_type` | column on `routines` | accessor over the rule's `frequency` (`daily`/`weekly` presented as `daily`/`weekdays`) |
| | `weekdays` | `routine_weekdays` rows | accessor over `recurring_rule_weekdays` |
| | `starts_on`, `ends_on` | columns on `routines` | accessors over the rule's bounds |
| | `preferred_time` | column on `routines` | accessor over the rule's `slot_time` |
| `POST /api/routines` | same fields | written to `routines` | written to the rule inside one transaction |
| `PATCH /api/routines/{id}` | same fields | same validation and lock messages | same validation and lock messages, now guarding the rule |
| `GET /api/today` | `preferred_time` | routine column | rule accessor |
| `PUT`/`DELETE /api/routines/{id}/logs/{date}` | unchanged | unchanged | additionally keeps the derived occurrence status in step |

Verification: a compatibility test asserts the exact response key set and values for a routine created
through the API, and the existing browser suites assert the request bodies are unchanged.

## Internal contracts

### `RecurringRuleExpander`

```php
public function occursOn(RecurringRule $rule, string $date): bool;
/** @return list<string> inclusive Y-m-d dates */
public function datesBetween(RecurringRule $rule, string $from, string $to): array;
```

Pure. No database access, no clock, no owner gating. `$date` is `Y-m-d`. Bounds are inclusive. A weekly
rule with no weekday produces nothing.

### `RoutineScheduleService`

```php
public function isScheduledFor(Routine $routine, CarbonInterface|string $date, ?string $timezone = null): bool;
```

Signature unchanged from feature 001, so every existing caller is untouched. Adds owner gating —
soft-deleted, paused, archived before the date — on top of the expander.

### `RecurrenceMaterializer`

```php
public function materialize(RecurringRule $rule, ?string $today = null): int;   // occurrences written
public function materializeForUser(User $user, ?string $today = null): int;
```

Bounded to 90 days from the owner's current local day, clamped by the rule's bounds. Atomic per rule,
idempotent, and never deletes an occurrence linked to a fact.

### `OccurrenceFactSynchronizer`

```php
public function syncFromLog(RoutineLog $log): void;
public function clearForRoutineDate(Routine $routine, string $date): void;
public function reconcile(User $user): int;
```

The only writer of `planned_occurrences.status` and `planned_occurrences.routine_log_id`.

### Console

- `php artisan recurrence:materialize [--user=]` — extends the window.
- `php artisan recurrence:reconcile [--user=]` — rebuilds derived occurrence status from the logs.

## Frontend contract

`apps/web/src/api/types.ts` is unchanged: `Routine` keeps `schedule_type`, `weekdays`, `preferred_time`,
`starts_on` and `ends_on`. The recurrence editor is a presentation change only.
