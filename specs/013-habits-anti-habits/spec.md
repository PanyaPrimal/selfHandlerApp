# Feature Specification: Habits and Anti-Habits

**Feature ID**: `013-habits-anti-habits`

**Created**: 2026-08-13

**Status**: Complete

**Input**: User description: "Implement the complete non-deployment Habits and Anti-Habits vertical
slice from the canonical design: recurring yes/no and numeric habits, abstinence and stepped limits,
habit stacking, implementation intentions, Planner and notification reuse, goal links, and
deterministic module-owned streaks and statistics."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Build and Check In a Habit (Priority: P1)

An authenticated user creates either a yes/no habit or a numeric habit, chooses its recurring days,
optionally gives it a target and unit, and records the result for a scheduled day. They can reopen the
same day to correct the result without creating a duplicate fact.

**Why this priority**: A scheduled definition plus a trustworthy daily fact is the smallest useful
habit loop and the source for every later streak or statistic.

**Independent Test**: Create one daily yes/no habit and one numeric weekday habit, use their daily
check-in controls, reload, edit the numeric value, and verify exactly one owned fact per occurrence and
matching Planner state.

**Acceptance Scenarios**:

1. **Given** a user is creating a habit, **When** they choose yes/no tracking and a recurrence, **Then**
   the habit is saved, occurrences are materialized by the shared recurrence engine, and scheduled
   occurrences appear in Habits and Planner.
2. **Given** a numeric habit has a positive target and unit, **When** the user records a non-negative
   value and completion time, **Then** the module stores one fact for that occurrence and deterministically
   decides whether the target was met.
3. **Given** a habit already has a fact for a day, **When** the owner changes or clears it, **Then** the
   existing fact/occurrence state is updated or removed idempotently and all aggregates reconcile.
4. **Given** another user's habit, occurrence, routine, or goal identifier, **When** a user tries to read
   or mutate it, **Then** the API returns the normal not-found boundary and exposes no private data.

---

### User Story 2 - See an Honest Chain and Progress (Priority: P1)

The user sees current streak, best streak, scheduled completion percentage, and numeric totals for a
selected period. The calculations treat only ended scheduled occurrences as failures, so future or
still-open local days never break a chain.

**Why this priority**: The chain and numbers are the promised motivation mechanism, but must remain
derived from facts rather than become editable counters.

**Independent Test**: Seed controlled scheduled occurrences and facts around the user's local date,
including a skipped day and a below-target numeric fact, and verify exact current/best streak,
percentage, and sum before and after a correction.

**Acceptance Scenarios**:

1. **Given** consecutive successful scheduled occurrences, **When** the user opens Habits, **Then**
   current and best streak count scheduled successes, not raw calendar days.
2. **Given** an ended scheduled occurrence is skipped, missing, explicitly false, or below a numeric
   target, **When** statistics are calculated, **Then** it breaks the success chain and contributes to
   the denominator without contributing a success.
3. **Given** a current or future planned occurrence, **When** statistics are calculated, **Then** it
   does not count as failure or reduce a streak.
4. **Given** a requested date range, **When** results are returned, **Then** percentage and numeric
   total are computed by the Habits module from its owned facts and occurrences.

---

### User Story 3 - Track Abstinence Without Self-Deception (Priority: P2)

The user creates an abstinence anti-habit and records either a protected day or a relapse for each
scheduled day. The screen reports the current and best abstinence streak and retains relapse history
with its actual recorded time.

**Why this priority**: Abstinence has opposite success semantics to an ordinary habit; making that
explicit prevents a generic checkbox from reporting harmful or misleading streaks.

**Independent Test**: Record protected days, a relapse, and another protected run; verify the relapse
breaks the chain, the prior run remains the best streak, and changing the relapse reconciles the result.

**Acceptance Scenarios**:

1. **Given** a scheduled abstinence occurrence, **When** the user records "protected", **Then** the
   occurrence succeeds and extends the abstinence streak.
2. **Given** a scheduled abstinence occurrence, **When** the user records a relapse, **Then** the actual
   time is retained, the occurrence is a completed domain fact but a failed outcome, and the streak ends.
3. **Given** no result for an ended abstinence occurrence, **When** statistics are calculated, **Then**
   the day is incomplete rather than silently assumed protected.

---

### User Story 4 - Follow a Stepped Reduction Ceiling (Priority: P2)

The user creates a stepped-limit anti-habit, defines an ordered reduction plan with day or week
ceilings that become effective on chosen dates, records non-negative consumption, and sees the active
ceiling, consumed amount, remaining allowance, and whether the period is within limit.

**Why this priority**: A changing ceiling is the core anti-habit requirement and is semantically
different from an achievement milestone.

