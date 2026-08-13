# Data Model: Sleep and Rich Routine Templates

**Feature ID**: `014-sleep-routine-templates`

## Additive Migration

One migration named `2026_08_13_180000_create_sleep_and_routine_templates.php` adds six tables and two
columns: non-null `routines.day_period` plus nullable fact link
`planned_occurrences.sleep_log_id`. It does not rewrite existing routines/logs/occurrences.

### `sleep_plans`

| Column | Type | Rules |
|---|---|---|
| `id` | bigint | primary key |
| `user_id` | bigint | FK users, cascade; immutable |
| `name` | varchar(160) | required |
| `planned_wake_time` | time | required; differs from rule slot time |
| `is_active` | boolean | default true |
| `is_archived` | boolean | default false |
| `archived_at` | timestamp nullable | derived with archive lifecycle |
| timestamps | timestamps | stable audit fields |

Indexes: `(user_id, is_archived, is_active)` and `(user_id, name)`.

Service invariant: at most one plan with `is_active=true AND is_archived=false` per user. A portable
transaction locks the owning user row before checking/saving instead of using an unsafe boolean unique
index; concurrent activations therefore serialize on both MySQL and SQLite-supported test paths.

### `sleep_occurrence_details`

| Column | Type | Rules |
|---|---|---|
| `id` | bigint | primary key |
| `user_id` | bigint | FK users, cascade; immutable |
| `planned_occurrence_id` | bigint | FK planned_occurrences, cascade; unique |
| `planned_wake_time` | time | snapshotted module metadata |
| timestamps | timestamps | audit |

Same-owner guard verifies the occurrence and its `RecurringRule::OWNER_SLEEP_PLAN` owner. Details for
unlinked future occurrences update when a plan changes; linked details never change.

### `sleep_logs`

| Column | Type | Rules |
|---|---|---|
| `id` | bigint | primary key |
| `user_id` | bigint | FK users, cascade; immutable |
| `sleep_plan_id` | bigint | FK sleep_plans, restrict |
| `sleep_date` | date | planned-bedtime local date |
| `actual_bed_at` | timestamp | UTC instant |
| `actual_wake_at` | timestamp | UTC instant; later and ≤24h |
| `quality` | unsigned tinyint | 1–10 |
| `note` | text nullable | max 5,000 at request boundary |
| timestamps | timestamps | audit |

Unique `(user_id, sleep_date)` and `(sleep_plan_id, sleep_date)` prevent duplicate episodes. Duration,
local wall fields, and averages are derived, never stored.

### `routine_activities`

| Column | Type | Rules |
|---|---|---|
| `id` | bigint | primary key |
| `user_id` | bigint | FK users, cascade; immutable |
| `routine_id` | bigint | FK routines, cascade; same owner |
| `name` | varchar(160) | required |
| `sort_order` | unsigned integer | required, unique per routine |
| `preferred_time` | time nullable | presentation/order context |
| `progress_total` | decimal(10,3) nullable | positive if present |
| timestamps | timestamps | audit |

Unique `(routine_id, sort_order)`. Membership and `progress_total` lock after the routine has a parent
or activity fact; name/order/time remain editable transactionally.

### `routine_activity_logs`

| Column | Type | Rules |
|---|---|---|
| `id` | bigint | primary key |
| `user_id` | bigint | FK users, cascade; immutable |
| `routine_activity_id` | bigint | FK routine_activities, cascade; same owner |
| `log_date` | date | user-local effective occurrence date |
| `status` | varchar(16) | `done` or `skipped` |
| `progress_value` | decimal(10,3) nullable | done + progress-compatible only, 0..total |
| `note` | text nullable | max 2,000 at request boundary |
| `completed_at` | timestamp nullable | stable first done timestamp; null when skipped |
| timestamps | timestamps | audit |

Unique `(routine_activity_id, log_date)`. Parent `routine_logs` remains the only generic occurrence fact
link and is synchronized transactionally after every activity change.

### `routine_day_selections`

| Column | Type | Rules |
|---|---|---|
| `id` | bigint | primary key |
| `user_id` | bigint | FK users, cascade; immutable |
| `selection_date` | date | user-local day |
| `period` | varchar(16) | `morning` or `evening` |
| `routine_id` | bigint nullable | FK routines, cascade; null means an explicitly saved none |
| timestamps | timestamps | audit |

Unique `(user_id, selection_date, period)`. No row means deterministic default; a row with null means
explicit none. The service validates an effective owned occurrence and period before saving a link.

## Altered Tables

### `routines`

Add `day_period varchar(16) NOT NULL DEFAULT 'anytime'` and index `(user_id, day_period, is_archived)`.
Existing rows receive `anytime` by the database default; no data rewrite is required.

### `planned_occurrences`

