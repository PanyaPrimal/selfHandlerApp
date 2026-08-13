# Tasks: Supplements, Courses, Intake, and Stock

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/openapi.yaml](contracts/openapi.yaml), and
[quickstart.md](quickstart.md)

**Tests**: Mandatory. Permanent tests are authored first and the intended missing-feature RED state is
recorded before production implementation.

**Execution**: Sequential within this feature. `[P]` marks independent files/checks that may be
prepared together, not permission to use another branch/worktree/agent.

## Phase 1: Spec and Test Foundation

**Purpose**: Freeze traceability and prove the baseline before product code.

- [x] T001 Run `$speckit-analyze`, resolve every critical/high inconsistency, and record the read-only result in `specs/017-supplements-courses-intake-stock/analysis.md`
- [x] T002 [P] Add schema/model/owner lifecycle tests in `apps/api/tests/Feature/Supplements/SupplementSchemaTest.php`
- [x] T003 [P] Add exact mg/g/ml/piece conversion tests in `apps/api/tests/Unit/Supplements/SupplementQuantityTest.php`
- [x] T004 [P] Add interval/cycle/multi-slot and legacy-equivalence tests in `apps/api/tests/Feature/Recurrence/RecurrenceEngineTest.php` and `apps/api/tests/Unit/Supplements/SupplementCourseRecurrenceTest.php`
- [x] T005 [P] Add closed OpenAPI/route/schema parity tests in `apps/api/tests/Feature/Supplements/SupplementOpenApiContractTest.php`
- [x] T006 [P] Add catalogue/course/intake/stock ownership and concurrency tests in `apps/api/tests/Feature/Supplements/SupplementOwnershipTest.php`
- [x] T007 [P] Add i18n/type/client contract fixtures in `apps/web/src/__tests__/supplements-contracts.test.ts` and locale gate fixtures
- [x] T008 Run focused new tests, confirm failures are only missing 017 schema/classes/routes/keys, and record RED evidence in `specs/017-supplements-courses-intake-stock/analysis.md`

---

## Phase 2: Shared and Domain Foundation

**Purpose**: Add portable persistence, exact quantities, owner invariants, and backward-compatible
recurrence capabilities. No user story endpoint starts until this phase is green.

- [x] T009 Create additive/reversible tables, rule columns, slots, indexes, and occurrence fact FK in `apps/api/database/migrations/2026_08_14_000000_create_supplements_courses_intake_stock.php`
- [x] T010 [P] Implement exact decimal unit conversion/validation in `apps/api/app/ValueObjects/SupplementQuantity.php`
- [x] T011 [P] Implement private reference/course models and relationships in `apps/api/app/Models/Supplement.php` and `apps/api/app/Models/SupplementCourse.php`
- [x] T012 [P] Implement normalized slot models and same-owner hooks in `apps/api/app/Models/RecurringRuleSlot.php` and `apps/api/app/Models/SupplementCourseSlot.php`
- [x] T013 [P] Implement fact/proposal models and lifecycle invariants in `apps/api/app/Models/SupplementIntake.php`, `SupplementStockMovement.php`, and `SupplementRestockProposal.php`
- [x] T014 Extend `apps/api/app/Models/RecurringRule.php` with supplement owner, interval/cycle casts, and slot relationship while preserving legacy API vocabulary
- [x] T015 Extend `apps/api/app/Models/PlannedOccurrence.php` with the mutually exclusive same-owner Supplement fact relationship and `hasFact()` support
- [x] T016 Extend pure date expansion for daily/weekly interval and paired on/off cycles in `apps/api/app/Services/RecurringRuleExpander.php`
- [x] T017 Extend date×slot materialization, stale reconciliation, enabled course lookup, and fixed slot-bound writes in `apps/api/app/Services/RecurrenceMaterializer.php`
- [x] T018 Extend reconcile/reset and Supplement fact synchronization in `apps/api/app/Services/OccurrenceFactSynchronizer.php`
- [x] T019 Add factories/builders for all 017 entities in `apps/api/database/factories/` and `apps/api/tests/Feature/Supplements/SupplementTestCase.php`
- [x] T020 Run foundation/legacy Recurrence, Habit, Sleep, Workout, Nutrition, Planner, and notification tests until green

