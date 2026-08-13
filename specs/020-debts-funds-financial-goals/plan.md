# Implementation Plan: Debts, Funds, Financial Goals, and Purchase Links

**Branch**: current user-selected `master` only
**Date**: 2026-08-13
**Spec**: [spec.md](spec.md)

## Summary

Complete the remaining deterministic Finance aggregates on top of 018/019: principal-only debts and
payments, virtual/linked saving and emergency funds, typed Finance Goal projections, and strict one-way
purchase/restock source links. Extend shared recurrence/Planner/Notifications and cash flow without
duplicating schedule, money, goal, Storage, or Supplement truth. Ship closed contracts and a complete
EN/RU/UK responsive web/Android client; keep every later integration and deployment concern absent.

## Technical Context

- Laravel 12 / PHP 8.4 / Eloquent / Sanctum; MySQL 8 target and SQLite portable tests
- Existing exact `Money`, BCMath decimal strings, immutable transaction groups/entries/reversals
- Existing monthly RecurringRule/PlannedOccurrence, Finance occurrence, Planner, notification pipeline
- Existing common Goal/Milestone, Storage Item/blockers, SupplementRestockProposal boundaries
- Vue 3 / TypeScript / Vite / typed i18n/theme; Capacitor 8 shared bundle
- PHPUnit, Pint, Composer/npm audit, Vitest, Playwright desktop/mobile, GitNexus

## Constitution Check

| Principle | Plan evidence | Gate |
|---|---|---|
| Specifications first | Full 020 artifacts and permanent RED tests precede production edits | Pass |
| Vision vs delivery | Canonical Goals/Storage/Finance docs linked; financial formulas are explicitly bounded | Pass |
| Thin vertical slice | One independently useful close-out of the remaining Finance aggregates and locked links | Pass |
| Deterministic core | Exact principal/reserve/FX/progress calculations; no AI/provider dependency | Pass |
| Ownership/privacy | Every private row, source, query, lock and unique boundary is owner-safe | Pass |
| Contracts/tests | Persistence, shared adapters, API/OpenAPI/types/client/browser move together | Pass |
| Complete localization | Every new visible string/error/enum/ARIA ships EN/RU/UK | Pass |

No deviation or complexity exception is required.

## Architecture Gates

1. **Ownership**: Finance owns counterparties, debt/fund rules, payment/allocation facts, projections,
   source money links and Finance-goal details. Common Goal owns lifecycle/milestones; Storage owns Item;
   Supplements owns stock/proposals; shared modules own schedule/delivery/presentation.
2. **Profile inputs**: base currency, timezone, locale, and unit/display preferences are read from Profile
   and never copied as mutable authority.
3. **Timezone/date**: origination/deadline/due/movement dates are Profile-local calendar values; reminder
   times are local; overdue and three-complete-month windows are evaluated in Profile timezone.
4. **Recurrence reuse**: fixed debts and scheduled funds add typed owners to the existing monthly rule,
   monthday, occurrence, materializer, reschedule, fact reconciliation, and look-ahead window.
5. **Cross-module direction**: Finance points outward to purchase/proposal source; Goal/Storage/Supplements
   do not query ledger internals. A narrow Finance adapter synchronizes derived purchase lifecycle.
6. **Evolution**: one additive migration creates normalized aggregate/detail/fact/movement tables, nullable
   purchase/source/fact fields, safe constraints/indexes, and an exact rollback that preserves 019.
7. **Contracts**: 14 paths / 19 authenticated operations plus additive extensions to 008/009/011/018/019;
   requests/resources/OpenAPI/TypeScript/client consumers change together.
8. **Aggregates**: Finance derives remaining principal, saved/reserved/available, emergency requirement,
   goals, and cash flow in grouped bounded services; no mutable balance/progress cache or copied review data.
9. **Privacy**: all new information is private, foreign references are 404-equivalent, source context is
   safely decorated, and no provider/credential/file/export surface is introduced.
10. **Deferral**: compound interest, variable schedules, investments, providers/bank sync, generic one-off
    plans, reporting, calendar, Analytics/Review, AI, offline-native ownership, and deployment stay absent.

