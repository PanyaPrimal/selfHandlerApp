# Tasks: Budget and Recurring Cash Flow

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/openapi.yaml](contracts/openapi.yaml), and
[quickstart.md](quickstart.md)

**Tests**: Mandatory and RED-first. `[P]` marks file-level independence only; implementation remains in
the current branch/worktree and one sequential feature history.

## Phase 1: Specification and RED Foundation

- [x] T001 Run `$speckit-analyze`, resolve every critical/high finding, and record artifact/contract/task coverage in `analysis.md`
- [x] T002 [P] Add additive table/column/constraint/MySQL-name/rollback tests in `apps/api/tests/Feature/Finance/FinancePlanningSchemaTest.php`
- [x] T003 [P] Add monthly interval/month-day/leap/short-month/idempotency tests in `apps/api/tests/Unit/Recurrence/MonthlyRecurrenceTest.php`
- [x] T004 [P] Add exact legacy daily/weekly/interval/cycle/slot set-equality regressions in `apps/api/tests/Unit/Recurrence/LegacyRecurrenceRegressionTest.php`
- [x] T005 [P] Add owner/model/relation/snapshot/fact immutability tests in `apps/api/tests/Unit/Finance/FinancePlanningModelTest.php`
- [x] T006 [P] [US1] Add budget lifecycle/scope/FX/threshold/query-budget service and API tests in `apps/api/tests/{Unit,Feature}/Finance/FinanceBudget*Test.php`
- [x] T007 [P] [US2] Add recurring-operation validation/lifecycle/snapshot/materialization service and API tests in `apps/api/tests/{Unit,Feature}/Finance/FinanceRecurringOperation*Test.php`
- [x] T008 [P] [US3] Add actual/skip/clear/retry/concurrency/reversal/reconcile service and API tests in `apps/api/tests/{Unit,Feature}/Finance/FinanceOccurrence*Test.php`
- [x] T009 [P] [US4] Add monthly totals/status/FX/base-change/query-budget service and API tests in `apps/api/tests/{Unit,Feature}/Finance/FinanceCashFlow*Test.php`
- [x] T010 [P] [US5] Add Finance Planner and reminder/budget-warning/settings integration tests in `apps/api/tests/Feature/{Planner,Notifications}/Finance*Test.php`
- [x] T011 [P] Add OpenAPI parse/ref/closed-schema/operation/route parity tests in `apps/api/tests/Feature/Finance/FinancePlanningOpenApiContractTest.php`
- [x] T012 [P] [US6] Add typed client/DTO/month/day/status/i18n contract tests in `apps/web/src/__tests__/finance-planning-contracts.test.ts`
- [x] T013 [P] [US6] Add complete planning flow and EN/RU/UK visual browser specs in `apps/web/e2e/finance/finance-planning-{flow,visual}.spec.ts`
- [x] T014 Run every new focused test RED and record failures limited to absent 019 schema/classes/routes/types/keys/UI in `analysis.md`

## Phase 2: Additive Schema and Shared Monthly Recurrence

- [x] T015 Create one reversible 019 migration for operations, budgets, month-days, occurrence details/facts, and the nullable fact mirror
- [x] T016 Add explicit owner/query/unique/FK indexes with MySQL-safe names and exact 019-only `down()` order
- [x] T017 [P] Implement owner-safe `FinanceBudgetLimit` and `FinanceRecurringOperation` models
- [x] T018 [P] Implement `RecurringRuleMonthday` constraints and same-owner relationship
- [x] T019 [P] Implement `FinanceOccurrenceDetail` same-owner snapshot invariants
- [x] T020 [P] Implement append-only `FinanceOccurrenceFact` outcome/group/date invariants
- [x] T021 Extend `User`, Finance account/category/group, `RecurringRule`, and `PlannedOccurrence` relationships without changing legacy semantics
- [x] T022 [P] Add owner-safe factories for every 019 entity and exact Finance planning builders
- [x] T023 Add Finance owner type, monthly frequency, normalized month-day relation, and schedule label to `RecurringRule`
- [x] T024 Implement anchored interval-month expansion and skip-not-clamp month-days in `RecurringRuleExpander`
- [x] T025 Extend recurrence materialization owner dispatch and enabled-owner query for Finance
- [x] T026 Materialize Finance occurrence/detail atomically with stable identity and bounded batches
- [x] T027 Extend `OccurrenceFactSynchronizer` with exact Finance fact identity/status/link reconciliation
- [x] T028 Run 019 schema/model/monthly plus every legacy recurrence/materializer/reconcile regression green

