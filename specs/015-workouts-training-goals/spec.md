# Feature Specification: Workouts and Training Goals

**Feature ID**: `015-workouts-training-goals`

**Created**: 2026-08-13

**Status**: Complete

**Input**: User description: "Implement the complete non-deployment Workouts and Training Goals
vertical slice from the canonical design: maintain an exercise catalogue, follow a recurring manual
program, record strength/cardio/running/flexibility/sport sessions, derive progression and records,
and show progress toward a typed training goal through the shared daily surfaces."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Build and Schedule a Program (Priority: P1)

The user creates a strength, cardio/running, flexibility, or sport program, gives it a daily or
selected-weekday schedule and optional preferred time, and pauses, restores, or archives it without
losing historical sessions. A strength program uses ordered exercises from a small built-in catalogue
or the user's own catalogue entries.

**Why this priority**: A reusable, recurring program is the smallest useful planning unit and the
authoritative owner needed by Planner, reminders, and planned session facts.

**Independent Test**: Create a weekday strength program with two ordered exercises, reload it,
materialize its dates, edit the future schedule, pause/restore it, and archive it. Historical or
rescheduled occurrences remain while only eligible future occurrences change.

**Acceptance Scenarios**:

1. **Given** the built-in catalogue and no programs, **When** the user creates a Monday/Wednesday/
   Friday program with a preferred time, **Then** one shared recurrence rule materializes one owned
   occurrence for each matching day and Planner shows the program once.
2. **Given** a strength program, **When** the user atomically replaces its ordered exercise plan,
   **Then** valid owned/public exercises and targets are stored in the submitted order while any
   invalid or foreign entry rejects the whole replacement.
3. **Given** a program with future occurrences and retained facts, **When** its schedule or lifecycle
   changes, **Then** untouched future projections reconcile while facts and user reschedules survive.
4. **Given** a built-in exercise, **When** the user attempts to edit/archive it, **Then** the request is
   rejected; a user-created exercise remains private and can be renamed or archived.

---

### User Story 2 - Record Strength Work (Priority: P1)

The user completes or skips a planned strength session, or records an unplanned one. In simple mode
they enter an exercise result; in detailed mode they enter ordered sets of canonical kilograms,
repetitions, and optional rest. A correction updates the same fact, and clearing a planned fact makes
that occurrence pending again.

**Why this priority**: Strength facts prove the class-table model, exercise ownership, planned/manual
identity, detailed input, and recurrence reconciliation at the highest-risk domain boundary.

**Independent Test**: Complete one planned detailed session, reload, correct a set and note, switch a
separate manual session through simple entry, skip another planned date, then clear each fact. Exact
session identities, set order, occurrence states, and totals remain deterministic.

**Acceptance Scenarios**:

1. **Given** a scheduled strength occurrence, **When** a detailed session with two exercises and
   ordered sets is saved, **Then** one common session, one strength detail, child exercise/set facts,
   and one occurrence link are committed atomically.
2. **Given** a strength session, **When** its sets are corrected, **Then** the same session is replaced
   atomically and volume, records, summaries, and goal progress immediately reflect the correction.
3. **Given** a planned workout that will not be performed, **When** it is skipped from Workouts or
   Planner, **Then** one module-owned skipped session closes that occurrence without fabricated
   exercise or duration data.
4. **Given** mixed subtype fields, negative weight, zero reps, duplicate order, excessive children,
   a foreign exercise/program, or an unscheduled planned date, **When** the request is submitted,
   **Then** it fails atomically with localized feedback.

---

### User Story 3 - Record Endurance and Timed Activity (Priority: P1)

The user manually records cardio (including running, cycling, walking, and swimming), flexibility, or
sport sessions. Duration is canonical seconds; distance is canonical metres; optional heart rate and
energy remain explicit user facts. Running additionally records its session type and shows derived
pace only when both distance and duration exist.

**Why this priority**: The module must be useful beyond strength and must establish stable inputs for
the following Nutrition feature without pretending that an unverified calorie formula is fact.

**Independent Test**: Record and correct a run, a flexibility session, and a sport session; reload
history and verify exact canonical values, localized display conversions, derived pace, and clean
subtype separation.

**Acceptance Scenarios**:

