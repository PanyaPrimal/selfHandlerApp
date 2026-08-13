# Tasks: Sleep and Rich Routine Templates

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/openapi.yaml](contracts/openapi.yaml),
[quickstart.md](quickstart.md)

**Tests**: Required at every boundary. Focused tests are written and observed failing before the
corresponding production implementation.

## Phase 1: Specification and Design

- [x] T001 Complete six prioritized stories, 32 functional requirements, localization surface,
  assumptions, 10 success criteria, and `checklists/requirements.md`
- [x] T002 Resolve Routine/template/activity/parent-fact, day-selection, SleepPlan/occurrence/wake-
  snapshot, night/DST, summary, Planner, and notification boundaries in `research.md`
- [x] T003 Design the additive six-table/two-column/one-fact-link schema, state machines, query plan,
  rollback, and ten-operation closed OpenAPI 3.1 contract
- [x] T004 Complete technical/localization plan, ten Architecture Gates, implementation sequence,
  constitution re-check, deliberate complexity, and manual/full-gate quickstart
- [x] T005 Run `$speckit-analyze`, record coverage/consistency findings in `analysis.md`, and resolve
  every critical/high issue before application code

**Checkpoint**: Approved internally consistent delivery contract exists before tests or application code.

---

## Phase 2: Failing Contract and Domain Evidence

- [x] T006 [P] Add additive schema, default-preservation, rollback, FK/unique, MySQL identifier, and
  account-deletion tests in `apps/api/tests/Feature/SleepRoutineTemplates/SleepRoutineSchemaTest.php`
- [x] T007 [P] Add SleepPlan/detail/log and activity/log/selection ownership, relationship, cast, and
  lifecycle invariant tests in `apps/api/tests/Unit/SleepRoutineTemplates/ModelTest.php`
- [x] T008 [P] Add sleep owner collision, materialization/detail snapshot, edit preservation,
  reschedule, reconcile, and query-bound tests in `SleepPlanRecurrenceTest.php`
- [x] T009 [P] Add local night, cross-midnight, spring/fall DST, duration, correction, clear, and
  occurrence-link tests in `SleepLogServiceTest.php`
- [x] T010 [P] Add range/average/empty/correction/timezone/fixed-query tests in
  `SleepStatisticsServiceTest.php`
- [x] T011 [P] Add activity replacement/lock and child-fact/parent-derivation/simple-compatibility/
  Planner-skip tests in `RoutineActivityServiceTest.php`
- [x] T012 [P] Add default/explicit/null/invalid/fact-bearing/concurrent day-selection and shared-
  projection query tests in `RoutineDayProjectionServiceTest.php`
- [x] T013 [P] Add strict create/update/log/statistics/activity/selection/lifecycle/ownership API tests
  in `apps/api/tests/Feature/SleepRoutineTemplates/SleepRoutineApiTest.php`
- [x] T014 [P] Add Today/Review/Planner/notification compatibility, dedupe, quiet-hours, closure, and
  lifecycle integration tests in `SleepRoutineIntegrationTest.php`
- [x] T015 [P] Add OpenAPI parse/ref/schema/auth-operation/route parity tests in
  `SleepRoutineOpenApiContractTest.php`
- [x] T016 [P] Add desktop/mobile EN/RU/UK sleep, templates, activity facts, selection, summaries,
  Planner, rollback, accessibility, overflow journeys in `apps/web/e2e/sleep-routines/`
- [x] T017 Run focused backend and browser tests, fix fixture/contract mistakes only, and record the
  expected missing-schema/model/route/UI failures in `analysis.md`

**Checkpoint**: Red evidence fails for intended missing behavior before production files exist.

---

## Phase 3: Additive Persistence and Shared Foundations

- [x] T018 Create reversible `2026_08_13_180000_create_sleep_and_routine_templates.php` with six
  user-owned tables, `routines.day_period`, and nullable unique occurrence sleep fact link
- [x] T019 [P] Implement SleepPlan/SleepOccurrenceDetail/SleepLog models with UserOwned same-owner
  guards, lifecycle, casts, rule/detail/fact relationships, and one-active-plan service invariant
