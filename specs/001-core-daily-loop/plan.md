# Implementation Plan: Core Daily Loop

> **Implementation status (2026-08-11):** T001-T040 are implemented and green on top of the
> authenticated account boundary delivered by `003-multi-user-auth`. Feature 001 does not recreate
> authentication, an implicit user, or a local/testing identity fallback.

**Feature**: `001-core-daily-loop` | **Date**: 2026-08-07 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-core-daily-loop/spec.md`

## Summary

Deliver the first usable SelfHandler slice: users manage simple daily or weekday routines, handle
today's checklist, complete one evening review per day, connect routines to goals, and see accurate
today/streak/seven-day progress. Reuse the existing Laravel/Vue prototype where it matches the spec,
close its lifecycle, ownership, timezone, progress, error-state, and testing gaps, and avoid bringing
recurrence, notification, analytics-rollup, authentication changes, or AI systems into scope.

## Technical Context

**Language/Version**: PHP `^8.4` and TypeScript `~6.0`

**Primary Dependencies**: Laravel `^12.61.1`, Eloquent/Carbon, Vue `^3.5`, Vue Router `^5.1`, Vite `^8`

**Storage**: MySQL 8 for normal use; isolated SQLite database for automated browser tests

**Testing**: PHPUnit 11 feature/unit tests, `vue-tsc`, Vite production build, Playwright 1.61

**Target Platform**: Responsive online web application on modern phone and desktop browsers;
Windows/Open Server is the primary local backend environment

**Project Type**: Monorepo web application with a REST API and a future Capacitor wrapper

**Performance Goals**: State changes and summaries visibly agree within two seconds; Today and
seven-day progress operate on bounded date windows and remain interactive with 500 routines and one
year of routine-log history for one user

**Constraints**: Online-only; every domain request requires an authenticated Sanctum session and
every record is user-owned; Laravel remains on UTC while one configured SelfHandler calendar timezone
is used until Profile exists; no full recurrence engine, notification delivery, daily rollups,
authentication expansion, or feature-branch automation

**Scale/Scope**: A private personal workspace per authenticated account, a small user-owned domain
model, four route-level web screens, one Today aggregate, and a seven-calendar-day progress window

## Constitution Check

*GATE: Passed before research and re-checked after Phase 1 design.*

- **Specifications Before Implementation**: PASS. `spec.md` defines prioritized, independently
  testable stories and bounded success criteria before application changes.
- **Vision and Delivery Sources**: PASS. The plan references `docs/design/` decisions while keeping
  the intentionally smaller feature contract in `specs/001-core-daily-loop/`.
- **Thin Vertical Slices**: PASS. P1 works without reviews, goals, or progress enhancements; the full
  recurrence engine and unrelated modules remain excluded.
- **Deterministic Core**: PASS. Scheduling, completion, and streak calculations are deterministic;
  no LLM dependency is introduced.
- **User-Owned Data and Privacy**: PASS. Ownership is enforced in persistence, route resolution,
  relationships, tests, and unique constraints; date handling follows the configured timezone.
- **Contracts and Tests**: PASS. The design includes an explicit HTTP contract, backend feature/unit
  tests, typed frontend consumers, build/type checks, and desktop/mobile browser coverage.
- **Branch Governance**: PASS. No Git extension is installed and all work remains on the user's
  currently checked-out branch.

### Post-Design Re-check

PASS. Phase 1 adds no deferred platform, speculative abstraction, external service, or constitution
exception. `UserOwned` is justified by four current domain models; schedule/progress services have
current consumers and isolated deterministic tests.

## Project Structure

### Documentation (this feature)

```text
specs/001-core-daily-loop/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── openapi.yaml
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
apps/api/
├── app/
│   ├── Http/Controllers/
│   │   ├── DailyReviewController.php
│   │   ├── GoalController.php
│   │   ├── RoutineController.php
│   │   ├── RoutineLogController.php
│   │   └── TodayController.php
│   ├── Models/
│   │   ├── DailyReview.php
│   │   ├── Goal.php
│   │   ├── Routine.php
│   │   ├── RoutineWeekday.php
│   │   └── RoutineLog.php
│   ├── Services/
│   │   ├── RoutineProgressService.php
│   │   └── RoutineScheduleService.php
│   ├── Support/
│   │   └── UserOwned.php
│   └── ValueObjects/
│       └── WeekdayCode.php
├── config/
│   ├── app.php
│   └── selfhandler.php
├── database/migrations/
├── routes/api.php
└── tests/
    ├── Feature/CoreDailyLoop/
    │   ├── CoreDailyLoopTestCase.php
    │   ├── DailyReviewApiTest.php
    │   ├── GoalApiTest.php
    │   ├── OwnershipBoundaryTest.php
    │   ├── ProgressApiTest.php
    │   └── RoutineApiTest.php
    └── Unit/CoreDailyLoop/
        ├── RoutineProgressServiceTest.php
        └── RoutineScheduleServiceTest.php