**Independent Test**: Define daily and weekly steps, record values around a step boundary, and verify
the correct local-date period, active ceiling, remaining amount, pass/fail state, and derived step
status without creating or mutating a goal milestone.

**Acceptance Scenarios**:

1. **Given** an ordered plan, **When** the local date reaches a step's effective date, **Then** that
   step becomes the active ceiling and earlier steps become completed while later steps remain upcoming.
2. **Given** a daily ceiling, **When** consumption values are recorded for the local day, **Then** the
   module reports consumed, remaining (never below zero), and within/exceeded deterministically.
3. **Given** a weekly ceiling, **When** values span the Monday-through-Sunday local week, **Then** the
   module compares their sum with the active weekly ceiling.
4. **Given** overlapping, duplicate-date, non-decreasing, or otherwise invalid steps, **When** the user
   saves, **Then** validation rejects the whole write and leaves the prior plan unchanged.

---

### User Story 5 - Put the Habit in Context (Priority: P3)

The user can optionally stack a habit after an owned routine, link it to an owned goal, and record an
implementation-intention time, place, and two-minute starter. A timed planned occurrence appears in
Planner and participates in the existing in-app reminder pipeline.

**Why this priority**: These are the documented Atomic Habits mechanisms that turn tracking into a
concrete plan while retaining authoritative module boundaries.

**Independent Test**: Link a habit to an owned routine and goal, set time/place/starter, verify those
fields and links after reload, see the habit in Planner, and generate one quiet-hours-aware in-app
notification for its timed occurrence.

**Acceptance Scenarios**:

1. **Given** owned routines and goals, **When** a user selects them, **Then** the habit retains direct
   optional links while routines and goals do not acquire copied habit state.
2. **Given** an unowned or archived link target, **When** the user saves, **Then** the write fails
   without revealing whether another user's record exists.
3. **Given** a timed planned habit occurrence, **When** the notification generator processes its due
   time, **Then** existing locale, quiet-hours, identity, deduplication, and inbox behavior are reused.
4. **Given** an untimed habit, **When** notifications are generated, **Then** no guessed reminder time
   or notification is created.

---

### User Story 6 - Manage the Habit Lifecycle Accessibly (Priority: P3)

The user edits, pauses, resumes, archives, and restores owned habits from a responsive Habits surface.
All controls, feedback, states, units, dates, and accessibility text work in English, Russian, and
Ukrainian at desktop and 390×844.

**Why this priority**: Long-lived personal records need reversible lifecycle controls and the product's
mandatory language/mobile baseline.

**Independent Test**: Exercise every lifecycle transition at both viewport sizes and in every locale;
verify history survives, paused/archived sources stop producing actionable future items, and keyboard,
screen-reader, overflow, reload, and error rollback behavior remains correct.

**Acceptance Scenarios**:

1. **Given** a paused or archived habit, **When** recurrence is extended, Planner is opened, or
   notifications are generated, **Then** no new actionable occurrence/reminder is produced while
   retained history and statistics remain visible.
2. **Given** an archived habit, **When** the owner restores it, **Then** recurrence resumes from the
   appropriate local date without duplicating occurrences or facts.
3. **Given** a failed mutation, **When** the UI receives the error, **Then** optimistic state rolls back
   and localized retryable feedback is shown.

### Edge Cases

- A numeric value of zero is valid; negative, non-finite, over-precision, and missing required values
  are invalid. Boolean outcomes cannot carry a numeric value.
- A habit cannot be linked to another user's, deleted, or archived routine/goal. Removing a linked
  routine or goal nulls the optional link without deleting habit history.
- DST transitions do not move a fact to a different user-local calendar date; stored event instants are
  UTC and the explicit `log_date` is the reporting key.
- Rule edits retain fact-linked and explicitly rescheduled occurrences while replacing only unmarked
  future predictions, using the existing recurrence behavior.
- Clearing a fact makes its occurrence planned again; deleting or archiving a habit never silently
  deletes historical facts.
- A stepped plan has at least one step, strictly increasing effective dates, strictly decreasing
  normalized daily ceilings (`day=value`, `week=value/7`), and positive values; a unit change from day
  to week is allowed only at a step boundary.
- If no limit step is effective yet, the plan reports its first upcoming step and accepts logs without
  claiming an active ceiling.
- Weekly periods are Monday through Sunday in the user's time zone for this increment; locale changes
  formatting but not persisted period semantics.
- Empty states distinguish active, paused, and archived definitions. API/network failures do not erase
  completed form input.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST provide a first-class, authenticated Habits surface and navigation entry
  for listing active, paused, and archived habits and anti-habits.
