# Tasks: Workouts and Training Goals

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/openapi.yaml](contracts/openapi.yaml),
[quickstart.md](quickstart.md)

**Tests**: Required at every boundary. Focused tests are written and observed failing before the
corresponding production implementation.

## Phase 1: Specification and Design

- [x] T001 Complete six prioritized stories, 34 functional requirements, localisation surface,
  assumptions/deferrals, 10 success criteria, and `checklists/requirements.md`
- [x] T002 Resolve catalogue, class-table, program/session identity, recurrence/fact, canonical unit,
  progression, training-goal, aggregate, Planner, notification, and rollback boundaries in `research.md`
- [x] T003 Design the additive 12-table/one-fact-link schema, relationships, state machines, derived
  values, query plan, and ordered rollback in `data-model.md`
- [x] T004 Complete technical/localisation plan, ten Architecture Gates, implementation sequence,
  constitution re-check, deliberate complexity, closed 15-operation OpenAPI, and quickstart
- [x] T005 Run `$speckit-analyze`, record coverage/consistency findings in `analysis.md`, and resolve
  every critical/high issue before application code

**Checkpoint**: Approved internally consistent delivery contract exists before tests or application code.

---

## Phase 2: Failing Contract and Domain Evidence

- [x] T006 [P] Add additive schema, built-in/default preservation, rollback, owner/FK/unique, MySQL
  identifier, and account-deletion tests in `WorkoutSchemaTest.php`
- [x] T007 [P] Add Exercise/Program/type-detail/Session/child/TrainingGoal ownership, relationship,
  casts, mutual-exclusion, and lifecycle tests in `WorkoutModelTest.php`
- [x] T008 [P] Add public/private catalogue visibility, immutability, duplicate, archive/reference, and
  bounded-query tests in `ExerciseCatalogueServiceTest.php`
- [x] T009 [P] Add workout owner collision, materialization, schedule edit, lifecycle, reschedule
  preservation/collision, global command, and query-bound tests in `WorkoutProgramRecurrenceTest.php`
- [x] T010 [P] Add planned/manual identity, simple/detailed/endurance/timed, DST, retry, correction,
  skip, clear, subtype/limit/concurrency, and occurrence-link tests in `WorkoutSessionServiceTest.php`
- [x] T011 [P] Add chronological success/failure/skip/correction, per-exercise independence, decimal,
  and absent-history tests in `WorkoutProgressionServiceTest.php`
- [x] T012 [P] Add day/range totals, pace/volume/records, max-366, correction/empty/timezone, and fixed-
  query tests in `WorkoutStatisticsServiceTest.php`
- [x] T013 [P] Add strength/distance/race/consistency scope/start/progress/null/lifecycle/race-event and
  query-bound tests in `TrainingGoalProgressServiceTest.php`
- [x] T014 [P] Add strict catalogue/program/prescription/session/goal lifecycle/ownership API tests in
  `apps/api/tests/Feature/WorkoutsTrainingGoals/WorkoutApiTest.php`
- [x] T015 [P] Add Today/Review/Planner/notification/dedupe/quiet-hours/deep-link/lifecycle/client
  compatibility tests in `WorkoutIntegrationTest.php`
- [x] T016 [P] Add OpenAPI parse/ref/closed-schema/auth-operation/route-parity and changed-existing-
  contract tests in `WorkoutOpenApiContractTest.php`
- [x] T017 [P] Add desktop/mobile EN/RU/UK catalogue/program/strength/endurance/goal/summary/Planner/
  rollback/accessibility/overflow journeys in `apps/web/e2e/workouts-training-goals/`
- [x] T018 Run focused backend and browser tests, fix fixture/contract mistakes only, and record the
  expected missing-schema/model/route/UI failures in `analysis.md`

**Checkpoint**: Red evidence fails for intended missing behavior before production files exist.

---

## Phase 3: Additive Persistence and Shared Foundations

- [x] T019 Create reversible `2026_08_13_200000_create_workouts_and_training_goals.php` with 12
  feature tables, six immutable built-ins, and nullable unique occurrence Workout fact link
- [x] T020 [P] Implement Exercise/WorkoutProgram/program exercise/endurance/timed models with exact
  casts, lifecycle, relationships, catalogue accessibility, and same-owner guards
- [x] T021 [P] Implement WorkoutSession/strength/endurance/timed/session-exercise/set models with
  fact/subtype mutual exclusion, canonical decimals, relationships, and same-owner guards
