# Feature Specification: Body Measurements and Body Goals

**Feature ID**: `007-body-measurements`

**Created**: 2026-08-12

**Status**: Ready for implementation

**Input**: Add dated body measurements with deterministic trends, and a body-composition goal expressed
as a typed detail of the existing Goal, without duplicating the Profile baseline or inventing a parallel
goal system.

**Design sources**:
[Module 0 — User Profile](../../docs/design/modules.md#module-0--user-profile) ·
[Module 4 — Goals](../../docs/design/modules.md#module-4--goals) ·
[Data Conventions](../../docs/design/data-conventions.md) ·
[Delivery Roadmap — 007](../../docs/design/delivery-roadmap.md#007--body-measurements-and-body-goals) ·
[Feature 004](../004-profile-settings/spec.md) · [Feature 005](../005-interface-foundation/spec.md)

## Why This Feature Exists

The Profile holds one current set of anthropometrics. That answers "what am I now"; it cannot answer
"what is happening to me". Body measurements are the result marker of the whole loop — nutrition and
training change the body, and the measurement log is where that change becomes visible. Later Nutrition
and Analytics features read this history rather than re-deriving it.

## Clarifications

### Session 2026-08-12

- Q: Who owns the measurement fact?
  A: A new Body Measurements module. The Profile keeps its single current baseline as an input to
  calculations; it never becomes a journal, and the measurement log never overwrites it. They are
  separate facts with no automatic synchronisation in either direction.
- Q: Is a body goal a new goal system?
  A: No. It is a typed detail row hanging off the existing `goals` table, with `goals.type = 'body'`.
  Name, description, status, lifecycle, archive and target date stay on the goal; only the body-specific
  fields live in the detail.
- Q: How are extensible metrics stored without becoming JSON soup or a table per number?
  A: One row per observation in `body_measurements`, with a validated `metric` column backed by a PHP
  enum that carries the canonical unit, precision and plausible bounds. Indexed, queryable, trendable,
  and extended by adding an enum case plus a migration-free validation change.
- Q: What is the canonical value?
  A: The base unit for the metric — grams for mass, metres for lengths, percent for ratios — stored as
  `DECIMAL`, never a float. Display units are a presentation concern only.
- Q: Duplicate measurement on the same date?
  A: One value per user, per metric, per calendar date, enforced by a unique key. Saving the same
  metric and date again is a correction and replaces the value; it is not a second observation and not
  an error.
- Q: Which calendar date does a measurement carry?
  A: The date the user selects, defaulting to their current day in the profile time zone. It is a
  `YYYY-MM-DD` day, never an instant.
- Q: How is a trend computed?
  A: Ordinary least squares over the observations in the requested window, ordered by date, reported as
  change per week in canonical units. Fewer than two points is an explicit insufficient-data state, not
  a zero. No smoothing is applied, because with sparse manual entries smoothing would invent data.
- Q: What are the safe-pace numbers?
  A: For weight loss, the CDC's published "gradual, steady" rate of 1 to 2 pounds a week is the
  boundary; the warning triggers above 2 lb/week (0.9072 kg/week). For weight gain no comparable
  authority publishes a rate, so the application states an explicit product limitation of 0.5 kg/week
  and labels it as such rather than presenting it as medical guidance. Both are warnings shown next to
  the field; neither blocks the save nor edits the target.
- Q: Do milestones store their achievement?
  A: No. A milestone is achieved when the measurement history says so, computed on read, so it can
  never disagree with the observations.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Record and Correct Measurements (Priority: P1)

As a signed-in user, I record dated measurements for the metrics I care about, correct a mistake, and
delete an observation I should not have entered.

**Independent Test**: Record weight and waist for three dates, correct one, delete another, and verify
the history reflects exactly those actions.

**Acceptance Scenarios**:

1. **Given** an empty history, **When** the user saves a weight for today, **Then** it appears in the
   history with that calendar date.
2. **Given** an existing value for a metric and date, **When** the user saves that metric and date
   again, **Then** the stored value is replaced and no second row appears.
3. **Given** a saved observation, **When** the user deletes it, **Then** it disappears from history and
   from every derived number.
4. **Given** a value outside the plausible range for its metric, **When** the user saves it, **Then**
   the save is rejected with a field-level message and nothing is written.
5. **Given** measurements entered out of order, **When** the history is read, **Then** it is ordered by
   date regardless of entry order.
6. **Given** a metric the user has never recorded, **When** history is read, **Then** it is absent
   rather than shown as zero.

---

### User Story 2 - See A Deterministic Trend (Priority: P1)

As a user, I see whether a metric is moving and how fast, computed the same way every time.

**Independent Test**: Enter a known series, read the trend, and verify the slope against a hand
calculation; then enter one point only and verify the explicit insufficient-data state.

**Acceptance Scenarios**:

1. **Given** two or more observations, **When** the trend is read, **Then** it reports change per week
   in canonical units, together with the first and last observation.
2. **Given** one observation, **When** the trend is read, **Then** an explicit insufficient-data state
   is returned, not a zero slope.
3. **Given** no observations, **When** the trend is read, **Then** an explicit empty state is returned.
4. **Given** the same series read twice, **When** the trends are compared, **Then** they are identical.
5. **Given** an observation is corrected or deleted, **When** the trend is read again, **Then** it
   reflects only the remaining observations.

---

### User Story 3 - Follow A Body Goal (Priority: P1)

As a user, I set a measurable body-composition goal, see my progress toward it, and break it into
milestones.

**Independent Test**: Create a body goal from a starting value to a target, record measurements toward
it, and verify progress and milestone achievement change accordingly.

**Acceptance Scenarios**:

1. **Given** a body goal with a metric, a starting value and a target value, **When** it is created,
   **Then** it appears in the existing goal list with its body detail.
2. **Given** measurements for the goal's metric, **When** progress is read, **Then** it is computed from
   the latest observation on or before today, relative to the starting and target values.
3. **Given** no measurement yet, **When** progress is read, **Then** it reports that it has no current
   value rather than claiming zero progress.
4. **Given** milestones between the start and the target, **When** they are read, **Then** they are
   ordered along the direction of travel and each is marked achieved only if the history reaches it.
5. **Given** the goal is completed or archived through the existing lifecycle, **When** the goal list is
   read, **Then** it behaves exactly as any other goal.

---

### User Story 4 - Be Warned About An Unsafe Pace (Priority: P2)

As a user, when my target and deadline imply a very fast change, I am told, in plain terms, without
being blocked or silently corrected.

**Independent Test**: Set a target and date implying more than the documented boundary and verify the
warning appears, the save still succeeds, and the target is unchanged.

**Acceptance Scenarios**:

1. **Given** a weight-loss goal implying more than the documented boundary per week, **When** it is
   saved, **Then** a warning is returned alongside the saved goal.
2. **Given** a rate at exactly the boundary, **When** it is saved, **Then** no warning is produced.
3. **Given** a warning was produced, **When** the goal is read back, **Then** the target value and date
   are exactly what the user entered.
4. **Given** a goal with no target date, **When** it is saved, **Then** no pace can be derived and no
   warning is produced.
5. **Given** a metric with no documented boundary, **When** a goal is saved, **Then** no warning is
   invented.

---

### User Story 5 - Use It In My Own Units (Priority: P2)

As a user with imperial display units, I enter and read measurements in pounds and inches while the
stored value stays canonical.

**Independent Test**: Save a value in metric, switch the profile to imperial, and verify the same
underlying quantity is shown converted, then convert back with no drift.

**Acceptance Scenarios**:

1. **Given** metric display units, **When** a weight is entered in kilograms, **Then** it is stored in
   grams.
2. **Given** imperial display units, **When** the same observation is read, **Then** it is shown in
   pounds without changing the stored value.
3. **Given** repeated switching between unit systems, **When** the value is read each time, **Then** it
   does not drift.
4. **Given** a 390×844 viewport, **When** the measurements screen is used, **Then** there is no
   horizontal overflow and every control is reachable by keyboard.

## Requirements *(mandatory)*

### Functional Requirements — Measurements

- **FR-001**: A measurement MUST be owned by a user and MUST carry a metric, a calendar date and a value.
- **FR-002**: The value MUST be stored in the metric's canonical base unit as an exact decimal, never a
  float.
- **FR-003**: The metric set MUST be a validated, typed vocabulary carrying unit, precision and
  plausible bounds; adding a metric MUST NOT require a schema change.
- **FR-004**: `(user, metric, date)` MUST be unique. Saving an existing combination is a correction that
  replaces the value.
- **FR-005**: A measurement MUST be deletable, and every derived value MUST follow.
- **FR-006**: A value outside its metric's bounds MUST be rejected with a field-level message and no
  partial write.
- **FR-007**: The measurement date MUST be a `YYYY-MM-DD` day, defaulting to the user's current day in
  their profile time zone.
- **FR-008**: History MUST be returned ordered by date regardless of insertion order, and MUST be
  bounded by an explicit range or limit.
- **FR-009**: The Profile baseline and the measurement log MUST remain separate: neither overwrites the
  other, and no synchronisation happens without an explicit user action.

### Functional Requirements — Trend

- **FR-010**: A trend MUST be computed by ordinary least squares over the observations in the requested
  window, ordered by date, and reported as change per week in canonical units.
- **FR-011**: Fewer than two observations MUST produce an explicit insufficient-data state; zero
  observations MUST produce an explicit empty state.
- **FR-012**: A trend MUST be reproducible: the same observations always produce the same numbers.
- **FR-013**: Rounding MUST be applied once, at a documented precision, and MUST NOT accumulate.
- **FR-014**: Deleted and corrected observations MUST NOT contribute to a trend.

### Functional Requirements — Body Goal

- **FR-015**: A body goal MUST be the existing `Goal` with `type = 'body'` plus a one-to-one detail row.
  No parallel goal entity may be introduced.
- **FR-016**: The detail MUST carry the metric, the direction, the starting value, the target value, and
  MUST reuse the goal's own target date.
- **FR-017**: Progress MUST be computed by the owning module from the latest observation on or before
  today, relative to the starting and target values, and MUST be direction-aware.
- **FR-018**: With no observation for the metric, progress MUST report the absence of a current value
  rather than zero.
- **FR-019**: Milestones MUST be user-defined target values, ordered along the direction of travel, with
  achievement derived from the history at read time rather than stored.
- **FR-020**: Goal lifecycle, archive and ownership behaviour MUST be unchanged.

### Functional Requirements — Safe Pace

- **FR-021**: When a goal has both a target date and a starting value, the implied weekly rate MUST be
  computed deterministically.
- **FR-022**: For body mass, a rate faster than the documented boundary MUST produce a warning returned
  with the saved goal.
- **FR-023**: The warning MUST NOT block the save, MUST NOT alter the target or the date, and MUST NOT
  prevent editing unrelated fields.
- **FR-024**: Boundaries MUST come from a named source or be labelled as a product limitation; no
  boundary may be invented for a metric that has neither.
- **FR-025**: Boundary behaviour MUST be exact at the boundary value and MUST have unit tests on both
  sides of it.

### Functional Requirements — Interface

- **FR-026**: An authenticated route MUST present measurement entry, history and body-goal progress.
- **FR-027**: The screen MUST use the feature 005 control set and MUST appear in the navigation.
- **FR-028**: Values MUST be entered and displayed in the profile's unit system with no conversion drift.
- **FR-029**: Empty history, single-observation and partial-metric states MUST each be explained rather
  than shown as blank or zero.
- **FR-030**: The screen MUST work on desktop, at exactly 390×844, and from the keyboard, with no
  horizontal overflow.

### Key Entities

- **BodyMeasurement**: one observation of one metric on one date, owned by a user, valued in the
  metric's canonical base unit.
- **BodyMetric**: the typed vocabulary — canonical unit, display units, precision, plausible bounds, and
  whether a safe-pace boundary exists.
- **BodyGoalDetail**: the body-specific detail of an existing goal — metric, direction, starting value,
  target value.
- **GoalMilestone**: an intermediate target value on a goal, achieved when the history reaches it.

## Success Criteria *(mandatory)*

- **SC-001**: Two accounts cannot read, write or link each other's measurements, details or milestones.
- **SC-002**: A value saved in metric units and read in imperial and back is unchanged.
- **SC-003**: A known series produces a trend matching a hand calculation to the documented precision.
- **SC-004**: One observation produces an insufficient-data state and zero observations an empty state.
- **SC-005**: Out-of-order insertion produces the same history and trend as in-order insertion.
- **SC-006**: Saving a duplicate metric and date replaces the value and leaves exactly one row.
- **SC-007**: A rate exactly at the boundary produces no warning; just past it produces one, and the
  saved target is untouched.
- **SC-008**: A rejected save leaves no partial row.
- **SC-009**: A long history is queried within an explicit bound.
- **SC-010**: The full Laravel suite, Pint, Vue type check, production build and both Playwright
  projects pass.

## Scope Boundaries

### Out of Scope

Body photos and any attachment (waits for feature 020), reminders and notifications (feature 010),
AI interpretation, nutrition and workout recommendations, medical diagnosis, wearable and Apple
Health/Google Fit import, complex analytics dashboards, and any automatic overwrite of the Profile
baseline.

## Assumptions

- The profile time zone, locale and unit system from feature 004 are authoritative.
- The form control set from feature 005 is available.

## Dependencies

Features 004 and 005. No new runtime dependency on either side.
