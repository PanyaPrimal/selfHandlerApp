# Tasks: Body Measurements and Body Goals

**Input**: Design documents from `specs/007-body-measurements/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/api.md`,
`quickstart.md`

**Tests**: Mandatory. This feature stores health data, derives numbers from it, and states a boundary
sourced from published guidance, so ownership, determinism and boundary behaviour all need coverage.

**Organization**: Grouped by independently testable user story.

## Phase 1: Setup

- [X] T001 Add the typed metric vocabulary with units, precision and bounds in `apps/api/app/ValueObjects/BodyMetric.php` (FR-003, FR-002)
- [X] T002 [P] Add shared body fixtures in `apps/api/tests/Feature/Body/BodyTestCase.php` (SC-001)

---

## Phase 2: Foundational (Persistence)

- [X] T003 Write failing schema, ownership and uniqueness tests in `apps/api/tests/Feature/Body/BodyMeasurementApiTest.php` (FR-001, FR-004, SC-001, SC-006)
- [X] T004 Implement the additive migration in `apps/api/database/migrations/2026_08_12_140000_create_body_measurements.php` for `body_measurements`, `body_goal_details` and `goal_milestones` (FR-001, FR-015, FR-019)
- [X] T005 [P] Implement `apps/api/app/Models/BodyMeasurement.php` with ownership and decimal casts (FR-001, FR-002)
- [X] T006 [P] Implement `apps/api/app/Models/BodyGoalDetail.php` and `apps/api/app/Models/GoalMilestone.php` with same-owner guards (FR-015, FR-019, SC-001)

**Checkpoint**: three owned tables, one observation per metric per day.

---

## Phase 3: User Story 1 - Record and Correct Measurements (Priority: P1)

- [X] T007 [P] [US1] Write failing create, correct, delete, bounds, ordering and empty-metric tests in `apps/api/tests/Feature/Body/BodyMeasurementApiTest.php` (FR-004-FR-008, SC-005, SC-006, SC-008)
- [X] T008 [US1] Implement `apps/api/app/Http/Controllers/BodyMeasurementController.php` with bounded reads, upsert-as-correction and delete (FR-004-FR-008)
- [X] T009 [US1] Register the body routes in `apps/api/routes/api.php` behind the existing session guard (FR-026)
- [X] T010 [US1] Assert the Profile baseline is never written by a measurement in `apps/api/tests/Feature/Body/BodyMeasurementApiTest.php` (FR-009)

---

## Phase 4: User Story 2 - Deterministic Trend (Priority: P1)

- [X] T011 [P] [US2] Write failing hand-checked slope, empty, insufficient, order-invariance and post-delete tests in `apps/api/tests/Feature/Body/BodyTrendAndGoalTest.php` (FR-010-FR-014, SC-003, SC-004, SC-005)
- [X] T012 [US2] Implement `apps/api/app/Services/BodyTrendService.php` with least-squares slope per week and explicit states (FR-010-FR-014)

---

## Phase 5: User Stories 3 and 4 - Body Goal, Progress, Milestones, Safe Pace (Priority: P1/P2)

- [X] T013 [P] [US3] Write failing goal-detail, progress, no-observation and milestone-ordering tests in `apps/api/tests/Feature/Body/BodyTrendAndGoalTest.php` (FR-015-FR-020, SC-001)
- [X] T014 [P] [US4] Write failing boundary tests on both sides of the documented rate in `apps/api/tests/Feature/Body/BodyTrendAndGoalTest.php` (FR-021-FR-025, SC-007)
- [X] T015 [US3] Implement `apps/api/app/Services/BodyGoalProgressService.php` for direction-aware progress and derived milestone achievement (FR-017-FR-019)
- [X] T016 [US4] Implement `apps/api/app/Services/SafePaceValidator.php` with the cited loss boundary and the labelled gain limitation (FR-021-FR-025)
- [X] T017 [US3] Implement `apps/api/app/Http/Controllers/BodyGoalController.php` creating the goal and its detail in one transaction and returning warnings (FR-015-FR-023)

---

## Phase 6: User Story 5 - The Screen (Priority: P2)

