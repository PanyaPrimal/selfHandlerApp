# Requirements Checklist: Planner and Day Planning

**Feature ID**: `009-planner-day` · **Reviewed**: 2026-08-12

## Specification quality

- [X] Every requirement is testable through a stored row, an API response or a rendered behaviour
- [X] Scope boundaries name what is excluded and which feature brings it back
- [X] Success criteria are measurable and mapped to requirements
- [X] Clarifications resolve the read boundary, what Planner owns, skip versus reschedule, how a
      reschedule is stored, Storage item semantics, the scheduler and reminders

## Requirement coverage

| Area | Requirements | Covered by |
|---|---|---|
| The boundary | FR-001-FR-006 | T002, T006-T011 |
| The day | FR-007-FR-010 | T007, T010, T011 |
| Reschedule and skip | FR-011-FR-017 | T012-T015 |
| Time blocks | FR-018-FR-020 | T016, T017 |
| Window | FR-021-FR-022 | T015, T018, T019 |
| Contracts and interface | FR-023-FR-028 | T020-T025 |

- [X] Every functional requirement maps to at least one task
- [X] Every task names at least one requirement or success criterion
- [X] Every user story has an independent test

## Constitution compliance

- [X] I — specification precedes implementation
- [X] II — answers `recurrence-engine.md` open question 6 and updates it in the same change
- [X] III — the source contract ships with three implementations
- [X] IV — deterministic ordering and refusals; no AI, no automatic planning
- [X] V — ownership on the new table and inside every source query
- [X] VI — migration, contract, compatibility and browser coverage move together

## Boundary review

- [X] No source writes; every action routes to the owning module
- [X] Nothing owned by another module is copied or cached
- [X] `occurrence_date` is never overwritten
- [X] Skip reuses the existing routine log rather than inventing a state
- [X] Today, progress and streak behaviour is asserted unchanged

## Open items

None.
