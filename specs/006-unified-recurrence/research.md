# Research: Unified Recurrence with Routine Migration

**Feature ID**: `006-unified-recurrence` · **Date**: 2026-08-12

## R1 — Expansion versus materialization as the source of truth

**Question**: Should "is this routine scheduled on day D" be answered by reading `planned_occurrences`
or by expanding the rule?

**Findings**: `RoutineProgressService` evaluates the schedule for arbitrary past days: the trailing
seven-day window, and streaks that walk backwards until they break. Feature 001 already ships history
that predates any engine. A materialized-only design would either have to backfill occurrences for all
history — unbounded, and wrong the moment a rule is edited — or answer "not scheduled" for days it never
wrote.

**Decision**: expansion is authoritative and pure. `PlannedOccurrence` is a forward-looking materialized
index that gives a *future* day a durable identity, which is precisely the reason
`docs/design/recurrence-engine.md` chose materialization ("we need to mark a SPECIFIC occurrence").

**Guard against divergence**: a test asserts that, over the materialized range, the occurrence set is
exactly equal to the expansion. The two cannot disagree without failing the suite.

## R2 — Cutover versus adapter

**Options**: (a) keep `routine_weekdays` and mirror into rules; (b) a temporary adapter removed later;
(c) a single migration that moves the data and drops the old shape.

**Decision**: (c). Options (a) and (b) both leave two writable schedule stores for some period, and the
roadmap explicitly forbids that outcome. The live dataset is a handful of rows, so the migration can
backfill inline and be verified directly.

The migration is additive-then-drop, the same pattern feature 001 used successfully when it moved
`routines.weekdays` into `routine_weekdays`: create the new shape, backfill from the old, verify, drop.
No historical migration is rewritten.

## R3 — Where completion lives

**Question**: `PlannedOccurrence` has a status. So does `routine_logs`. Which is authoritative?

**Decision**: `routine_logs`. It holds the note, the first `completed_at`, the idempotent upsert
semantics and the public API, all of which feature 001 specified and tested. The occurrence carries a
*derived* status plus `routine_log_id` as the design's `fact_ref`, kept in step by a single service used
by both log write paths, and recomputable by `recurrence:reconcile`.

Rejected: moving completion onto occurrences. It would break every historical day that has no
materialized occurrence, and would rewrite a public contract for no user-visible gain.

## R4 — Daylight saving

**Findings**: The failure mode is stepping through a range with `addDay()` on a date-time in a zone that
shifts: 03:00 + 1 day can land on 02:00 or 04:00, and a naive loop can then repeat or skip a calendar
day. Ukraine, the only live profile zone, transitions in March and October.

**Decision**: expansion iterates over **calendar dates**, not instants. The range is walked as
`Y-m-d` strings using `CarbonImmutable::parse($date, $timezone)->startOfDay()->addDay()`, which Carbon
normalises back to the start of the next local day. The weekday is derived from the calendar date. No
arithmetic ever crosses an instant boundary, so a transition cannot duplicate or drop a day. Both
transitions are covered by explicit tests.

## R5 — Window size and trigger

**Decision**: 90 days ahead of the owner's current local day, clamped by the rule's start and end,
matching the design document's `+90`. `recurring_rules.last_materialized_until` records the boundary.

Triggers: rule create and update (inline, inside the same transaction as the routine write), plus
`php artisan recurrence:materialize`. Reads never materialize — a `GET` that writes is a surprise, and
nothing in this feature's behaviour depends on the window existing, because expansion is authoritative.

## R6 — Editing a rule that already has occurrences

This is open question 2 in `docs/design/recurrence-engine.md`.

**Decision**: regenerate unmarked occurrences, keep the ones linked to a fact. An occurrence linked to a
log is evidence that something happened; deleting it would lose the link. An unmarked future occurrence
is a prediction and is safely replaced. Recorded here as the resolution of that open question.

Note that for routines this path is narrow in practice: the feature-001 rule that a schedule locks once
history exists is preserved, so a rule with facts can only change through pause, resume, archive and
restore.

## R7 — Query bounds

**Decision**: materialization loads the rule and its weekdays once, expands in memory, reads the
existing window in one query, and applies one `upsert` and at most one `delete` per rule. That is a
constant number of queries per rule regardless of window length, asserted by a query-count test over 50
routines.

## R8 — Frequencies included

Only `daily` and `weekly`. Interval, monthly, month-days, on/off cycles, several slots per day and RRULE
strings are all in the design document, and all lack a consumer today. Constitution principle III makes
that decisive: each returns with the feature that needs it, as an additive column or child table.

## Constitution Check

| Principle | Assessment |
|---|---|
| I | Full Spec Kit contract before implementation. |
| II | Resolves open question 2 of the recurrence design and records it there and here. |
| III | Only the two frequencies the product uses; every other design field deferred with a named trigger. |
| IV | Expansion is pure arithmetic. No AI. |
| V | `user_id` on both new tables, ownership enforced on every read and write, no new exposure. |
| VI | Migration, expansion, materialization, compatibility and browser coverage change with the code. |
