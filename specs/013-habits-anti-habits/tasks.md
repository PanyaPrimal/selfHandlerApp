# Tasks: Habits and Anti-Habits

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/openapi.yaml](contracts/openapi.yaml),
[quickstart.md](quickstart.md)

**Tests**: Required at every boundary. Focused tests are written and observed failing before the
corresponding production implementation.

## Phase 1: Specification and Design

- [x] T001 Complete prioritized stories, functional requirements, localization surface, assumptions,
  and quality checklist in `specs/013-habits-anti-habits/spec.md` and `checklists/requirements.md`
- [x] T002 Resolve habit/routine boundary, recurrence owner dispatch, fact semantics, aggregate rules,
  stepped-limit normalization, link direction, integrations, and evidence in `research.md`
- [x] T003 Design additive schema/state/ownership/rollback in `data-model.md` and seven-operation exact
  REST contract in `contracts/openapi.yaml`
- [x] T004 Complete technical plan, localization plan, ten roadmap Architecture Gates, implementation
  sequence, constitution re-check, and justified complexity in `plan.md`
- [x] T005 Run `$speckit-analyze`, record traceability/consistency findings in `analysis.md`, and resolve
  every critical/high issue before application code

**Checkpoint**: Approved, internally consistent delivery contract exists before implementation.

---

## Phase 2: Failing Contract and Domain Evidence

- [x] T006 [P] Add additive migration/schema/rollback/identifier/preservation tests in
  `apps/api/tests/Feature/Habits/HabitSchemaTest.php`
- [x] T007 [P] Add model ownership, relationship, mode, lifecycle, and fact-link invariant tests in
  `apps/api/tests/Unit/Habits/HabitModelTest.php`
- [x] T008 [P] Add second-owner materialization, rule-edit preservation, reschedule, idempotency, and
  reconcile tests in `apps/api/tests/Unit/Habits/HabitRecurrenceTest.php`
- [x] T009 [P] Add yes/no, numeric, abstinence, missing/open-day, range, correction, and query-bound
  aggregate tests in `apps/api/tests/Unit/Habits/HabitStatisticsServiceTest.php`
- [x] T010 [P] Add daily/weekly boundary, normalized reduction, step transition, consumption, and atomic
  rejection tests in `apps/api/tests/Unit/Habits/HabitLimitServiceTest.php`
- [x] T011 [P] Add create/update/lifecycle/log/clear/statistics/limit exact-payload, DST, locale, and
  nested ownership tests in `apps/api/tests/Feature/Habits/HabitApiTest.php`
- [x] T012 [P] Add Planner projection/reschedule and notification settings/source/dedupe/closure tests
  in `apps/api/tests/Feature/Habits/HabitIntegrationTest.php`
- [x] T013 [P] Add OpenAPI parse/schema/route-operation parity tests in
  `apps/api/tests/Feature/Habits/HabitsOpenApiContractTest.php`
- [x] T014 [P] Add desktop/mobile EN/RU/UK create/check/correct/abstinence/limit/lifecycle/Planner/
  rollback/a11y/overflow browser scenarios in `apps/web/e2e/habits/habits-flow.spec.ts`
- [x] T015 Run focused backend and browser tests and record expected missing-schema/route/UI failures in
  `analysis.md` before production implementation

**Checkpoint**: Acceptance contracts fail for the intended missing behavior, not due to fixture errors.

---

## Phase 3: Foundational Domain and Shared Recurrence

- [x] T016 Create the reversible additive habits/logs/steps/fact-link migration in
  `apps/api/database/migrations/2026_08_13_160000_create_habits.php`
- [x] T017 [P] Implement `Habit`, `HabitLog`, and `HabitLimitStep` models with UserOwned guards,
  constants, casts, relationships, lifecycle, and cross-owner invariants in `apps/api/app/Models/`
- [x] T018 Extend `RecurringRule`, `PlannedOccurrence`, `RecurrenceMaterializer`, and
  `OccurrenceFactSynchronizer` for type-aware habit ownership and either-fact preservation/reconcile
- [x] T019 Implement shared strict request-key and user-local date/time validation helpers plus
  mode-aware requests in `apps/api/app/Http/Requests/`
- [x] T020 Implement transactional `HabitRecurrence` and `HabitLogService` create/update/sync/clear
  behavior in `apps/api/app/Services/`
- [x] T021 Run T006–T008 and relevant feature 006/009 recurrence regressions until schema, ownership,
  materialization, and reconciliation are green

**Checkpoint**: Safe persistence and second recurrence owner work without an HTTP or UI dependency.

---

## Phase 4: User Story 1 — Build and Check In a Habit (P1)

**Goal**: Create ordinary recurring habits and idempotently record/correct/clear yes/no or numeric facts.

**Independent Test**: Create daily yes/no and weekday numeric definitions; check, reload, correct, and
clear one effective occurrence with exactly one fact and synchronized occurrence state.