## Phase 3: User Story 1 — Monthly Expense Budgets (P1)

### Tests

- [x] T029 [P] [US1] Prove unique month/category and concurrent root/child overlap rejection
- [x] T030 [P] [US1] Prove direct/root-child/reversal/range spend grouping counts each ledger entry once
- [x] T031 [P] [US1] Prove 79.999%, 80%, 100%, above-100%, zero-actual, and exact remaining states
- [x] T032 [P] [US1] Prove direct/inverse/base/missing historical FX evidence and all-null incomplete state
- [x] T033 [P] [US1] Prove archived history visibility, active-create restrictions, and foreign-as-missing API behavior

### Implementation

- [x] T034 [US1] Implement closed canonical-month budget create/update requests and owned reference validation
- [x] T035 [US1] Implement locked budget create/update/delete and ancestor/descendant overlap checks in `FinanceBudgetService`
- [x] T036 [US1] Implement grouped category-scope ledger actual selection including exact reversals
- [x] T037 [US1] Implement per-date historical FX conversion evidence and missing-currency completeness
- [x] T038 [US1] Implement exact remaining/utilization/state projection without stored counters
- [x] T039 [US1] Implement closed localized budget resource/list projection
- [x] T040 [US1] Implement budget list/create/update/delete controller routes
- [x] T041 [US1] Add simultaneous EN/RU/UK budget validation, overlap, incomplete-FX, and threshold copy
- [x] T042 [US1] Run budget/schema/ledger/FX/ownership/OpenAPI focused gates green

## Phase 4: User Story 2 — Recurring Income and Expenses (P1)

### Tests

- [x] T043 [P] [US2] Prove account-currency/category-direction/mandatory/name/amount owner validation
- [x] T044 [P] [US2] Prove 1–10 unique days, interval 1–12, optional local time, and ten-year inclusive bounds
- [x] T045 [P] [US2] Prove create/retry-safe expansion for salary days 5/15/25 and short-month day 31
- [x] T046 [P] [US2] Prove edit updates only unfactored/unmoved future snapshots
- [x] T047 [P] [US2] Prove pause/archive/restore removes only unfactored future projections and preserves history
- [x] T048 [P] [US2] Prove foreign/unknown operation and reference behavior through the closed API

### Implementation

- [x] T049 [US2] Implement strict recurring-operation create/update/lifecycle requests
- [x] T050 [US2] Implement atomic operation plus monthly rule/month-day creation in `FinanceRecurringOperationService`
- [x] T051 [US2] Implement locked semantics/rule edit with normalized month-day replacement
- [x] T052 [US2] Reconcile only eligible future occurrence snapshots after accepted edits
- [x] T053 [US2] Implement pause/archive/restore future materialization cleanup while retaining fact/moved history
- [x] T054 [US2] Implement closed recurring-operation resource and ordered list projection
- [x] T055 [US2] Implement recurring-operation list/create/update controller routes
- [x] T056 [US2] Add simultaneous EN/RU/UK operation/rule/lifecycle validation and domain messages
- [x] T057 [US2] Run operation/monthly/materializer/legacy/ownership/OpenAPI focused gates green

## Phase 5: User Story 3 — Explicit Plan Outcomes (P1)

### Tests

- [x] T058 [P] [US3] Prove actual creates one ordinary income/expense group, entry, fact, and balance change
- [x] T059 [P] [US3] Prove repeated and concurrent actualization returns one stable result
- [x] T060 [P] [US3] Prove skip creates no ledger, idempotent retry matches, and clear returns to planned
- [x] T061 [P] [US3] Prove actual clear/conflicting outcome/future/lifecycle/foreign inputs are rejected
- [x] T062 [P] [US3] Prove moved effective date and immutable detail determine the accepted ledger group
- [x] T063 [P] [US3] Prove existing reversal corrects linked actual money while outcome history remains
- [x] T064 [P] [US3] Prove reconciliation rebuilds every Finance mirror and leaves legacy facts unchanged

### Implementation