apps/web/
├── src/
│   ├── api/
│   │   ├── client.ts
│   │   └── types.ts
│   ├── components/
│   │   ├── AsyncState.vue
│   │   └── ProgressSummary.vue
│   └── views/
│       ├── GoalsView.vue
│       ├── ReviewView.vue
│       ├── RoutinesView.vue
│       └── TodayView.vue
└── e2e/core-daily-loop/
    ├── goal-flow.spec.ts
    ├── progress-flow.spec.ts
    ├── review-flow.spec.ts
    ├── routine-flow.spec.ts
    └── support.ts
```

**Structure Decision**: Keep the existing `apps/api` and `apps/web` delivery units. Add only two
domain services and small reusable UI components where multiple current screens need the behavior.
Do not create a shared package, repository layer, mobile implementation, or separate analytics store.

## Implementation Strategy

### Foundation

1. Treat the existing migration and models as a prototype baseline rather than replace the monorepo.
2. Upgrade that baseline through an additive migration: normalize routine weekdays, preserve existing
   data, add domain archive state separately from soft deletion, and replace MySQL indexes in safe order.
3. Require `auth:sanctum` for every domain route, derive the owner from the authenticated request, and
   use `ownedBy()` for reads and relationship lookups. Cross-owner identifiers resolve as `404`.
4. Keep Laravel/storage timestamps on UTC, add a separate configurable SelfHandler calendar timezone,
   and use it consistently at strict `Y-m-d` input and selected-day boundaries.

### User Story Delivery

1. **P1 Daily routines**: close routine lifecycle, schedule validation, Today filtering, done/skip/
   pending transitions, resilient UI states, and ownership tests.
2. **P2 Daily review**: preserve one-per-day upsert behavior, validate bounded ratings, and integrate
   completed state with Today.
3. **P3 Goal context**: complete goal lifecycle plus link/unlink contracts and hide inactive context
   from Today without removing history.
4. **P4 Progress**: compute selected-day summary, current scheduled-occurrence streaks, and a bounded
   seven-day completion result on demand.

### Contract Evolution

The implemented contract exposes list/create/`PATCH` for routines and goals; pause/resume,
archive/restore, and goal lifecycle transitions are explicit update fields rather than overloaded
resource deletion. Routine outcomes use idempotent `PUT`, with `DELETE` limited to one dated log to
return that occurrence to pending. Today combines the selected-date checklist, review state, active
goal context, selected-day summary, per-routine streaks, and a seven-day progress summary. The OpenAPI
contract, backend feature tests, client operations, and TypeScript declarations evolve together. See
[contracts/openapi.yaml](contracts/openapi.yaml).

## Implemented Architecture and Status

T001-T008 established the UTC/calendar-timezone split, additive schema alignment, normalized
`routine_weekdays`, reusable `ownedBy()` boundary, explicit authenticated fixtures, typed API errors,
and isolated browser-test support.

T009-T017 delivered the independently usable routine loop: validated daily/weekday schedules,
post-history schedule locking, pause/archive/restore behavior, idempotent done/skipped/pending state,
historical occurrence preservation, and selected-day summaries.

T018-T022 delivered one review per strict calendar date with bounded fields, first-completion
preservation, recoverable validation/service states, and Today completion context. T023-T029 delivered
server-derived goal lifecycle, owner-matched idempotent routine links, archive/restore behavior, and
active/non-archived Today context. T030-T035 delivered streamed on-demand seven-day progress and
scheduled-occurrence streaks without a rollup table or N+1 query pattern.

Each completed story passed the Laravel suite and formatter, Vue typecheck and production build, and
desktop plus exact 390-pixel Playwright journeys. T036-T040 reconciled shared async presentation,
keyboard/focus/overflow behavior, OpenAPI/types, full-suite evidence, and these design documents. The
final gate passed 105 Laravel tests with 847 assertions and all 24 browser journeys. No deployment,
production migration, or push was part of this feature continuation.

### Accepted Limitation

`is_active` stores only the routine's current paused state; it does not record pause/resume intervals.
Consequently, historical scheduled denominators are evaluated using the current active state and
cannot be reproduced across repeated pause cycles. Correctly versioning those occurrences belongs to
the deferred recurrence/materialized-occurrence design. Archive history remains reproducible through
`archived_at` and retained logs.

## Complexity Tracking

No constitution violations or complexity exceptions are required.
