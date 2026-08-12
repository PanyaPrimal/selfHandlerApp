# Requirements Checklist: Storage Inbox and Quick Capture

**Feature ID**: `008-storage-inbox` · **Reviewed**: 2026-08-12

## Specification quality

- [X] Every requirement is testable through a stored row, an API response or a rendered behaviour
- [X] Scope boundaries name what is excluded and which feature brings it back
- [X] Success criteria are measurable and mapped to requirements
- [X] Clarifications resolve storage shape, the shipped types, the inbox question, nesting depth,
      blocking semantics, tag scope and the container question

## Requirement coverage

| Area | Requirements | Covered by |
|---|---|---|
| Item | FR-001-FR-007 | T003-T011 |
| Hierarchy and blocking | FR-008-FR-013 | T012-T014 |
| Projects and tags | FR-014-FR-020 | T006, T010, T015, T016 |
| Ownership and contracts | FR-021-FR-024 | T003, T005, T022 |
| Interface | FR-025-FR-029 | T017-T021 |

- [X] Every functional requirement maps to at least one task
- [X] Every task names at least one requirement or success criterion
- [X] Every user story has an independent test

## Constitution compliance

- [X] I — specification precedes implementation
- [X] II — follows `data-conventions.md`; resolves two `modules.md` questions and records the open one
- [X] III — no type, container or scope without a current consumer
- [X] IV — manual triage; no AI
- [X] V — `user_id` on all four tables; cross-account relationships refused
- [X] VI — migration, API, contract and browser coverage move with the code

## Risk review

- [X] Structural corruption is bounded by one nesting level plus cycle checks
- [X] Container deletion cannot destroy work (`null on delete`, asserted)
- [X] The blocking rule lives in one service consulted by every closing path
- [X] Counts are derived on read, never cached
- [X] List reads are explicitly bounded with a query-count assertion

## Open items

None. The Project/List container question in `modules.md` stays open on purpose, recorded in research R5.