- **FR-002**: The Habits module MUST own user-scoped habit definitions with kind `habit` or
  `anti_habit`, mode `yes_no`, `numeric`, `abstinence`, or `stepped_limit`, lifecycle state, optional
  description, and stable timestamps.
- **FR-003**: Ordinary habits MUST support yes/no and numeric tracking; numeric habits MUST have a
  positive target and a short user-authored unit, while yes/no habits MUST reject numeric target data.
  Kind/mode are immutable after creation, and numeric target/unit MUST lock after the first fact so
  historical success semantics cannot silently change.
- **FR-004**: Anti-habits MUST support exactly abstinence or stepped-limit mode and reject incompatible
  ordinary-habit modes.
- **FR-005**: Every habit MUST own exactly one shared `RecurringRule`; create/edit/pause/resume/archive
  behavior MUST reuse recurrence materialization and preserve fact-linked/rescheduled history.
- **FR-006**: The supported schedule editor MUST expose daily or selected-weekday recurrence, optional
  inclusive start/end dates, and optional time using the existing recurrence contract and Profile time zone.
- **FR-007**: The module MUST store at most one check-in fact per habit and scheduled local date, with
  explicit outcome, optional numeric value, UTC completion instant, and user-local `log_date`.
- **FR-008**: Habit fact create/update/delete MUST atomically synchronize the matching planned
  occurrence's fact link and completed/planned state without making occurrence status the source of
  domain success semantics.
- **FR-009**: For an ordinary yes/no habit, `done` is success and `not_done` is failure; for numeric,
  success means recorded value is at least target; for abstinence, `protected` is success and `relapse`
  is failure.
- **FR-010**: The module MUST compute current streak, best streak, scheduled completion percentage,
  successes, ended scheduled opportunities, and numeric total from owned recurrence/fact data on read.
- **FR-011**: Streaks MUST traverse scheduled occurrences, not raw calendar days; current/future
  pending occurrences MUST not fail a streak, while an ended missing/skipped/failed occurrence MUST.
- **FR-012**: A stepped-limit anti-habit MUST own a separate ordered plan of positive ceiling steps,
  each with effective local date, value, and period `day` or `week`; its completed/current/upcoming
  status MUST be derived rather than user-editable.
- **FR-013**: Step writes MUST be atomic and validate strictly increasing dates and strictly decreasing
  normalized daily ceilings, so transitions such as `1/day → 5/week` remain valid; they MUST NOT read,
  create, or mutate goal milestones.
- **FR-014**: The module MUST compute the active ceiling, current local period, consumption sum,
  non-negative remaining allowance, and within/exceeded result from owned steps and facts.
- **FR-015**: A habit MAY link authoritatively from `habits.routine_id` to one active owned routine for
  stacking and from `habits.goal_id` to one active owned goal; target modules MUST NOT store copied
  habit state.
- **FR-016**: A habit MAY store an implementation-intention place and two-minute starter; its optional
  time MUST be the shared recurrence rule time and therefore the time Planner and notifications use.
- **FR-017**: Habits MUST implement the existing `SchedulableSource` contract and expose planned
  occurrences in Planner without creating Planner-owned projections.
- **FR-018**: Timed planned habit occurrences MUST reuse feature 011's in-app channel, locale,
  quiet-hours, source identity, deduplication, and source-closure behavior; untimed occurrences MUST
  not infer a reminder time.
- **FR-019**: Pausing/archiving MUST stop new actionable planning and reminders while preserving facts;
  restoring MUST resume idempotent materialization.
- **FR-020**: Every read and mutation MUST enforce authenticated ownership, including nested facts,
  steps, recurrence occurrences, routine links, and goal links, with non-disclosing 404 behavior.
- **FR-021**: Database evolution MUST be additive, preserve all existing rows, use bounded MySQL-safe
  identifiers/indexes, and provide a reversible rollback for new schema only.
- **FR-022**: API routes, OpenAPI 3.1 contracts, frontend types, and consumers MUST change together and
  cover definitions, facts, statistics, lifecycle, and stepped plans.
- **FR-023**: The web and bundled Android client MUST share the responsive Habits UI, safe-area/mobile
  shell behavior, explicit native bearer transport, and no-horizontal-overflow baseline.
- **FR-024**: All mutations MUST validate exact bounded payloads, return localized validation/domain
  feedback, and let the UI preserve or restore user input after rejection.
- **FR-025**: New user-visible content MUST ship simultaneously in `en-GB`, `ru-UA`, and `uk-UA`, use
  locale-aware dates/numbers/plurals, and pass locale parity, used-key, and hardcoded-copy guards.
