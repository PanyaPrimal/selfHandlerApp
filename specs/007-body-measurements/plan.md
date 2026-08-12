# Implementation Plan: Body Measurements and Body Goals

**Feature ID**: `007-body-measurements`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/api.md](contracts/api.md) · **Quickstart**: [quickstart.md](quickstart.md)

## Summary

Add a user-owned, dated measurement log with a typed metric vocabulary, a deterministic trend, and a
body-composition goal expressed as a typed detail of the existing `Goal`. Present it on a new
authenticated screen built from the feature 005 control set.

## Technical Context

- Laravel 12 / PHP 8.4, Vue 3 / TypeScript. No new dependency.
- Additive migration only. Live rows are untouched; `goals` gains a new accepted `type` value.
- Canonical values are `DECIMAL`, converted for display only.

## Architecture

```
apps/api/app/
  ValueObjects/BodyMetric.php            unit, precision, bounds, pace boundary
  Models/BodyMeasurement.php
  Models/BodyGoalDetail.php
  Models/GoalMilestone.php
  Services/BodyTrendService.php          OLS slope per week, explicit empty/insufficient states
  Services/BodyGoalProgressService.php   progress and milestone achievement, derived
  Services/SafePaceValidator.php         documented boundaries, warning only
  Http/Controllers/BodyMeasurementController.php
  Http/Controllers/BodyGoalController.php

apps/web/src/
  views/BodyView.vue                     entry, history, trend, goal progress
```

**Boundaries**

- `BodyMetric` is the only place a unit, a precision or a bound is written down.
- The Body module owns progress and trend. Analytics and Nutrition will read them, not recompute them.
- The Profile is never written by this feature.
- `SafePaceValidator` returns warnings; it never mutates a goal.

## Architecture Gate Answers

1. **Owner**: Body Measurements owns the observation and every value derived from it. `Goal` keeps the
   lifecycle; the body detail is only its typed extension.
2. **Inputs**: time zone, locale and unit system come from Profile; none is copied.
3. **Time**: `measured_on` is a calendar day defaulted from the user's profile time zone; timestamps
   stay UTC.
4. **Scheduling**: none. Recurring measurement reminders wait for feature 011.
5. **Cross-module links**: one direction — the body detail points at its goal; the goal knows only its
   type.
6. **Evolution**: purely additive; rollback drops the three new tables and nothing else.
7. **Contracts**: new endpoints, typed frontend payloads, and browser coverage in the same change.
8. **Aggregates**: progress and trend are computed by this module.
9. **Privacy**: health data is user-owned, never exposed across accounts, never sent anywhere.
10. **Deferral**: photos wait for feature 021, reminders for 011, analysis for 023 and 026.

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I | Pass | Contract authored first. |
| II | Pass | Follows `modules.md` on the log and the typed goal. |
| III | Pass | Milestones and the metric vocabulary both have a consumer here. |
| IV | Pass | Deterministic arithmetic only; no AI, no interpretation. |
| V | Pass | Ownership on all three tables from the first migration. |
| VI | Pass | Migration, unit, API and browser coverage move with the code. |

**Accepted deviations**

- **AD-1 — the gain boundary is a product limitation, not a citation.** No authority publishes a general
  weekly weight-gain rate. The application applies 500 g/week and says in the message that it is this
  application's own conservative limit. Recorded in research R6.

## Phases

| Phase | Content |
|---|---|
| 1 Setup | Fixtures, factories, the `BodyMetric` vocabulary. |
| 2 Foundational | Migration and models. |
| 3 US1 | Measurement CRUD, correction, deletion, bounds, ordering. |
| 4 US2 | Trend service and its states. |
| 5 US3+US4 | Body goal detail, progress, milestones, safe pace. |
| 6 US5 | The screen, navigation, units, responsive and keyboard behaviour. |
| 7 Polish | Contracts, changelog, full gate, evidence. |

## Risks

| Risk | Mitigation |
|---|---|
| Rounding drift between unit systems | Decimal storage; a round-trip assertion in both directions. |
| A trend that quietly changes shape | Hand-checked fixture and an order-invariance assertion. |
| Medical overreach | One cited boundary, one labelled product limitation, warnings only, no diagnosis. |
| Duplicate observations | Database-level uniqueness plus an explicit correction path. |
| The Profile and the log drifting into one another | Neither writes the other; asserted by test. |