- [x] T020 [P] Implement RoutineActivity/RoutineActivityLog/RoutineDaySelection models and extend
  Routine/RoutineLog/PlannedOccurrence/RecurringRule relationships and fact/owner vocabulary
- [x] T021 Extend `RecurrenceMaterializer` and `OccurrenceFactSynchronizer` for SleepPlan owner-type
  dispatch, atomic wake-detail synchronization on plan/global paths, three-fact preservation, sleep
  sync/clear/reconcile, and no numeric-id collision
- [x] T022 Implement shared strict-key, nested-list, Profile-local wall-time/DST, and owner/date
  validation requests in `apps/api/app/Http/Requests/`
- [x] T023 Make T006–T008 and affected feature 006/009 recurrence/schema evidence green
- [x] T024 Run Pint and verify all foundation migrations/models remain MySQL/SQLite portable

**Checkpoint**: Persistence and the third recurrence owner work without HTTP/UI dependencies.

---

## Phase 4: User Story 1 — Plan and Record Sleep (P1)

**Goal**: One recurring nightly plan and one explicit correctable cross-midnight fact.

**Independent Test**: Create → materialize/details → record → reload → correct → clear with exact UTC,
duration, quality, one log, and one occurrence link.

- [x] T025 [US1] Implement transactional `SleepPlanRecurrence` with one-active-plan validation,
  rule apply, lifecycle materialization, and unlinked wake-detail snapshot upsert
- [x] T026 [US1] Implement `SleepLogService` local wall-time/DST/duration validation, idempotent
  upsert/correction/clear, and occurrence fact synchronization
- [x] T027 [US1] Implement strict Sleep plan/index/log resources, requests, controllers, routes, and
  active/paused/archived state handling
- [x] T028 [P] [US1] Add Sleep plan/night/log/schedule TypeScript contracts and API functions
- [x] T029 [US1] Add Sleep workspace section under `/routines` with plan form, selected night,
  record/correct/clear, lifecycle, loading/empty/error/success/focus/rollback states
- [x] T030 [US1] Add exact EN/RU/UK sleep plan/night/date/time/quality/duration/lifecycle/validation/
  accessibility catalog entries and formatting
- [x] T031 [US1] Make T009/T013 sleep cases and focused sleep browser loop green

**Checkpoint**: The sleep loop is independently usable and historically stable.

---

## Phase 5: User Story 2 — Build Ordered Templates (P1)

**Goal**: Existing Routine owns a morning/evening/anytime ordered activity definition.

**Independent Test**: Replace/reorder/edit valid activities, reload, reject atomic invalid changes,
then prove first-fact structure/total lock and existing simple-routine preservation.

- [x] T032 [US2] Add strict `day_period` validation/resource/type/UI while preserving every existing
  routine as `anytime` and the legacy kind/schedule/log/goal contract
- [x] T033 [US2] Implement transactional `RoutineActivityService` replace/create/update/delete with
  owner/id/order/limit validation and semantic lock after first fact
- [x] T034 [US2] Add exact replace-activities controller/request/route/resource and update feature 001
  routine OpenAPI contracts plus frontend Routine types/API
- [x] T035 [US2] Add accessible dynamic activity editor with order, time, optional progress total,
  lock explanation, keyboard focus, and atomic rollback to `RoutinesView.vue`
- [x] T036 [US2] Add EN/RU/UK day-period/activity/editor/lock/validation/ARIA copy simultaneously
- [x] T037 [US2] Make T007/T011/T013 template-definition and simple-compatibility cases green

**Checkpoint**: Rich definitions exist without replacing or regressing simple routines.

---

## Phase 6: User Story 3 — Complete Activities Independently (P1)

**Goal**: Child facts remain independent while one parent RoutineLog/occurrence is derived exactly.

**Independent Test**: Partial → all done → mixed skipped → clear/correct with stable child ids,
parent state, occurrence state, progress value, and simple direct-log compatibility.

- [x] T038 [US3] Implement `RoutineActivityLogService` scheduled/selected validation, progress
  compatibility, stable completion time, idempotent correction, clear, and set-query parent derivation