- **FR-026**: The feature MUST add automated behavior evidence for aggregates, time zones/DST,
  recurrence edits, ownership, MySQL schema constraints, Planner/notification integration,
  accessibility, keyboard use, mobile 390×844, reload, rollback, and complete regression safety.

### Localisation Surface *(mandatory)*

- **Locales**: English (`en-GB`), Russian (`ru-UA`), and Ukrainian (`uk-UA`).
- **New user text**: Habits navigation, headings, tabs, cards, form labels/helpers/placeholders,
  tracking modes, schedule/outcome/step labels, limit state, statistics, empty/loading/error/success
  states, lifecycle confirmations, validation/domain feedback, reminder copy, ARIA labels, and
  changelog content.
- **Formatting**: User-local dates and completion times; locale-aware decimal values, percentages,
  day/week labels, remaining allowance, and streak/opportunity plural forms.
- **Non-translatable content**: User-entered habit names, descriptions, units, places, two-minute
  starters, routine/goal names, and stable technical identifiers.
- **Verification**: Existing i18n parity, known/used-key and hardcoded-copy gates; localized Laravel
  validation/domain tests; EN/RU/UK browser scenarios at desktop and 390×844.

### Key Entities *(include if feature involves data)*

- **Habit**: User-owned definition and lifecycle; kind/mode, optional numeric target/unit, routine and
  goal links, implementation place/starter; authoritative owner of its domain configuration.
- **Habit Log**: One owned result for a habit's scheduled local date, including the mode-specific
  outcome/value and UTC recorded/completed instant; authoritative domain fact.
- **Habit Limit Step**: An ordered effective-date ceiling with day/week period for a stepped-limit
  anti-habit; status is derived from date order.
- **Recurring Rule / Planned Occurrence**: Existing shared plan and concrete instance. The occurrence
  links to a habit log but does not own success semantics.
- **Habit Statistics**: Non-persisted module-owned projection of streaks, opportunities, successes,
  completion percentage, and numeric total.
- **Limit Status**: Non-persisted projection of active/upcoming step and current period consumption,
  remaining allowance, and within/exceeded state.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An authenticated user can create a recurring yes/no or numeric habit, record/correct a
  scheduled result, reload, and see the same fact and Planner state in one end-to-end flow.
- **SC-002**: Controlled histories produce exact current/best streak, success/opportunity counts,
  completion percentage, and numeric sums in 100% of automated boundary cases.
- **SC-003**: Abstinence histories retain exact relapse times and never count missing ended days as
  protected; stepped limits select the correct boundary step and period in 100% of timezone tests.
- **SC-004**: Repeating identical materialization, fact, plan, and notification operations creates zero
  duplicate occurrences, facts, steps, or notifications.
- **SC-005**: Cross-user reads/writes of definitions, logs, steps, occurrences, routine links, and goal
  links fail through the not-found boundary in 100% of ownership tests.
- **SC-006**: All habit interactions remain keyboard and screen-reader operable with at least 44px
  touch targets and no horizontal overflow at 390×844.
- **SC-007**: Every new interface and backend feedback path works in all three supported locales with
  exact dictionary parity and no newly introduced unapproved hardcoded copy.
- **SC-008**: Full Laravel, Pint, typecheck, production build, i18n, affected/full Playwright, and
  contract-route parity gates pass without regressions.
- **SC-009**: Additive migration preservation tests prove existing routine, goal, recurrence,
  notification, profile, and user data survive migrate/rollback behavior unchanged.

## Assumptions

- A "scheduled opportunity" is one materialized occurrence. Daily and exact selected-weekday schedules
  implement the canonical "N times per week on given days" pattern by the number of selected weekdays.
  A floating "any N days in a week" quota is a different, currently undocumented recurrence semantic
  and is not inferred in this increment.
- Numeric ordinary habits use `value >= target` as success. Stepped-limit anti-habits use accumulated
  consumption `<= ceiling`; equality is within limit.
- Abstinence requires explicit daily protected/relapse input. The absence of a log is never inferred as
  success.
- Weekly ceilings use Monday 00:00 through Sunday 23:59:59 in the profile time zone. A configurable
  first day of week is a Profile concern and is deferred until a second module requires it.
- Habit stacking links to an existing routine as a whole. Ordered routine steps arrive in feature 014;
  an additive step-level link may replace or refine this link then without copying routine data.
- A habit links to at most one routine and one goal in this increment. Many-to-many coaching or tags
  are deferred until demonstrated by real use.
- Completion facts are online writes through the existing browser/native transport. Offline queues,
  push/FCM, arbitrary RRULE editing, analytics rollups, review scoring, AI coaching, shared templates,
  and medical/withdrawal advice remain explicitly out of scope.
