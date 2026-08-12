# Implementation Plan: Planner and Day Planning

**Feature ID**: `009-planner-day`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/openapi.yaml](contracts/openapi.yaml) · **Quickstart**: [quickstart.md](quickstart.md)

## Summary

Add one day surface assembled on read from three registered sources, one owned fact (the time block),
reschedule and skip routed back to the modules that own them, and a scheduler that keeps the
materialization window ahead of the user.

## Technical Context

- Laravel 12 / PHP 8.4, Vue 3 / TypeScript. No new dependency.
- One additive migration. One new `scheduler` service in the local deployment compose.

## Architecture

```
apps/api/app/
  Contracts/SchedulableSource.php        name() + entriesFor(user, date)
  Support/PlannerEntry.php               read-only projection, never persisted
  Services/Planner/SourceRegistry.php    the registered sources, in display order
  Services/Planner/RoutineOccurrenceSource.php
  Services/Planner/StorageItemSource.php
  Services/Planner/TimeBlockSource.php
  Services/Planner/DayAssembler.php      ordering, window state
  Models/TimeBlock.php
  Http/Controllers/PlannerController.php day read, reschedule, skip
  Http/Controllers/TimeBlockController.php
```

**Boundaries**

- A source reads; it never writes. Every planner action is routed to the endpoint that owns the record,
  so ownership rules and validation stay where they already are.
- `DayAssembler` owns ordering and the window answer, nothing else.
- Planner persists only `time_blocks`.

## Architecture Gate Answers

1. **Owner**: Planner owns time blocks and the reschedule pointer. Routine completion stays with feature
   001/006; item state stays with feature 008.
2. **Inputs**: the day defaults to the user's today from Profile; nothing is copied.
3. **Time**: days are `Y-m-d`; block times are local wall-clock times on that day; instants stay UTC.
4. **Scheduling**: uses the feature 006 engine. No second schedule table; a recurring block would become
   a rule owner, and no case needs one yet.
5. **Cross-module links**: one direction — Planner reads modules and calls their endpoints. Nothing
   points back at Planner.
6. **Evolution**: additive; rollback drops one table and one column.
7. **Contracts**: endpoints, OpenAPI, typed frontend payloads and browser coverage change together.
8. **Aggregates**: the day is assembled on read; progress and streaks remain owned by their modules.
9. **Privacy**: `user_id` on the new table; every source scopes to the owner.
10. **Deferral**: reminders (010), calendar sync (024), further sources with their own features,
    drag-and-drop and recurring blocks until a case exists.

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I | Pass | Contract authored first. |
| II | Pass | Answers recurrence-engine open question 6 and records it there. |
| III | Pass | The source contract has three implementations on day one. |
| IV | Pass | Deterministic ordering and refusals; no AI, no auto-planning. |
| V | Pass | Ownership on the new table and inside every source query. |
| VI | Pass | Migration, contract, compatibility and browser tests in the same change. |

**Accepted deviations**: none.

## Phases

| Phase | Content |
|---|---|
| 1 Setup | Fixtures; the entry value object and the contract. |
| 2 Foundational | Migration, `TimeBlock`, the registry. |
| 3 US1 | The three sources, assembly, ordering, window state, day endpoint. |
| 4 US2 | Reschedule and skip, with their refusals; Storage move routed onward. |
| 5 US3 | Time-block endpoints and validation. |
| 6 US4/US5 | Tomorrow surface; scheduled materialization and the scheduler service. |
| 7 Interface | Planner screen, navigation, responsive and keyboard behaviour. |
| 8 Polish | OpenAPI plus its guard, changelog, design-doc update, full gate, evidence. |

## Risks

| Risk | Mitigation |
|---|---|
| Planner becomes a second owner of tasks | Sources are read-only; actions are routed to owning endpoints, asserted by tests that check the owning table changed. |
| Reschedule and materialization fight each other | `occurrence_date` is never overwritten; a materialization test asserts a rescheduled occurrence survives. |
| Skip diverges from Today | Skip writes the same routine log; a test compares the row Planner produces with the row Today produces. |
| A day read degrades with entry count | One query per source, asserted by a query-count test. |
| The window silently stops advancing | A scheduler service plus a test that the command is registered on the schedule. |