1. **Given** a manual running session, **When** distance, duration, run type, and optional average heart
   rate are saved, **Then** the common wrapper and endurance detail are stored and pace is derived.
2. **Given** flexibility or sport, **When** a duration and applicable activity/style are saved,
   **Then** only the matching timed detail exists and no endurance/strength fields are fabricated.
3. **Given** a completed planned non-strength program, **When** the session is saved or corrected,
   **Then** it links to the effective occurrence exactly once and keeps the program's type immutable.

---

### User Story 4 - Follow Deterministic Progression and Records (Priority: P2)

The user sees per-exercise history, personal records, and the next suggested strength target. A
strength program may use a simple linear rule: after a configured number of consecutive sessions that
meet the prescribed sets, reps, and weight, increase the next target by a configured increment. A
failed or incomplete session resets only that exercise's success streak.

**Why this priority**: Progression is the deterministic Level 1 value promised by the design and is
independent of future templates, charts, or AI coaching.

**Independent Test**: Starting at 50 kg with a +2.5 kg increment after two successful 3×5 sessions,
record two successes, one failure, and a correction. The suggested target and successes remaining
always recompute to the exact expected values, while max weight and volume records follow facts.

**Acceptance Scenarios**:

1. **Given** a configured progression item and qualifying chronological history, **When** statistics
   are read, **Then** the next target and success counter are derived without a mutable progression
   state row.
2. **Given** a non-qualifying or skipped session, **When** progression is recalculated, **Then** the
   affected exercise streak resets or remains unchanged according to the published rule.
3. **Given** no recorded work for an exercise, **When** records are read, **Then** current record fields
   are null rather than misleading zeroes.

---

### User Story 5 - Track a Training Goal (Priority: P2)

The user creates a typed training goal for a strength result, cardio/race distance, or weekly session
consistency. It remains an ordinary Goal for lifecycle and ownership, with one training detail whose
current value and progress are derived only from workout history. A race goal appears on its target
date in Planner.

**Why this priority**: It closes the documented goal → training history loop without redesigning all
future Goal types or copying workout aggregates into the Goal table.

**Independent Test**: Create each goal kind, add/correct matching and non-matching sessions, and verify
current value/progress, optional program/exercise scope, target-date event, lifecycle, and absence
semantics. No progress field is persisted.

**Acceptance Scenarios**:

1. **Given** a strength goal scoped to an exercise, **When** matching sets are recorded, **Then** its
   current value is the best canonical weight and progress is measured from the immutable starting
   snapshot to the target.
2. **Given** a race/distance goal, **When** matching runs are recorded, **Then** the farthest completed
   distance drives progress and a race target date is exposed to Planner without recurrence.
3. **Given** a consistency goal, **When** completed sessions enter or leave the trailing seven local
   dates, **Then** the current value is the exact distinct-session count for its optional program scope.
4. **Given** no matching history, **When** the goal is read, **Then** current value and progress are null;
   archive/restore/completion/abandonment still use the shared Goal lifecycle.

---

### User Story 6 - Use the Workout Loop Everywhere (Priority: P3)

The user reaches Workouts from responsive navigation, acts on planned sessions from Planner, receives
eligible workout reminders, and sees module-owned selected-day/range summaries in Today and Review.
Every workflow is complete in English, Russian, and Ukrainian in the browser and bundled Android
client.

**Why this priority**: Cross-surface delivery makes the module part of SelfHandler's daily loop while
preserving Planner, Notifications, Today, Review, i18n, and mobile ownership boundaries.

**Independent Test**: For one controlled day, plan, remind, complete/skip/correct sessions and inspect
Workouts, Planner, Today, Review, and Notifications in each locale/theme at desktop and exact 390×844.
All surfaces agree and retain their existing drafts/state.

**Acceptance Scenarios**:

1. **Given** an active timed program occurrence, **When** notification synchronization runs outside
   quiet hours, **Then** one deduplicated workout reminder deep-links to that session; completion,
   skip, pause, archive, reschedule, or invalidation closes pending reminder state.
2. **Given** a selected date, **When** Today and Review load, **Then** both present the same workout-owned
   planned/completed/skipped/manual/duration/distance/volume summary without persisting it in Review.
