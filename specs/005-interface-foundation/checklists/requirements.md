# Requirements Checklist: Interface Foundation and User Changelog

**Feature ID**: `005-interface-foundation` · **Reviewed**: 2026-08-12

## Specification quality

- [X] Every requirement is testable through a rendered behaviour, a keyboard operation, a request body
      or a repository check
- [X] No requirement prescribes an implementation detail that the plan is not free to choose
- [X] Scope boundaries name what is excluded, not only what is included
- [X] Success criteria are measurable and mapped to at least one requirement
- [X] Clarifications resolve every decision that would otherwise change scope: dependency choice,
      accepted native control, mobile navigation shape, content location, content language, and
      nullable-date behaviour

## Requirement coverage

| Area | Requirements | Covered by |
|---|---|---|
| Control set | FR-001-FR-010 | T004-T022 |
| Accessibility | FR-011-FR-017 | T006, T010, T014-T019, T022 |
| Calendar/time invariants | FR-018-FR-022 | T005, T011, T016, T017, T026 |
| Screen migration | FR-023-FR-027 | T023-T032, T042 |
| Changelog | FR-028-FR-033 | T033-T037 |
| Navigation | FR-034-FR-038 | T038-T041 |

- [X] Every functional requirement maps to at least one task
- [X] Every task names at least one requirement or success criterion
- [X] Every user story has an independent test and at least one browser scenario

## Constitution compliance

- [X] I — the specification precedes implementation
- [X] II — the roadmap is updated in the same change; no design document is contradicted
- [X] III — every component has a named current consumer (data-model.md §5); speculative controls are
      excluded
- [X] IV — no AI, no non-deterministic behaviour
- [X] V — no persistence, no user data, no secrets
- [X] VI — typed contracts and browser tests change with the code

## Risk review

- [X] Hand-written ARIA risk is acknowledged and mitigated by keyboard-only coverage on both projects
- [X] Payload-regression risk is mitigated by explicit request-body assertions
- [X] Viewport risk is mitigated by bounding-box assertions at exactly 390×844
- [X] Date-drift risk is mitigated by a west-of-UTC browser scenario and a pure calendar module
- [X] Accepted deviations (AD-1, AD-2, AD-3) are recorded with rationale in `plan.md` and `tasks.md`

## Open items

None.
