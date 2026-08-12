# Requirements Checklist: Body Measurements and Body Goals

**Feature ID**: `007-body-measurements` · **Reviewed**: 2026-08-12

## Specification quality

- [X] Every requirement is testable through a stored row, an API response, a computed number or a
      rendered behaviour
- [X] Scope boundaries name what is excluded and which feature brings it back
- [X] Success criteria are measurable and mapped to requirements
- [X] Clarifications resolve fact ownership, metric storage, canonical units, duplicate policy, trend
      method, pace numbers and milestone achievement

## Requirement coverage

| Area | Requirements | Covered by |
|---|---|---|
| Measurements | FR-001-FR-009 | T003-T010 |
| Trend | FR-010-FR-014 | T011, T012 |
| Body goal | FR-015-FR-020 | T013, T015, T017 |
| Safe pace | FR-021-FR-025 | T014, T016, T017 |
| Interface | FR-026-FR-030 | T018-T022 |

- [X] Every functional requirement maps to at least one task
- [X] Every task names at least one requirement or success criterion
- [X] Every user story has an independent test

## Constitution compliance

- [X] I — specification precedes implementation
- [X] II — follows `modules.md` on the measurement log and the typed goal
- [X] III — milestones and the metric vocabulary each have a consumer here
- [X] IV — trend, progress and pace are deterministic; no AI
- [X] V — `user_id` on all three tables; health data confined to its owner
- [X] VI — migration, unit, API and browser coverage move with the code

## Safety review

- [X] The one medical number in the product is cited to a named public health authority
- [X] The one number without a citation is labelled in its own message as a product limitation
- [X] Metrics with neither produce no warning
- [X] Warnings never block a save and never alter a stored target
- [X] Nothing diagnoses, prescribes or recommends

## Open items

None.
