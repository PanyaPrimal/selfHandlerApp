# Feature Specification: Unified Recurrence with Routine Migration

**Feature ID**: `006-unified-recurrence`

**Created**: 2026-08-12

**Status**: Ready for implementation

**Input**: Replace the routine-specific schedule with the shared recurrence boundary described in
`docs/design/recurrence-engine.md`, using routines as the first and only consumer, while preserving
every existing routine behaviour, log, streak and API response.

**Design sources**: [Recurrence Engine](../../docs/design/recurrence-engine.md) ·
[Data Conventions](../../docs/design/data-conventions.md) ·
[Delivery Roadmap — 006](../../docs/design/delivery-roadmap.md#006--unified-recurrence-with-routine-migration) ·
[Feature 001](../001-core-daily-loop/spec.md) · [Feature 004](../004-profile-settings/spec.md)

## Why This Feature Exists

Feature 001 shipped a deliberately small schedule: `routines.schedule_type`, a `routine_weekdays`
table, and `routines.starts_on` / `ends_on` / `preferred_time`. It works, but it belongs to one module.
Planner, habits, supplements, workouts and recurring finance all need schedules, and every one of them
would otherwise invent its own table. The design has settled this: one `RecurringRule` plus one
`PlannedOccurrence`, expanded in the owner's time zone.

The current schedule must therefore move, not be duplicated. Two schedule stores would diverge the
first time someone edits a routine through the wrong path.

## Clarifications

### Session 2026-08-12

- Q: Cutover or adapter?
  A: Full cutover in one migration. `recurring_rules` becomes the authoritative schedule store;
  `routine_weekdays` is backfilled into `recurring_rule_weekdays` and dropped; `routines.schedule_type`,
  `starts_on`, `ends_on` and `preferred_time` are backfilled onto the rule and dropped from `routines`.
  No adapter survives the feature.
- Q: What is authoritative for "is this routine scheduled on day D"?
  A: Deterministic expansion of the rule, for any date, past or future. `PlannedOccurrence` is a
  materialized forward index, not the source of the answer. This keeps historical evaluation exact for
  days that predate the engine, which a materialized-only design could not do.
- Q: Then what are occurrences for, and how are they not a second truth?
  A: They give a future day a durable identity so it can later be individually rescheduled or reminded
  about — the reason the design chose materialization. Tests assert that the materialized window is
  exactly equal to the expansion over the same range, so the two can never disagree.
- Q: Where does completion live?
  A: `routine_logs` remains the authoritative fact and the authoritative API. A materialized occurrence
  carries a derived `status` and a `routine_log_id` reference, kept in step whenever a log is written or
  cleared, and recomputable from the logs at any time.
- Q: Window size?
  A: 90 days ahead of the user's current day, bounded below by the rule's start and above by its end.
  Materialization runs when a rule changes and from an explicit console command; it is never triggered
  by a read request.
- Q: What happens to future occurrences when a rule is edited?
  A: Unmarked future occurrences are regenerated; occurrences already linked to a fact are kept. This
  resolves open question 2 in the recurrence design document.
- Q: Does the schedule lock after history still apply?
  A: Yes, unchanged. It now guards the rule instead of the routine columns, with the same messages.
- Q: Frequencies?
  A: `daily` and `weekly` (with weekdays) only — exactly the two the current product has. Interval,
  monthly, month-days, cycles, multiple daily slots and RRULE strings are deferred; each needs a
  consumer before it earns a column.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Keep Every Existing Routine Working (Priority: P1)

As an existing user, my routines, their schedules, their history, my Today list, my progress and my
streaks are exactly what they were before the change.

**Why this priority**: This feature is a migration. Any observable difference is a defect.

**Independent Test**: Capture Today, progress, streaks and the routine list before the migration, run
it on a database with live rows, and compare.

**Acceptance Scenarios**:

1. **Given** a daily routine created before this feature, **When** the migration runs, **Then** it has
   one active rule with daily frequency and the same bounds, and Today still lists it.
2. **Given** a weekday routine with stored weekdays, **When** the migration runs, **Then** the same
   weekday set is present on the rule and no other day becomes scheduled.
3. **Given** existing routine logs, **When** the migration runs, **Then** every log is preserved and
   still readable through the existing endpoints.
4. **Given** the routine API, **When** it is called after the migration, **Then** the response body has
   the same fields and the same values as before, including `schedule_type`, `weekdays`, `starts_on`,
   `ends_on` and `preferred_time`.
5. **Given** seven-day progress and streaks, **When** they are recalculated after the migration,
   **Then** the numbers are unchanged.

---

### User Story 2 - Own One Schedule (Priority: P1)

As a user, when I change a routine's schedule I change it in exactly one place, and the change takes
effect everywhere.

**Independent Test**: Edit a routine's schedule and verify the rule, the materialized window, Today and
progress all reflect it, and that no other schedule store exists in the database.

**Acceptance Scenarios**:

1. **Given** a routine, **When** its schedule is edited before any history exists, **Then** the rule is
   updated and future occurrences are regenerated to match.
2. **Given** a routine with at least one log, **When** a schedule change is attempted, **Then** it is
   rejected with the existing "archive and create a replacement" message and nothing is written.
3. **Given** a routine is paused, **When** the schedule is evaluated, **Then** it produces no scheduled
   days until it is resumed, and resuming restores the previous behaviour.
4. **Given** a routine is archived, **When** the schedule is evaluated for a day after archiving,
   **Then** it is not scheduled, while days before archiving remain visible in history.
5. **Given** the database after this feature, **When** it is inspected, **Then** `routine_weekdays` no
   longer exists and `routines` no longer carries schedule columns.

---

### User Story 3 - Expand Time Zones Correctly (Priority: P1)

As a user in my own time zone, my routine appears on my local day, including across daylight-saving
boundaries, and another user's time zone never affects mine.

**Independent Test**: Give two users opposite local days and a routine each, and verify each sees only
their own scheduled day; then evaluate across both daylight-saving transitions.

**Acceptance Scenarios**:

1. **Given** two users whose local dates differ at the same instant, **When** each evaluates their
   schedule, **Then** each gets their own local day.
2. **Given** a rule in a zone with a spring-forward transition, **When** occurrences are expanded across
   it, **Then** every calendar day appears exactly once with no gap.
3. **Given** a zone with a fall-back transition, **When** occurrences are expanded across it, **Then**
   no day is duplicated.
4. **Given** a user changes their profile time zone, **When** the rule is next expanded, **Then** future
   days follow the new zone while stored calendar dates and logs are unchanged.

---

### User Story 4 - Materialize Deterministically and Safely (Priority: P2)

As the system, I write planned occurrences for a bounded window, repeatedly and safely, without
duplicates and without losing marked days.

**Independent Test**: Run materialization twice, then a third time after a partial failure, and verify
the row set is identical and matches the expansion exactly.

**Acceptance Scenarios**:

1. **Given** an active rule, **When** materialization runs, **Then** occurrences exist for every
   expanded day inside the window and for no other day.
2. **Given** materialization has already run, **When** it runs again, **Then** no row is duplicated and
   no row is needlessly rewritten.
3. **Given** a failure part-way through, **When** materialization is retried, **Then** the result is the
   same as an uninterrupted run and no partial state is visible.
4. **Given** a rule with an end date, **When** it is materialized, **Then** no occurrence exists after
   that date.
5. **Given** a paused or archived rule, **When** materialization runs, **Then** it produces no new
   occurrences.
6. **Given** a routine log is written or cleared, **When** a materialized occurrence exists for that
   day, **Then** its derived status and fact reference follow the log.
7. **Given** many routines and a long window, **When** materialization runs, **Then** the query count
   stays bounded and does not grow per occurrence.

---

### User Story 5 - Edit Recurrence In The Interface (Priority: P2)

As a user, I set a routine's recurrence with the shared form controls, on desktop, on a phone and from
the keyboard.

**Independent Test**: Create a weekday routine using only the keyboard at 390×844 and verify the saved
schedule.

**Acceptance Scenarios**:

1. **Given** the routine form, **When** the frequency is daily, **Then** no weekday selection is shown
   or required.
2. **Given** weekly frequency, **When** no weekday is chosen, **Then** the save is rejected with a
   field-level message and nothing is written.
3. **Given** a routine with history, **When** the form is opened, **Then** the schedule fields explain
   that they are locked rather than failing silently on save.
4. **Given** a 390px viewport, **When** the recurrence editor is used, **Then** there is no horizontal
   overflow and every control is reachable.

## Requirements *(mandatory)*

### Functional Requirements — Model

- **FR-001**: A `RecurringRule` MUST be owned by a user and MUST reference its owner polymorphically.
- **FR-002**: A rule MUST carry frequency (`daily` or `weekly`), an optional start date, an optional end
  date, an IANA time zone, an optional time of day, and how far it has been materialized. It MUST NOT
  carry its own pause flag: the owner's lifecycle is authoritative, and duplicating it would recreate the
  competing-source problem this feature removes.
- **FR-003**: Weekly weekdays MUST be stored in a normalized child table, not JSON, and MUST be unique
  per rule.
- **FR-004**: A routine MUST have exactly one rule, enforced by a unique constraint on the owner.
- **FR-005**: A `PlannedOccurrence` MUST be owned by a user, reference its rule, carry a calendar date, a
  slot, an optional time, a derived status and an optional fact reference.
- **FR-006**: `(rule, occurrence date, slot)` MUST be unique, with a non-null slot default so the
  constraint holds on every supported database.
- **FR-007**: All schedule state MUST live on the rule. `routine_weekdays` MUST be removed and
  `routines` MUST no longer carry `schedule_type`, `starts_on`, `ends_on` or `preferred_time`.

### Functional Requirements — Expansion

- **FR-008**: Expansion MUST be deterministic and MUST answer "is this rule active on calendar day D"
  for any date, past or future, without reading materialized rows.
- **FR-009**: Expansion MUST use the rule's time zone, never the server or browser time zone.
- **FR-010**: Expansion MUST respect start and end bounds inclusively.
- **FR-011**: Expansion MUST be correct across both daylight-saving transitions: every calendar day
  appears at most once and no day inside the range is skipped.
- **FR-012**: A paused or archived owner MUST produce no scheduled days, and archived days before the
  archive date MUST remain visible as history exactly as they are today.

### Functional Requirements — Materialization

- **FR-013**: Materialization MUST write occurrences for a bounded window of 90 days from the user's
  current day, clamped by the rule's bounds.
- **FR-014**: Materialization MUST be idempotent: repeated runs converge to the same row set.
- **FR-015**: Materialization MUST be atomic per rule, so a failure leaves no partial window.
- **FR-016**: Materialization MUST NOT delete an occurrence that is linked to a fact.
- **FR-017**: Materialization MUST remove unmarked occurrences that the current rule no longer expands.
- **FR-018**: Materialization MUST run when a rule is created or changed, and from an explicit console
  command. A read request MUST NOT trigger it.
- **FR-019**: Materialization MUST use a bounded number of queries per rule regardless of window size.

### Functional Requirements — Facts

- **FR-020**: `routine_logs` remains the authoritative completion fact and its API is unchanged.
- **FR-021**: Writing or clearing a routine log MUST keep the matching materialized occurrence's derived
  status and fact reference in step.
- **FR-022**: The derived occurrence status MUST be recomputable from the logs, and a command MUST be
  able to reconcile it.

### Functional Requirements — Compatibility

- **FR-023**: The routine list, create and update endpoints MUST keep their request and response shapes,
  including `schedule_type`, `weekdays`, `starts_on`, `ends_on` and `preferred_time`.
- **FR-024**: Today, seven-day progress and streaks MUST produce identical values to the pre-migration
  implementation for the same data.
- **FR-025**: The existing schedule lock after history MUST be preserved with the same fields and
  messages.
- **FR-026**: Ownership MUST be enforced on rules and occurrences: another user's identifiers MUST NOT
  be readable, writable or linkable.
- **FR-027**: The migration MUST preserve every existing routine, log, goal link and review row.

### Functional Requirements — Interface

- **FR-028**: The routine form MUST express recurrence with the feature 005 control set.
- **FR-029**: Weekly frequency MUST require at least one weekday, rejected with a field-level message.
- **FR-030**: The recurrence editor MUST work on desktop, at exactly 390×844, and from the keyboard,
  with no horizontal overflow.

### Key Entities

- **RecurringRule**: the schedule. Owns frequency, weekdays, bounds, time zone, time of day, active
  state and the materialization boundary. Polymorphic owner; one per routine.
- **RecurringRuleWeekday**: one selected weekday of a weekly rule.
- **PlannedOccurrence**: a materialized future day belonging to a rule, with a derived status and an
  optional reference to the domain fact that satisfied it.

## Success Criteria *(mandatory)*

- **SC-001**: A migration on a database containing live routines, weekdays, logs, goals and reviews
  preserves every row and produces exactly one rule per routine.
- **SC-002**: Today, progress and streak values are identical before and after the migration for the
  same data.
- **SC-003**: Over any 400-day range, the materialized window equals the expansion for the same range.
- **SC-004**: Running materialization twice produces byte-identical row sets.
- **SC-005**: Two users in opposite local days each see only their own scheduled day.
- **SC-006**: Expansion across both daylight-saving transitions produces one row per calendar day.
- **SC-007**: No `routine_weekdays` table and no schedule column on `routines` remains.
- **SC-008**: Materializing 50 routines over the full window uses a bounded number of queries.
- **SC-009**: The full Laravel suite, Pint, Vue type check, production build and both Playwright
  projects pass.

## Scope Boundaries

### In Scope

Rule and occurrence persistence, deterministic expansion, bounded idempotent materialization, the full
routine cutover, fact linkage, API compatibility, the recurrence editor, and migration/ownership tests.

### Out of Scope

Reminders and notifications, Planner, habits, supplements, workouts, external calendar synchronisation,
RRULE strings, monthly and yearly frequencies, intervals, on/off cycles, several occurrences per day,
rescheduling a single occurrence to another date, Android, and offline behaviour. Each returns with the
feature that needs it.

## Assumptions

- The rule's time zone is seeded from the owner's profile time zone (feature 004) and is stored on the
  rule so a later profile change does not silently rewrite history.
- Live data is small (single-digit routines), so the cutover migration can run inline.

## Dependencies

Feature 004 for the user time zone; feature 005 for the form controls used by the recurrence editor.
</content>
