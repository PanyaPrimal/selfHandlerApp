# Data Model: Core Daily Loop

This feature uses user-owned relational records and computes Today/progress views from source data.
All timestamps are stored in UTC; calendar-date fields represent dates in the configured SelfHandler
calendar timezone and are not converted into instants.

## User

Existing identity owner. Account registration and login UI are outside this feature.

### Relationships

- Has many Routines, Routine Weekdays, Routine Logs, Daily Reviews, Goals, and Goal-Routine Links.
- Deleting a user cascades to all records in this feature.

## Routine

A repeatable checklist action owned by one user.

### Fields

| Field | Type | Required | Rules |
|---|---|---:|---|
| `id` | identifier | yes | Primary key |
| `user_id` | identifier | yes | Owner; indexed and immutable |
| `name` | string | yes | Trimmed, 1-160 characters |
| `description` | text | no | Maximum 2,000 characters |
| `kind` | string | yes | `routine`, `sleep`, or `habit`; default `routine` |
| `schedule_type` | string | yes | `daily` or `weekdays` |
| `preferred_time` | local time | no | Display/sort hint in configured timezone |
| `sort_order` | non-negative integer | yes | Default `0`; stable secondary sort by name/id |
| `is_active` | boolean | yes | Pauses future scheduling when false |
| `is_archived` | boolean | yes | Domain archive flag; default false |
| `archived_at` | instant | no | UTC instant when archived; cleared on restore |
| `starts_on` | date | no | Inclusive first eligible calendar date |
| `ends_on` | date | no | Inclusive last eligible date; not before `starts_on` |
| `created_at`, `updated_at` | instant | yes | Application-managed timestamps |
| `deleted_at` | instant | no | Future trash semantics, not archive |

### Relationships and Constraints

- Has zero or more Routine Weekdays; exactly one or more are required for `weekdays` scheduling and
  none are used for `daily` scheduling.
- Has many Routine Logs and many Goals through Goal-Routine Links.
- Index `(user_id, is_archived, is_active, sort_order)` supports current planning.
- Ownership of every related weekday, log, and goal link MUST equal the Routine owner.
- After the first Routine Log exists, `schedule_type`, weekdays, and `starts_on` are immutable. Archive
  the Routine and create a replacement when the schedule needs to change.

### Lifecycle

```text
active <-> paused
  |
  v
archived <-> restored (restores prior active/paused setting)
  |
  v
soft-deleted (future trash action, not a normal archive operation)
```

Archived and deleted routines never generate current/future checklist items. For a past date, a
Routine archived after that date remains eligible under its unchanged schedule; an existing log is
always sufficient to expose the historical occurrence. Soft-deleted trash remains excluded.

## Routine Weekday

A normalized weekday belonging to a weekday-scheduled Routine. It is an implementation detail of the
Routine schedule, not a separately managed product concept.

### Fields

| Field | Type | Required | Rules |
|---|---|---:|---|
| `id` | identifier | yes | Primary key |
| `user_id` | identifier | yes | Must equal Routine owner |
| `routine_id` | identifier | yes | Cascades when Routine is truly deleted |
| `weekday` | fixed string | yes | One of `MO`, `TU`, `WE`, `TH`, `FR`, `SA`, `SU` |
| `created_at`, `updated_at` | instant | yes | Application-managed timestamps |

### Constraints

- Unique `(user_id, routine_id, weekday)`.
- Index `(user_id, weekday, routine_id)` supports date schedule resolution.

## Routine Log

The explicit outcome for one Routine on one calendar date. Absence of a row means pending.

### Fields

| Field | Type | Required | Rules |
|---|---|---:|---|
| `id` | identifier | yes | Primary key |
| `user_id` | identifier | yes | Must equal Routine owner |
| `routine_id` | identifier | yes | Parent Routine |
| `log_date` | date | yes | Calendar date in configured timezone |
| `status` | string | yes | `done` or `skipped` |
| `note` | text | no | Maximum 2,000 characters |
| `completed_at` | instant | no | Set for `done`, cleared for `skipped` |
| `created_at`, `updated_at` | instant | yes | Application-managed timestamps |

### Constraints and Transitions

