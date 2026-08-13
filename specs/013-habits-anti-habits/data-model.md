# Data Model: Habits and Anti-Habits

**Feature ID**: `013-habits-anti-habits`

One additive migration creates three user-owned tables and adds one nullable fact link to the existing
occurrence table. No current column/table/data is renamed, rewritten, or dropped.

## `habits` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | immutable owner; indexed with lifecycle |
| `name` | varchar(160) | trimmed, required |
| `description` | text nullable | max 2,000 input chars |
| `kind` | varchar(20) | `habit` or `anti_habit` |
| `mode` | varchar(32) | `yes_no`, `numeric`, `abstinence`, `stepped_limit` |
| `target_value` | decimal(12,3) nullable | positive; numeric mode only |
| `unit` | varchar(32) nullable | trimmed user content; numeric/stepped only as applicable |
| `routine_id` | nullable fk routines, null on delete | authoritative outbound stacking link |
| `goal_id` | nullable fk goals, null on delete | authoritative outbound alignment link |
| `intention_place` | varchar(160) nullable | user content |
| `two_minute_starter` | varchar(300) nullable | user content |
| `is_active` | boolean default true | false = paused |
| `is_archived` | boolean default false | retained lifecycle state |
| `archived_at` | timestamp nullable | derived on archive/restore |
| timestamps | | UTC Laravel timestamps |

Indexes: `(user_id, is_archived, is_active)`, `routine_id`, `goal_id`. The model uses `UserOwned` and
rejects cross-owner relationship changes in a saving guard as a defence below controller validation.

### Mode invariant

| Kind | Allowed mode | Target | Unit | Limit steps |
|---|---|---|---|---|
| habit | yes_no | null | null | none |
| habit | numeric | positive | required | none |
| anti_habit | abstinence | null | null | none |
| anti_habit | stepped_limit | null | required | one or more |

Kind and mode do not change after creation. Numeric target/unit stop changing after the first fact so
old success semantics cannot be silently reinterpreted.

## `habit_logs` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | immutable owner |
| `habit_id` | fk habits, cascade | same owner enforced by model/service |
| `log_date` | date | effective scheduled date in profile timezone |
| `outcome` | varchar(24) | `done`, `not_done`, `recorded`, `protected`, `relapse`, `skipped` |
| `value` | decimal(12,3) nullable | non-negative; numeric/stepped recorded outcomes only |
| `occurred_at` | timestamp nullable | required for non-skipped; UTC instant |
| `note` | varchar(1000) nullable | user content |
| timestamps | | audit/write instants |

Unique `(habit_id, log_date)` makes correction an upsert. Index `(user_id, log_date)` supports daily
and period reads. The service validates that one owned effective planned occurrence exists. A log is
the authoritative domain fact; outcome success is derived from the parent mode/target.

## `habit_limit_steps` (new)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | immutable owner |
| `habit_id` | fk habits, cascade | stepped-limit parent only |
| `effective_on` | date | local calendar date |
| `limit_value` | decimal(12,3) | positive ceiling |
| `period` | varchar(8) | `day` or `week` |
| timestamps | | |

Unique `(habit_id, effective_on)` and index `(user_id, effective_on)`. Ordering is by effective date;
status is never persisted. An atomic replacement validates increasing dates and decreasing normalized
daily rates before deleting prior steps.

## `planned_occurrences` (additive change)

| Column | Type | Notes |
|---|---|---|
| `habit_log_id` | nullable fk habit_logs, null on delete | fact mirror for a habit-owned occurrence |

A unique nullable index on `habit_log_id` ensures one log cannot satisfy two occurrences. Existing
`routine_log_id` is unchanged. A row may have at most one of these links; services/model guard enforce
that invariant. Stale materialization preserves a row when either link or `rescheduled_to` is present.

## Existing `recurring_rules`

No schema change. Add owner vocabulary `habit`; unique `(owner_type, owner_id)` continues to guarantee
one rule. Habit responses expose the existing schedule vocabulary:

- `daily` → rule frequency `daily`, no weekday rows;
- `weekdays` → rule frequency `weekly`, one or more normalized weekday rows;
- `starts_on`, `ends_on`, `preferred_time` map to rule bounds/slot time;
- `timezone` always comes from Profile at creation and follows existing recurrence behavior.

## State transitions

```text
Habit: active ↔ paused; active/paused → archived → restored
Occurrence: planned → done|skipped when a log links; clear → planned
Log: absent → created → corrected → cleared
Step (derived): upcoming → current → completed as user-local date advances
Limit (derived): no-active-step | within | exceeded
```

- Pause/archive invokes materialization with `enabled=false`; unmarked current/future predictions are
  removed, fact-linked/rescheduled rows survive.
- Restore/resume invokes normal materialization and idempotently recreates the window.
- A failed log or limit-plan transaction leaves both domain rows and occurrence mirror unchanged.

## Derived aggregates

### Ordinary and abstinence statistics

For effective occurrence dates inside the selected inclusive range:

- `opportunities`: logged rows plus missing/skipped occurrences strictly before local today;
- `successes`: yes/no done; numeric recorded value ≥ target; abstinence protected;
- `completion_percentage`: `successes / opportunities * 100`, zero for no opportunities;
- `numeric_total`: sum of recorded values for numeric mode, else null;
- `current_streak`: consecutive successful scheduled opportunities ending at the latest resolved
  opportunity, while an unresolved current/future occurrence is ignored rather than treated as failure;
- `best_streak`: maximum consecutive successful scheduled opportunities in the range.

### Stepped limit status

- active step = latest `effective_on <= local date`; first future step is returned when none is active;
- day period = local date; week period = containing Monday–Sunday;
- consumed = sum of recorded values within inclusive period;
- remaining = `max(0, limit - consumed)`;
- state = `within` when consumed ≤ limit, otherwise `exceeded`.

These are response projections, not columns. Feature 023 may persist module-produced daily rollups once
real query volume proves the need.

## Ownership and deletion

- Every new query begins with `ownedBy($request->user())`; nested ids resolve through an owned Habit.
- Model guards verify child and routine/goal parent ownership even outside HTTP.
- User deletion cascades all new records. Habit deletion is not exposed, but the database cascade is
  correct for rollback/test fixtures. Routine/Goal deletion nulls the optional link.
- An occurrence/log parent deletion nulls its fact mirror before/while cascading, leaving recurrence
  repairable. No cross-user id is surfaced in validation errors.

## Rollback order

1. Drop `planned_occurrences.habit_log_id` FK/index/column.
2. Drop `habit_limit_steps`.
3. Drop `habit_logs`.
4. Drop `habits`.

Existing recurrence, routine, goal, notification, profile, and user tables/rows remain byte-for-byte
outside normal timestamp effects of the migration test transaction.
