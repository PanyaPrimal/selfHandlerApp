# Data Model: Body Measurements and Body Goals

**Feature ID**: `007-body-measurements`

One additive migration, `2026_08_12_140000_create_body_measurements`. No existing table is reshaped and
no historical migration is edited; `goals` gains only a new accepted value for its existing `type`
column.

## `body_measurements`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | owner boundary |
| `metric` | string(32) | validated against `BodyMetric` |
| `measured_on` | date | calendar day, cast `date:Y-m-d` |
| `value` | decimal(12,4) | canonical base unit for the metric |
| `note` | string(500), nullable | |
| timestamps | | |

Unique `(user_id, metric, measured_on)` — one value per metric per day; saving again is a correction.
Index `(user_id, metric, measured_on)` serves ordering and range reads.

## `body_goal_details`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | |
| `goal_id` | fk goals, cascade, unique | one detail per goal |
| `metric` | string(32) | validated against `BodyMetric` |
| `direction` | string(16) | `lose`, `gain` or `maintain` |
| `starting_value` | decimal(12,4) | canonical unit, the reference the goal was set from |
| `target_value` | decimal(12,4) | canonical unit |
| timestamps | | |

The deadline is the goal's own `target_date`; it is not duplicated here.

## `goal_milestones`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | |
| `goal_id` | fk goals, cascade | |
| `target_value` | decimal(12,4) | canonical unit |
| `target_date` | date, nullable | |
| timestamps | | |

Unique `(goal_id, target_value)`. There is deliberately **no** `achieved_at` column: achievement is
derived from the measurement history at read time, so a milestone can never claim something the
observations do not support.

## `BodyMetric` vocabulary

`App\ValueObjects\BodyMetric` is the single source for units and bounds.

| Case | Canonical unit | Metric display | Imperial display | Precision | Plausible range (canonical) | Pace boundary |
|---|---|---|---|---|---|---|
| `body_mass` | gram | kg | lb | 0.1 kg | 20 000 – 500 000 g | yes |
| `body_fat_percentage` | percent | % | % | 0.1 | 2 – 75 | no |
| `waist` | metre | cm | in | 0.1 cm | 0.30 – 3.00 | no |
| `chest` | metre | cm | in | 0.1 cm | 0.30 – 3.00 | no |
| `hips` | metre | cm | in | 0.1 cm | 0.30 – 3.00 | no |
| `thigh` | metre | cm | in | 0.1 cm | 0.10 – 1.50 | no |
| `upper_arm` | metre | cm | in | 0.1 cm | 0.10 – 1.00 | no |
| `neck` | metre | cm | in | 0.1 cm | 0.15 – 1.00 | no |
| `calf` | metre | cm | in | 0.1 cm | 0.10 – 1.00 | no |

Adding a metric is one enum case. It needs no migration, because the value column is already canonical
decimal and the metric column is already validated against the enum.

## Derived values

| Value | Owner | Rule |
|---|---|---|
| history | `BodyMeasurementController` | bounded by range or limit, ordered by date |
| trend | `BodyTrendService` | OLS slope per week over the window; explicit empty and insufficient states |
| body-goal progress | `BodyGoalProgressService` | latest observation on or before today against start and target, direction-aware |
| milestone achievement | `BodyGoalProgressService` | derived from history, never stored |
| pace warning | `SafePaceValidator` | implied weekly rate against a documented boundary |

## Ownership

All three tables carry `user_id` and use `UserOwned`, which refuses an unowned write and refuses an
owner change. `body_goal_details` and `goal_milestones` additionally refuse an owner different from the
goal they belong to, matching the guard `RoutineLog` already uses.
