# Data Model: Planner and Day Planning

**Feature ID**: `009-planner-day`

One additive migration, `2026_08_12_180000_create_planner_day`. It adds one table and one nullable
column. Nothing is reshaped.

## `time_blocks` (new)

The only fact Planner owns.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | owner boundary |
| `title` | string(200) | required, trimmed |
| `note` | string(500), nullable | |
| `block_date` | date | calendar day, cast `date:Y-m-d` |
| `starts_at` | time, nullable | optional: "dentist, Tuesday" is a real entry |
| `ends_at` | time, nullable | must be after `starts_at` when both are present |
| timestamps | | |

Index `(user_id, block_date)`.

Overlap is not constrained: two blocks at the same time is a conflict the user is noting, not an error
for the product to refuse.

## `planned_occurrences.rescheduled_to` (new column)

| Column | Type | Notes |
|---|---|---|
| `rescheduled_to` | date, nullable | the day this occurrence was moved to |

`occurrence_date` keeps what the rule expanded. Overwriting it instead would make the next
materialization see a missing day and recreate it, producing a duplicate — and would erase what was
originally planned.

An occurrence with `rescheduled_to` set is shown on that day and not on its original one.

## What Planner does not store

No projection of a routine, a routine log, a Storage item or a project is ever written here. A day is
assembled on read from the modules that own its parts.

## The `SchedulableSource` contract

```php
interface SchedulableSource
{
    public function name(): string;                                  // 'routine' | 'storage' | 'time_block'
    /** @return list<PlannerEntry> */
    public function entriesFor(User $user, string $date): array;
}
```

`PlannerEntry` is a read-only value object, never persisted:

| Field | Meaning |
|---|---|
| `source` | which module reported it |
| `source_id` | its identifier inside that module |
| `title` | what to show |
| `time` | `HH:MM` or null |
| `status` | the source's own status word |
| `actions` | which planner actions apply: `skip`, `reschedule`, `move`, `edit` |
| `meta` | small source-specific detail the interface needs |

Registered implementations in this feature:

| Source | Reads | Offers |
|---|---|---|
| `routine` | `planned_occurrences` for the day, plus their rules and routines | skip, reschedule |
| `storage` | `items` with `due_on` on the day | move |
| `time_block` | `time_blocks` for the day | edit, delete |

## Ordering

Timed entries first, ascending by time; then untimed, both then by title and finally by
`source`+`source_id` so the order is total and cannot vary between reads.

## Derived values

| Value | Owner | Rule |
|---|---|---|
| a day's entries | Planner | assembled from the registered sources on read |
| whether a day is inside the window | Planner | compared against `recurring_rules.last_materialized_until` |
| routine completion and skips | feature 001/006 | unchanged; Planner routes actions to them |