- [X] T018 [P] [US5] Add failing desktop, 390px, keyboard and unit round-trip scenarios in `apps/web/e2e/body/body-measurements.spec.ts` (FR-026-FR-030, SC-002)
- [X] T019 [US5] Add typed body payloads to `apps/web/src/api/types.ts` and client calls to `apps/web/src/api/client.ts` (contracts)
- [X] T020 [US5] Implement `apps/web/src/views/BodyView.vue` on the feature 005 controls with explicit empty, insufficient and partial states (FR-026-FR-030)
- [X] T021 [US5] Register the `/body` route and add the destination to `apps/web/src/layouts/AppShell.vue` (FR-026, FR-027)
- [X] T022 [US5] Add body styles with no horizontal overflow at 390px in `apps/web/src/style.css` (FR-030)

---

## Phase 7: Polish and Completion Gate

- [X] T023 Add the changelog entry for body measurements in `apps/web/src/content/changelog.ts`
- [X] T024 Reconcile `spec.md`, `plan.md`, `contracts/api.md` and `data-model.md` against the implementation (Constitution VI)
- [X] T027 Publish the machine-readable contract in `specs/007-body-measurements/contracts/openapi.yaml` and hold it against the routes and enums in `apps/api/tests/Feature/Body/BodyOpenApiContractTest.php` (contracts, Constitution VI)
- [X] T025 Run the full gate: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm run build`, both Playwright projects, `git diff --check` (SC-010)
- [X] T026 Add the implementation-evidence section here and mark the roadmap entry complete

---

## Dependencies

- T001 blocks T004, T008, T012 and T016.
- T004 blocks every later phase.
- T012 blocks T015; T015 and T016 block T017.
- T019 blocks T020, which blocks T021.

## Parallel Opportunities

- T005 and T006 are separate model files.
- T007, T011, T013 and T014 are four independent failing surfaces.
- T018 is independent of the backend once the contract is settled.

## Notes

- Values cross the API in canonical base units only.
- No boundary may be invented for a metric that has neither a citation nor a stated product limitation.
- A pace warning never blocks a save and never edits a target.
- Nothing here writes the Profile.
- Mark a task `[X]` only after its behaviour and verification are complete.

---

## Implementation Evidence

**Completed**: 2026-08-12 — 27/27 tasks.

### Delivered

- `BodyMetric`, the one place a canonical unit, display unit, bound or pace boundary is written down.
- `body_measurements`, `body_goal_details` and `goal_milestones`, all user-owned, added by one additive
  migration. `goals` is unchanged apart from accepting `type = 'body'`.
- `BodyTrendService` (least-squares change per week, explicit `empty`/`insufficient`/`ready` states),
  `BodyGoalProgressService` (direction-aware progress, milestone achievement derived at read time) and
  `SafePaceValidator` (one cited boundary, one labelled product limitation, warnings only).
- `GET/PUT/DELETE /api/body/measurements`, `GET /api/body/trend`, and
  `GET/POST/PATCH /api/body/goals`, all behind the existing session guard.
- `/body` screen built on the feature 005 controls, reachable from the navigation, with explicit empty,
  insufficient and no-current-value states and display-unit conversion that does not drift.

### Contract

`contracts/openapi.yaml` (OpenAPI 3.1, 5 paths, 18 schemas) is the machine-readable contract;
`contracts/api.md` remains the prose companion. `BodyOpenApiContractTest` fails when a documented
operation is not a registered route, when a `/body` route is undocumented, or when the documented
metric or direction vocabulary drifts from its enum. The guard was verified by injecting both kinds of
drift and confirming it reports them.

### Defect found by the browser suite

A measurement dated in the future saved successfully but then fell outside the default history window,
so it silently disappeared. An observation is something that already happened, so `measured_on` is now
rejected when it is after the user's own today, with a field-level message and a regression test.

### Accepted deviations

- **AD-1**: the weight-*gain* boundary is this application's own conservative limit of 500 g a week, not
  published guidance, and its message says exactly that. The weight-*loss* boundary is the CDC's stated
  1-2 lb a week. No boundary is invented for any other metric, which a test asserts.
- **AD-2**: test files were consolidated into `BodyMeasurementApiTest` and `BodyTrendAndGoalTest` because
  they share fixtures. Every scenario the task list described exists; the paths above were corrected.

### Gate results (2026-08-12)

Recorded with the final gate for the session; see the repository changelog and
`docs/design/delivery-roadmap.md`.
