# Tasks: Finance Ledger Foundation

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/openapi.yaml](contracts/openapi.yaml), and
[quickstart.md](quickstart.md)

**Tests**: Mandatory and RED-first. `[P]` marks file-level independence only; implementation remains in
the current branch/worktree and one sequential feature history.

## Phase 1: Spec and RED Foundation

- [x] T001 Run `$speckit-analyze`, resolve all critical/high findings, and record the read-only baseline in `analysis.md`
- [x] T002 [P] Add additive schema/constraint/rollback/factory tests in `apps/api/tests/Feature/Finance/FinanceSchemaTest.php`
- [x] T003 [P] Add exact Money parse/arithmetic/rounding/overflow tests in `apps/api/tests/Unit/Finance/MoneyTest.php`
- [x] T004 [P] Add owner/model/immutability/category-depth tests in `apps/api/tests/Unit/Finance/FinanceModelTest.php`
- [x] T005 [P] Add OpenAPI parse/ref/closed-schema/route parity tests in `apps/api/tests/Feature/Finance/FinanceOpenApiContractTest.php`
- [x] T006 [P] Add TypeScript/client/money/locale contract tests in `apps/web/src/__tests__/finance-contracts.test.ts`
- [x] T007 Run new focused tests RED and record failures limited to absent 018 schema/classes/routes/types/keys in `analysis.md`

## Phase 2: Exact Schema and Shared Domain Foundation

- [x] T008 Add reversible Currency and five Finance tables in `apps/api/database/migrations/2026_08_14_020000_create_finance_ledger_foundation.php`
- [x] T009 Seed UAH/USD/EUR immutable reference rows and MySQL-safe owner/index/FK constraints in the migration
- [x] T010 [P] Implement exact amount+currency behavior in `apps/api/app/ValueObjects/Money.php`
- [x] T011 [P] Implement `Currency` and owner-safe `FinanceAccount` models
- [x] T012 [P] Implement two-level `FinanceCategory` same-owner/direction/depth hooks
- [x] T013 [P] Implement `FinanceExchangeRate` pair/date invariants
- [x] T014 [P] Implement immutable `FinanceTransactionGroup` and `FinanceLedgerEntry` same-owner hooks
- [x] T015 [P] Add owner-safe factories for all 018 entities in `apps/api/database/factories/`
- [x] T016 Add `FinanceTestCase` exact builders and frozen Profile-local clock fixtures
- [x] T017 Run schema/Money/model plus existing Profile/Auth exact-decimal regression gates green

## Phase 3: User Story 1 — Accounts and Reconciliation (P1)

### Tests

- [x] T018 [P] [US1] Add create/update/archive/restore/opening/currency-lock API tests in `apps/api/tests/Feature/Finance/FinanceAccountApiTest.php`
- [x] T019 [P] [US1] Add exact grouped balance/reconciliation/query-budget tests in `apps/api/tests/Unit/Finance/FinanceAccountServiceTest.php`
- [x] T020 [P] [US1] Add foreign account/reference isolation cases in `apps/api/tests/Feature/Finance/FinanceOwnershipTest.php`

### Implementation

- [x] T021 [US1] Implement strict account create/update/reconcile requests in `apps/api/app/Http/Requests/Finance/`
- [x] T022 [US1] Implement account lifecycle, atomic opening adjustment, and locked reconciliation in `apps/api/app/Services/Finance/FinanceAccountService.php`
- [x] T023 [US1] Implement grouped exact balance projection in `apps/api/app/Services/Finance/FinanceBalanceService.php`
- [x] T024 [US1] Implement closed account resource in `apps/api/app/Http/Resources/Finance/FinanceAccountResource.php`
- [x] T025 [US1] Implement account list/create/update/reconcile controller routes in `apps/api/app/Http/Controllers/Finance/FinanceAccountController.php`
- [x] T026 [US1] Add EN/RU/UK account/reconciliation validation and domain messages
- [x] T027 [US1] Run account/balance/schema/Money/ownership/OpenAPI tests green

## Phase 4: User Story 2 — Two-level Categories (P1)

### Tests

- [x] T028 [P] [US2] Add starter idempotency/localization/concurrency tests in `apps/api/tests/Unit/Finance/FinanceCategoryServiceTest.php`
- [x] T029 [P] [US2] Add category CRUD/depth/direction/archive/history API tests in `apps/api/tests/Feature/Finance/FinanceCategoryApiTest.php`

### Implementation