**Checkpoint**: Old owner behavior is unchanged and new portable schema/quantity/slot primitives pass.

---

## Phase 3: User Story 1 — Neutral Supplement Catalogue (P1)

**Goal**: Private exact-unit references with create/edit/archive/restore and neutral language.

**Independent Test**: Create g/mg/ml/piece references, round-trip exact quantities, archive/restore,
and verify 404 isolation plus absence of product recommendations.

### Tests

- [x] T021 [P] [US1] Add catalogue request/domain/API and dependent-stock-unit immutability tests in `apps/api/tests/Feature/Supplements/SupplementApiTest.php`
- [x] T022 [P] [US1] Add localized backend validation tests in `apps/api/tests/Feature/Supplements/SupplementLocalizationTest.php`

### Implementation

- [x] T023 [US1] Implement strict create/update requests in `apps/api/app/Http/Requests/StoreSupplementRequest.php` and `UpdateSupplementRequest.php`
- [x] T024 [US1] Implement catalogue lifecycle and unit compatibility in `apps/api/app/Services/SupplementService.php`
- [x] T025 [US1] Implement closed resource/meta projection in `apps/api/app/Http/Resources/SupplementResource.php`
- [x] T026 [US1] Implement list/create/update controller and authenticated routes in `apps/api/app/Http/Controllers/SupplementController.php` and `apps/api/routes/api.php`
- [x] T027 [US1] Add EN/RU/UK API messages in `apps/api/lang/{en,ru,uk}/messages.php`
- [x] T028 [US1] Run catalogue, schema, quantity, localization, route, ownership, and OpenAPI tests green

**Checkpoint**: Catalogue is independently useful and contains no advice/regimen behavior.

---

## Phase 4: User Story 2 — Bounded Flexible Courses (P1)

**Goal**: User-entered bounded courses with daily/weekly interval, cycle, and 1–8 timed slots through
the shared recurrence engine.

**Independent Test**: Hand-expand twice-daily, alternate-day, selected-weekday/interval, and 7-on/7-off
fixtures across DST; exercise edits/pause/archive and history preservation.

### Tests

- [x] T029 [P] [US2] Add course request/API/lifecycle/goal/reference, no-past-create, and immutable-Supplement tests in `apps/api/tests/Feature/Supplements/SupplementCourseApiTest.php`
- [x] T030 [P] [US2] Add materialization idempotency/query-bound tests in `apps/api/tests/Unit/Supplements/SupplementCourseRecurrenceTest.php`
- [x] T031 [P] [US2] Add affected legacy owner compatibility cases in `apps/api/tests/Feature/Recurrence/RecurrenceEngineTest.php`

### Implementation

- [x] T032 [US2] Implement strict nested course/schedule requests in `apps/api/app/Http/Requests/StoreSupplementCourseRequest.php` and `UpdateSupplementCourseRequest.php`
- [x] T033 [US2] Implement atomic rule/weekday/slot/context application in `apps/api/app/Services/SupplementCourseRecurrence.php`
- [x] T034 [US2] Implement owned supplement/Goal checks, duration derivation, lifecycle, and forecast invalidation in `apps/api/app/Services/SupplementCourseService.php`
- [x] T035 [US2] Implement full course/schedule projection in `apps/api/app/Http/Resources/SupplementCourseResource.php`
- [x] T036 [US2] Implement list/create/update controller and routes in `apps/api/app/Http/Controllers/SupplementCourseController.php` and `apps/api/routes/api.php`
- [x] T037 [US2] Extend recurrence design docs for interval/cycle/normalized slots/legacy fallback in `docs/design/recurrence-engine.md` and `docs/design/data-conventions.md`
- [x] T038 [US2] Run course plus all legacy recurrence/materializer/command tests green within documented query bounds

**Checkpoint**: Course plan is fully managed by shared recurrence and old owners remain equivalent.

