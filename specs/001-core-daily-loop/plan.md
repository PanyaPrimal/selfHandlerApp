# Implementation Plan: Core Daily Loop

> **Authentication supersession (2026-08-09):** Feature `003-multi-user-auth` supersedes this plan's
> temporary implicit-user implementation choice. If 003 is implemented first, reuse its authenticated
> account boundary and MUST NOT recreate `CurrentUser` or any local/testing fallback. Authentication
> remains outside the product scope of feature 001 itself.

**Feature**: `001-core-daily-loop` | **Date**: 2026-08-07 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-core-daily-loop/spec.md`

## Summary

Deliver the first usable SelfHandler slice: users manage simple daily or weekday routines, handle
today's checklist, complete one evening review per day, connect routines to goals, and see accurate
today/streak/seven-day progress. Reuse the existing Laravel/Vue prototype where it matches the spec,
close its lifecycle, ownership, timezone, progress, error-state, and testing gaps, and avoid bringing
the future recurrence, notification, analytics-rollup, authentication-UI, or AI systems into scope.

## Technical Context

**Language/Version**: PHP `^8.2` (local CLI 8.4) and TypeScript `~6.0`

**Primary Dependencies**: Laravel `^11.31`, Eloquent/Carbon, Vue `^3.5`, Vue Router `^5.1`, Vite `^8`

**Storage**: MySQL 8 for normal use; isolated SQLite database for automated browser tests

**Testing**: PHPUnit 11 feature/unit tests, `vue-tsc`, Vite production build, Playwright 1.61

**Target Platform**: Responsive online web application on modern phone and desktop browsers;
Windows/Open Server is the primary local backend environment

**Project Type**: Monorepo web application with a REST API and a future Capacitor wrapper

**Performance Goals**: State changes and summaries visibly agree within two seconds; Today and
seven-day progress operate on bounded date windows and remain interactive with 500 routines and one
year of routine-log history for one user

**Constraints**: Online-only; user-owned records from day one; Laravel remains on UTC while one
configured SelfHandler calendar timezone is used until Profile exists; no full recurrence engine, notification delivery, daily
rollups, production sign-in UI, or feature-branch automation

**Scale/Scope**: One personal user in the first delivery, a small user-owned domain model, four route-level web
screens, one Today aggregate, and a seven-calendar-day progress window

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
│   │   ├── CurrentUser.php
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
2. Add domain archive flags separately from soft deletion and make uniqueness/ownership constraints
   explicit for every relationship.
3. Centralize current-user query scoping in a reusable model concern, while retaining the temporary
   local/testing resolver and production `401` behavior.
4. Keep Laravel/storage timestamps on UTC, add a separate configurable SelfHandler calendar timezone,
   and use it consistently at date-input boundaries.

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

The existing REST paths remain stable where possible. Add archive/restore behavior through normal
resource updates, add deletion of a daily log to return it to pending, and extend Today with a
`progress` object. Update `apps/web/src/api/types.ts`, client functions, and backend feature tests in
the same tasks as each contract change. See [contracts/openapi.yaml](contracts/openapi.yaml).

## Complexity Tracking

No constitution violations or complexity exceptions are required.
