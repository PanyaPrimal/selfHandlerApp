# Data Model: Cross-Module and Periodic Review

## Existing DailyReview (Unchanged)

One owner-scoped reflection for one calendar date. Its existing one-per-owner/date invariant, fields,
validation, and first completion time remain authoritative. No module aggregate or score column is added.

## PeriodicReview

One owner-scoped manual reflection for one canonical week or month.

| Field | Type | Required | Rules |
|---|---|---:|---|
| `id` | identifier | yes | Primary key |
| `user_id` | identifier | yes | Owner; cascades on hard user deletion |
| `period_type` | string(16) | yes | `weekly` or `monthly` |
| `period_start` | date | yes | Monday for weekly; day 1 for monthly |
| `period_end` | date | yes | start+6 days or calendar month end |
| `period_rating` | tiny integer | no | 1 through 10 |
| `worked_well` | text | no | Trimmed/null; maximum 5,000 characters |
| `did_not_work` | text | no | Trimmed/null; maximum 5,000 characters |
| `learned` | text | no | Trimmed/null; maximum 5,000 characters |
| `next_focus` | text | no | Trimmed/null; maximum 5,000 characters |
| `notes` | text | no | Trimmed/null; maximum 10,000 characters |
| `completed_at` | instant | yes after save | First successful save; preserved on edit |
| `created_at`, `updated_at` | instant | yes | Application-managed UTC timestamps |

### Constraints

- Unique `(user_id, period_type, period_start)`.
- Index `(user_id, period_start, period_end)` for owner history and period lookup.
- `period_end >= period_start` and both must equal the server-derived canonical boundary.
- At least one manual field is present on each API write.
- The model refuses non-canonical type/start/end combinations.
- Upsert aliases every valid anchor within the period to the same record.

### Transitions

```text
absent --first valid save--> completed
completed --valid edit--> completed (same completed_at)
```

Deletion/archive is not exposed in 022. A review can be edited in place; history/versioning is deferred.

## ReviewPeriod (Derived Value)

| Value | Rules |
|---|---|
| `type` | `daily`, `weekly`, or `monthly` |
| `anchor` | Strict requested `Y-m-d` |
| `start` | Daily anchor, Monday, or month day 1 |
| `end` | Same day, Sunday, or month final day |
| `timezone` | Current profile calendar timezone |

It is never stored independently. PeriodicReview stores the canonical start/end as identity evidence.

## ReviewAggregateSource (Read Contract)

```text
key() -> stable unique source key
daily(user, date) -> module-owned daily aggregate
period(user, start, end) -> module-owned bounded aggregate
```

Registered source keys in 022: `routines`, `sleep`, `workouts`, `nutrition`, `supplements`, `habits`,
`planner`, `finance`. Rich routine-activity detail remains an additive legacy-compatible daily member.

No Review table stores source outputs. Adapters may normalize presentation but delegate raw calculation
to module services.

## DayScore (Derived Value)

| Field | Type | Rules |
|---|---|---|
| `value` | decimal/null | 0-100, 2 decimals; null with no available component |
| `available_components` | integer | 0 through 5 |
| `total_components` | integer | Always 5 in feature 022 |
| `coverage_percentage` | decimal | available / 5 × 100 |
| `components` | ordered list | nutrition, workouts, supplements, habits, planner |

Each component contains `key`, `available`, `value`, `weight`, and `reason`. Available weights are
`1 / available_components`; unavailable weight is zero. Stable reasons include `available`,
`no_target_evidence`, `no_workout`, `no_scheduled_items`, and `no_planner_items`.

## WellBeingSummary (Derived Review-Owned Projection)

For a periodic workspace, DailyReview records within the canonical period provide:

- `reviewed_days` and `period_days`;
- nullable average `mood`, `energy`, `stress`, and `day_rating` over non-null values.

Journal text is not concatenated into the aggregate response. It remains available only from its daily
review endpoint and is not copied to PeriodicReview.

## Ownership and Deletion

- All persisted review records carry `user_id` and use the established `UserOwned` scope.
- User hard deletion cascades PeriodicReview rows.
- Source parent deletion/correction affects the next derived workspace naturally; no cleanup job exists.
- Foreign IDs are never accepted because periodic writes are addressed only by type and anchor.

## Migration Strategy

One additive migration creates `periodic_reviews`. Rollback drops only that table. Existing daily review,
source, recurrence, finance, attachment, and deployment schema is untouched.