3. **Given** any supported locale, theme, viewport, keyboard path, or API failure, **When** the workout
   loop is used, **Then** copy, units, dates, live states, focus recovery, rollback, and layout remain
   accessible and correct.

### Edge Cases

- A rescheduled program occurrence cannot collide with another effective occurrence for the same rule;
  a fact-bearing occurrence cannot move.
- A planned session is addressed by the occurrence's effective local date. Retries update the same
  session; a manual session may coexist but cannot masquerade as the planned fact.
- One session can link to only one occurrence, one owner, and one subtype. A skipped session has no
  subtype or child details.
- Exercise names are user content. Built-in exercise labels use stable catalogue keys and localized
  display copy; custom names are never translated or exposed to other users.
- Program exercise edits affect future suggestions only; recorded session exercise/set facts retain
  their exact values and catalogue relationship.
- Zero kilograms is valid for bodyweight work; negative weight, zero repetitions, negative rest, and
  non-finite/over-limit values are rejected.
- A run needs positive duration and distance for pace. Other cardio can omit distance but never
  duration. Heart rate and energy are optional observations, not medical/calorie estimates.
- DST gap wall times are rejected; repeated fall-back wall times use the same documented deterministic
  profile-timezone resolution as other module facts.
- Goal starting snapshots do not silently rewrite when history changes. Target changes retain the
  original start unless the goal is recreated; no matching history yields null progress.
- Account deletion removes private workout/goal data while global catalogue rows remain. Rollback of
  the additive migration never touches pre-feature tables or facts beyond removing its nullable link.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST provide a read-only built-in exercise catalogue plus user-owned custom
  exercises with stable name, muscle group, equipment, type, lifecycle, and deterministic ordering.
- **FR-002**: Built-in exercises MUST be immutable through user APIs; custom exercises MUST be visible
  only to their owner and remain referenceable after archive.
- **FR-003**: Users MUST be able to create, read, update, pause, restore, and archive user-owned workout
  programs of type `strength`, `cardio`, `flexibility`, or `sport`.
- **FR-004**: Every program MUST own exactly one shared RecurringRule using supported daily/selected-
  weekday scheduling, Profile timezone, optional preferred time, date bounds, and one occurrence/day.
- **FR-005**: Strength programs MUST own an atomically replaceable ordered exercise plan with target
  sets/reps, starting canonical kilograms, increment, and successes-required progression fields.
- **FR-006**: Cardio programs MUST support activity, optional running subtype, target distance, and
  planned duration; flexibility/sport programs MUST support their matching activity/style and planned
  duration without unrelated subtype fields.
- **FR-007**: Program lifecycle and schedule reconciliation MUST preserve fact-bearing or explicitly
  rescheduled occurrences and all historical sessions.
- **FR-008**: The system MUST persist each workout fact in one common user-owned session wrapper plus
  at most one matching class-table detail; skipped planned sessions MUST have no subtype detail.
- **FR-009**: Users MUST be able to idempotently complete, skip, correct, or clear a planned session and
  create, correct, or delete unplanned completed sessions.
- **FR-010**: Planned sessions MUST link exactly one effective occurrence and MUST use the program's
  immutable type; manual sessions MUST not claim an occurrence.
- **FR-011**: Strength facts MUST support simple ordered exercise results or detailed ordered sets with
  canonical kilograms, repetitions, and optional rest seconds, with bounded child counts.
- **FR-012**: Endurance facts MUST support running/cycling/walking/swimming/other, canonical distance
  metres and duration seconds, optional average heart rate/explicit energy, and running session type.
- **FR-013**: Flexibility and sport facts MUST store duration plus their matching optional/required
  style/activity without creating strength or endurance details.
- **FR-014**: Session writes MUST be atomic, strict-key validated, same-owner, date/timezone safe,
  subtype-compatible, and stable under retries and concurrent planned writes.
- **FR-015**: Clearing/deleting a planned fact MUST restore its occurrence to pending; correcting any
  fact MUST keep the same session identity and reconcile status once.
- **FR-016**: The Workout module MUST derive session duration totals, endurance distance, strength
  volume, per-exercise maximum weight, and best valid pace directly from source facts.
- **FR-017**: The module MUST derive linear progression chronologically per program exercise, increasing
  after configured consecutive qualifying sessions and resetting its success streak on a completed
  non-qualifying session; skipped/unrelated sessions MUST not qualify.