- [x] T022 [P] Implement TrainingGoalDetail and extend User/Goal/RecurringRule/PlannedOccurrence
  relationships, type/owner/fact vocabulary, and existing serialization compatibility
- [x] T023 Extend RecurrenceMaterializer and OccurrenceFactSynchronizer for WorkoutProgram dispatch,
  bounded enabled-owner resolution, completed/skipped sync/clear/reconcile, and no numeric collision
- [x] T024 Implement shared strict-key, nested-list, subtype, Profile-local wall-time/DST, owner/date,
  schedule, catalogue, and training-goal requests in `apps/api/app/Http/Requests/`
- [x] T025 Make T006–T009 and affected feature 006/009/013/014 recurrence/schema evidence green
- [x] T026 Run Pint and verify all foundation migrations/models are MySQL/SQLite portable

**Checkpoint**: Catalogue, class-table persistence, and the fourth recurrence/fact owner work without UI.

---

## Phase 4: User Story 1 — Build and Schedule a Program (P1)

**Goal**: Private exercise choices and one reusable recurring program with matching subtype targets.

**Independent Test**: Catalogue/custom exercise → create ordered program → materialize/reload → edit
future schedule → pause/restore/archive while preserving facts/reschedules.

- [x] T027 [US1] Implement ExerciseCatalogueService plus strict list/create/update/archive controller,
  resource, routes, public immutability, private scoping, and stable built-in display keys
- [x] T028 [US1] Implement WorkoutProgramRecurrence with one-rule apply, lifecycle materialization,
  schedule/date validation, fact/reschedule preservation, and type immutability
- [x] T029 [US1] Implement transactional WorkoutProgramService for matching endurance/timed detail and
  atomic strength prescription replacement with owner/order/limit/progression validation
- [x] T030 [US1] Add program list/create/update/replace controllers, requests, resources, routes, options,
  selected-date occurrence projection, and strict response shapes
- [x] T031 [P] [US1] Add Exercise/WorkoutProgram/schedule/prescription/progression TypeScript contracts
  and exact API functions
- [x] T032 [US1] Add `/workouts` catalogue and accessible program editor/lifecycle/history-state shell
  with ordered exercises, subtype targets, rollback, confirmation, and focus recovery
- [x] T033 [US1] Add exact EN/RU/UK catalogue/program/type/intensity/activity/schedule/progression/
  lifecycle/validation/ARIA copy and built-in exercise labels simultaneously
- [x] T034 [US1] Make T007–T009/T014 and focused catalogue/program browser journeys green

**Checkpoint**: A program is independently usable and scheduled through the shared engine.

---

## Phase 5: User Story 2 — Record Strength Work (P1)

**Goal**: Planned/manual simple/detailed strength facts remain exact, correctable, and occurrence-linked.

**Independent Test**: Planned detailed complete/correct/clear + planned skip + manual simple create/
correct/delete with stable identity, children, volume, and occurrence state.

- [x] T035 [US2] Implement transactional WorkoutSessionService planned/manual root identity, type/date/
  owner validation, local started-time conversion, idempotent correction, skip, delete, and fact sync
- [x] T036 [US2] Implement exact atomic strength subtype/exercise/set replacement with simple/detailed
  mutual exclusion, accessible exercise checks, order/limit/canonical value validation
- [x] T037 [US2] Add manual/planned/update/delete controllers, requests, resources, routes, eager loading,
  and owned 404/strict conditional-domain feedback
- [x] T038 [US2] Extend Planner skip/reschedule collision dispatch through WorkoutSessionService while
  preserving every existing routine/habit/sleep action and source identity
- [x] T039 [P] [US2] Add WorkoutSession/strength/exercise/set/totals TypeScript contracts and API functions
- [x] T040 [US2] Add planned/manual simple/detailed session editor, history cards, skip/correct/clear/
  delete confirmations, busy/live/error/rollback states, and Planner deep-link date/program hydration
- [x] T041 [US2] Make T010/T014 plus desktop/mobile strength identity/subtype/correction journeys green

**Checkpoint**: Strength work is a reliable planned or unplanned fact without duplicate truth.

---

## Phase 6: User Story 3 — Record Endurance and Timed Activity (P1)

**Goal**: Cardio/running, flexibility, and sport facts use exact compatible canonical inputs.

**Independent Test**: Create/correct/delete run, flexibility, sport, and planned cardio sessions; verify
pace/unit round-trip, one matching subtype, and occurrence identity.