- [x] T065 [US3] Implement locked due-occurrence resolution and stable outcome identity in `FinanceOccurrenceService`
- [x] T066 [US3] Reuse the 018 ledger boundary with stable normalized idempotency and immutable snapshot input
- [x] T067 [US3] Implement atomic actual fact/group/link and conflict-safe retry recovery
- [x] T068 [US3] Implement skipped fact upsert/clear and prohibit actual deletion
- [x] T069 [US3] Implement closed outcome request plus occurrence/list resources
- [x] T070 [US3] Implement occurrence range and outcome put/delete controller routes
- [x] T071 [US3] Add simultaneous EN/RU/UK actual/skip/conflict/future/correction messages
- [x] T072 [US3] Run occurrence/ledger/reversal/reconcile/concurrency/ownership/OpenAPI focused gates green

## Phase 6: User Story 4 — Planned Monthly Cash Flow (P2)

### Tests

- [x] T073 [P] [US4] Prove exact income/mandatory/discretionary/free totals across multiple days and currencies
- [x] T074 [P] [US4] Prove pending+actual inclusion, skipped exclusion, moved dates, and lifecycle/history semantics
- [x] T075 [P] [US4] Prove direct/inverse/base/missing FX all-null behavior and current Profile base recomputation
- [x] T076 [P] [US4] Prove current/future/month/366-day bounds and fixed query budget

### Implementation

- [x] T077 [US4] Implement deterministic materialized/unmaterialized monthly projection in `FinanceCashFlowService`
- [x] T078 [US4] Implement grouped historical conversion, four totals, counts, evidence, and complete-null state
- [x] T079 [US4] Implement strict month request, closed cash-flow resource, and GET controller route
- [x] T080 [US4] Add simultaneous EN/RU/UK cash-flow labels, completeness, counts, and domain messages
- [x] T081 [US4] Run cash-flow/FX/Profile/monthly/ownership/OpenAPI focused gates green

## Phase 7: User Story 5 — Planner and Notifications Adapters (P2)

### Tests

- [x] T082 [P] [US5] Prove one Finance Planner entry per occurrence with snapshot title/status/time/deep link
- [x] T083 [P] [US5] Prove Planner move preserves occurrence identity and Finance skip delegation is idempotent
- [x] T084 [P] [US5] Prove timed due reminder identity, locale-at-delivery, quiet hours, snooze/disposition, and closure
- [x] T085 [P] [US5] Prove Finance settings default enabled and disabling closes reminders/warnings
- [x] T086 [P] [US5] Prove approaching/exceeded separate identities, retry dedupe, correction close, and re-arm episodes
- [x] T087 [P] [US5] Prove full legacy Planner source ordering/actions and notification owner/category behavior unchanged

### Implementation

- [x] T088 [US5] Implement read-only `FinanceOccurrenceSource`, register it once, and extend the 009 Planner OpenAPI source/action contract
- [x] T089 [US5] Delegate Planner Finance skip to the outcome service and retain shared reschedule identity
- [x] T090 [US5] Extend notification type/category/settings enums, defaults, and the 011 Notifications OpenAPI contract with backward-compatible Finance
- [x] T091 [US5] Extend source synchronization for timed pending Finance occurrences and lifecycle/outcome closure
- [x] T092 [US5] Implement current-month budget eligibility for distinct approaching/exceeded source types
- [x] T093 [US5] Add localized-at-delivery EN/RU/UK reminder/warning copy and safe Finance deep links in implementation and contracts
- [x] T094 [US5] Run Planner/Notifications/Finance OpenAPI parity plus every legacy shared regression green

## Phase 8: User Story 6 — Complete Shared Finance Client (P3)

### Tests

- [x] T095 [P] [US6] Complete typed consumer, exact money, closed input, rollback, and deep-link unit cases
- [x] T096 [P] [US6] Complete budget/operation/cash-flow/occurrence/actual/skip browser journey
- [x] T097 [P] [US6] Cover rejected saves, preserved drafts/focus/live regions, keyboard, and reload/deep-link state
- [x] T098 [P] [US6] Cover EN/RU/UK × light/dark × desktop/exact-390 Budget/Plans/Cash Flow visual matrix

### Implementation

