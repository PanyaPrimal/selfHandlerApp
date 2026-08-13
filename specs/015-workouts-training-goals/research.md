# Research: Workouts and Training Goals

## Canonical Inputs and Current Boundaries

- `docs/design/modules.md`, Module 3 and Module 4
- `docs/design/decisions.md`, Workouts and Goals decisions
- `docs/design/data-conventions.md`, user ownership, class-table modelling, base units, time, aggregates
- `docs/design/recurrence-engine.md`, WorkoutProgram as an intended recurrence owner
- `docs/design/notifications.md`, next-workout reminders and source ownership
- `docs/design/delivery-roadmap.md`, feature 015 outcome, prerequisites, and deferrals
- Features 001, 004, 006, 009, 011–014 and current implementation of Goal, Profile,
  RecurringRule/PlannedOccurrence, Planner, Notifications, Today/Review, i18n, and Capacitor

The repository contains no Workout owner or fact. The safe implementation therefore extends shared
dispatch once, retains all current owners, and creates module-owned projections rather than adding a
second schedule, planner, goal, notification, or analytics subsystem.

## Decisions

### 1. One useful slice, not the entire long-term training vision

**Decision**: Deliver a private/custom program loop, a small generic exercise catalogue, manual and
planned facts for all four documented workout families, one deterministic progression scheme, exact
records/summaries, and typed training goals. Defer licensed ready-made content, advanced programming,
integrations, GPS, attachments, long charts, and AI.

**Why**: These parts independently satisfy the roadmap outcome and establish stable facts for
Nutrition. The deferred work either has an unresolved content/provider dependency or belongs to a
later owning feature.

**Rejected**: One mega-feature containing Strava/Garmin, GPX, coaching, program generation, charts,
and calorie science. It would violate thin-slice, privacy, integration, attachment, and aggregate
ownership boundaries.

### 2. Class-table models both historical facts and divergent program details

**Decision**: `workout_sessions` is the common fact wrapper. Completed facts have exactly one of
strength, endurance, or timed details; skipped facts have none. Ordered strength exercise results and
sets are child tables. `workout_programs` is the common template wrapper with either ordered strength
prescriptions, one endurance detail, or one timed detail.

**Why**: Strength sets, running metrics, and a sport duration do not share a stable column set. The
canonical data convention explicitly selects class-table modelling for Workouts. Runtime/service and
schema evidence enforce matching subtype and same-owner rows.

**Rejected**: A sparse single table or unvalidated JSON. Both weaken contracts, same-owner constraints,
queryability, and the following Nutrition feature's stable inputs. Laravel STI was also rejected; the
project uses explicit Eloquent relationships and services.

### 3. A small global catalogue plus private custom exercises

**Decision**: `exercises.user_id` is nullable. Rows with a stable `system_key` are immutable public
reference data inserted by the additive migration; rows with an owner are private custom entries.
The built-in list is intentionally generic (squat, bench press, deadlift, overhead press, row, pull-up)
and its display labels come from EN/RU/UK keys. No built-in program is shipped.

**Why**: Strength program/session/goal FKs need durable exercise identity. Generic exercise names are
not a copyrighted training plan, while custom rows make the slice fully useful without a licensing
decision. Every query scopes to `user_id IS NULL OR user_id = current user`; only custom owners mutate.

**Rejected**: Frontend-only constants, because facts and goals need stable backend references; seeding
ready-made programs, because the canonical source/license question is unresolved.

### 4. WorkoutProgram is the only schedule owner

**Decision**: Each program owns exactly one existing RecurringRule with owner type `workout_program`.
Only `daily` and `weekdays` are exposed, with one occurrence per local date and optional slot time.
The existing expander, materializer, reschedule pointer, and 90-day window remain authoritative.

**Why**: This matches the locked recurrence design and existing routine/habit/sleep delivery. A
program contains domain targets; the rule contains scheduling only. Schedule/lifecycle edits remove
only untouched predictions and preserve facts/reschedules.

**Rejected**: Dates on each program exercise, a Workout-specific scheduler, or copying sessions into
Planner. Multi-slot/interval/cycle/RRULE expansion is not required by this slice.