---

## Phase 5: User Story 3 — Actual Intake and Correction (P1)

**Goal**: Idempotent taken/skipped facts, exact snapshots, corrections, clear, and occurrence sync.

**Independent Test**: Take/skip/correct/clear different slots, retry/concurrently submit, and verify one
fact, correct local/UTC time, status, stock contribution, and privacy.

### Tests

- [x] T039 [P] [US3] Add intake service/idempotency/time/snapshot tests in `apps/api/tests/Unit/Supplements/SupplementIntakeServiceTest.php`
- [x] T040 [P] [US3] Add intake API/ownership/concurrency tests in `apps/api/tests/Feature/Supplements/SupplementIntakeApiTest.php`
- [x] T041 [P] [US3] Add fact reconcile/clear/legacy exclusivity tests in `apps/api/tests/Feature/Recurrence/RecurrenceEngineTest.php`

### Implementation

- [x] T042 [US3] Implement strict taken/skipped correction request in `apps/api/app/Http/Requests/UpsertSupplementIntakeRequest.php`
- [x] T043 [US3] Implement locked occurrence lookup, effective date/time validation, snapshot upsert, clear, and synchronizer calls in `apps/api/app/Services/SupplementIntakeService.php`
- [x] T044 [US3] Implement occurrence/intake closed resource in `apps/api/app/Http/Resources/SupplementOccurrenceResource.php`
- [x] T045 [US3] Implement PUT/DELETE controller/routes in `apps/api/app/Http/Controllers/SupplementIntakeController.php` and `apps/api/routes/api.php`
- [x] T046 [US3] Run intake, recurrence fact, owner, retry/concurrency, localized validation, and route tests green

**Checkpoint**: One authoritative fact drives status and consumption; corrections are immediately true.

---

## Phase 6: User Story 4 — Stock, Forecast, and One-off Proposal (P1)

**Goal**: Immutable stock facts, exact remainder, 730-date run-out states, and one active one-off
proposal with stable dismissal.

**Independent Test**: Combine stock/corrections with overlapping course facts and verify every forecast
state, correction transition, unique proposal, dismissal fingerprint, and no restock recurrence.

### Tests

- [x] T047 [P] [US4] Add immutable movement/remainder tests in `apps/api/tests/Unit/Supplements/SupplementStockServiceTest.php`
- [x] T048 [P] [US4] Add forecast overlay/no-stock/depleted-as-of/state/query-bound tests in `apps/api/tests/Unit/Supplements/SupplementStockForecastServiceTest.php`
- [x] T049 [P] [US4] Add proposal reconciliation/concurrency/fingerprint tests in `apps/api/tests/Unit/Supplements/SupplementRestockProposalServiceTest.php`
- [x] T050 [P] [US4] Add stock/proposal API and ownership tests in `apps/api/tests/Feature/Supplements/SupplementStockApiTest.php`

### Implementation

- [x] T051 [US4] Implement strict append-only movement request in `apps/api/app/Http/Requests/StoreSupplementStockMovementRequest.php`
- [x] T052 [US4] Implement grouped exact remainder/history in `apps/api/app/Services/SupplementStockService.php`
- [x] T053 [US4] Implement durable-overlay plus pure 730-date forecast in `apps/api/app/Services/SupplementStockForecastService.php`
- [x] T054 [US4] Implement lock/unique-active/fingerprint/dismiss/resolve reconciliation in `apps/api/app/Services/SupplementRestockProposalService.php`
- [x] T055 [US4] Implement stock movement and forecast/proposal resources in `apps/api/app/Http/Resources/SupplementStockMovementResource.php` and existing Supplement resource
- [x] T056 [US4] Implement movement list/create and proposal dismiss controllers/routes in `apps/api/app/Http/Controllers/SupplementStockMovementController.php`, `SupplementRestockProposalController.php`, and `apps/api/routes/api.php`
- [x] T057 [US4] Wire reconciliation after catalogue/course/intake/stock writes and before workspace reads without creating a RecurringRule
- [x] T058 [US4] Run exact stock, negative discrepancy, forecast state, query budget, proposal concurrency, API, and OpenAPI tests green