- [x] T099 [US6] Add exact 019 TypeScript DTO/input unions and eleven API client operations
- [x] T100 [US6] Add simultaneous EN/RU/UK tab, budget, recurrence, outcome, cash-flow, error, and ARIA keys
- [x] T101 [US6] Extend the Finance tab/deep-link query contract for overview/budgets/plans/activity
- [x] T102 [US6] Build accessible monthly budget list/editor with scope, progress, conversion evidence, and warnings
- [x] T103 [US6] Build accessible recurring-operation list/editor with month-days, bounds, reminder, and lifecycle
- [x] T104 [US6] Build monthly cash-flow summary with complete/incomplete totals and explainable counts/evidence
- [x] T105 [US6] Build monthly occurrence calendar/list with actual/skip/clear actions and linked transaction state
- [x] T106 [US6] Integrate Planner/Notification deep links and Finance notification setting into existing surfaces
- [x] T107 [US6] Add responsive/safe-area styles, 44px targets, exact-money overflow handling, and both schemes
- [x] T108 [US6] Verify draft rollback, focus restoration/live errors, keyboard/screen-reader semantics, and no overflow
- [x] T109 [US6] Run i18n parity/used-key/hardcoded-copy, typecheck, Vitest, build, and focused browser gates green
- [x] T110 [US6] Run mobile Node/audit/Capacitor sync and verify the final shared-bundle fingerprint

## Phase 9: Documentation and Closure

- [x] T111 [P] Mark 019 delivered in English README, API/web changelogs, roadmap, modules, Finance/recurrence/notification/data-convention/decision docs
- [x] T112 [P] Update durable workspace memory with 019 decisions, exact evidence, commit, and next feature 020
- [x] T113 Re-run `$speckit-analyze`, resolve all findings, mark spec Complete, and check requirement checklist
- [x] T114 Run focused/full Laravel, Pint, Composer validate/audit, OpenAPI, i18n, typecheck, Vitest, build, and record exact evidence
- [x] T115 Run focused/full Playwright desktop/mobile and inspect every EN/RU/UK × scheme × viewport screenshot
- [x] T116 Run mobile Node/audit/sync, isolated migration rollback/reapply, and prove every earlier row/table remains
- [x] T117 Run MySQL identifier, ownership, secret, dependency, large-file, diff, protected-path, workflow, and handoff audits
- [x] T118 Refresh GitNexus, run full change detection, and inspect every medium/high/critical process and direct consumer
- [x] T119 Check tasks only after evidence, stage only 019, and run staged change detection
- [x] T120 Create one atomic non-coauthored 019 commit, push `master`, fetch, and verify local HEAD equals `origin/master`

## Dependencies and Execution Order

- The migration/models and shared monthly recurrence block every product story.
- Budgets depend on the immutable 018 ledger and historical FX only; they can be verified before plans.
- Recurring-operation snapshots block explicit outcomes and the cash-flow/Planner/notification adapters.
- Actualization depends on 018 ledger idempotency/reversal and shared fact reconciliation.
- Planner and Notifications consume proven Finance projections; they never become money or budget owners.
- The shared client begins after all seven-path/eleven-operation backend contracts are green.
- Closure begins only after all six independent user-story checkpoints pass.

## Requirement Traceability

| Requirements | Primary tasks |
|---|---|
| FR-001–008 monthly limits, actuals, FX, thresholds | T006, T029–T042 |
| FR-009–017 recurring operations, monthly recurrence, snapshots, lifecycle | T003–T005, T015–T028, T043–T057 |
| FR-018–022 explicit outcomes, ledger, correction, reconcile | T008, T020–T021, T027, T058–T072 |
| FR-023–025 cash flow, historical FX, bounds | T009, T073–T081 |
| FR-026 Planner source/delegation | T010, T025–T027, T082–T083, T087–T089 |
| FR-027–029 reminders, warnings, settings | T010, T032, T084–T086, T090–T094 |
| FR-030–032 ownership and synchronized contracts | T002, T005–T012, T021, T033, T048, T061, T076, T095–T099 |
| FR-033–034 localization, accessibility, responsive shared client | T012–T013, T041, T056, T071, T080, T093, T095–T110 |
| FR-035 additive migration and isolated rollback | T002, T015–T016, T028, T116–T117 |
| FR-036–037 module ownership and explicit deferrals | T001, T077–T094, T111–T120 |

## Completion Standard

Every task is checked only with evidence. Deployment, feature 002, workflow/live data, handoff, branches,
worktrees, merge, debts/funds/goals/purchase/restock links, one-off plans, investments, providers,
imports/exports/integrations, AI, and native offline authority are prohibited for feature 019.
