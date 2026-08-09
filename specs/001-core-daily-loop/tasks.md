---

description: "Dependency-ordered implementation tasks for the SelfHandler core daily loop"
---

# Tasks: Core Daily Loop

> **Authentication supersession (2026-08-09):** When feature `003-multi-user-auth` is present, execute
> these domain tasks against its authenticated account boundary. Do not recreate the removed
> `CurrentUser` local/testing fallback; feature 001 still does not independently implement auth UI.

**Input**: Design documents from `specs/001-core-daily-loop/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/openapi.yaml](contracts/openapi.yaml),
[quickstart.md](quickstart.md)

**Tests**: Required by the project constitution and the feature success criteria. Within each story,
write the listed tests first, confirm they fail for the missing behavior, then implement.

**Organization**: Tasks are grouped by user story so each story can be delivered and tested as a
distinct increment after the shared foundation is complete.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it changes different files and has no unmet dependency.
- **[Story]**: Maps work to the corresponding prioritized story in `spec.md`.
- Every task names the concrete repository path it changes or validates.

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Align the existing prototype configuration and test layout with this feature.

- [ ] T001 Keep `APP_TIMEZONE=UTC` and expose `SELFHANDLER_TIMEZONE` through `apps/api/.env.example` and `apps/api/config/selfhandler.php` (FR-017)
- [ ] T002 [P] Add shared user/domain fixture helpers in `apps/api/tests/Feature/CoreDailyLoop/CoreDailyLoopTestCase.php` and remove the placeholder example tests under `apps/api/tests/`
- [ ] T003 [P] Extract reusable console/page-error collection from `apps/web/e2e/mvp-flow.spec.ts` into `apps/web/e2e/core-daily-loop/support.ts` before splitting story specs

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Establish schema, ownership, and shared contract foundations required by every story.

**Critical**: No user-story implementation starts until this phase is complete.

- [ ] T004 Rewrite the prototype domain migration in `apps/api/database/migrations/2026_06_21_000000_create_mvp_domain_tables.php` to add `is_archived`/`archived_at`, normalize `routine_weekdays`, and retain user-scoped unique keys (FR-002, FR-016, FR-020)
- [ ] T005 [P] Add normalized weekday persistence in `apps/api/app/Models/RoutineWeekday.php` and `apps/api/app/ValueObjects/WeekdayCode.php` (FR-002, FR-004)
- [ ] T006 [P] Add the reusable ownership boundary in `apps/api/app/Support/UserOwned.php` and apply it to domain models in `apps/api/app/Models/` (FR-016)
- [ ] T007 Add cross-user read/write/link rejection tests in `apps/api/tests/Feature/CoreDailyLoop/OwnershipBoundaryTest.php` before controller changes (FR-016, SC-006)
- [ ] T008 Update shared JSON types and error payload handling in `apps/web/src/api/types.ts` and `apps/web/src/api/client.ts` to match `specs/001-core-daily-loop/contracts/openapi.yaml` (FR-019)

**Checkpoint**: Schema, ownership, configured date boundary, and typed error handling are ready.

---

## Phase 3: User Story 1 - Plan and Complete Today's Routines (Priority: P1) MVP

**Goal**: Let the user manage simple schedules and complete a durable daily checklist.

**Independent Test**: Create a weekday routine, open a matching day, mark it done, clear/change the
state, and confirm the item and completion summary survive reload without duplicates.

### Tests for User Story 1

- [ ] T009 [P] [US1] Add schedule, post-history schedule immutability, lifecycle, state-transition, idempotency, and Today contract tests in `apps/api/tests/Feature/CoreDailyLoop/RoutineApiTest.php` (FR-001-FR-007, FR-020, SC-007)
- [ ] T010 [P] [US1] Add daily/weekday/start/end/archive scheduling unit tests in `apps/api/tests/Unit/CoreDailyLoop/RoutineScheduleServiceTest.php` (FR-002-FR-004, FR-017)

### Implementation for User Story 1

- [ ] T011 [US1] Implement normalized schedule evaluation in `apps/api/app/Services/RoutineScheduleService.php` and update `apps/api/app/Models/Routine.php` relationships/casts (FR-002-FR-004, FR-017)
- [ ] T012 [US1] Complete routine create/edit/pause/archive/restore, `archived_at`, post-history schedule immutability, and ownership validation in `apps/api/app/Http/Controllers/RoutineController.php` and `apps/api/routes/api.php` (FR-001-FR-003, FR-016, FR-020)
- [ ] T013 [US1] Implement done/skipped upsert plus idempotent clear-to-pending in `apps/api/app/Http/Controllers/RoutineLogController.php` and `apps/api/routes/api.php` (FR-005-FR-006, SC-007)
- [ ] T014 [US1] Build the scheduled checklist and selected-day summary in `apps/api/app/Http/Controllers/TodayController.php` (FR-004, FR-007, FR-017)
- [ ] T015 [US1] Complete typed routine CRUD, archive/restore, mark, and clear client operations in `apps/web/src/api/client.ts` and `apps/web/src/api/types.ts` (FR-001-FR-007)
- [ ] T016 [US1] Implement routine edit/schedule/archive/restore states in `apps/web/src/views/RoutinesView.vue` and durable done/skip/pending interactions in `apps/web/src/views/TodayView.vue` (FR-001-FR-007, FR-018-FR-019)
- [ ] T017 [US1] Add desktop and phone P1 journeys in `apps/web/e2e/core-daily-loop/routine-flow.spec.ts` (SC-001, SC-002, SC-005, SC-007, SC-008)

**Checkpoint**: User Story 1 is independently usable and is the recommended first implementation stop.

---

## Phase 4: User Story 2 - Complete an Evening Review (Priority: P2)

**Goal**: Let the user create or update one validated reflection per calendar date.

**Independent Test**: Save bounded ratings and reflections for any date, reload them, update the same
review, and verify Today reports completion.

### Tests for User Story 2

- [ ] T018 [P] [US2] Add one-per-date, validation, upsert, ownership, and Today-integration tests in `apps/api/tests/Feature/CoreDailyLoop/DailyReviewApiTest.php` (FR-011-FR-013, FR-016, SC-003)

### Implementation for User Story 2

- [ ] T019 [US2] Complete bounded rating/text validation and completion-preserving upsert behavior in `apps/api/app/Http/Controllers/DailyReviewController.php` and `apps/api/app/Models/DailyReview.php` (FR-011-FR-013, FR-017)
- [ ] T020 [P] [US2] Complete typed review loading/saving/error contracts in `apps/web/src/api/client.ts` and `apps/web/src/api/types.ts` (FR-011-FR-013, FR-019)
- [ ] T021 [US2] Implement loading, unsaved, saving, saved, validation, retry, and restored-value states in `apps/web/src/views/ReviewView.vue` and review completion context in `apps/web/src/views/TodayView.vue` (FR-013, FR-018-FR-019)
- [ ] T022 [US2] Add desktop and phone P2 journeys in `apps/web/e2e/core-daily-loop/review-flow.spec.ts` (SC-003, SC-005, SC-008)

**Checkpoint**: Review works independently and integrates with, but does not own, Today.

---

## Phase 5: User Story 3 - Connect Routines to Goals (Priority: P3)

**Goal**: Let the user manage general goals and show active purpose beside scheduled routines.

**Independent Test**: Create a goal and routine, link/unlink them idempotently, verify active Today
context, and confirm inactive or archived goals disappear without losing history.

### Tests for User Story 3

- [ ] T023 [P] [US3] Add goal lifecycle, archive, link/unlink, cross-owner, and active-context tests in `apps/api/tests/Feature/CoreDailyLoop/GoalApiTest.php` (FR-008-FR-010, FR-016, FR-020, SC-007)

### Implementation for User Story 3

- [ ] T024 [US3] Complete goal lifecycle and archive/restore behavior in `apps/api/app/Models/Goal.php` and `apps/api/app/Http/Controllers/GoalController.php` (FR-008, FR-020)
- [ ] T025 [US3] Enforce owner-matched idempotent link/unlink behavior in `apps/api/app/Http/Controllers/GoalController.php` and `apps/api/routes/api.php` (FR-009, FR-016, SC-007)
- [ ] T026 [US3] Filter Today goal context to active current-user relationships in `apps/api/app/Http/Controllers/TodayController.php` (FR-010, FR-016)
- [ ] T027 [P] [US3] Add typed goal lifecycle and link/unlink operations in `apps/web/src/api/client.ts` and `apps/web/src/api/types.ts` (FR-008-FR-010)
- [ ] T028 [US3] Implement goal edit/archive/restore and routine-link management in `apps/web/src/views/GoalsView.vue`, plus active context in `apps/web/src/views/TodayView.vue` (FR-008-FR-010, FR-018-FR-019)
- [ ] T029 [US3] Add desktop and phone P3 journeys in `apps/web/e2e/core-daily-loop/goal-flow.spec.ts` (SC-005, SC-007, SC-008)

**Checkpoint**: Goals add optional context without becoming a dependency of the routine loop.

---

## Phase 6: User Story 4 - Understand Recent Progress (Priority: P4)

**Goal**: Show correct selected-day completion, per-routine streaks, and seven-day completion.

**Independent Test**: Prepare a known seven-day history and compare every displayed number with the
manual calculation, including a period with no scheduled occurrences.

### Tests for User Story 4

- [ ] T030 [P] [US4] Add streak, current-day pending, skipped, bounded-window, empty-period, and 500-routine/one-year-history regression tests in `apps/api/tests/Unit/CoreDailyLoop/RoutineProgressServiceTest.php` (FR-014-FR-015, SC-004)
- [ ] T031 [P] [US4] Add Today progress response and ownership tests in `apps/api/tests/Feature/CoreDailyLoop/ProgressApiTest.php` (FR-014-FR-017, SC-004, SC-006)

### Implementation for User Story 4

- [ ] T032 [US4] Implement bounded on-demand summary and scheduled-occurrence streak calculations in `apps/api/app/Services/RoutineProgressService.php` (FR-014-FR-015, FR-017)
- [ ] T033 [US4] Extend the Today contract with streak and seven-day progress data in `apps/api/app/Http/Controllers/TodayController.php` and `apps/web/src/api/types.ts` (FR-014-FR-015)
- [ ] T034 [US4] Add accessible progress and empty-state presentation in `apps/web/src/components/ProgressSummary.vue` and `apps/web/src/views/TodayView.vue` (FR-015, FR-018-FR-019)
- [ ] T035 [US4] Add known-history and empty-period browser journeys in `apps/web/e2e/core-daily-loop/progress-flow.spec.ts` (SC-004, SC-005, SC-008)

**Checkpoint**: All four stories are independently verifiable and compose into the full daily loop.

---

## Phase 7: Polish and Cross-Cutting Validation

**Purpose**: Verify the complete contract, accessibility, responsiveness, and documentation without
expanding product scope.

- [ ] T036 [P] Add shared explicit loading/empty/error/retry rendering in `apps/web/src/components/AsyncState.vue` and adopt it across `apps/web/src/views/` (FR-019, SC-008)
- [ ] T037 Review phone/desktop keyboard, label, focus, and overflow behavior in `apps/web/src/style.css` and all files under `apps/web/src/views/` (FR-018, SC-005)
- [ ] T038 Reconcile implemented request/response shapes with `specs/001-core-daily-loop/contracts/openapi.yaml` and frontend declarations in `apps/web/src/api/types.ts` (Constitution VI)
- [ ] T039 Run backend tests, frontend typecheck/build, and Playwright suites from `specs/001-core-daily-loop/quickstart.md`, resolving all failures before completion
- [ ] T040 Update implementation status and any accepted contract deviations in `specs/001-core-daily-loop/plan.md` and `docs/MVP_TECHNICAL_DESIGN.md`

---

## Dependencies and Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: Starts immediately on the current user-managed Git branch.
- **Foundational (Phase 2)**: Depends on Setup and blocks every user story.
- **US1 (Phase 3)**: Depends only on Foundational and is the MVP delivery.
- **US2 (Phase 4)**: Depends only on Foundational; its Today indicator integrates after US1 when both
  are selected for delivery.
- **US3 (Phase 5)**: Depends only on Foundational; its Today context integrates after US1 when both
  are selected for delivery.
- **US4 (Phase 6)**: Depends on the Routine/Routine Log foundation and can be developed with fixtures;
  full browser validation assumes US1.
- **Polish (Phase 7)**: Depends on every story selected for the release.

### Within Each User Story

1. Write the listed automated tests and confirm the new assertions fail.
2. Complete model/service behavior.
3. Complete route/controller contracts.
4. Update TypeScript types and client calls in the same contract change.
5. Complete UI behavior and story-specific browser coverage.
6. Stop at the checkpoint and validate the story independently.

### Parallel Opportunities

- T002 and T003 can run in parallel after T001 begins because they affect separate applications.
- T005 and T006 can run in parallel after the migration shape in T004 is agreed.
- Within US1, T009 and T010 can be authored in parallel; backend and frontend implementation become
  sequential at their shared contract boundary.
- US2 and US3 can proceed in parallel after Foundational because they use separate primary entities
  and separate browser specs; coordinate their small Today/client integrations.
- T030 and T031 can be authored in parallel, followed by T032-T035 in dependency order.
- T036 can proceed in parallel with backend-only final checks before T037-T040.

## Parallel Example: User Stories 2 and 3

```text
Task: T018 [US2] Define review API behavior in DailyReviewApiTest.php
Task: T023 [US3] Define goal/link API behavior in GoalApiTest.php

After those contracts fail as expected:
Task: T019 [US2] Implement DailyReview model/controller behavior
Task: T024-T025 [US3] Implement Goal lifecycle and link behavior
```

## Implementation Strategy

### MVP First: User Story 1 Only

1. Complete Setup and Foundational phases.
2. Complete T009-T017 for the daily routine loop.
3. Run backend, type/build, and P1 browser checks.
4. Stop for product validation before adding review, goals, or progress.

### Incremental Delivery

1. Foundation -> schema, ownership, timezone, typed errors.
2. US1 -> useful routine checklist MVP.
3. US2 -> closes the day with a reflection.
4. US3 -> adds optional purpose and goal context.
5. US4 -> adds recent deterministic feedback.
6. Polish -> verifies all selected stories as one release.

## Notes

- Existing prototype code is reusable evidence, not a completed task.
- `[P]` never means two tasks may edit the same file without coordination.
- Do not install the Spec Kit Git extension or create/switch branches while executing this list.
- Do not introduce production authentication, recurrence, notifications, analytics rollups, or AI
  while implementing this feature.