- [x] T039 [US3] Route rich-template whole-date clear and Planner skip through child ownership; reject
  direct rich parent set and preserve zero-activity direct behavior
- [x] T040 [US3] Add strict nested activity-log controllers/requests/routes/resources and update Today
  routine projection with ordered activity facts and parent state
- [x] T041 [P] [US3] Add ActivityLog/Today nested contracts and API functions to TypeScript
- [x] T042 [US3] Add independent done/skipped/pending/progress/note controls to Today with optimistic
  rollback, busy/live states, focus restoration, and parent summary
- [x] T043 [US3] Make T011/T013 plus desktop/mobile activity-fact/parent-derivation journeys green

**Checkpoint**: Activity truth and parent recurrence closure are deterministic and correctable.

---

## Phase 7: User Story 4 — Choose Morning and Evening (P2)

**Goal**: One shared day projection provides deterministic defaults, explicit alternatives, and none.

**Independent Test**: Default → explicit choices → explicit null, with identical Today/Planner/action/
reminder eligibility and atomic rejection of invalid/fact-hiding changes.

- [x] T044 [US4] Implement bounded `RoutineDayProjectionService` for scheduled candidates, explicit
  selection/null, deterministic defaults, anytime pass-through, and reusable eligibility checks
- [x] T045 [US4] Implement exact full-replacement day-selection request/controller/route with owned
  correct-period/effective-occurrence/fact-hiding validation in one transaction
- [x] T046 [US4] Integrate Today routine loading and activity writes with the shared projection while
  preserving historical simple logs and response compatibility
- [x] T047 [US4] Filter `RoutineOccurrenceSource` through the same projection; retain source/action ids,
  order, reschedule semantics, and no Planner-owned state
- [x] T048 [P] [US4] Add day projection/selection TypeScript contracts/API and accessible morning/
  evening candidate/none controls to Today or Routines day planning
- [x] T049 [US4] Make T012–T014 and browser default/explicit/null/invalid/Planner consistency green

**Checkpoint**: Every consumer agrees which morning/evening template is actionable.

---

## Phase 8: User Story 5 — Module-Owned Today and Review Summaries (P2)

**Goal**: Sleep and routine modules compute bounded facts; Today transports and Review presents.

**Independent Test**: Controlled facts return exact selected/range DTOs in both views, corrections
update both, DailyReview row stays byte-equivalent, and query counts stay fixed.

- [x] T050 [US5] Implement set-query `SleepStatisticsService` selected-night plus inclusive max-366-day
  planned/recorded/duration/quality averages and honest empty semantics
- [x] T051 [US5] Implement set-query `RoutineActivitySummaryService` selected template/activity totals,
  done/skipped/pending/nullable-empty rate, and per-template data
- [x] T052 [US5] Inject both owner DTOs into Today `module_summaries` without changing legacy summary;
  update feature 001 OpenAPI/types/tests
- [x] T053 [US5] Make Review fetch/render the same Today summary for its date without persisting or
  computing module data; preserve draft/save/retry behavior
- [x] T054 [US5] Make T010/T012/T014 and browser Today/Review/correction/query-bound cases green

**Checkpoint**: Both day surfaces show one source of deterministic module truth.

---

## Phase 9: User Story 6 — Planner, Reminders, Lifecycle, Locales, and Clients (P3)

**Goal**: Complete cross-surface delivery without duplicate owners or client-specific behavior.

**Independent Test**: Selected routine and sleep occurrences move/remind/close correctly across
quiet hours/lifecycle, while every locale/theme/viewport/client remains accessible and recoverable.

- [x] T055 [US6] Implement/register `SleepOccurrenceSource` with wake metadata, safe reschedule, no
  skip action, fact/collision guard, and Planner deep-link back to the sleep workspace
- [x] T056 [US6] Add backwards-compatible sleep notification category/type/default/request/UI/types,
  localized bedtime/escalation copy, configured interval, source sync/disposition/dedupe/closure
- [x] T057 [US6] Make routine notifications use shared day projection so unselected/explicit-none
  templates close and only selected timed occurrences remain actionable
- [x] T058 [US6] Complete sleep/routine pause/archive/restore occurrence/detail/selection/reminder
  behavior and preserve every historical fact
