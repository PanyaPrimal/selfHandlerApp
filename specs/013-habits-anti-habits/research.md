# Research: Habits and Anti-Habits

**Feature**: `013-habits-anti-habits`
**Date**: 2026-08-13

## R1 — Habit is not Routine

Routines currently own reusable activity definitions and binary completion logs. Canonical design says
a habit may stack onto a routine but must remain a separate entity, because numeric results,
abstinence relapses, limit ceilings, and habit-specific aggregates do not belong to Routine.

**Decision**: introduce one `Habit` aggregate for ordinary and anti-habits. It owns outbound nullable
routine/goal links and one shared recurrence rule. Do not reuse `routines.kind=habit`, which remains an
older presentation label but has none of the new semantics.

**Rejected**: add columns to routines (pollutes the daily routine aggregate); create separate Habit and
AntiHabit tables (duplicates lifecycle/schedule/UI); model habits as goals (completion target and ongoing
practice are not the same fact).

## R2 — Shared recurrence gains a real second owner

Feature 006's schema is polymorphic but its materializer currently resolves enabled owners only from
`routines`, keyed by bare numeric id. A habit with id 1 and routine with id 1 would otherwise collide.

**Decision**: add `RecurringRule::OWNER_HABIT`, a Habit rule relation/service, and dispatch enabled
lifecycle by `(owner_type, owner_id)`. Preserve an occurrence during rule regeneration when either
`routine_log_id`, new `habit_log_id`, or `rescheduled_to` is non-null. Expansion, weekday rows, 90-day
window, uniqueness, and schedule vocabulary remain unchanged.

**Rejected**: a habit schedule table; copying planned dates into habit logs; a general morph rewrite of
fact columns before enough consumers exist.

## R3 — One explicit result per scheduled local day

Ordinary yes/no, numeric, abstinence, and stepped-limit modes have different success semantics, but all
need an actual time and correction/idempotency. Missing abstinence data cannot mean success.

**Decision**: one `habit_logs` row per `(habit_id, log_date)` with mode-compatible outcome, optional
decimal value, `occurred_at` UTC, and optional note. Allowed outcomes are `done/not_done`, `recorded`,
`protected/relapse`, and common `skipped`. Numeric/stepped results require value; every non-skipped
result requires explicit local time. The service finds exactly one effective planned occurrence and
sets its mirror to done or skipped. Clearing reverses the link/status.

**Rejected**: event-per-sip/repetition in 013 (unbounded capture UI and ambiguous occurrence link);
implicit success from no relapse row; storing only boolean success (loses the actual user fact).

## R4 — Success and streaks stay derived

Editable/cached counters drift after correction, schedule edits, reschedules, timezone changes, or
fact deletion. Long-period rollups are explicitly feature 023.

**Decision**: `HabitStatisticsService` reads owned occurrences plus linked logs in set queries. Success
depends on mode: done, value at/above target, or protected. The denominator contains logged
opportunities through the selected date and missing/skipped opportunities only once their effective
local day ended. Current and best streak walk scheduled opportunities in effective-date order. Numeric
total sums facts in the selected range. No counters are persisted.

**Rejected**: increment/decrement counter columns; calendar-day streaks; Analytics-owned computation;
nightly rollups before real volume exists.

## R5 — Stepped limits are normalized constraints

Canonical design explicitly forbids reusing Goal milestones. Raw limits cannot be compared when the
period changes: `1/day → 5/week` is a reduction despite 5 being numerically larger.

**Decision**: separate ordered effective-date steps with decimal ceiling and period day/week. Validate
strictly increasing dates and strictly decreasing normalized daily rate (`day=value`, `week=value/7`).
Status is derived by local date. The active step chooses a calendar-day or Monday–Sunday local period;
the service sums recorded consumption, reports remaining clamped to zero, and exposes exceeded state.
Plan replacement is one transaction.

**Rejected**: milestone reuse; editable step status; JSON array (steps are validated/queryable records);
rolling seven-day windows (hard to explain and inconsistent with calendar-period review).

## R6 — Context links remain one-way

Habit stacking and goal alignment need context but must not add copied state to existing owners.
Feature 014 will introduce ordered routine activities; they do not exist yet.

**Decision**: nullable `habits.routine_id` and `habits.goal_id` with null-on-delete FKs. Validate the
target is active, unarchived, and owned. Store place and two-minute starter on Habit; use the recurrence
rule's one time for implementation intention, Planner ordering, and reminders. Refine routine link to a
step additively in 014 if that feature requires it.

## R7 — Planner and notification integrations are projections

Planner's feature 009 contract already supports new `SchedulableSource` implementations. Feature 011
uses planned occurrence id as stable notification identity and closes delivery when status changes.

**Decision**: `HabitOccurrenceSource` returns active non-archived habit occurrences with mode/kind/log
metadata and reschedule action for untouched days. Check-in navigates to the owning Habits screen;
Planner stores nothing. Notification synchronization recognizes habit-owned timed occurrences, adds
the backwards-compatible `habit` settings category and `habit_reminder` type, and reuses quiet hours,
locale, dedupe, escalation, inbox, Android presentation, and safe Planner action URL. Untimed habits do
not create a direct reminder.

**Rejected**: a habit reminder table; client-side future notification scheduling; treating a habit as
a routine notification; a guessed default reminder time.

## R8 — Time and DST boundary

The existing product stores calendar dates separately and obtains the per-user IANA timezone from
Profile. A completion/relapse time is an instant, while schedule and limit periods are local dates.

**Decision**: input `occurred_time` is parsed with `log_date` in the profile timezone and converted to
UTC. A nonexistent DST wall time is rejected rather than shifted silently; ambiguous fallback times
use Carbon/PHP's deterministic zone resolution and round-trip to the same local date/time. Statistics
compare effective occurrence dates to the user's current local date; today without a log remains open.

## R9 — API shape and lifecycle

One dense index response is preferable to a request per card, but period exploration needs a narrow
statistics endpoint. Domain identity changes would reinterpret history.

**Decision**: list by lifecycle/date; create; patch editable configuration/lifecycle; replace limit
steps; upsert/clear one date; get one statistics period. Resource responses include schedule, links,
steps, selected-date occurrence/log, all-time-through-date statistics, and limit status. Kind/mode are
immutable after creation; target/unit lock after the first fact. Payloads reject unknown keys. Pause
and archive retain history while disabling future materialization/reminders.

## R10 — Evidence layers

1. Schema/model tests prove additive tables, FK actions, unique keys, ownership guards, and MySQL-safe
   names.
2. Unit/service tests prove owner dispatch, idempotency, fact mirror/reconcile, DST, streak and limit
   boundaries, query bounds, and plan validation.
3. API/OpenAPI tests prove payload exactness, mode compatibility, lifecycle, nested 404 ownership, and
   route/contract parity.
4. Playwright proves create/check/correct/clear, abstinence/limit flows, Planner/reminder surfaces,
   rollback, EN/RU/UK, accessibility, keyboard, reload, desktop, and 390×844 overflow.
5. Full existing Laravel/i18n/typecheck/build/desktop/mobile suites prove regression safety.

## Constitution Check

All research decisions preserve specification-first delivery, canonical design ownership, one vertical
slice, deterministic behavior, user privacy, synchronized contracts/evidence, and complete localization.
No exception or unresolved clarification remains.