- [x] T030 [US2] Implement strict category create/update requests in `apps/api/app/Http/Requests/Finance/`
- [x] T031 [US2] Implement portable normalized uniqueness, starter materialization, and lifecycle in `apps/api/app/Services/Finance/FinanceCategoryService.php`
- [x] T032 [US2] Implement locale-aware builtin/custom projection in `apps/api/app/Http/Resources/Finance/FinanceCategoryResource.php`
- [x] T033 [US2] Implement category list/create/update controller routes
- [x] T034 [US2] Add simultaneous EN/RU/UK starter labels and category messages
- [x] T035 [US2] Run category/model/ownership/localization/OpenAPI tests green

## Phase 5: User Story 3 — Actual Income, Expense, and Reversal (P1)

### Tests

- [x] T036 [P] [US3] Add exact income/expense/idempotency/date/category tests in `apps/api/tests/Unit/Finance/FinanceLedgerServiceTest.php`
- [x] T037 [P] [US3] Add create/list/retry/conflict/closed-request API tests in `apps/api/tests/Feature/Finance/FinanceTransactionApiTest.php`
- [x] T038 [P] [US3] Add append-only reversal/concurrency/aggregate cancellation tests in `apps/api/tests/Unit/Finance/FinanceReversalServiceTest.php`

### Implementation

- [x] T039 [US3] Implement strict actual transaction/reversal requests in `apps/api/app/Http/Requests/Finance/`
- [x] T040 [US3] Implement normalized payload hashing and conflict-safe idempotency in `apps/api/app/Services/Finance/FinanceIdempotency.php`
- [x] T041 [US3] Implement locked exact income/expense group+entry creation in `apps/api/app/Services/Finance/FinanceLedgerService.php`
- [x] T042 [US3] Implement one linked opposite-delta reversal group in the ledger service
- [x] T043 [US3] Implement ledger entry/group resources with archived relation visibility
- [x] T044 [US3] Implement transaction list/create/reverse controller routes
- [x] T045 [US3] Add simultaneous EN/RU/UK transaction/idempotency/reversal messages
- [x] T046 [US3] Run ledger/reversal/account/ownership/OpenAPI tests green

## Phase 6: User Story 4 — Atomic Transfers (P1)

### Tests

- [x] T047 [P] [US4] Add same/cross currency pair/rate/atomicity/query tests in `apps/api/tests/Unit/Finance/FinanceTransferServiceTest.php`
- [x] T048 [P] [US4] Add transfer API retry/foreign/archive/same-account/reversal tests in `apps/api/tests/Feature/Finance/FinanceTransferApiTest.php`

### Implementation

- [x] T049 [US4] Implement strict transfer request with both exact positive amounts
- [x] T050 [US4] Implement atomic two-leg transfer and exact rate snapshot in `FinanceLedgerService.php`
- [x] T051 [US4] Extend reversal to atomically negate both transfer legs
- [x] T052 [US4] Implement transfer create controller route and closed resource projection
- [x] T053 [US4] Add EN/RU/UK transfer validation/domain messages
- [x] T054 [US4] Run transfer, reversal, balance, cash-flow exclusion, ownership, and OpenAPI tests green

## Phase 7: User Story 5 — Historical FX and Summaries (P2)

### Tests

- [x] T055 [P] [US5] Add rate upsert/pair/date/ownership tests in `apps/api/tests/Feature/Finance/FinanceExchangeRateApiTest.php`
- [x] T056 [P] [US5] Add direct/inverse/missing/base-change/rounding tests in `apps/api/tests/Unit/Finance/FinanceExchangeRateServiceTest.php`
- [x] T057 [P] [US5] Add bounded balance/range/archived/query-budget tests in `apps/api/tests/Unit/Finance/FinanceSummaryServiceTest.php`
- [x] T058 [P] [US5] Add summary API and Profile input parity tests in `apps/api/tests/Feature/Finance/FinanceSummaryApiTest.php`

### Implementation

- [x] T059 [US5] Implement strict rate filter/upsert and summary range requests
- [x] T060 [US5] Implement historical direct/inverse lookup and exact conversion in `apps/api/app/Services/Finance/FinanceExchangeRateService.php`
- [x] T061 [US5] Implement grouped account/consolidated/actual range projections in `apps/api/app/Services/Finance/FinanceSummaryService.php`
- [x] T062 [US5] Implement rate and summary closed resources
- [x] T063 [US5] Implement currency/rate/summary controller routes
- [x] T064 [US5] Add EN/RU/UK rate/missing-conversion/summary messages
- [x] T065 [US5] Run FX/summary/Profile/account/ledger/ownership/OpenAPI gates green

