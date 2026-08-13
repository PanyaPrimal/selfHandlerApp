# Tasks: Debts, Funds, Financial Goals, and Purchase Links

**Input**: [spec.md](spec.md), [research.md](research.md), [plan.md](plan.md),
[data-model.md](data-model.md), [contract](contracts/openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Mandatory permanent RED-first tests precede production implementation for every story.

**Organization**: Tasks are grouped by user story so each story has an independent evidence checkpoint.
`[P]` marks tasks that may run in parallel only when their referenced files do not overlap.

## Phase 1: Specification and Baseline

- [x] T001 Confirm branch/HEAD/origin/dirty state and record protected deployment plus handoff exclusions
- [x] T002 Inventory canonical Goal, Storage, Supplement, Finance, recurrence, Planner, Notifications, i18n, mobile, and prior Spec Kit contracts
- [x] T003 Resolve counterparty, principal-only schedule, virtual/linked fund, emergency average, purchase, and restock decisions in `research.md`
- [x] T004 Complete the ten Architecture Gates and complexity justification in `plan.md`
- [x] T005 Complete entity/state/ownership/reversal/rollback definitions in `data-model.md`
- [x] T006 Validate `contracts/openapi.yaml` parses as OpenAPI 3.1 with 14 paths, 19 unique operations, closed objects, and resolving refs
- [x] T007 Validate 7 stories, 28 acceptance scenarios, FR-001–FR-044, SC-001–SC-013, assumptions, edge cases, and exclusions in `spec.md`
- [x] T008 Complete the requirements checklist with no clarification marker or placeholder
- [x] T009 Refresh GitNexus and record pre-change impact for Goal, Item, Finance ledger, recurrence, Planner, and Notifications
- [x] T010 Run and record clean 019 baseline gates before adding permanent 020 RED tests

---

## Phase 2: Foundational Schema, Contracts, and Shared Safety

**Goal**: Add permanent absent-020 tests and the minimum additive persistence/shared vocabulary required
by every story. No story production behavior begins before the expected RED checkpoint is recorded.

- [x] T011 [P] Add schema/rollback/identifier/constraint RED tests in `apps/api/tests/Feature/Finance/FinanceCommitmentSchemaTest.php`
- [x] T012 [P] Add model ownership/immutability/XOR RED tests in `apps/api/tests/Unit/Finance/FinanceCommitmentModelTest.php`
- [x] T013 [P] Add 020 OpenAPI parse/ref/closed/security/route RED tests in `apps/api/tests/Feature/Finance/FinanceCommitmentOpenApiContractTest.php`
- [x] T014 [P] Add legacy 008/009/011/018/019 contract compatibility expectations in affected contract tests
- [x] T015 [P] Add TypeScript/client RED contract tests in `apps/web/src/__tests__/finance-commitments-contracts.test.ts`
- [x] T016 [P] Add focused browser RED journey and visual specs under `apps/web/e2e/finance/`
- [x] T017 Run permanent focused RED suites and append the exact absent-020 failure boundary to `analysis.md`
- [x] T018 Create additive migration `apps/api/database/migrations/2026_08_14_050000_create_debts_funds_financial_goals.php`
- [x] T019 Add Counterparty, Debt, DebtOccurrenceDetail, DebtPaymentFact models and owner-safe factories
- [x] T020 Add SavingFund, FundMovement, FundOccurrenceDetail, FundOccurrenceFact models and factories
- [x] T021 Add FinanceGoalDetail model/factory and common Goal/User relationships/type constant
- [x] T022 Extend Item purchase fields/type/relations/factory without changing task/idea defaults
- [x] T023 Extend transaction group source fields/relations and enforce immutable source-pair invariants
- [x] T024 Extend RecurringRule owner vocabulary/owner validation for debt and saving fund
- [x] T025 Extend PlannedOccurrence fact-link XOR, relations, fillable fields, and `hasFact()` for debt/fund
- [x] T026 Extend RecurrenceMaterializer owner enablement, upsert columns, and immutable debt/fund detail synchronization
- [x] T027 Extend OccurrenceFactSynchronizer to rebuild reversed/repaid debt and fund fact mirrors
- [x] T028 Add migration/model/factory ownership, cascade/restrict, immutable fact, unique, and MySQL-safe identifier implementation
- [x] T029 Prove migration rollback removes only 020 additions while preserving 019 tables/data, then reapplies
- [x] T030 Run shared recurrence legacy set-equality/materializer/query/fact suites after foundational changes
- [x] T031 Add base request/resource helpers for closed Finance commitment inputs and exact output serialization
- [x] T032 Register 020 controller imports/routes and keep every new operation inside authenticated middleware
- [x] T033 Synchronize OpenAPI route vocabulary in 008/009/011/018/019 where additive members are consumed
- [x] T034 Update `apps/api/tests/Support/FinanceTestCase.php` with bounded 020 fixtures only
- [x] T035 Run foundational schema/model/contract/legacy recurrence checkpoint and fix every failure

**Checkpoint**: additive persistence and shared types are proven; every earlier owner still behaves identically.

---

## Phase 3: User Story 1 — Track Both Debt Directions (P1)

**Independent test**: counterparty/debt CRUD, flexible payments/reversals, exact remaining/state, ownership,
archived references, bounded totals, and closed API all pass without fixed recurrence.

- [x] T036 [P] [US1] Add counterparty service lifecycle/duplicate/archive tests in `apps/api/tests/Unit/Finance/FinanceCounterpartyServiceTest.php`
- [x] T037 [P] [US1] Add flexible debt projection/payment/reversal/query-budget tests in `apps/api/tests/Unit/Finance/FinanceDebtServiceTest.php`
- [x] T038 [P] [US1] Add counterparty/debt/payment owner/closed/idempotency API tests in `apps/api/tests/Feature/Finance/FinanceDebtApiTest.php`
- [x] T039 [US1] Implement owner-scoped `FinanceCounterpartyService` with normalized duplicate and active-reference guards
- [x] T040 [US1] Implement Counterparty requests/resource/controller list/create/update contracts
- [x] T041 [US1] Implement Debt create/update validation for direction, flexible mode, exact Money, local dates, account/category, and lifecycle
- [x] T042 [US1] Implement `FinanceDebtProjectionService` active-payment/reversal fold with remaining/progress/state/counts
- [x] T043 [US1] Implement grouped Profile-base owe/owed-to-me totals with historical FX completeness
- [x] T044 [US1] Implement `FinanceDebtPaymentService` flexible idempotent ordinary income/expense posting
- [x] T045 [US1] Enforce payment ≤ remaining, same owner/currency/direction, active references, and concurrency locks
- [x] T046 [US1] Serialize immutable payment attempts, reversed state, safe transaction public IDs, and bounded history
- [x] T047 [US1] Implement Debt requests/resource/controller list/create/update/payment contracts
- [x] T048 [US1] Add User/Debt/Counterparty/Account/Category/Transaction relationships needed by reads only
- [x] T049 [US1] Add EN/RU/UK backend validation and domain messages for counterparty/debt/flexible payment flows
- [x] T050 [US1] Prove unknown/foreign nested references are 404-equivalent and never alter owned projections
- [x] T051 [US1] Prove one and many debt lists/totals use the same fixed query budget
- [x] T052 [US1] Run US1 unit/feature/OpenAPI checkpoint and correct all defects
- [x] T053 [US1] Mark US1 tasks complete only after exact remaining/reversal/ownership evidence is green

---

## Phase 4: User Story 2 — Follow a Fixed Debt Schedule (P1)

**Independent test**: exact installment count across skipped month-days, repeat-safe materialization,
overdue/reschedule/payment/reversal/re-payment, Planner/reminder context, and legacy recurrence pass.

- [x] T054 [P] [US2] Add fixed schedule validation/count/leap/short-month tests in `FinanceDebtScheduleServiceTest.php`
- [x] T055 [P] [US2] Add fixed payment retry/reversal/re-payment tests in `FinanceDebtPaymentServiceTest.php`
- [x] T056 [P] [US2] Add debt recurrence materializer/fact preservation tests in `FinanceDebtRecurrenceTest.php`
- [x] T057 [US2] Implement fixed schedule validation including exact total, first day match, count, interval, and ten-year bound
- [x] T058 [US2] Compute the Nth valid monthly due date without clamping absent month-days
- [x] T059 [US2] Create/update one shared monthly rule and one normalized monthday for fixed debts
- [x] T060 [US2] Materialize immutable debt occurrence snapshots and update only never-paid/unmoved future rows
- [x] T061 [US2] Project the complete bounded fixed schedule with scheduled/paid/overdue state in Profile timezone
- [x] T062 [US2] Require exact scheduled principal and owned matching occurrence for fixed payment
- [x] T063 [US2] Preserve historical payment fact on reversal, reopen derived status, and allow one corrected repayment
- [x] T064 [US2] Keep accepted moved/ever-paid occurrences through schedule edit, pause, and archive
- [x] T065 [US2] Extend Finance planned-occurrence resource with additive `debt` kind/context and safe action URL
- [x] T066 [US2] Extend outcome dispatcher/controller so fixed debt actual/skip/clear use the same occurrence routes
- [x] T067 [US2] Extend Planner Finance source/actions for fixed debt move/skip/pay identities
- [x] T068 [US2] Extend Finance notification source for timed pending debt installments and closure/re-arm
- [x] T069 [US2] Update 009/011/019 contracts and integration tests for additive debt occurrence context
- [x] T070 [US2] Run US2 plus full recurrence/Planner/Notifications checkpoint and fix every regression

---

## Phase 5: User Story 3 — Reserve Money in Saving Funds (P1)

**Independent test**: virtual and linked storage, reserve capacity/over-reserved state, append-only movements,
linked transfers, correction, pace/target lifecycle, account availability, ownership, and bounded reads pass.

- [x] T071 [P] [US3] Add fund validation/lifecycle/projection tests in `FinanceFundServiceTest.php`
- [x] T072 [P] [US3] Add virtual reserve/idempotency/reversal/capacity tests in `FinanceFundMovementServiceTest.php`
- [x] T073 [P] [US3] Add linked-account transfer/dedicated-account tests in `FinanceLinkedFundTest.php`
- [x] T074 [P] [US3] Add fund API owner/closed/draft-safe tests in `FinanceFundApiTest.php`
- [x] T075 [US3] Implement Fund create/update validation for regular/emergency and virtual/linked XOR invariants
- [x] T076 [US3] Enforce same-owner/currency account/category links and unique linked-account claim
- [x] T077 [US3] Implement append-only virtual `FinanceFundMovementService` idempotency and linked reversals
- [x] T078 [US3] Lock account/funds and reject positive reserve beyond current ledger balance
- [x] T079 [US3] Reject withdrawal/reversal below zero while preserving immutable movement history
- [x] T080 [US3] Implement linked top-up/drawdown through ordinary same-currency transfer with exact pairs
- [x] T081 [US3] Implement grouped saved/reserved/available balance projections for all accounts/funds
- [x] T082 [US3] Expose account `reserved_amount`, `available_balance`, and `over_reserved` additively
- [x] T083 [US3] Derive fund target/remaining/progress/reached/spent and deadline monthly pace exactly
- [x] T084 [US3] Implement Fund movement/history/resource/controller list/create/update/movement contracts
- [x] T085 [US3] Prevent archive/storage/currency rewrites that would orphan active reserve/history
- [x] T086 [US3] Add EN/RU/UK backend messages for fund storage, capacity, movement, and target validation
- [x] T087 [US3] Prove one and many fund/account projections use fixed query budgets
- [x] T088 [US3] Run US3 unit/feature/018-account/transfer/reversal checkpoint and fix every defect

---

## Phase 6: User Story 4 — Emergency Fund and Honest Cash Flow (P1)

**Independent test**: fixed/percent/expense-month modes, exact historical evidence, missing history/FX,
scheduled actual/skip/drawdown resume, mandatory cash flow, Planner/reminders, and query bounds pass.

- [x] T089 [P] [US4] Add emergency formula/rounding/history/FX tests in `FinanceEmergencyFundProjectionTest.php`
- [x] T090 [P] [US4] Add fund recurrence/snapshot/outcome tests in `FinanceFundRecurrenceTest.php`
- [x] T091 [P] [US4] Add extended cash-flow composition/query tests in `FinanceCommitmentCashFlowTest.php`
- [x] T092 [US4] Extract reusable recurring-operation planned-money rows without changing 019 totals
- [x] T093 [US4] Implement fixed and planned-income-percent monthly top-up calculation with exact conversion evidence
- [x] T094 [US4] Implement three-complete-prior-month actual expense average including reversals and excluding transfer/adjustment
- [x] T095 [US4] Implement expense-month target/shortfall/build-horizon calculation and explicit missing history state
- [x] T096 [US4] Produce one shared monthly rule for scheduled regular/emergency funds and pause projections when ineligible/full
- [x] T097 [US4] Materialize calculated immutable FundOccurrenceDetail evidence and update only unfactored/unmoved future rows
- [x] T098 [US4] Implement fund actual/skip/clear through virtual allocation or linked transfer with one fact
- [x] T099 [US4] Correct actual money/allocation only through append-only reversal and rebuild occurrence state
- [x] T100 [US4] Resume future mandatory top-up projection after drawdown below target
- [x] T101 [US4] Compose fixed debt income/mandatory expense and emergency mandatory top-up into Finance cash flow once
- [x] T102 [US4] Preserve whole cash-flow null set and sorted evidence when any non-zero conversion/calculation is unavailable
- [x] T103 [US4] Extend Planner Finance source and actions for fund occurrences/unavailable state
- [x] T104 [US4] Extend Finance reminders for timed eligible fund occurrences using existing category/settings unchanged
- [x] T105 [US4] Run US4 plus 019 cash-flow/recurrence/Planner/Notifications checkpoint and correct all failures

---

## Phase 7: User Story 5 — Use Real Finance Goals (P2)

**Independent test**: both Finance Goal types, aggregate-only progress, reverse movement, milestones,
duplicate/foreign targets, common lifecycle, unified list compatibility, and query bounds pass.

- [x] T106 [P] [US5] Add Finance Goal create/update/ownership/milestone tests in `FinanceGoalServiceTest.php`
- [x] T107 [P] [US5] Add save/pay-off progress/reversal/query-budget tests in `FinanceGoalProgressServiceTest.php`
- [x] T108 [P] [US5] Add Finance Goal API and unified `/goals` compatibility tests in `FinanceGoalApiTest.php`
- [x] T109 [US5] Implement common Goal type `finance`, one FinanceGoalDetail XOR link, and owner-safe relationships
- [x] T110 [US5] Implement Finance Goal create/update and exact milestone replacement atomically
- [x] T111 [US5] Reject duplicate active aggregate goal, wrong subtype/currency, foreign, or inactive new target
- [x] T112 [US5] Derive save progress only from Fund projection and pay-off progress only from Debt projection
- [x] T113 [US5] Derive increasing/decreasing monetary milestone achievement and clamp progress 0–1
- [x] T114 [US5] Preserve common Goal lifecycle/timestamps and block aggregate retargeting after accepted history
- [x] T115 [US5] Implement bulk Finance Goal projection with fixed query count
- [x] T116 [US5] Implement Finance Goal requests/resource/controller list/create/update contracts
- [x] T117 [US5] Extend `/goals` eager relations/serialization to include Finance detail/progress without altering general/body/training
- [x] T118 [US5] Update shared Goal TypeScript shape and affected Today/Nutrition/Habit/Workout consumers safely
- [x] T119 [US5] Add EN/RU/UK backend messages for Goal subtype, aggregate, duplicate, milestones, and lifecycle
- [x] T120 [US5] Run US5 plus full Goal/body/training/nutrition/habit compatibility checkpoint and fix every regression

---

## Phase 8: User Story 6 — Turn Purchases and Restocks into Money Facts (P2)

**Independent test**: purchase quick capture/estimate/lifecycle, direct expense/installment exclusivity,
reversal/blocker restoration, restock one-way expense/no-stock behavior, retry/concurrency, history context,
ownership, and old Storage/Supplement behavior pass.

- [x] T121 [P] [US6] Add purchase type/validation/blocker compatibility tests in `StoragePurchaseIntegrationTest.php`
- [x] T122 [P] [US6] Add purchase direct/installment/reversal/race tests in `FinancePurchaseSourceTest.php`
- [x] T123 [P] [US6] Add restock expense/no-stock/retry/reversal tests in `FinanceRestockSourceTest.php`
- [x] T124 [P] [US6] Extend 008/017/018 contract tests for additive purchase/source members
- [x] T125 [US6] Implement Item purchase validation, estimate Money, purchase-specific lifecycle labels, and default compatibility
- [x] T126 [US6] Prohibit direct bought mutation and invalid finance action for canceled/foreign/non-purchase items
- [x] T127 [US6] Implement `FinanceSourceExpenseService` source row locking and active-path detection
- [x] T128 [US6] Extend internal ledger actual post with validated optional source pair without widening public actual input
- [x] T129 [US6] Create direct purchase expense from explicit/draft estimate and synchronize bought lifecycle atomically
- [x] T130 [US6] Create unique `owe` fixed installment debt from purchase without immediate expense
- [x] T131 [US6] Prevent simultaneous active direct-expense and installment-debt paths under retry/concurrency
- [x] T132 [US6] Restore purchase wanted/blocker state when the only direct source expense is reversed
- [x] T133 [US6] Keep purchase bought for a historical installment debt through settlement/archive
- [x] T134 [US6] Post open restock proposal expense with one-way source link and never mutate stock/proposal lifecycle
- [x] T135 [US6] Decorate transaction history with safe owned source label/action URL and inactive/reversed evidence
- [x] T136 [US6] Extend Item request/resource/controller/types and Storage completion guard/source counts consistently
- [x] T137 [US6] Run US6 plus full Storage/Supplements/018 ledger/reversal/ownership checkpoint and fix every defect

---

## Phase 9: User Story 7 — Complete Shared Client (P3)

**Independent test**: all new backend journeys are usable in EN/RU/UK on desktop/exact-mobile, retain
drafts/focus, follow safe deep links, remain accessible/overflow-free, and synchronize to Android.

- [x] T138 [P] [US7] Add exact TypeScript types for counterparty/debt/fund/goal/source/extended occurrence/cash-flow contracts
- [x] T139 [P] [US7] Add typed client methods for all 19 operations and preserve older signatures
- [x] T140 [US7] Build `FinanceDebtPanel.vue` for counterparties, both modes, schedule, payment, history, state, and reversal link
- [x] T141 [US7] Build `FinanceFundPanel.vue` for both storage modes, emergency rules/evidence, movements, progress, and states
- [x] T142 [US7] Build `FinanceGoalPanel.vue` for both typed goals, exact milestones, lifecycle, and aggregate progress
- [x] T143 [US7] Extend Finance navigation/deep-link/month state for Debts, Funds, Goals and existing tabs without draft loss
- [x] T144 [US7] Extend Storage purchase editor/list/actions/parent blocker presentation with estimated Money and source state
- [x] T145 [US7] Extend Supplement restock card with explicit Finance expense action and no false stock-arrival copy
- [x] T146 [US7] Extend transaction history, Planner, Notifications, and unified Goals UI with safe source/occurrence links
- [x] T147 [US7] Add every visible/domain/validation/ARIA string simultaneously to EN/RU/UK dictionaries and changelog
- [x] T148 [US7] Extend i18n dynamic-key guard allowlists narrowly and prove parity/usage/hardcoded-copy checks
- [x] T149 [US7] Add responsive debt/fund/goal/source layout, 44px controls, safe areas, live regions, focus/error, and both-scheme styles
- [x] T150 [US7] Complete permanent browser journey coverage for create/edit/pay/reverse/move/skip/top-up/goal/buy/restock/deep-link/reload
- [x] T151 [US7] Generate and inspect EN/RU/UK × light/dark × desktop/exact-390 visual matrix for all main new surfaces
- [x] T152 [US7] Run i18n, TypeScript, Vitest, production build, focused desktop/mobile Playwright and fix all failures
- [x] T153 [US7] Run mobile Node/audit/Capacitor sync and record final shared-bundle fingerprint

---

## Phase 10: Full Closure and Atomic Delivery

- [x] T154 Run focused backend for all seven stories and shared 008/009/011/017/018/019 integrations
- [x] T155 Run full Laravel suite and record exact tests/assertions with zero unexplained skip/failure
- [x] T156 Run Pint, strict Composer validation, locked Composer audit, and route/OpenAPI parity
- [x] T157 Repeat isolated migration rollback/reapply with seeded pre-020 users/currencies/019 data
- [x] T158 Run full Playwright desktop project and record exact pass/conditional-skip result
- [x] T159 Run full Playwright exact-mobile project and record exact pass/conditional-skip result
- [x] T160 Reinspect every final screenshot after the last UI change and document visual evidence
- [x] T161 Run mobile Node/audit/final sync after the last shared-client change and update fingerprint
- [x] T162 Run `git diff --check`, secret, dependency, >1 MiB, generated artifact, protected path, and handoff audits
- [x] T163 Refresh GitNexus, run staged change detection, and review every medium/high/critical direct consumer
- [x] T164 Update README, API/web changelogs, roadmap, Finance ER, modules, recurrence, notifications, data conventions, and decisions in English
- [x] T165 Update `C:\Code\memory\projects\selfhandler\overview.md` with 020 decisions, gates, commit, and next 021
- [x] T166 Run final Spec Kit read-only analysis and resolve every critical/high/medium/low inconsistency
- [x] T167 Verify all 173 tasks, 44 FR, 13 SC, 28 acceptance scenarios, checklist items, and explicit deferrals have evidence
- [x] T168 Change spec status to Complete and append exact GREEN/rollback/visual/mobile/GitNexus evidence to `analysis.md`
- [x] T169 Stage only authorized 020/shared/docs files and prove no deployment/handoff path is cached
- [x] T170 Create one meaningful atomic non-coauthored feature commit on current `master`
- [x] T171 Push `master`, fetch origin, and prove local HEAD equals `origin/master`
- [x] T172 Confirm handoff remains untouched/untracked and the worktree has no other unexpected change
- [x] T173 Immediately select feature 021 Private Attachments and begin its Spec Kit cycle without deployment

## Dependencies and Execution Order

- Phase 1 precedes permanent RED; Phase 2 blocks every story.
- US1 flexible debt establishes principal/payment truth before US2 fixed scheduling.
- US3 fund storage/progress blocks US4 emergency recurrence and cash-flow composition.
- US5 consumes proven debt/fund projections; it never writes their progress.
- US6 consumes the proven ledger/debt boundary and preserves Storage/Supplement ownership.
- US7 starts typed contract work after backend shapes are stable; UI files sharing FinanceView run serially.
- Closure runs only after every independent story checkpoint passes.

## Requirement Traceability

| Requirements | Primary tasks |
|---|---|
| FR-001 counterparties | T036, T039–T040, T049–T052 |
| FR-002–005 flexible debt and balance | T019, T037–T048, T051–T053 |
| FR-006–012 fixed schedule/payment/history | T024–T027, T054–T070 |
| FR-013–018 fund storage/movements/progress | T020, T071–T088 |
| FR-019–025 emergency/cash flow/shared surfaces | T089–T105 |
| FR-026–029 Finance Goals | T021, T106–T120 |
| FR-030–036 purchase/restock/source history | T022–T023, T121–T137 |
| FR-037–039 ownership/query/contracts | T011–T035, T043, T051, T069, T087, T115, T124, T135–T139, T154–T157 |
| FR-040–041 localization/accessibility/shared client | T138–T153, T158–T161 |
| FR-042 additive evolution | T018, T028–T030, T157, T162 |
| FR-043 ownership direction | T002–T005, T039–T048, T075–T084, T109–T118, T125–T136, T164 |
| FR-044 explicit deferrals | T003–T008, T164, T166–T173 |

## Completion Standard

All tasks are checked only with evidence. Deployment, feature 002, workflows/live data, branches,
worktrees, merge, handoff, amortization/interest, investments, providers, generic one-off plans,
imports/exports/reports, calendar integration, AI, and native offline authority are prohibited.