- [x] T022 [US1] Implement create/update/index plus owned routine/goal option loading and exact resources
  in `apps/api/app/Http/Controllers/HabitController.php` and `app/Http/Resources/HabitResource.php`
- [x] T023 [US1] Implement log upsert/clear endpoints with mode compatibility, scheduled-date check,
  explicit occurred time, atomic mirror, and owner-scoped 404 in `HabitLogController.php`
- [x] T024 [US1] Register authenticated habit routes in `apps/api/routes/api.php` and make relevant T011
  request/fact cases green
- [x] T025 [P] [US1] Add Habit/Log/Schedule/request/response TypeScript contracts and API functions in
  `apps/web/src/api/types.ts` and `apps/web/src/api/client.ts`
- [x] T026 [US1] Add `/habits`, navigation, selected-day loading, ordinary create/edit form, weekday
  schedule, check-in/correction/clear, loading/empty/error/success states in `HabitsView.vue`
- [x] T027 [US1] Prove ordinary create/check/correct/reload behavior in focused Laravel and Playwright
  scenarios before continuing

**Checkpoint**: The ordinary habit loop is independently usable end to end.

---

## Phase 5: User Story 2 — Honest Chain and Progress (P1)

**Goal**: Compute and present deterministic all-time/selected-period module aggregates.

**Independent Test**: Controlled success, missing, skipped, below-target, open-day, and future histories
produce exact streaks, percentage, opportunities, successes, and numeric total after correction.

- [x] T028 [US2] Implement set-query `HabitStatisticsService` with mode-aware success and local-day
  resolution in `apps/api/app/Services/HabitStatisticsService.php`
- [x] T029 [US2] Add selected-date/all-time projections to `HabitResource` and range endpoint in
  `apps/api/app/Http/Controllers/HabitStatisticsController.php`
- [x] T030 [US2] Render current/best streak, completion percentage, resolved opportunity counts, and
  numeric total with locale formatting/plurals in `HabitsView.vue`
- [x] T031 [US2] Make T009 plus aggregate API/browser cases green, including bounded query evidence and
  timezone open-day boundaries

**Checkpoint**: Every displayed chain/number is reproducible from owned facts and occurrences.

---

## Phase 6: User Story 3 — Explicit Abstinence (P2)

**Goal**: Record protected/relapse outcomes and report abstinence chains without implicit success.

**Independent Test**: Protected run → relapse → protected run retains actual times and exact current/
best streak; missing ended opportunity fails rather than being inferred protected.

- [x] T032 [US3] Complete abstinence validation/success semantics and response mapping in
  `HabitLogService`, `HabitStatisticsService`, and `HabitResource`
- [x] T033 [US3] Add localized protected/relapse check-in controls, relapse history cue, confirmations,
  and accessible state in `HabitsView.vue`
- [x] T034 [US3] Make abstinence service/API/browser scenarios green in all three locales

**Checkpoint**: Abstinence semantics are explicit, correctable, and never optimistic by omission.

---

## Phase 7: User Story 4 — Stepped Reduction Ceiling (P2)

**Goal**: Own an ordered day/week reduction plan and deterministic current allowance.

**Independent Test**: `1/day → 5/week → 3/week` transitions select the correct local step/period and
reject an invalid normalized increase without partial replacement.

- [x] T035 [US4] Implement normalized ladder validation, transactional replacement, step status, and
  day/week consumption projection in `apps/api/app/Services/HabitLimitService.php`
- [x] T036 [US4] Add create-time and replace-plan handling in requests/controllers/resources/routes,
  isolated from Goal milestones
- [x] T037 [US4] Add dynamic accessible step editor plus active/upcoming limit, period, consumed,
  remaining, within/exceeded UI in `HabitsView.vue`
- [x] T038 [US4] Make T010 and stepped-limit API/browser boundary/rollback scenarios green

**Checkpoint**: A changing constraint works without milestone reuse or editable derived status.

---

## Phase 8: User Story 5 — Habit in Context (P3)

**Goal**: Link owned routine/goal context and reuse Planner plus notifications for timed occurrences.

**Independent Test**: Save links/place/starter/time, see the occurrence in Planner, deliver one localized
quiet-hours-aware reminder, then close it through the habit fact.

- [x] T039 [US5] Enforce active owned routine/goal link validation and null-on-delete behavior in model,
  requests, resources, and API tests
- [x] T040 [US5] Implement/register `HabitOccurrenceSource`, expose `habit` Planner source/action/meta,
  and harden generic occurrence reschedule identity/collision behavior
- [x] T041 [US5] Add backwards-compatible habit notification category/type/defaults/request/UI types,
  source synchronization/disposition, localized channel copy, and unchanged Android presentation
