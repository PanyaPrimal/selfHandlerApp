# Requirements Checklist: Unified Recurrence with Routine Migration

**Feature ID**: `006-unified-recurrence` · **Reviewed**: 2026-08-12

## Specification quality

- [X] Every requirement is testable through a database row, an API response, an expansion result or a
      rendered behaviour
- [X] Scope boundaries name what is excluded and what event brings it back
- [X] Success criteria are measurable and mapped to at least one requirement
- [X] Clarifications resolve every decision that would otherwise change scope: cutover strategy, the
      source of truth for scheduling, the purpose of occurrences, where completion lives, the window,
      rule-edit behaviour, the schedule lock, and the supported frequencies

## Requirement coverage

| Area | Requirements | Covered by |
|---|---|---|
| Model and schema | FR-001-FR-007 | T003-T006 |
| Expansion | FR-008-FR-012 | T007, T010, T011, T015, T016 |
| Materialization | FR-013-FR-019 | T017, T019, T020, T022, T023 |
| Facts | FR-020-FR-022 | T018, T021, T023 |
| Compatibility | FR-023-FR-027 | T003, T008, T009, T012-T014 |
| Interface | FR-028-FR-030 | T024, T025 |

- [X] Every functional requirement maps to at least one task
- [X] Every task names at least one requirement or success criterion
- [X] Every user story has an independent test and automated coverage

## Constitution compliance

- [X] I — specification precedes implementation
- [X] II — the recurrence design document is updated with the resolved open question in the same change
- [X] III — only the two frequencies in current use; every other design field deferred with a trigger
- [X] IV — expansion is deterministic; no AI
- [X] V — `user_id` on both new tables from the first migration; ownership on every query
- [X] VI — migration, unit, API, compatibility and browser coverage move with the code

## Migration safety

- [X] Backfill is asserted before any drop
- [X] `down()` restores the previous shape from the rules
- [X] A data-bearing database is part of the completion gate
- [X] No historical migration is edited

## Risk review

- [X] Behaviour drift is caught by feature-001 suites that predate this change
- [X] Expansion/materialization drift is caught by a set-equality assertion
- [X] Daylight saving is covered in both directions
- [X] Query growth is bounded by an explicit assertion
- [X] The derived occurrence status is recorded as accepted deviation AD-1 with a reconciliation path

## Open items

None.