- [x] T042 [US3] Extend WorkoutSessionService for endurance/timed subtype rules, required duration,
  running distance/run type, heart-rate/energy bounds, and sport/flexibility activity compatibility
- [x] T043 [US3] Extend WorkoutSessionResource/statistics primitives with exact canonical subtype fields,
  derived pace, null semantics, and no fabricated cross-subtype values
- [x] T044 [US3] Add responsive cardio/running/flexibility/sport program/session controls with localized
  units/pace, conditional fields, validation, rollback, and keyboard/focus behavior
- [x] T045 [US3] Make T010/T012/T014 and focused endurance/timed browser journeys green

**Checkpoint**: Every delivered workout family has a complete manual/planned fact path.

---

## Phase 7: User Story 4 — Deterministic Progression and Records (P2)

**Goal**: History yields transparent next targets and honest records with no mutable aggregate state.

**Independent Test**: Controlled success/success/failure/correction sequence recalculates target,
counter, max weight/volume, and best pace exactly.

- [x] T046 [US4] Implement WorkoutProgressionService chronological per-exercise fold with qualifying
  simple/detailed sessions, independent streak/reset/increment, decimal output, and bounded loading
- [x] T047 [US4] Implement WorkoutStatisticsService selected-day/max-366 summary, history, volume,
  distance, duration, per-exercise records, best pace, empty semantics, and fixed query plan
- [x] T048 [US4] Return bounded history/summary/records/progression through GET `/workouts` and exact
  resources/query validation; keep calculations module-owned
- [x] T049 [US4] Add history filters, selected/range summary, record and next-target cards with honest
  empty/null states and locale/unit-reactive formatting
- [x] T050 [US4] Make T011–T012/T014 and browser progression/record/correction/query cases green

**Checkpoint**: Level 1 progression and records are deterministic and correction-safe.

---

## Phase 8: User Story 5 — Track a Training Goal (P2)

**Goal**: Existing Goal lifecycle gains a typed training detail whose progress comes from Workout facts.

**Independent Test**: Strength/distance/race/consistency goal create/update/lifecycle plus matching/
nonmatching session corrections and one race Planner event.

- [x] T051 [US5] Implement TrainingGoalService create/update with existing Goal lifecycle, immutable
  kind/scope/start snapshot, compatible exercise/activity/program/target/date validation, and owner guards
- [x] T052 [US5] Implement set-query TrainingGoalProgressService for max weight/distance, trailing-seven-
  date consistency, absent semantics, clamped progress, and correction/query stability
- [x] T053 [US5] Add training goal list/create/update controllers, requests, resources, routes, options,
  owned 404s, and generic/body Goal compatibility evidence
- [x] T054 [US5] Implement/register TrainingGoalSource for active nonarchived race target dates only,
  read-only Planner metadata, stable deep link, and no fake recurrence
- [x] T055 [P] [US5] Add TrainingGoal/current/progress TypeScript contracts and exact API functions
- [x] T056 [US5] Add accessible strength/distance/race/consistency goal editor, progress/lifecycle cards,
  null/current/date states, rollback, and immutable-scope explanation to Workouts
- [x] T057 [US5] Add exact EN/RU/UK training-goal/kind/unit/progress/race/consistency/lifecycle/ARIA copy
- [x] T058 [US5] Make T013–T015 and browser training-goal/progress/race-event journeys green

**Checkpoint**: Training goals extend Goal without copying Workout progress or redesigning future types.

---

## Phase 9: User Story 6 — Shared Daily Surfaces, Reminders, Locales, and Clients (P3)

**Goal**: The full workout loop agrees across Workouts, Planner, Notifications, Today, Review, and Android.

**Independent Test**: Controlled planned/manual facts and lifecycle transitions produce exact shared
summaries/actions/reminders/deep links in every locale/theme/viewport/client.

- [x] T059 [US6] Implement/register WorkoutOccurrenceSource with exact program metadata, action URL,
  reschedule/skip availability, lifecycle/fact filters, query bound, and Planner contract update
- [x] T060 [US6] Add backward-compatible workout notification category/type/default/request/UI/types,
  localized reminder copy, direct/digest classification, dedupe/quiet-hours/disposition/closure
- [x] T061 [US6] Inject Workout selected-day DTO into Today `module_summaries` and update feature 001
  contract/types/tests without changing legacy summary or owner calculations
- [x] T062 [US6] Make Review present the same Today Workout DTO without persisting/recomputing it and
  preserve all DailyReview draft/save/retry behavior