- [x] T042 [US5] Add context fields/options and Planner deep-link/check-in behavior to `HabitsView.vue`
  and `PlannerView.vue`
- [x] T043 [US5] Make T012 and Planner/reminder/link browser scenarios green, including untimed/no-direct-
  reminder, dedupe, quiet hours, closure, and cross-user targets

**Checkpoint**: Context is useful while all linked modules retain one authoritative direction.

---

## Phase 9: User Story 6 — Accessible Lifecycle and Localization (P3)

**Goal**: Pause/resume/archive/restore safely across locales and responsive clients.

**Independent Test**: Lifecycle transitions retain facts, stop/resume future occurrences/reminders, roll
back failures, and remain fully operable at desktop/390×844 with keyboard and assistive semantics.

- [x] T044 [US6] Complete pause/resume/archive/restore timestamp/materialization/notification behavior
  and lifecycle API evidence
- [x] T045 [US6] Complete active/paused/archived segments, confirmations, disabled/busy/live states,
  focus management, 44px targets, safe areas, and no-overflow responsive CSS
- [x] T046 [US6] Add every new navigation/form/mode/outcome/stat/limit/lifecycle/Planner/notification/
  validation/ARIA/changelog key simultaneously to EN/RU/UK frontend and backend catalogs
- [x] T047 [US6] Make all T014 desktop/mobile/locale/keyboard/ARIA/reload/rollback/overflow journeys green

**Checkpoint**: The complete product slice meets the existing interface and localization foundation.

---

## Phase 10: Contracts, Documentation, and Full Closure

- [x] T048 Make T013 OpenAPI parse/schema/auth-operation/route parity evidence green and confirm
  frontend types/consumers match every response field
- [x] T049 [P] Add feature 013 changelog metadata/copy in all locales and update `README.md`,
  `docs/ARCHITECTURE.md`, `docs/design/modules.md`, `decisions.md`, `recurrence-engine.md`,
  `notifications.md`, and `delivery-roadmap.md` to match implementation/deferrals
- [x] T050 Run focused Habit plus affected Recurrence/Planner/Notification/Mobile Laravel tests and
  Pint; fix all failures without weakening assertions
- [x] T051 Run full `php artisan test`, Pint, migration preservation, MySQL identifier, and ownership/
  security gates; record exact counts in `analysis.md`
- [x] T052 Run i18n guards, TypeScript typecheck, production build, focused Habits Playwright desktop/
  mobile, then full desktop and full mobile projects; record exact pass/skip counts
- [x] T053 Capture and visually inspect EN/RU/UK light/dark desktop and 390×844 Habits screenshots; fix
  contrast, clipping, focus, console/page errors, and horizontal overflow
- [x] T054 Run `git diff --check`, broad secret scan, OpenAPI parse/route scan, protected deployment-path
  audit, and verify `design_handoff_selfhandler_mvp/` remains the only unrelated untracked path
- [x] T055 Re-run `$speckit-analyze`, resolve all critical/high and document medium/low disposition,
  set spec ready/status, and mark tasks complete only against recorded evidence
- [x] T056 Update canonical `C:\Code\memory\projects\selfhandler\overview.md` with implementation,
  gates, deferrals, and next feature; do not stage workspace memory
- [x] T057 Stage only feature 013 files, verify task count/protected paths/handoff, create one atomic
  commit without co-author, push current `master`, and verify local HEAD equals `origin/master`

---

## Dependencies and Execution Order

- T001–T005 block all code. T006–T014 may be authored independently, then T015 proves they fail for the
  intended missing behavior.
- T016–T021 are foundational and block all stories.
- US1 is the fact loop. US2–US4 depend on that fact, but each remains independently demonstrable.
- US5 depends on scheduled occurrences from US1, not on US3/US4 UI. US6 closes lifecycle/localization.
- T048–T057 run only after every story checkpoint is green. Work remains sequential in this goal even
  where `[P]` marks non-overlapping files.

## Traceability

| Story | Requirements | Primary tasks |
|---|---|---|
| US1 ordinary loop | FR-001–FR-009, FR-020–FR-024 | T006–T027 |
| US2 statistics | FR-010–FR-011 | T009, T028–T031 |
| US3 abstinence | FR-004, FR-007–FR-011 | T032–T034 |
| US4 stepped limit | FR-012–FR-014 | T010, T035–T038 |
| US5 context/integrations | FR-015–FR-018 | T012, T039–T043 |
| US6 lifecycle/localization | FR-019, FR-023–FR-026 | T014, T044–T047 |
| Cross-cutting closure | FR-020–FR-026, SC-004–SC-009 | T048–T057 |

## Notes

- `[P]` means different files can be prepared independently; no sub-agent or branch is required.
- Deployment, feature 002, live rollout, workflows, production data, and the user handoff are excluded.
- A checkbox changes only after its concrete file/behavior/evidence exists and was verified.
