# Implementation Plan: Budget and Recurring Cash Flow

**Branch**: current user-selected `master` only
**Date**: 2026-08-13
**Spec**: [spec.md](spec.md)

## Summary

Deliver monthly category budgets and recurring Finance plans on top of the exact 018 ledger. Extend
shared recurrence only for its first monthly consumer; link explicit outcomes without copying money;
derive cash flow and budget actuals with historical FX; adapt Planner/Notifications; complete closed
API/OpenAPI/types and the EN/RU/UK responsive web/Android client. Keep 020+ domain entities absent.

## Technical Context

- Laravel 12 / PHP 8.4 / Eloquent / Sanctum; MySQL 8 target and SQLite portable tests
- BCMath decimal strings; Money `DECIMAL(19,4)`, FX `DECIMAL(24,12)`
- Existing RecurringRule/PlannedOccurrence, SchedulableSource, notifications and 018 Finance services
- Vue 3 / TypeScript / Vite / typed i18n-theme-controls; Capacitor 8 shared bundle
- PHPUnit, Pint, Composer/npm audit, Vitest, Playwright desktop/mobile, GitNexus

## Constitution Check

| Principle | Plan evidence | Gate |
|---|---|---|
| Specifications first | Complete artifacts and RED tests precede production edits | Pass |
| Vision vs delivery | Canonical Finance/recurrence/notification docs linked; 019 narrows 020+ | Pass |
| Thin vertical slice | Budget + recurring cash-flow outcome only; no debts/funds/one-offs | Pass |
| Deterministic core | Exact recurrence, conversion, thresholds, idempotency; no AI | Pass |
| Ownership/privacy | Every private row/reference/query/unique key is owner-safe | Pass |
| Contracts/tests | Schema/domain/shared/API/OpenAPI/types/client/browser move together | Pass |
| Complete localization | Every new product string/error/enum/ARIA ships EN/RU/UK | Pass |

No deviation or complexity exception is required.

## Architecture Gates

1. **Ownership**: Finance owns limits, recurring-operation semantics, occurrence details/facts, budget
   actuals, planned cash flow and warning eligibility. Shared modules own schedule/delivery/presentation.
2. **Profile inputs**: locale, timezone and base currency are read from Profile and never copied as inputs.
3. **Timezone/date**: month and occurrence dates are Profile-local calendar values; optional reminder
   time is local; delivery timestamps convert to UTC; every range is inclusive and bounded.
4. **Recurrence reuse**: add monthly/by-monthday and a typed owner to the existing rule/materializer;
   no local schedule table. Preserve daily/weekly/interval/cycle/slots behavior exactly.
5. **Cross-module direction**: Finance supplies one read-only Planner source and domain skip action;
   Notifications reads Finance eligibility. Neither Planner nor Notifications writes Finance totals.
6. **Evolution**: one additive migration creates five private/normalized tables and nullable unique
   fact link, with restrictive facts and account/category/group references, MySQL-safe names and exact down.
7. **Contracts**: 7 paths / 11 authenticated operations, closed OpenAPI/resources/requests, TypeScript
   types/client, Planner/notification contract extensions and docs/locales change together.
8. **Aggregates**: Finance performs grouped ledger and recurrence projections; no mutable counter/cache,
   Daily Review copy or Analytics rollup.
9. **Privacy**: finance plans/budgets/outcomes are private; foreign IDs are 404-equivalent; no external
   provider/token/log exposure; global Currency remains reference data only.
10. **Deferral**: debt/fund/goal/purchase/restock links (020), analytics/export/integrations/AI and
    investments/one-off plans/carry-over/offline-native/deployment remain outside 019.

## Project Structure

```text
specs/019-budget-recurring-cash-flow/
├── spec.md                 ├── research.md
├── checklists/requirements.md
├── plan.md                 ├── data-model.md
├── contracts/openapi.yaml  ├── quickstart.md
├── tasks.md                └── analysis.md

apps/api/
├── database/migrations/*create_budget_recurring_cash_flow.php
├── app/Models/{FinanceBudgetLimit,FinanceRecurringOperation,
│   FinanceOccurrenceDetail,FinanceOccurrenceFact,RecurringRuleMonthday}.php
├── app/Services/Finance/{FinanceBudgetService,FinanceRecurringOperationService,
│   FinanceOccurrenceService,FinanceCashFlowService}.php
├── app/Services/{RecurringRuleExpander,RecurrenceMaterializer,OccurrenceFactSynchronizer}.php
├── app/Services/Planner/{FinanceOccurrenceSource,SourceRegistry}.php
├── app/Services/Notifications/* + notification models/settings/request
├── app/Http/{Requests,Resources,Controllers}/Finance/*
├── routes/api.php, lang/{en,ru,uk}/messages.php
└── tests/{Unit,Feature}/Finance + affected shared integration tests

apps/web/
├── src/api/{types.ts,client.ts}
├── src/components/finance/{FinanceBudgetPanel,FinancePlanPanel}.vue
├── src/views/{FinanceView,PlannerView,NotificationsView}.vue
├── src/i18n/locales/{en,ru,uk}.ts, src/style.css, src/content/changelog.ts
├── src/__tests__/finance-planning-contracts.test.ts
└── e2e/finance/{finance-planning-flow,finance-planning-visual}.spec.ts
```

## Delivery Phases

1. Finalize contract/tests and record absent-019 RED only.
2. Add additive schema, models, factories, monthly recurrence and legacy regression.
3. Deliver budget lifecycle/actual/status/FX/warning eligibility.
4. Deliver recurring-operation lifecycle, snapshot materialization and explicit outcomes/reconcile.
5. Deliver cash-flow projection, Planner and Notifications adapters.
6. Deliver API/OpenAPI/types and complete localized responsive shared client.
7. Run full visual/regression/mobile/rollback/safety/GitNexus gates, docs/memory, atomic push.

## Verification Strategy

- Permanent RED-first schema/model/monthly expansion/budget/outcome/cash-flow/API/client/browser tests.
- Focused Finance plus full legacy recurrence/Planner/Notifications/Profile/ledger ownership regressions.
- Full Laravel, Pint, strict Composer validate/audit, MySQL identifiers and isolated rollback.
- OpenAPI parse/ref/closed-schema/route parity and TypeScript consumer parity.
- i18n parity/used-key/hardcoded-copy, typecheck, Vitest, production build.
- Focused/full Playwright desktop/mobile; inspect every EN/RU/UK × scheme × Budget/Plans/Cash Flow state.
- Mobile Node/audit/Capacitor sync/fingerprint; secret/dependency/large-file/diff/protected/handoff audit.
- GitNexus full/staged detection and every medium/high/critical shared direct consumer reviewed.

## Complexity Tracking

Five tables and one nullable fact link are the minimum relational representation: budget limit,
recurring owner, normalized month-day, immutable occurrence snapshot, and explicit outcome. The shared
engine extension has a current Finance consumer and avoids JSON or a duplicate scheduler.