**Checkpoint**: Stock truth is reconstructable and proposal identity is stable/concurrency-safe.

---

## Phase 7: User Story 5 — Reminders, Planner, and Adherence (P2)

**Goal**: Shared intake escalation/restock delivery and one Supplements summary consumed by all daily
surfaces.

**Independent Test**: Deliver/repeat/stop notification families, skip/reschedule in Planner, and compare
Supplements/Planner/Today/Review for a controlled max range.

### Tests

- [x] T059 [P] [US5] Add selected-day/range/adherence/query tests in `apps/api/tests/Unit/Supplements/SupplementAdherenceServiceTest.php`
- [x] T060 [P] [US5] Add Planner source/action/contract tests in `apps/api/tests/Feature/Supplements/SupplementPlannerIntegrationTest.php`
- [x] T061 [P] [US5] Add intake/proposal notification delivery/escalation/closure tests in `apps/api/tests/Feature/Supplements/SupplementNotificationIntegrationTest.php`
- [x] T062 [P] [US5] Add Today/Review DTO parity and no-copy tests in `apps/api/tests/Feature/Supplements/SupplementDailyLoopIntegrationTest.php`

### Implementation

- [x] T063 [US5] Implement selected-day and max-366-date projections in `apps/api/app/Services/SupplementAdherenceService.php`
- [x] T064 [US5] Implement day/adherence controller/resource/routes in `apps/api/app/Http/Controllers/SupplementDayController.php`, `apps/api/app/Http/Resources/SupplementDayResource.php`, and `apps/api/routes/api.php`
- [x] T065 [US5] Implement Planner projection in `apps/api/app/Services/Planner/SupplementOccurrenceSource.php` and register it in `SourceRegistry.php`
- [x] T066 [US5] Delegate Planner skip/collision semantics to intake facts in `apps/api/app/Http/Controllers/PlannerController.php`
- [x] T067 [US5] Extend notification category/type/default/settings/validation/config/render vocabulary in `apps/api/app/Models/{InAppNotification,NotificationSettings}.php`, requests, config, and language files
- [x] T068 [US5] Extend source synchronization/disposition and category interval selection in `apps/api/app/Services/Notifications/NotificationSourceSynchronizer.php` and `NotificationDispatcher.php`
- [x] T069 [US5] Add Supplements summary to `apps/api/app/Http/Controllers/TodayController.php` and preserve Review as a presentation consumer
- [x] T070 [US5] Update Planner/Notifications/module ownership docs in `docs/design/modules.md` and `docs/design/notifications.md`
- [x] T071 [US5] Run Supplements plus affected Planner/Notifications/Core/Habit/Sleep/Workout/Nutrition full integration gates green

**Checkpoint**: Delivery and daily surfaces agree without owning duplicate domain state.

---

## Phase 8: User Story 6 — Complete EN/RU/UK Clients (P3)

**Goal**: Full accessible responsive catalogue/course/intake/stock/proposal/adherence workspace plus
affected navigation, Planner, Today, Review, Settings/Notifications, and Android bundle.

**Independent Test**: Complete primary flow on desktop and exact 390×844 in all locales/themes with
rollback, keyboard, ARIA, safe-area, overflow, and Android validation.

### Tests

- [x] T072 [P] [US6] Add API client/enum/format/rollback unit tests in `apps/web/src/__tests__/supplements-contracts.test.ts`
- [x] T073 [P] [US6] Add full browser flow tests in `apps/web/e2e/supplements/supplements-flow.spec.ts`
- [x] T074 [P] [US6] Add EN/RU/UK light/dark desktop/mobile visual cases and screenshots in `apps/web/e2e/supplements/supplements-visual.spec.ts`

### Implementation