- **FR-018**: Progression and record values MUST be computed rather than stored as mutable truth, must
  recalculate after corrections, and must return null for absent observations.
- **FR-019**: A selected-day summary MUST distinguish planned, completed, skipped, and unplanned
  sessions and expose exact duration, distance, and volume with bounded queries.
- **FR-020**: An inclusive statistics range MUST be limited to 366 local dates and remain query-bounded
  as session/set/program counts grow.
- **FR-021**: The shared Goal aggregate MUST add a `training` type with one same-owner training detail
  for `strength`, `distance`, `race`, or `consistency`, without changing general/body goal ownership or
  lifecycle contracts.
- **FR-022**: Training goal details MUST use canonical target units and enforce compatible exercise,
  activity, optional program scope, target date, and immutable creation-time starting snapshot.
- **FR-023**: Strength/distance/race/consistency current values and progress MUST be derived from matching
  workout history; no current/progress/achievement aggregate may be persisted on the goal.
- **FR-024**: Race goals MUST require a target date and appear once as a read-only event in Planner;
  other training goals MUST not create fabricated Planner events.
- **FR-025**: WorkoutProgram MUST be a new recurrence owner and WorkoutSession a new mutually exclusive
  PlannedOccurrence fact link without numeric owner/fact collision with routines, habits, or sleep.
- **FR-026**: Planner MUST pull workout occurrences and race events through registered
  SchedulableSource implementations, retain source/action identity, and route mutations to module
  services; workout reschedules MUST reject same-rule effective-date collisions.
- **FR-027**: Timed workout occurrences MUST produce one workout-category reminder through feature 011;
  untimed occurrences follow the existing digest policy, and fact/lifecycle/reschedule invalidation
  MUST close pending notification families.
- **FR-028**: Today MUST transport and Review MUST present the same Workout-owned summary without
  persisting or recomputing workout data in DailyReview or Planner.
- **FR-029**: The web client MUST provide one responsive Workouts workspace for catalogue, programs,
  planned/manual facts, history/records/progression, and training goals with explicit async, empty,
  error, retry, success, confirmation, focus, and rollback states.
- **FR-030**: Every new API operation MUST be authenticated, owner-scoped, closed-schema documented in
  OpenAPI 3.1, matched by TypeScript contracts/consumers, and return foreign identifiers as 404.
- **FR-031**: All new visible copy, validation/domain feedback, enum/unit/date labels, reminder copy,
  changelog text, ARIA, and screen-reader content MUST ship simultaneously in en-GB, ru-UA, and uk-UA;
  user-authored names/notes remain unchanged.
- **FR-032**: Desktop and exact 390×844 flows MUST support both schemes, 44px touch targets, keyboard
  operation, focus/live-region semantics, safe areas, long localized copy, and no horizontal overflow
  or console/page errors.
- **FR-033**: The bundled Android client MUST use the same API/UI/domain behavior after final Capacitor
  synchronization; this feature MUST add no native-only workout state or unsafe remote transport.
- **FR-034**: Persistence evolution MUST be additive, reversible, MySQL/SQLite portable, safe for all
  existing users/data, and must not touch deployment, feature 002, live data, attachments, providers,
  GPS, wearable imports, or AI.

### Localisation Surface *(mandatory)*

- **Locales**: English (`en-GB`), Russian (`ru-UA`), and Ukrainian (`uk-UA`).
- **New user text**: Workouts navigation; workspace/catalogue/program/session/history/statistics/goal
  headings; type/activity/run-mode/intensity/lifecycle/outcome labels; exercise/set/progression/record/
  distance/duration/pace/heart-rate/energy/volume fields; schedule, Planner, Today, Review,
  Notifications, loading/empty/error/retry/success/confirmation/validation states; placeholders,
  hints, chips, live regions, ARIA labels, and changelog entries.
- **Formatting**: Profile-local calendar dates/times; locale numbers; canonical metres/seconds/
  kilograms converted only for display through Profile unit preferences; pace as localized minutes
  and seconds per kilometre; counts/plurals and percentages through the shared formatter/catalogue.
- **Non-translatable content**: Brand/product name, user exercise/program/session/goal names and notes,
  IDs, API paths, and canonical unit codes. Built-in exercise catalogue keys are translated for display.