### 5. Planned and manual session identity is explicit

**Decision**: A planned session uses idempotent
`PUT /workout-programs/{program}/sessions/{effective-date}` and is unique per user/program/date. It
links the one effective occurrence through `planned_occurrences.workout_session_id`. A manual session
uses `POST /workouts`, has no program/occurrence, and multiple manual facts may share a date. PATCH
corrects the same session; DELETE clears it and unlinks a planned fact.

**Why**: Retried writes cannot duplicate facts, while unplanned activity is not forced into recurrence.
The effective-date lookup naturally respects Planner reschedules. Planner rejects same-rule date
collisions before a write can make identity ambiguous.

**Rejected**: A polymorphic JSON `fact_ref`, because the current additive recurrence schema uses
explicit mutually exclusive FKs and tests owner/fact integrity. A Planner-owned skip flag was rejected;
a skipped planned session is a Workout-domain fact with no fabricated subtype values.

### 6. Dates, instants, durations, and units stay canonical

**Decision**: `performed_on` is a Profile-local date. Optional `started_time` is resolved in the
Profile timezone and stored as UTC `started_at`; DST gaps are rejected and fall-back ambiguity follows
the existing deterministic Carbon resolution. Duration is positive integer seconds, distance is
positive integer metres, weight is non-negative `DECIMAL(8,3)` kilograms, energy is explicit integer
kcal, and heart rate is an optional bounded integer observation.

**Why**: This follows data conventions, survives unit preference changes, and gives Nutrition stable
inputs. Pace is derived as seconds per kilometre only from positive duration and distance.

**Rejected**: Storing display pounds/miles, floats, duration text, or a guessed calorie/MET number.
Automatic energy accuracy remains an open canonical question.

### 7. Strength modes share one fact vocabulary

**Decision**: Simple mode stores ordered exercise result weight/repetitions directly on session
exercise rows and has no set children. Detailed mode stores ordered set children and leaves simple
result fields null. Zero kg is valid; reps are positive. A completed strength fact contains 1–50
unique exercises and a detailed exercise contains 1–20 unique ordered sets.

**Why**: Both modes preserve exercise history and can drive max-weight goals/records. Strict mutual
exclusion prevents a response or aggregate from guessing which value is authoritative.

**Rejected**: A simple free-text statement, because it cannot feed deterministic goals/progression;
always requiring detailed sets, because it violates the canonical quick-log option.

### 8. Linear progression is a pure chronological fold

**Decision**: Each strength prescription stores start weight, target sets/reps, increment, and required
consecutive successes. Starting from the stored weight, completed matching sessions are folded by
date/id. A session succeeds when its simple result or required first detailed sets meet/exceed the
current prescription. Success increments a counter; reaching the threshold raises the target and
resets the counter. A completed non-qualifying session resets the counter. Skips/unrelated facts do not.

**Why**: This implements the explicit Level 1 example transparently. Corrections automatically
recompute truth; there is no mutable counter that can drift. Each exercise progresses independently.

**Rejected**: Estimated 1RM, double progression, periodization, or opaque recommendation scoring.
Those require a later explicitly specified scheme and, for advice, stronger safety framing.

### 9. Records and summaries remain module-owned, bounded projections

**Decision**: `WorkoutStatisticsService` owns selected-day and inclusive max-366-day totals. It derives
planned/completed/skipped/unplanned counts, duration, distance, volume (`kg × reps`), per-exercise max
weight, and best valid pace. Today transports the selected-day DTO; Review presents the same DTO.
No rollup or DailyReview column is introduced.

**Why**: It follows the implemented feature 014 module-summary boundary and the canonical aggregation
principle. Set queries/preloaded relations have explicit query budgets and honest null empty values.

**Rejected**: Computing in Vue/Review, mutating totals on session writes, or implementing feature 023
long-period analytics early.

### 10. Training is a typed detail of the existing Goal

**Decision**: Add `Goal::TYPE_TRAINING` and one `training_goal_details` row with immutable kind/scope/
starting snapshot plus editable target value. Kinds are `strength`, `distance`, `race`, and
`consistency`. Strength requires an accessible exercise; distance requires an activity; race requires
running plus target date; consistency may scope to a program. Common lifecycle stays on Goal. The
creation snapshot is the matching current value, or canonical zero when no matching history exists;
current/progress remain null until a real matching fact exists.

