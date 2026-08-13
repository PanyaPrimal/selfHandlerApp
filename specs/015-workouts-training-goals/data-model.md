# Data Model: Workouts and Training Goals

## Conventions

- Every private row has `user_id` and same-owner model/service guards.
- Built-in Exercise rows alone have `user_id = null`; their `system_key` is immutable and unique.
- Actual instants are UTC; `performed_on` and API date/time fields are interpreted in Profile timezone.
- Base units: kilograms `DECIMAL(8,3)`, metres unsigned integer, seconds unsigned integer, energy kcal.
- Enum-like strings are validated in application/domain tests so additive schema remains portable.
- No aggregate/progression/record/goal-current column is persisted.

## Additive Migration

`2026_08_13_200000_create_workouts_and_training_goals.php`

### `exercises`

| Column | Type | Rules |
|---|---|---|
| id | bigint | primary key |
| user_id | nullable FK users | cascade account deletion for custom rows |
| system_key | nullable string(64) | unique; non-null only for immutable built-ins |
| name | string(160) | custom user content or canonical English fallback |
| muscle_group | string(64) | validated catalogue enum |
| equipment | nullable string(64) | validated catalogue enum |
| exercise_type | string(32) | `strength|mobility` in this slice |
| is_archived | boolean | default false |
| archived_at | nullable timestamp | lifecycle-derived |
| timestamps | timestamps | |

Indexes: unique `system_key`; unique `(user_id,name)`; lookup `(user_id,is_archived,name)`.
The migration inserts six `system_key` rows: `squat`, `bench_press`, `deadlift`, `overhead_press`,
`row`, and `pull_up`. Public rows cannot be mutated by API.

### `workout_programs`

| Column | Type | Rules |
|---|---|---|
| id | bigint | primary key |
| user_id | FK users | cascade on account deletion |
| name | string(160) | user content |
| description | nullable text | max 5,000 at API |
| workout_type | string(24) | `strength|cardio|flexibility|sport` |
| intensity | string(16) | `light|moderate|vigorous` |
| planned_duration_seconds | nullable unsigned int | 60..86,400 when present |
| is_active | boolean | default true |
| is_archived | boolean | default false |
| archived_at | nullable timestamp | lifecycle-derived |
| timestamps | timestamps | |

Index `(user_id,is_archived,is_active,name)`. A unique shared RecurringRule points to this owner via
`(owner_type='workout_program', owner_id=id)`; schedule fields remain on that rule.

### `workout_program_exercises`

Ordered strength-only prescriptions.

| Column | Type | Rules |
|---|---|---|
| id/user_id | bigint/FK | private owner |
| workout_program_id | FK | cascade with program |
| exercise_id | FK exercises | restrict referenced catalogue deletion |
| sort_order | unsigned smallint | 0..49 |
| target_sets | unsigned tinyint | 1..20 |
| target_reps | unsigned smallint | 1..1,000 |
| starting_weight_kg | decimal(8,3) | 0..9,999.999 |
| increment_kg | decimal(8,3) | >0..1,000 |
| successes_required | unsigned tinyint | 1..20 |
| timestamps | timestamps | |

Unique `(workout_program_id,sort_order)` and `(workout_program_id,exercise_id)`; owner/index on
`(user_id,exercise_id)`.

### `workout_program_endurance_details`

One row only for cardio programs: `id`, `user_id`, unique FK `workout_program_id`, `activity`
(`running|cycling|walking|swimming|other`), nullable `run_type` (`easy|tempo|intervals|long`), nullable
unsigned `target_distance_m`, timestamps. Running accepts a run type; non-running forbids it.

### `workout_program_timed_details`

One row only for flexibility/sport programs: `id`, `user_id`, unique FK `workout_program_id`, nullable
`activity_name` string(160), timestamps. Sport requires an activity; flexibility may use a style or null.

### `workout_sessions`

| Column | Type | Rules |
|---|---|---|
| id/user_id | bigint/FK | private fact owner |
| workout_program_id | nullable FK | null on hard program deletion; null for manual facts |
| name | string(160) | manual name or program-name snapshot |
| workout_type | string(24) | immutable after creation; matches program when planned |
| outcome | string(16) | `completed|skipped`; manual must be completed |
| performed_on | date | Profile-local effective date |
| started_at | nullable UTC timestamp | derived from local date/time |
| duration_seconds | nullable unsigned int | required completed endurance/timed; optional strength |
| note | nullable text | max 5,000 at API |
| timestamps | timestamps | |

Unique `(user_id,workout_program_id,performed_on)` makes planned PUT idempotent while SQL nullable
semantics allow multiple manual facts. Indexes `(user_id,performed_on,id)` and
`(user_id,workout_type,performed_on)`.

### `workout_strength_details`

`id`, `user_id`, unique FK `workout_session_id`, `mode` (`simple|detailed`), timestamps. Exists only
for completed strength sessions.

### `workout_endurance_details`

`id`, `user_id`, unique FK `workout_session_id`, `activity`, nullable `run_type`, nullable unsigned
`distance_m`, nullable unsigned smallint `average_heart_rate`, nullable unsigned `energy_kcal`,
timestamps. Exists only for completed cardio sessions; duration remains on the wrapper.

### `workout_timed_details`

`id`, `user_id`, unique FK `workout_session_id`, nullable `activity_name`, timestamps. Exists only for
completed flexibility/sport sessions; duration remains on the wrapper.