- **Verification**: i18n key parity/blank/unknown/unused/hardcoded-copy guards, backend message
  assertions, EN/RU/UK browser journeys, locale-reactive live states, and visual inspection.

### Key Entities

- **Exercise**: A stable built-in or user-owned exercise catalogue entry; custom lifecycle never
  changes historical references.
- **WorkoutProgram**: The user-owned recurring template and lifecycle owner; one RecurringRule and one
  matching strength/endurance/timed program detail.
- **WorkoutProgramExercise**: An ordered strength prescription and deterministic progression rule.
- **WorkoutSession**: A completed or skipped fact on one local date; optional program/occurrence origin
  plus exactly one compatible class-table detail for completed facts.
- **WorkoutStrengthDetail / WorkoutEnduranceDetail / WorkoutTimedDetail**: Mutually exclusive
  subtype-specific session data.
- **WorkoutSessionExercise / WorkoutSet**: Ordered actual strength exercise results and detailed sets.
- **TrainingGoalDetail**: The training-specific class-table row attached to one ordinary Goal; stores
  target/scope/start snapshot while progress remains derived.
- **RecurringRule / PlannedOccurrence**: Existing shared schedule and durable occurrence identity,
  extended only with the workout owner/fact vocabulary.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can create a recurring program with at least two strength exercises and see its
  first Planner occurrence in under two minutes without entering duplicate schedule data.
- **SC-002**: Retrying any planned session write produces exactly one session, one matching subtype,
  one occurrence link, and no duplicate exercise/set rows.
- **SC-003**: For controlled simple/detailed/cardio/timed fixtures, every displayed duration, distance,
  volume, pace, personal record, and progression suggestion equals the independently calculated value.
- **SC-004**: Corrections or clears update session history, occurrence status, Today/Review summaries,
  records, progression, reminders, and training goal progress on the next read with no stale aggregate.
- **SC-005**: A 366-day statistics read stays within its documented fixed query budget for at least
  100 programs, 1,000 sessions, and 20,000 sets in automated evidence.
- **SC-006**: Cross-account access to every catalogue/program/session/goal nested identifier returns 404
  and leaves all rows unchanged; account deletion removes every private workout/training row.
- **SC-007**: All new authenticated OpenAPI operations match Laravel routes, closed request schemas,
  success/error shapes, and TypeScript consumers with zero undocumented operation.
- **SC-008**: The i18n guard reports exact EN/RU/UK key parity and permanent browser tests complete the
  primary workflow in every locale on desktop and exact 390×844 with zero horizontal overflow.
- **SC-009**: The final full Laravel, Pint, typecheck, unit, production build, desktop/mobile Playwright,
  and Capacitor validation gates pass without weakening prior assertions.
- **SC-010**: Git diff/status and secret/protected-path audits show zero deployment/feature-002/handoff/
  live-data changes, and one atomic feature commit is pushed with local HEAD equal to `origin/master`.

## Assumptions and Explicit Deferrals

- Daily and selected-weekday programs cover this slice; shared recurrence interval/monthly/cycle/
  multi-slot/RRULE expansion remains deferred until a module requires it.
- A program produces at most one occurrence per local date. Ready-made licensed programs, advanced
  plan generation, periodization, deloads, supersets, timers, and coach collaboration are deferred.
- Linear consecutive-success progression is the one deterministic Level 1 scheme. Double progression,
  estimated 1RM formulas, medical load advice, and LLM coaching are deferred.
- Manual entry is authoritative. Wearables, Strava/Garmin/Apple Health, GPX/elevation, heart-rate
  zones, automatic calorie/MET estimation, and attachments wait for their owning features.
- User-entered energy is an optional observation. Feature 016 may consume stable planned/actual workout
  inputs but cannot rewrite workout facts or let actual activity drift the day's planned nutrition target.
- The built-in catalogue is intentionally small and generic; ready-made program content needs a future
  product/licensing decision. Custom exercises provide full current usefulness.
- Long-period charts/rollups remain owned by feature 023. This feature supplies bounded module-owned
  aggregates and exact source facts only.
- Deployment, signing, live Android device builds, feature 002, and the untracked design handoff are
  outside scope.
