# Tasks: Profile and Settings Foundation

**Input**: Design documents from `specs/004-profile-settings/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/openapi.yaml`,
`quickstart.md`

**Tests**: Backend, typed-client, and browser tests are mandatory because this feature changes private
profile data, the current-user contract, and current date/schedule behavior.

**Organization**: Tasks are grouped by independently testable user story. Test tasks precede the
implementation they verify.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it changes a different file and has no incomplete dependency
- **[Story]**: Maps the task to one specification user story
- Every task names concrete repository paths and applicable requirement IDs

## Phase 1: Setup (Shared Test and Configuration Support)

**Purpose**: Establish reusable fixtures and finite option/default definitions before persistence or
story work.

- [X] T001 Add shared authenticated profile fixtures and response assertions in `apps/api/tests/Feature/Profile/ProfileTestCase.php` (FR-001, FR-015-FR-017)
- [X] T002 [P] Add supported locale/unit/currency/tone/formula/sex/activity sets and deterministic profile defaults in `apps/api/config/selfhandler.php` and `apps/api/app/Support/ProfileDefaults.php` (FR-006-FR-010, FR-016)
- [X] T003 [P] Add reusable profile navigation and API interception helpers in `apps/web/e2e/profile/support.ts` (FR-018-FR-019)

---

## Phase 2: Foundational (Persistence and Account Provisioning)

**Purpose**: Guarantee one default profile per existing/new account before any user story reads it.

**⚠️ CRITICAL**: No story implementation starts until additive migration and account provisioning pass.

- [X] T004 Write failing existing-row preservation, one-profile-per-user, and deterministic-default tests in `apps/api/tests/Feature/Profile/ProfileMigrationTest.php` (FR-001, FR-016, SC-006)
- [X] T005 Implement the additive `user_profiles` schema and existing-account backfill in `apps/api/database/migrations/2026_08_11_120000_create_user_profiles_table.php` (FR-001, FR-004, FR-016)
- [X] T006 [P] Add `UserProfile` casts, ownership, completeness, and factory support in `apps/api/app/Models/UserProfile.php` and `apps/api/database/factories/UserProfileFactory.php` (FR-010-FR-012, FR-015, FR-017)
- [X] T007 Write failing registration/default/current-user compatibility tests in `apps/api/tests/Feature/Profile/ProfileProvisioningTest.php` and update safe response assertions in `apps/api/tests/Feature/Auth/AuthTestCase.php` (FR-001, FR-016-FR-017)
- [X] T008 Implement the `User::profile()` boundary, transactional registration provisioning, missing-profile repair, and non-sensitive preference summary in `apps/api/app/Models/User.php`, `apps/api/app/Http/Controllers/AuthController.php`, and `apps/api/app/Http/Resources/UserResource.php` (FR-001, FR-015-FR-017)

**Checkpoint**: Existing data is preserved and every normal account has one private default profile.

---

## Phase 3: User Story 1 - Set Personal Regional Preferences (Priority: P1) 🎯 MVP

**Goal**: Users can view/save regional inputs, and current date-sensitive behavior follows each user's
saved named timezone.

**Independent Test**: Save different regional settings for two accounts, reload both, and prove that
Today defaults to each authenticated user's local date with no cross-account values.

### Tests for User Story 1

- [X] T009 [P] [US1] Write failing profile GET/full-PUT, defaults, options, display-name, validation, ownership, and atomic-save API tests in `apps/api/tests/Feature/Profile/ProfileApiTest.php` (FR-001-FR-009, FR-013, FR-015-FR-017, SC-001, SC-004-SC-005)
- [X] T010 [P] [US1] Write failing two-user timezone, opposite-day Today, DST, explicit-date, history-preservation, and query-bound tests in `apps/api/tests/Feature/Profile/ProfileTimezoneBoundaryTest.php` (FR-003-FR-004, SC-002, SC-006)
- [X] T011 [P] [US1] Update schedule/progress timezone unit coverage in `apps/api/tests/Unit/CoreDailyLoop/RoutineScheduleServiceTest.php` and `apps/api/tests/Unit/CoreDailyLoop/RoutineProgressServiceTest.php` before changing service signatures (FR-003-FR-004)
- [X] T012 [P] [US1] Add a failing desktop and 390px regional preference journey in `apps/web/e2e/profile/regional-settings.spec.ts` (FR-002-FR-009, FR-016-FR-019, SC-001-SC-002, SC-007-SC-008)

### Implementation for User Story 1