**Why**: This is the same additive class-table pattern already proven by Body goals. Current values are
derived: max matching weight, max distance, or completed-session count across the trailing seven local
dates. Starting value is captured from history at creation (zero when none exists) and never silently
rewritten; absent current history is null progress.

**Rejected**: A new TrainingGoal root, progress columns, or a generic future-proof Goal detail JSON.
Those duplicate lifecycle/ownership or speculate about Finance and other future goal types.

### 11. Race events and program occurrences are distinct Planner sources

**Decision**: `WorkoutOccurrenceSource` projects active program occurrences with module deep links and
reschedule/skip actions. `TrainingGoalSource` projects only active, nonarchived race goals on their
target date, read-only. Planner delegates workout skip to the Workout session service; race events have
no recurrence or Planner-owned state.

**Why**: A program repeats; a race deadline is a one-off goal event. Both implement the existing pull
contract without copying facts. Source names/IDs prevent numeric collision.

**Rejected**: Giving a race a fake daily rule or writing a Planner event table.

### 12. Notifications extend category/type vocabulary only

**Decision**: Add enabled-by-default `workout` category and `workout_reminder` type. Active timed
program occurrences are direct; untimed occurrences remain digest candidates under feature 011.
Existing quiet hours, dedupe, delivery locale, snooze, and closure mechanics are reused. Fact,
reschedule, pause, archive, missing owner, or invalid date closes pending families.

**Why**: The canonical notification is “date of the next workout.” The existing source synchronizer
already owns these mechanics; only source classification and localized content are new.

**Rejected**: Per-program alarm infrastructure, escalation guesses, push/FCM, or a Workout inbox.

### 13. API and UI are one closed responsive contract

**Decision**: Fifteen authenticated operations cover catalogue, programs, strength prescriptions,
manual/planned sessions, statistics, and training goals. Closed OpenAPI schemas, strict Laravel
requests, TypeScript types/API functions, one `/workouts` workspace, navigation/Planner/Today/Review/
Notifications integrations, and EN/RU/UK copy ship together. The final shared bundle is synchronized
through the existing Capacitor validator.

**Why**: No client or surface may invent subtype interpretation. One responsive implementation keeps
web/mobile behavior identical and preserves current transport/auth boundaries.

### 14. Additive evolution and rollback preserve every existing module

**Decision**: One migration creates twelve tables and adds one nullable unique workout fact FK to
PlannedOccurrence. Rollback drops that FK first and then only feature-owned tables in dependency order.
Every identifier is explicitly short enough for MySQL. Existing rows receive no destructive backfill.

**Why**: This is the established safe evolution pattern for Body, Habits, and Sleep. Built-in catalogue
rows live only inside a feature-owned table and disappear with it.

## GitNexus Architecture and Impact Evidence

The index was refreshed at baseline commit `0b38fcea7c46ea4cc808c01e2b89a7cf418b1257`
(5,766 nodes, 13,952 edges, 300 flows). Exploration confirmed no existing Workout owner and identified
the implemented Goal, recurrence, Planner, notification, Today/Review, and client boundaries.

Pre-change upstream impact:

| Boundary | Risk | Direct evidence and required regression scope |
|---|---:|---|
| `RecurrenceMaterializer` | Medium | 6 imports: recurrence command plus routine/habit/sleep/planner tests |
| `OccurrenceFactSynchronizer` | Medium | 5 direct imports plus API routes transitively |
| `SourceRegistry` | Low | Planner controller and Planner/Habit contract tests |
| `NotificationSourceSynchronizer` | Medium | processing job plus notification/habit/sleep suites |
| `Goal` | Medium | 11 direct consumers, one store flow, Body/Core/Auth/Habit tests |
| `GoalController` | Low | API route registry only |

There is no High/Critical pre-change warning. Every depth-1 dependent is represented in focused
closure tasks, and `detect_changes(all/staged)` is mandatory before commit.