- [x] T063 [US6] Complete `/workouts` route, desktop/More navigation, Planner/notification/Today/Review
  deep links, locale-reactive feedback, and three-language changelog
- [x] T064 [US6] Complete desktop/exact-390 CSS, 44px controls, safe areas, keyboard/focus/live regions,
  long EN/RU/UK copy, dark/light contrast, and no horizontal overflow
- [x] T065 [US6] Make T015/T017 Planner/reminder/summary/lifecycle/locale/accessibility/rollback/browser cases green
- [x] T066 [US6] Synchronize/validate the final shared web bundle through Capacitor without native domain code

**Checkpoint**: Workouts are part of every current daily surface and client without duplicate ownership.

---

## Phase 10: Contracts, Documentation, and Full Closure

- [x] T067 Make T016 parse/ref/closed-schema/auth-operation/route parity green for all 15 operations;
  verify changed feature 001/009/011 OpenAPI and every TypeScript consumer use exact fields
- [x] T068 [P] Add feature 015 changelog and update README, ARCHITECTURE, modules, decisions, roadmap,
  data conventions, recurrence, and notifications docs with implementation and explicit deferrals
- [x] T069 Run focused Workout plus affected Auth/CoreDailyLoop/Body/Recurrence/Planner/Notification/
  Habit/Sleep/Mobile Laravel tests and Pint; fix failures without weakening assertions
- [x] T070 Run full Laravel tests, migration preservation/MySQL identifier/ownership/security gates,
  and record exact counts in `analysis.md`
- [x] T071 Run i18n guard, typecheck, Vitest, production build, and focused Playwright both projects;
  record exact pass counts
- [x] T072 Run full desktop and full mobile Playwright after final CSS/code; record pass/skip counts
- [x] T073 Capture/inspect EN/RU/UK light/dark desktop and 390×844 Workouts/Today/Review/Planner images;
  fix contrast, clipping, focus, console/page errors, and overflow, then rerun probes
- [x] T074 Run mobile Node tests and final HTTPS-origin Capacitor sync/validation; record bundle fingerprint
- [x] T075 Run diff check, broad secret scan, OpenAPI/route scan, protected deployment-path audit,
  handoff/untracked audit, and GitNexus pre-commit impact/detect-changes review
- [x] T076 Re-run `$speckit-analyze`, resolve all critical/high and document medium/low disposition;
  set spec Complete and mark tasks only against recorded evidence
- [x] T077 Update canonical `C:\Code\memory\projects\selfhandler\overview.md` with implementation,
  gates, deferrals, commit placeholder, and next feature; never stage workspace memory
- [x] T078 Stage only feature 015 files, verify 78/78 and protected/handoff scope, create one atomic
  commit without co-author, push current master, update memory SHA, and verify HEAD equals origin/master

---

## Dependencies and Execution Order

- T001–T005 block tests/code. T006–T017 are authored before T018 captures red evidence.
- T019–T026 establish catalogue/class-table/recurrence/fact roots and block every story.
- US1 owns programs. US2 depends on US1; US3 shares the session root; US4 needs facts; US5 needs facts/
  goals; US6 integrates completed owners. Sequential goal execution follows this order.
- T067–T078 run only after every story checkpoint is green. `[P]` marks file independence, not
  permission for a branch, worktree, sub-agent, deployment, or parallel feature.

## Traceability

| Story | Requirements | Primary tasks |
|---|---|---|
| US1 catalogue/program | FR-001–FR-007, FR-025 | T006–T009, T019–T034 |
| US2 strength facts | FR-008–FR-011, FR-014–FR-015, FR-025 | T007, T010, T014, T021–T024, T035–T041 |
| US3 endurance/timed | FR-008–FR-015 | T010, T012, T014, T042–T045 |
| US4 progression/records | FR-016–FR-020 | T011–T012, T046–T050 |
| US5 training goals | FR-021–FR-024, FR-026 | T013–T015, T051–T058 |
| US6 integrations/clients | FR-026–FR-034 | T015–T017, T059–T066 |
| Cross-cutting closure | FR-030–FR-034, SC-005–SC-010 | T067–T078 |

## Notes

- Deployment, feature 002, live providers/data, and `design_handoff_selfhandler_mvp/` are excluded.
- Do not check an application task before its concrete file/behavior/evidence exists.
- Repository docs remain English; every product string ships EN/RU/UK together.