- [x] T059 [US6] Complete Planner/notification/Today/Review deep-links, copy, states, and changelog in
  EN/RU/UK; ensure live success/error feedback remains reactive on locale change
- [x] T060 [US6] Complete desktop/exact-390 CSS, 44px controls, safe areas, keyboard/focus/live regions,
  Android native transport compatibility, and no horizontal overflow
- [x] T061 [US6] Make T014/T016 Planner/reminder/lifecycle/locale/accessibility/rollback/browser cases green
- [x] T062 [US6] Synchronize/validate final web bundle through Capacitor without adding native domain code

**Checkpoint**: The complete slice works in every existing delivery surface and lifecycle state.

---

## Phase 10: Contracts, Documentation, and Full Closure

- [x] T063 Make T015 parse/ref/closed-schema/auth-operation/route parity green for all ten operations;
  verify changed existing OpenAPI and TypeScript consumers use exact response fields
- [x] T064 [P] Add feature 014 changelog metadata/copy and update README, ARCHITECTURE, modules,
  decisions, roadmap, recurrence, and notifications docs with implementation and explicit deferrals
- [x] T065 Run focused Sleep/Routine plus affected CoreDailyLoop/Recurrence/Planner/Notification/Mobile
  Laravel tests and Pint; fix failures without weakening assertions
- [x] T066 Run full Laravel tests, migration preservation/MySQL identifier/ownership/security gates,
  and record exact counts in `analysis.md`
- [x] T067 Run i18n guard, typecheck, Vitest, production build, focused Playwright both projects, then
  full desktop and full mobile; record pass/skip counts
- [x] T068 Capture/inspect EN/RU/UK light/dark desktop and 390×844 Routines/Sleep/Today/Review images;
  fix contrast, clipping, focus, console/page errors, and overflow
- [x] T069 Run diff check, broad secret scan, OpenAPI/route scan, protected deployment-path audit,
  handoff/untracked audit, and GitNexus pre-commit impact review
- [x] T070 Re-run `$speckit-analyze`, resolve all critical/high and document medium/low disposition;
  set spec Complete and mark tasks only against recorded evidence
- [x] T071 Update canonical `C:\Code\memory\projects\selfhandler\overview.md` with implementation,
  gates, deferrals, commit, and next feature; never stage workspace memory
- [x] T072 Stage only feature 014 files, verify 72/72 and protected/handoff scope, create one atomic
  commit without co-author, push current master, and verify HEAD equals origin/master

---

## Dependencies and Execution Order

- T001–T005 block tests/code. T006–T016 are authored before T017 captures red evidence.
- T018–T024 are shared foundations and block every story.
- US1 sleep and US2 template definitions are independent after foundation. Sequential goal execution
  still completes US1 first. US3 depends on US2; US4 depends on recurrence and template periods.
- US5 depends on facts/projection. US6 depends on all owners and closes shared integrations.
- T063–T072 run only after every story checkpoint is green. `[P]` marks file independence, not permission
  for a branch, worktree, sub-agent, deployment, or parallel feature.

## Traceability

| Story | Requirements | Primary tasks |
|---|---|---|
| US1 sleep loop | FR-001–FR-008, FR-025–FR-030 | T006–T010, T018–T031 |
| US2 templates | FR-009–FR-011, FR-015, FR-026–FR-030 | T006–T007, T011, T032–T037 |
| US3 activity facts | FR-012–FR-015, FR-019–FR-020 | T011, T013–T014, T038–T043 |
| US4 day selection | FR-016–FR-019, FR-022, FR-024 | T012–T014, T044–T049 |
| US5 summaries | FR-008, FR-020–FR-021, FR-031 | T010, T012, T014, T050–T054 |
| US6 integrations/clients | FR-022–FR-032 | T014–T016, T055–T062 |
| Cross-cutting closure | FR-026–FR-032, SC-005–SC-010 | T063–T072 |

## Notes

- Deployment, feature 002, live provider/data, and `design_handoff_selfhandler_mvp/` are excluded.
- No application checkbox changes before its concrete file/behavior/evidence exists.
- Repository docs remain English; product copy ships EN/RU/UK together.