- [x] T075 [US6] Add exact TypeScript contract models and all thirteen client operations in `apps/web/src/api/types.ts` and `apps/web/src/api/client.ts`
- [x] T076 [US6] Add complete simultaneous keys in `apps/web/src/i18n/locales/{en,ru,uk}.ts`
- [x] T077 [US6] Build owned editors/cards in `apps/web/src/components/supplements/{SupplementEditor,CourseEditor,IntakeEditor,StockEditor,ForecastCard,AdherenceCard}.vue`
- [x] T078 [US6] Build selected-day responsive control surface in `apps/web/src/views/SupplementsView.vue`
- [x] T079 [US6] Register `/supplements`, desktop/mobile navigation, deep-link restoration, and responsive styles in `apps/web/src/router.ts`, shell components, and `apps/web/src/style.css`
- [x] T080 [US6] Integrate exact Supplement DTO/status/actions into `apps/web/src/views/{PlannerView,TodayView,ReviewView}.vue`
- [x] T081 [US6] Extend notification settings/inbox rendering for supplement intake/restock in existing web views/components
- [x] T082 [US6] Verify rejected mutation rollback, draft recovery, focus/live regions, 44px controls, safe areas, and no horizontal overflow in component/browser tests
- [x] T083 [US6] Run i18n parity/used-key/hardcoded-copy, typecheck, Vitest, build, focused desktop/mobile browser, Android Node, sync, and fingerprint validation green

**Checkpoint**: The full daily loop is usable and visually verified in current clients.

---

## Phase 9: Polish, Documentation, and Closure

- [x] T084 [P] Update English changelog/README/API/module roadmap status and mark 017 delivered in `CHANGELOG.md`, `README.md`, and `docs/design/delivery-roadmap.md`
- [x] T085 [P] Update durable workspace memory with feature decisions/evidence in `C:/Code/memory/projects/selfhandler/overview.md`
- [x] T086 Re-run `$speckit-analyze`, resolve all critical/high findings, mark spec `Complete`, and check every task/checklist in `specs/017-supplements-courses-intake-stock/`
- [x] T087 Run focused and full Laravel, Pint, Composer validate/audit, i18n, typecheck, Vitest, build, Playwright desktop/mobile, mobile Node/audit/sync gates and record exact evidence in `analysis.md`
- [x] T088 Inspect all new EN/RU/UK × theme × desktop/exact-390 screenshots plus affected Planner/Today/Review/Notifications/Settings surfaces and record dimensions/findings
- [x] T089 Run secret, dependency, large-file, migration rollback, protected-path, feature-002/deployment/workflow, handoff, diff-check, and status audits; prove only the preserved handoff is unrelated/untracked
- [x] T090 Refresh GitNexus, run `detect_changes(all)`, inspect all high/critical processes and direct consumers, then stage only 017 and run `detect_changes(staged)`
- [x] T091 Create one atomic non-coauthored feature 017 commit, push `master`, fetch, and verify exact local HEAD equals `origin/master`

---

## Dependencies and Execution Order

- Phase 1 freezes contract/RED evidence before production.
- Phase 2 blocks every story because all use schema, exact quantities, owner invariants, and recurrence.
- US1 supplies the selectable reference; US2 supplies planned occurrences; US3 supplies consumption facts;
  US4 derives stock/forecast/proposals; US5 integrates shared consumers; US6 completes clients.
- Phase 9 starts only when every independent story checkpoint is green.

## Requirement Traceability

| Requirements | Primary tasks |
|---|---|
| FR-001–004 catalogue/neutral/exact units | T002–T003, T009–T13, T021–T028 |
| FR-005–009 course/shared recurrence | T004, T014–T20, T029–T038 |
| FR-010–013 intake facts/correction | T039–T046 |
| FR-014–019 stock/forecast/proposal | T047–T058 |
| FR-020–025 notifications/Planner/adherence/Today/Review | T059–T071 |
| FR-026–031 contracts/clients/docs | T005–T008, T072–T091 |
| FR-032 explicit deferrals | T001, T084, T086, T089 |

## Completion Standard

Every task is checked only with evidence. No deployment/live operation, feature-002 path, workflow,
handoff file, branch/worktree, unrelated cleanup, money fact, or medical/AI regimen output is allowed.