- [X] T013 [US1] Implement strict full-state validation and atomic current-user save in `apps/api/app/Http/Requests/UpdateProfileRequest.php`, `apps/api/app/Http/Resources/ProfileResource.php`, `apps/api/app/Http/Controllers/ProfileController.php`, and `apps/api/routes/api.php` (FR-001-FR-009, FR-013, FR-015-FR-018)
- [X] T014 [US1] Resolve authenticated user timezone once and pass it explicitly through `apps/api/app/Http/Controllers/TodayController.php`, `apps/api/app/Http/Controllers/RoutineLogController.php`, `apps/api/app/Services/RoutineScheduleService.php`, and `apps/api/app/Services/RoutineProgressService.php` (FR-003-FR-004, SC-002, SC-006)
- [X] T015 [P] [US1] Add typed profile/current-user contracts and GET/PUT operations in `apps/web/src/api/types.ts`, `apps/web/src/api/client.ts`, and `apps/web/src/auth/session.ts` (FR-001-FR-009, FR-017-FR-018)
- [X] T016 [P] [US1] Make calendar formatting locale-aware without parsing calendar dates as UTC in `apps/web/src/lib/format.ts`, `apps/web/src/views/TodayView.vue`, `apps/web/src/views/ReviewView.vue`, `apps/web/src/views/GoalsView.vue`, and `apps/web/src/components/ProgressSummary.vue` (FR-004, FR-006)
- [X] T017 [US1] Build the regional Profile and Settings form with loading/dirty/save states in `apps/web/src/views/AccountView.vue` and supporting layout styles in `apps/web/src/style.css` (FR-002-FR-009, FR-016-FR-019)
- [X] T018 [US1] Complete and pass the regional desktop/390px browser journey in `apps/web/e2e/profile/regional-settings.spec.ts` (SC-001-SC-002, SC-007-SC-008)

**Checkpoint**: P1 is independently usable; two simultaneous users can have different current days.

---

## Phase 4: User Story 2 - Record Calculation Inputs (Priority: P2)

**Goal**: Users can save a partial or complete canonical anthropometric baseline with formula-aware
readiness and drift-free metric/imperial presentation.

**Independent Test**: Save a baseline, switch display units repeatedly, reload the same canonical
values, and prove Katch-McArdle rejects a missing body-fat percentage without partial persistence.

### Tests for User Story 2

- [X] T019 [P] [US2] Extend failing API coverage for anthropometric bounds, nullable fields, formula/body-fat coupling, derived readiness, and complete rollback in `apps/api/tests/Feature/Profile/ProfileApiTest.php` (FR-010-FR-014, FR-017, SC-003-SC-004)
- [X] T020 [P] [US2] Add failing canonical metric/imperial round-trip and formula-readiness tests in `apps/api/tests/Unit/Profile/ProfileValueConversionTest.php` (FR-005, FR-009-FR-014, SC-003)
- [X] T021 [P] [US2] Add a failing desktop and 390px anthropometric/formula journey in `apps/web/e2e/profile/anthropometrics.spec.ts` (FR-005, FR-009-FR-014, FR-017-FR-019, SC-003-SC-004, SC-008)

### Implementation for User Story 2

- [X] T022 [US2] Complete canonical anthropometric casts, formula-aware missing-field derivation, and cross-field rules in `apps/api/app/Models/UserProfile.php` and `apps/api/app/Http/Requests/UpdateProfileRequest.php` (FR-009-FR-014, FR-017)
- [X] T023 [P] [US2] Implement pure canonical/display conversions for centimetres/kilograms and feet/inches/pounds in `apps/web/src/lib/units.ts` (FR-005, SC-003)
- [X] T024 [US2] Add accessible anthropometric, activity, formula, readiness, and explicit-clear controls to `apps/web/src/views/AccountView.vue` (FR-005, FR-009-FR-014, FR-017-FR-019)
- [X] T025 [US2] Complete and pass the anthropometric desktop/390px browser journey in `apps/web/e2e/profile/anthropometrics.spec.ts` (SC-003-SC-004, SC-008)

**Checkpoint**: P1 and P2 remain independently verifiable; no downstream target calculation exists.

---

## Phase 5: User Story 3 - Recover Safely from Invalid or Unavailable Saves (Priority: P3)

**Goal**: Profile editing truthfully handles validation, duplicate submit, service failure, session
expiry, retry, keyboard focus, and narrow-screen content.

**Independent Test**: Exercise each failure then recover successfully without partial writes, lost
drafts, stale success, or cross-account data.