## Phase 8: User Story 6 — Complete Shared Client (P3)

### Tests

- [x] T066 [P] [US6] Complete client/money/rollback/format unit cases in `apps/web/src/__tests__/finance-contracts.test.ts`
- [x] T067 [P] [US6] Add full account/category/rate/actual/transfer/reversal/reconcile flow in `apps/web/e2e/finance/finance-flow.spec.ts`
- [x] T068 [P] [US6] Add EN/RU/UK light/dark desktop/exact-390 visual matrix in `apps/web/e2e/finance/finance-visual.spec.ts`

### Implementation

- [x] T069 [US6] Add all exact Finance TypeScript DTO/input types and fifteen client operations
- [x] T070 [US6] Add decimal-string display/input helpers in `apps/web/src/finance/money.ts`
- [x] T071 [US6] Add simultaneous EN/RU/UK navigation, enums, forms, states, validation, and ARIA keys
- [x] T072 [US6] Build account/balance/reconciliation components in `apps/web/src/components/finance/`
- [x] T073 [US6] Build category tree/editor and historical rate editor components
- [x] T074 [US6] Build income/expense, transfer, immutable history, and reversal components
- [x] T075 [US6] Compose the bounded workspace in `apps/web/src/views/FinanceView.vue`
- [x] T076 [US6] Register `/finance`, desktop/mobile navigation, deep links, and responsive/safe-area styles
- [x] T077 [US6] Verify rejected mutation rollback, draft preservation, focus/live regions, keyboard, 44px targets, and no horizontal overflow
- [x] T078 [US6] Run i18n/typecheck/Vitest/build/focused desktop+mobile browser gates green
- [x] T079 [US6] Run mobile Node/audit/sync and verify final web bundle fingerprint

## Phase 9: Documentation and Closure

- [x] T080 [P] Mark 018 delivered in English README, API changelog, web changelog, roadmap, modules, Finance ER, data conventions, and decisions
- [x] T081 [P] Update durable workspace memory with 018 decisions, exact evidence, and next feature
- [x] T082 Re-run `$speckit-analyze`, resolve all findings, mark spec Complete, and check requirement checklist
- [x] T083 Run focused/full Laravel, Pint, Composer validate/audit, OpenAPI, i18n, typecheck, Vitest, build, and record exact evidence
- [x] T084 Run focused/full Playwright desktop/mobile and inspect every EN/RU/UK × scheme × viewport screenshot
- [x] T085 Run mobile Node/audit/sync, migration rollback, MySQL identifier, ownership, secret, dependency, large-file, diff, protected-path, and handoff audits
- [x] T086 Refresh GitNexus, run `detect_changes(all)`, inspect every high/critical process and direct consumer
- [x] T087 Check all implementation tasks only after evidence, stage only 018, and run `detect_changes(staged)`
- [x] T088 Create one atomic non-coauthored 018 commit, push `master`, fetch, and verify local HEAD equals `origin/master`

## Dependencies and Execution Order

- Schema/Money/immutability block all stories.
- Accounts and categories block actual transaction facts.
- Actual fact/idempotency/reversal blocks transfers and summaries.
- FX blocks complete multi-currency consolidation but missing-rate behavior is independently testable.
- The shared client begins only after the closed API contract is green.
- Closure begins only after all six independent story checkpoints pass.

## Requirement Traceability

| Requirements | Primary tasks |
|---|---|
| FR-001–005 Money/accounts/opening/reconciliation | T002–T027 |
| FR-006–008 category hierarchy/starters/lifecycle | T004, T028–T035 |
| FR-009–013 actual groups/idempotency/reversal | T036–T046 |
| FR-014–017 transfer/reversal/reconciliation | T018–T027, T047–T054 |
| FR-018–022 FX/consolidation/aggregates/bounds | T055–T065 |
| FR-023 contracts/privacy | T002, T004–T005, T020, T037, T048, T055, T058 |
| FR-024–027 shared localized clients/Android | T006, T066–T079 |
| FR-028–030 evolution/docs/deferral | T001–T002, T080–T088 |

## Completion Standard

Every task is checked only with evidence. Deployment, feature 002, workflow, live data, handoff, branches,
worktrees, merge, budget/recurrence/debt/fund/purchase/investment/integration/AI scope, and mutable balance
truth are prohibited.
