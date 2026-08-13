# Specification Quality Checklist: Budget and Recurring Cash Flow

**Purpose**: Validate specification completeness and quality before planning
**Created**: 2026-08-13
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] Six prioritized stories describe independently useful user outcomes
- [x] Acceptance scenarios cover exact money, recurrence, outcomes, aggregates, adapters, and client
- [x] Repository documentation remains English and EN/RU/UK product delivery is explicit
- [x] No implementation task sequence is used as a substitute for requirements

## Requirement Completeness

- [x] Thirty-seven functional requirements are testable and unambiguous
- [x] Twelve measurable success criteria define delivery evidence
- [x] Monthly short-month, overlap, FX, retry, correction, ownership, lifecycle, and bounds are explicit
- [x] Planner/Notifications/Android/shared-client responsibilities are explicit
- [x] Scope, assumptions, dependencies, and adjacent-feature deferrals are explicit
- [x] No unresolved clarification marker remains

## Architecture Readiness

- [x] Finance owns budgets, recurring plan semantics, outcomes, and projections
- [x] Shared recurrence owns schedule identity/expansion and adds only consumer-required monthly fields
- [x] Profile remains the only locale/timezone/base-currency input
- [x] Ledger remains the only actual money truth and accepted transactions remain append-only
- [x] Planner and Notifications consume Finance state through adapters without copying it
- [x] Migration is additive, owner-safe, MySQL-portable, rollback-scoped, and data preserving
- [x] API/OpenAPI/types/client/locales/browser/mobile verification are required together
- [x] Specification is ready for plan/tasks/analyze without material user clarification

## Notes

- Root/child same-month budget overlap is prohibited so consolidated limits cannot double-count.
- Invalid month-days skip short months; clamping would silently change a user's stated payday.
- An actualized occurrence is corrected through the existing reversal ledger, never outcome deletion.