- Unique `(user_id, routine_id, log_date)` makes state changes idempotent.
- `pending -> done`, `pending -> skipped`, `done <-> skipped` use upsert semantics.
- `done/skipped -> pending` deletes the log through the explicit clear operation.
- A log may be written only for a Routine owned by the current user.

## Daily Review

One reflection for one user and calendar date.

### Fields

| Field | Type | Required | Rules |
|---|---|---:|---|
| `id` | identifier | yes | Primary key |
| `user_id` | identifier | yes | Owner |
| `review_date` | date | yes | Calendar date in configured timezone |
| `mood` | small integer | no | 1 through 10 |
| `energy` | small integer | no | 1 through 10 |
| `stress` | small integer | no | 1 through 10 |
| `day_rating` | small integer | no | 1 through 10 |
| `went_well` | text | no | Maximum 5,000 characters |
| `improve_tomorrow` | text | no | Maximum 5,000 characters |
| `notes` | text | no | Maximum 10,000 characters |
| `completed_at` | instant | yes after save | First completion instant; later edits preserve completion |
| `created_at`, `updated_at` | instant | yes | Application-managed timestamps |

### Constraints

- Unique `(user_id, review_date)`.
- Upsert updates the one existing review; it never appends a second review for the date.

## Goal

A general desired outcome that can provide context for Routines.

### Fields

| Field | Type | Required | Rules |
|---|---|---:|---|
| `id` | identifier | yes | Primary key |
| `user_id` | identifier | yes | Owner |
| `name` | string | yes | Trimmed, 1-160 characters |
| `description` | text | no | Maximum 5,000 characters |
| `type` | string | yes | `general` in this feature; extensible later |
| `status` | string | yes | `active`, `completed`, or `abandoned` |
| `target_date` | date | no | User-selected calendar date |
| `completed_at` | instant | no | Required/set when completed; cleared otherwise |
| `is_archived` | boolean | yes | Domain archive flag; default false |
| `archived_at` | instant | no | UTC instant when archived; cleared on restore |
| `created_at`, `updated_at` | instant | yes | Application-managed timestamps |
| `deleted_at` | instant | no | Future trash semantics |

### Lifecycle

```text
active -> completed
active -> abandoned
completed/abandoned -> active
any status <-> archived/restored
```

Only active, non-archived, non-deleted Goals appear as motivation on Today. Historical links remain.

## Goal-Routine Link

User-owned many-to-many relationship showing that a Routine supports a Goal.

### Fields and Constraints

| Field | Type | Required | Rules |
|---|---|---:|---|
| `id` | identifier | yes | Primary key |
| `user_id` | identifier | yes | Must equal both related owners |
| `goal_id` | identifier | yes | Parent Goal |
| `routine_id` | identifier | yes | Parent Routine |
| `created_at`, `updated_at` | instant | yes | Application-managed timestamps |

- Unique `(user_id, goal_id, routine_id)` makes linking idempotent.
- Link creation fails if either side belongs to another user.
- Unlink deletes only the relationship.

## Derived Views

### Scheduled Occurrence

Not persisted in this feature. A Routine is scheduled for a date when all conditions are true:

1. It is active, not archived, and not soft-deleted.
2. The date is on or after `starts_on` when present.
3. The date is on or before `ends_on` when present.
4. Its schedule is `daily`, or its normalized weekday set contains the date's weekday.

### Today Summary

For the selected calendar date:

- `scheduled`: number of scheduled occurrences;
- `done`: scheduled occurrences with a done log;
- `skipped`: scheduled occurrences with a skipped log;
- `pending`: `scheduled - done - skipped`;
- `completion_rate`: `done / scheduled * 100`, or `0` with an explicit empty state when scheduled is
  zero.

### Current Streak

Walk backward through scheduled occurrences from the selected date. Count consecutive done logs.
Skipped occurrences break the streak. A missing log breaks the streak only when that occurrence's
calendar date has ended in the configured timezone; a current or future pending occurrence is ignored.

### Seven-Day Completion

Use the inclusive range ending on the selected date and beginning six calendar days earlier. Sum only
scheduled occurrences. The numerator is done and the denominator is scheduled; skipped and pending
remain in the denominator.
