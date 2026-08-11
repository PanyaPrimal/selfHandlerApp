# Data Model: Unified Recurrence with Routine Migration

**Feature ID**: `006-unified-recurrence`

## Migration

One additive-then-drop migration, `2026_08_12_120000_introduce_recurring_rules`, run inline because the
live dataset is a handful of rows.

1. Create `recurring_rules`, `recurring_rule_weekdays`, `planned_occurrences`.
2. Backfill one rule per existing routine from `routines.schedule_type`, `routines.starts_on`,
   `routines.ends_on`, `routines.preferred_time`, `routines.is_active` and the owner's profile time zone.
3. Backfill `recurring_rule_weekdays` from `routine_weekdays`.
4. Drop `routine_weekdays`; drop `schedule_type`, `starts_on`, `ends_on`, `preferred_time` from
   `routines`.

`down()` restores the dropped columns and table and backfills them from the rules, so the change is
reversible on a data-bearing database.

No historical migration is edited.

## `recurring_rules`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | owner boundary, indexed |
| `owner_type` / `owner_id` | string / bigint | polymorphic owner; unique together |
| `frequency` | string | `daily` or `weekly` |
| `starts_on` | date, nullable | inclusive lower bound; null means unbounded |
| `ends_on` | date, nullable | inclusive upper bound; null means unbounded |
| `timezone` | string | IANA zone, seeded from the owner's profile |
| `slot_time` | time, nullable | time of day for the occurrence; surfaced as `preferred_time` |
| `last_materialized_until` | date, nullable | how far the window has been written |
| timestamps | | |

Indexes: `(user_id, owner_type)`, unique `(owner_type, owner_id)`.

There is deliberately no `is_active` column. Pause and resume belong to the owner's lifecycle, next to
archive; putting the same fact on the rule as well would be a second source of truth.

## `recurring_rule_weekdays`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | denormalised owner |
| `recurring_rule_id` | fk recurring_rules, cascade | |
| `weekday` | string(2) | `MO`…`SU`, validated by `WeekdayCode` |

Unique `(recurring_rule_id, weekday)`. A child table rather than JSON, per
`data-conventions.md` §2: the value is filtered and validated, so it is not JSON.

## `planned_occurrences`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | |
| `recurring_rule_id` | fk recurring_rules, cascade | |
| `occurrence_date` | date | calendar day in the rule's zone |
| `slot` | string(32), default `''` | non-null so the unique key holds; `''` = the day's only slot |
| `occurrence_time` | time, nullable | copied from `slot_time` at materialization |
| `status` | string | `planned`, `done`, `skipped` — derived from the fact |
| `routine_log_id` | fk routine_logs, nullable, null on delete | the design's `fact_ref` |
| `materialized_at` | timestamp | |
| timestamps | | |

Unique `(recurring_rule_id, occurrence_date, slot)`. Index `(user_id, occurrence_date)`.

`slot` defaults to the empty string rather than `null` because MySQL treats `NULL`s as distinct in a
unique index, which would silently allow duplicates.

## `routines` after the cutover

Keeps `name`, `description`, `kind`, `sort_order`, `is_active`, `is_archived`, `archived_at`,
timestamps, soft deletes. Loses every schedule column. The API shape does not change: `schedule_type`,
`weekdays`, `starts_on`, `ends_on` and `preferred_time` become appended accessors that read the rule.

## Ownership and lifecycle

- Every rule and occurrence carries `user_id` and is created through `UserOwned`, which refuses a write
  without an owner and refuses an owner change.
- Deleting a routine cascades to its rule and its occurrences.
- `routine_logs` is unchanged and remains the authoritative completion fact. Deleting a log nulls the
  occurrence's `routine_log_id`; the reconciler then returns its status to `planned`.

## Derived values

| Value | Owner | Rule |
|---|---|---|
| "is scheduled on day D" | `RecurringRuleExpander` | pure expansion of the rule, any date |
| materialized window | `RecurrenceMaterializer` | equals the expansion over the same range |
| occurrence status | `OccurrenceFactSynchronizer` | derived from `routine_logs`, recomputable |
| seven-day progress, streaks | `RoutineProgressService` | unchanged behaviour, now reading the rule |