### Tests for User Story 3

- [X] T026 [P] [US3] Add failing unauthenticated, duplicate-submit/idempotency, injected-owner, and transactional failure tests in `apps/api/tests/Feature/Profile/ProfileApiTest.php` (FR-013, FR-015, FR-018, SC-004-SC-005, SC-007)
- [X] T027 [P] [US3] Add failing service-unavailable, validation-focus, session-expiry, retry, long-content, and exact-390px journeys in `apps/web/e2e/profile/profile-recovery.spec.ts` (FR-018-FR-019, SC-007-SC-008)

### Implementation for User Story 3

- [X] T028 [US3] Complete draft preservation, duplicate-submit protection, all-field validation display, session expiry, retry, focus recovery, and truthful accepted-state updates in `apps/web/src/views/AccountView.vue` and `apps/web/src/auth/session.ts` (FR-018-FR-019, SC-007-SC-008)
- [X] T029 [US3] Harden profile layout, long option/name wrapping, keyboard visibility, and 390px overflow rules in `apps/web/src/style.css` (FR-019, SC-008)
- [X] T030 [US3] Complete and pass the recovery desktop/390px journey in `apps/web/e2e/profile/profile-recovery.spec.ts` (SC-004-SC-005, SC-007-SC-008)

**Checkpoint**: All three stories are independently functional and recoverable.

---

## Phase 6: Polish and Cross-Cutting Validation

**Purpose**: Reconcile contracts, protect existing flows, and record completion evidence.

- [X] T031 [P] Reconcile `specs/004-profile-settings/contracts/openapi.yaml`, `apps/web/src/api/types.ts`, `apps/api/app/Http/Resources/ProfileResource.php`, and the additive current-user shape with `specs/003-multi-user-auth/contracts/openapi.yaml` (Constitution VI)
- [X] T032 Run the full Laravel suite plus `vendor/bin/pint --test`, Vue typecheck/build, and all Playwright projects from `specs/004-profile-settings/quickstart.md`, resolving every regression before completion
- [X] T033 Verify migration preservation against a disposable data-bearing database and record row/date/owner evidence in `specs/004-profile-settings/plan.md` (FR-004, FR-016, SC-006)
- [X] T034 Update implementation status, accepted deviations, gate counts, and roadmap progress in `specs/004-profile-settings/plan.md`, `docs/MVP_TECHNICAL_DESIGN.md`, and `docs/design/delivery-roadmap.md`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies
- **Foundational (Phase 2)**: depends on Setup and blocks all stories
- **US1 (Phase 3)**: depends on Foundational and is the MVP
- **US2 (Phase 4)**: depends on persisted/profile API foundation; can be verified separately from
  timezone behavior but shares the profile screen
- **US3 (Phase 5)**: depends on the complete editable form from US1/US2
- **Polish (Phase 6)**: depends on all desired stories

### User Story Dependency Graph

```text
Setup -> Foundational -> US1 Regional Preferences -> US2 Anthropometrics -> US3 Recovery -> Polish
```

The product order is sequential because all stories deliberately share one atomic Profile form.
Test files and backend/frontend tasks marked `[P]` can still proceed in parallel within a phase.

### Parallel Opportunities

- T002 and T003 can run independently after T001 begins.
- T006 can run alongside provisioning tests T007 after the migration contract is known.
- T009-T012 are separate backend/unit/browser failing-test surfaces.
- T015 and T016 touch independent typed-session and formatting paths before T017 integrates them.
- T019-T021 cover API, unit, and browser behavior independently.
- T023 can proceed while backend completion rules T022 are implemented.
- T026 and T027 cover separate backend and browser recovery boundaries.

## Implementation Strategy

### MVP First

1. Complete Setup and Foundational phases.
2. Deliver T009-T018 for regional preferences and per-user Today timezone.
3. Run the P1 independent test before expanding the form.

### Incremental Delivery

1. Add P2 canonical anthropometrics and formula readiness without downstream calculations.
2. Add P3 recovery/accessibility hardening.
3. Reconcile contracts and run the full regression/migration gate.

## Notes

- Existing code is evidence, not automatic task completion.
- All production schema changes are additive; do not rewrite old migrations.
- Never use the browser/device timezone as the authenticated product timezone fallback.
- Do not persist display-rounded imperial/metric values or a derived completeness cache.
- Do not add recurrence, notifications, finance, nutrition/workout calculations, mobile code, or AI.
- Mark a task `[X]` only after its described behavior and verification are complete.