### `workout_session_exercises`

Strength actuals: `id`, `user_id`, FK `workout_session_id`, FK `exercise_id`, `sort_order`, nullable
`simple_weight_kg DECIMAL(8,3)`, nullable `simple_reps`, nullable note string(500), timestamps. Unique
`(workout_session_id,sort_order)` and `(workout_session_id,exercise_id)`. Simple mode requires both
simple fields and no sets; detailed mode requires both simple fields null and child sets.

### `workout_sets`

Detailed strength actuals: `id`, `user_id`, FK `workout_session_exercise_id`, `set_order`,
`weight_kg DECIMAL(8,3)`, positive `reps`, nullable `rest_seconds`, timestamps. Unique
`(workout_session_exercise_id,set_order)`; index `(user_id,workout_session_exercise_id)`.

### `training_goal_details`

| Column | Type | Rules |
|---|---|---|
| id/user_id | bigint/FK | private owner |
| goal_id | unique FK goals | cascade with Goal |
| kind | string(24) | `strength|distance|race|consistency` |
| exercise_id | nullable FK exercises | strength only; restrict deletion |
| activity | nullable string(24) | distance/race; race exactly running |
| workout_program_id | nullable FK programs | optional scope; null on hard deletion |
| starting_value | decimal(14,3) | derived immutable creation snapshot; current or zero |
| target_value | decimal(14,3) | kg/metres/session count by kind |
| timestamps | timestamps | |

Indexes `(user_id,kind)` and `(user_id,workout_program_id)`. Goal holds name, description, target date,
status, completion/archive timestamps, soft delete, and `type='training'`.

### `planned_occurrences` extension

Add nullable unique FK `workout_session_id` to `workout_sessions` with `nullOnDelete`. The model enforces
at most one of `routine_log_id`, `habit_log_id`, `sleep_log_id`, and `workout_session_id`, and verifies
same owner before save. `hasFact()` includes all four.

## Relationships and Owner Rules

- User has many Exercises (custom), WorkoutPrograms, WorkoutSessions, and TrainingGoalDetails.
- Exercise is visible when global or owned; every mutable/reference assignment is checked explicitly.
- WorkoutProgram belongs to User; has one RecurringRule, many prescriptions/sessions, and one matching
  endurance/timed detail.
- WorkoutSession belongs to User and optionally Program; has one matching subtype, many strength
  exercises, and at most one PlannedOccurrence through its fact FK.
- Every child repeats `user_id`. Model hooks reject owner mismatch; services also validate the root and
  accessible catalogue/program/goal scope before writes.
- Goal has optional one TrainingGoalDetail; only `type='training'` may own it.

## State Machines

### Program

`active (is_active=1,is_archived=0)` ↔ `paused (0,0)` → `archived (*,1)` → active/paused restore.
Archiving forces inactive. Active state materializes; paused/archived removes only untouched future
predictions. Facts and explicit reschedules survive.

### Exercise

Built-in: permanently active/immutable. Custom: active ↔ archived. Archive hides it from new selection
but existing program/session/goal references remain readable.

### Planned session/occurrence

- no WorkoutSession → occurrence `planned`
- linked completed session → `done`
- linked skipped session → `skipped`
- delete/clear session → link null and occurrence `planned`
- a fact-bearing occurrence cannot reschedule; an effective-date collision is rejected

### Training goal

Uses existing Goal lifecycle: `active ↔ completed|abandoned`, plus independent archive/restore.
Starting snapshot and kind/scope are immutable; target/common lifecycle fields are editable. The
snapshot is matching current history or zero at creation. Progress is null without matching current
history and otherwise clamped to 0..1 from start to target.

## Deterministic Derived Values

- Strength volume: sum `weight_kg × reps`; simple rows contribute `simple_weight_kg × simple_reps`.
- Max weight: maximum actual simple/set weight for the exercise in matching completed sessions.
- Pace: `round(duration_seconds / (distance_m / 1000))`, null without positive distance/duration;
  best pace is the lowest valid value.
- Progression: chronological per-prescription fold defined in `research.md`; no persisted counter.
- Goal current: max matching weight/distance, or count of matching completed sessions across the
  inclusive trailing seven Profile-local dates. Progress is null when current is absent.
- Day/range summary: occurrence-based planned/completed/skipped counts plus manual completed count;
  duration/distance/volume use completed facts only.

## Query Plan

- Catalogue: one `(global OR owner)` ordered query; referenced archived rows eager-loaded by ID.
- Program index: programs + rules/weekdays + type details + prescriptions/exercises/progression in a
  fixed set of eager/set queries.
- Session history/statistics: bounded date query with subtype/exercise/set eager loads; occurrences and
  programs fetched as sets. Never query per program/session/exercise.
- Training goals: goals/details/scopes fetched as sets; current values grouped from one bounded source
  query per metric family, not per goal.
- Today/Review use one Workout summary DTO; Planner sources query occurrences/goals in sets.

## Rollback Order

1. Drop `planned_occurrences.workout_session_id` FK/index/column.
2. Drop `training_goal_details`, `workout_sets`, `workout_session_exercises`, session subtype tables,
   `workout_sessions`, program subtype/prescription tables, `workout_programs`, then `exercises`.
3. Never alter/drop existing goals, rules, occurrences, users, reviews, or prior fact columns.
