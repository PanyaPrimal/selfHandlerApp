# Implementation Plan: Unified Recurrence with Routine Migration

**Feature ID**: `006-unified-recurrence`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/openapi-delta.md](contracts/openapi-delta.md) ·
**Quickstart**: [quickstart.md](quickstart.md)

## Summary

Move the routine schedule onto `RecurringRule` + `PlannedOccurrence` in a single cutover, keeping every
routine behaviour and every API response identical, and rebuild the routine form's schedule section on
the feature 005 control set.

## Technical Context

- Laravel 12 / PHP 8.4 in `apps/api`; Vue 3 / TypeScript in `apps/web`.
- MySQL 8 in production, SQLite for the automated suite. Both must honour the unique keys, which is why
  `slot` is non-null.
- No new dependency on either side.

## Architecture

```
apps/api/app/
  Models/RecurringRule.php                 schedule aggregate, weekdays, bounds, window boundary
  Models/RecurringRuleWeekday.php
  Models/PlannedOccurrence.php
  Services/RecurringRuleExpander.php       pure: occursOn(rule, date), datesBetween(rule, from, to)
  Services/RecurrenceMaterializer.php      bounded, idempotent, atomic window writer
  Services/OccurrenceFactSynchronizer.php  keeps derived status in step with routine_logs
  Services/RoutineScheduleService.php      thin owner-aware facade over the expander
  Console/Commands/MaterializeRecurrence.php
  Console/Commands/ReconcileOccurrences.php
```

**Boundaries**

- `RecurringRuleExpander` is pure: no database, no clock, no owner knowledge. It answers questions about
  a rule and a calendar date.
- `RoutineScheduleService` keeps its existing public signature and adds the owner gating (paused,
  archived, soft-deleted) that the expander must not know about. Every current caller — Today, progress,
  streaks — is untouched.
- `RecurrenceMaterializer` is the only writer of `planned_occurrences` and of `last_materialized_until`.
- Routine log writes stay in `RoutineLogController`; the synchronizer is called from there.

## Architecture Gate Answers

1. **Owner**: the rule owns the schedule; `routine_logs` owns completion; the occurrence owns only its
   own identity and a derived pointer.
2. **Inputs**: the time zone is seeded from the profile and then stored on the rule, so a later profile
   change cannot silently rewrite an existing schedule's meaning.
3. **Time**: calendar dates stay `Y-m-d`; expansion walks calendar days in the rule's zone; instants stay
   UTC.
4. **Scheduling**: this feature *is* the shared boundary. Nothing else may add a schedule table.
5. **Cross-module links**: one direction — the occurrence references the log, never the reverse.
6. **Evolution**: one migration, backfill before drop, reversible `down()`, verified on a data-bearing
   database.
7. **Contracts**: routine endpoints keep their shapes; the OpenAPI delta records that nothing public
   changed and why.
8. **Aggregates**: progress and streaks remain owned by `RoutineProgressService`.
9. **Privacy**: `user_id` on both tables, ownership on every query, no new exposure.
10. **Deferral**: intervals, monthly, cycles, multi-slot days, RRULE, reminders and single-occurrence
    rescheduling are listed with the feature that will bring them.

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I | Pass | Contract authored first. |
| II | Pass | Resolves recurrence-design open question 2 and records it in both places. |
| III | Pass | Two frequencies, both in use. Every other design field deferred. |
| IV | Pass | Pure deterministic expansion. |
| V | Pass | Ownership on both new tables from the first migration. |
| VI | Pass | Migration, unit, API, compatibility and browser tests in the same change. |

**Accepted deviations**

- **AD-1 — occurrences carry a derived status.** It duplicates information `routine_logs` already holds.
  Justified because the engine needs a per-occurrence state for Planner and Notifications later;
  mitigated by making it strictly derived, recomputable, covered by a reconciliation command and by a
  test that rebuilds it from the logs.

## Phases

| Phase | Content |
|---|---|
| 1 Setup | Fixtures and factories for rules and occurrences. |
| 2 Foundational | Migration and models. Nothing else can move first. |
| 3 US1+US2 | Expander, facade, cutover of routine reads and writes, API compatibility. |
| 4 US3 | Time-zone and daylight-saving coverage. |
| 5 US4 | Materializer, fact synchronizer, console commands, query bounds. |
| 6 US5 | Recurrence editor on the feature 005 controls. |
| 7 Polish | Contract delta, reconciliation, full gate, evidence. |

## Risks

| Risk | Mitigation |
|---|---|
| The cutover loses live schedule data | Backfill asserted on a data-bearing database before the drop; reversible `down()`. |
| Streaks or progress shift silently | Compatibility tests compare values from the new path against the recorded feature-001 expectations. |
| Expansion and materialization drift | A test asserts set equality over the window. |
| Daylight saving duplicates or drops a day | Calendar-date iteration plus explicit spring and autumn tests. |
| Materialization degrades with scale | Query-count assertion over 50 routines and a full window. |
