# Specification Quality Checklist: Workouts and Training Goals

**Purpose**: Validate specification completeness and quality before planning
**Created**: 2026-08-13
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details beyond required class-table and shared-owner boundaries
- [x] Focused on independently useful user outcomes and business needs
- [x] Written for product and technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No unresolved `[NEEDS CLARIFICATION]` markers remain
- [x] Six prioritized stories have independent tests and acceptance scenarios
- [x] Thirty-four functional requirements are testable and unambiguous
- [x] Ten success criteria are measurable
- [x] Edge cases cover planned/manual identity, subtypes, recurrence, progression, goals, and ownership
- [x] Scope, assumptions, dependencies, canonical units, and explicit deferrals are stated
- [x] EN/RU/UK localisation surface is explicit
- [x] User ownership/privacy and additive migration boundaries are explicit

## Architecture Readiness

- [x] WorkoutProgram is the recurrence owner and WorkoutSession is the module fact
- [x] Planner/Notifications/Today/Review consume module projections without copied ownership
- [x] Training goals extend the existing Goal aggregate through one typed detail
- [x] Class-table detail separates divergent workout types without STI magic or sparse mega-tables
- [x] Exercise catalogue distinguishes immutable public references from private custom entries
- [x] Progression, records, pace, and goal progress are deterministic derived values
- [x] Existing routine/habit/sleep facts and recurrence dispatch remain explicitly compatible
- [x] Specification is ready for `$speckit-plan`

## Notes

- The canonical design leaves progression schemes, ready-made program sources, and energy accuracy
  open. The slice resolves one transparent linear scheme, private custom programs, and explicit energy
  facts while deferring unverifiable content/formulas.
- Running is delivered as a specialized endurance activity with manual metrics and race goals. Route/
  wearable integrations remain with Attachments/Calendar/Integrations rather than leaking into 015.