## Project Structure

```text
specs/020-debts-funds-financial-goals/
├── spec.md                 ├── research.md
├── checklists/requirements.md
├── plan.md                 ├── data-model.md
├── contracts/openapi.yaml  ├── quickstart.md
├── tasks.md                └── analysis.md

apps/api/
├── database/migrations/*create_debts_funds_financial_goals.php
├── app/Models/Finance/{conceptual: counterparty, debt, payment, fund, movement, details/facts, goal detail}
│   (physical classes remain under app/Models per repository convention)
├── app/Services/Finance/{FinanceDebtService,FinanceDebtPaymentService,FinanceFundService,
│   FinanceFundProjectionService,FinanceFundMovementService,FinanceGoalService,
│   FinanceGoalProgressService,FinanceSourceExpenseService,FinancePlannedMoneyService}.php
├── app/Services/{RecurrenceMaterializer,OccurrenceFactSynchronizer,ItemCompletionGuard}.php
├── app/Services/Planner/FinanceOccurrenceSource.php + Notifications integration
├── app/Http/{Requests,Resources,Controllers}/Finance/* + affected Goal/Item resources/controllers
├── routes/api.php, lang/{en,ru,uk}/*
└── tests/{Unit,Feature}/Finance + affected shared integration/contract suites

apps/web/
├── src/api/{types.ts,client.ts}
├── src/components/finance/{FinanceDebtPanel,FinanceFundPanel,FinanceGoalPanel}.vue
├── src/views/{FinanceView,GoalsView,StorageView,SupplementsView,PlannerView,NotificationsView}.vue
├── src/i18n/locales/{en,ru,uk}.ts, src/style.css, src/content/changelog.ts
├── src/__tests__/finance-commitments-contracts.test.ts
└── e2e/finance/{finance-commitments-flow,finance-commitments-visual}.spec.ts
```

## Delivery Phases

1. Finalize research/model/contracts/tasks and record expected absent-020 RED.
2. Add additive schema, models, factories, shared typed owners/fact links and legacy regressions.
3. Deliver counterparty/debt lifecycle, fixed schedule, payments, reversal-derived state.
4. Deliver virtual/linked funds, movements, emergency formulas, scheduled outcomes and account availability.
5. Deliver cash-flow, Planner and Notifications composition for debt/fund occurrences.
6. Deliver Finance Goal detail/progress/milestones and unified Goal compatibility.
7. Deliver purchase type, direct/installment invariant, restock source expense and source-safe history.
8. Deliver closed API/OpenAPI/types and the full localized responsive shared client.
9. Run full visual/regression/mobile/rollback/safety/GitNexus gates, docs/memory, atomic push.

## Verification Strategy

- Permanent RED-first schema/model/service/API/shared-client/browser tests, then smallest production code.
- Exact decimal/idempotency/concurrency/reversal fixtures for debt, reserve, transfer and source actions.
- Leap/short-month/count/timezone tests and legacy recurrence materializer set/query regressions.
- Fixed query-budget tests for debt/fund/goal/source/account/cash-flow projections.
- Full Laravel, Pint, Composer validate/audit, identifier and isolated rollback/reapply checks.
- OpenAPI parse/ref/closed-schema/security/route parity including synchronized older contracts.
- i18n parity/used-key/hardcoded-copy, TypeScript, Vitest, production build.
- Focused/full Playwright desktop/mobile; inspect EN/RU/UK × scheme × main new surfaces/contact sheets.
- Mobile Node/audit/Capacitor sync/fingerprint and secret/large-file/diff/protected/handoff audits.
- GitNexus refreshed detection plus direct-consumer review for every high/critical shared symbol.

## Complexity Tracking

Normalized counterparty, debt, debt occurrence/payment, fund, allocation movement, fund occurrence/fact,
and Finance-goal detail tables represent distinct immutable or queryable truths. They are not collapsed
into JSON because ownership, idempotency, range queries, historical correction, and uniqueness require
relational constraints. No second money ledger exists: only virtual allocation uses its own append-only
reserve ledger; all real money remains in 018 transaction groups/entries.