Add nullable `sleep_log_id` with unique index and FK to `sleep_logs` (`nullOnDelete`). Model invariant:
at most one of `routine_log_id`, `habit_log_id`, or `sleep_log_id` may be non-null. `hasFact()` covers
all three. Existing rows retain both current values byte-for-byte.

## Shared Recurrence Ownership

`RecurringRule::OWNER_SLEEP_PLAN = 'sleep_plan'`. Each SleepPlan has exactly one rule:

- rule `slot_time` = planned bedtime;
- plan `planned_wake_time` = second wall time;
- occurrence `occurrence_date` = night date;
- occurrence detail = wake-time snapshot;
- occurrence `sleep_log_id` = authoritative fact mirror;
- materializer enabled state = active, not archived, not soft-deleted plan.

`SleepPlanRecurrence::apply()` creates/updates the rule and calls the shared materializer. For a sleep
owner, the materializer resolves `planned_wake_time` in its bounded owner query and, in the same
transaction as occurrence upsert/delete, inserts missing details and updates only unlinked future
details. This applies equally to plan writes and `recurrence:materialize`. Reconcile can rebuild
occurrence fact links from sleep logs and never changes actual facts or wake snapshots.

## State Machines

### Sleep plan

```text
active <-> paused
  |          |
  +------> archived
              |
              +--> restored paused/active only if one-active invariant permits
```

### Sleep occurrence/fact

```text
planned --record--> done (sleep_log_id set)
done --correct--> done (same log id)
done --clear--> planned (sleep_log deleted/link null)
planned --reschedule--> planned on effective target date
```

### Rich routine parent derivation

```text
any child pending                     => no parent RoutineLog / occurrence planned
all children done                     => parent RoutineLog done / occurrence done
all resolved and at least one skipped => parent RoutineLog skipped / occurrence skipped
clear any child                       => recompute; possibly remove parent fact
Planner skip                          => unresolved children skipped, then recompute
```

Simple routines with zero active activities retain their existing direct state machine unchanged.

## Validation Invariants

- Sleep plan name 1–160; wake and bedtime differ; daily or selected non-empty weekdays; valid bounds.
- One active non-archived sleep plan per user; lifecycle update is locked/transactional.
- Sleep actual bed date is night date or next day; wake is later; duration `(0, 1440]` minutes; quality
  1–10; both wall datetimes exist in Profile timezone.
- Sleep log date is one effective occurrence for its plan; rescheduled collisions are rejected.
- Routine `day_period` is morning/evening/anytime.
- Activity names 1–160; orders unique/non-negative; time valid; total positive ≤9,999,999.999.
- Replacement ids belong to the parent/user exactly once. Semantic membership/total changes reject
  after any parent/activity fact exists.
- Activity done requires progress only when a total exists and bounds it to 0..total; skipped rejects
  progress. Activity date must belong to the selected/effective template.
- Selection body contains exactly required nullable morning/evening ids. Linked routine must be owned,
  active, not archived, matching period, and effectively scheduled. A fact-bearing selected routine
  cannot be replaced/hidden.
- All mutation objects reject unknown top-level and nested keys.

## Query and Aggregate Plan

- Selected-day routine projection loads candidates, explicit selections, active activities, activity
  logs, and parent logs in bounded set queries; no query per candidate/activity.
- Sleep selected-day/range loads rules/occurrences/details/logs in set queries. Range max is 366 days.
- Sleep statistics: planned nights, recorded nights, average duration minutes, average quality.
- Routine activity summary: scheduled, done, skipped, pending, nullable completion rate (null when
  scheduled is zero), plus per-template counts in the same shape.
- Today obtains both DTOs once. Review obtains the same Today response for its date and does not query
  activity/sleep tables itself.

## Deletion and Ownership

- Account delete cascades all six new tables and rules/occurrences through existing user ownership.
- Hard routine delete cascades definitions/facts/selection rows; an explicitly saved null selection is
  independent of a routine and survives until its user/date replacement. Normal lifecycle archives.
- Sleep plans are archived, not API-deleted; logs restrict plan deletion and retain history.
- Sleep occurrence delete cascades its detail and nulls the log fact link through migration order.
- Nested cross-owner saves throw at the model boundary; HTTP lookup returns 404/422 without disclosure.

## Rollback Order

1. Drop `planned_occurrences.sleep_log_id` FK/index/column.
2. Drop `routines.day_period` index/column.
3. Drop `routine_day_selections`.
4. Drop `routine_activity_logs`.
5. Drop `routine_activities`.
6. Drop `sleep_occurrence_details`.
7. Drop `sleep_logs`.
8. Drop `sleep_plans`.

Existing routine, habit, recurrence, Planner, notification, profile, goal, review, and user rows remain
unchanged outside normal transaction timestamp effects.
