# Implementation Plan: Finance Ledger Foundation

**Branch**: current user-selected `master` only
**Date**: 2026-08-13
**Spec**: [spec.md](spec.md)

## Summary

Deliver one auditable Finance ledger slice: exact multi-currency accounts, localized two-level
categories, actual income/expense groups, atomic paired transfers, append-only reversal/reconciliation,
manual historical FX, derived balances and bounded summaries, closed REST/OpenAPI/TypeScript contracts,
and the complete EN/RU/UK responsive shared client. The implementation establishes the money source of
truth required by 019/020 without implementing their budgets, recurrence, debts, or cross-module links.

## Technical Context

- **Backend**: Laravel 12 / PHP 8.4 / Eloquent / Sanctum
- **Persistence**: MySQL 8 target; SQLite portable automated tests
- **Exact arithmetic**: BCMath decimal strings; `DECIMAL(19,4)` amounts and `DECIMAL(24,12)` FX
- **Frontend**: Vue 3, TypeScript, Vite, existing typed i18n/theme/UI primitives
- **Mobile**: existing Capacitor 8 Android shell consuming the built web bundle
- **Tests**: PHPUnit, Pint, Composer audit, Vitest, Playwright desktop/mobile, mobile Node/audit/sync

## Constitution Check

| Principle | Plan evidence | Gate |
|---|---|---|
| Specifications first | Spec/checklist/research/model/contracts/tasks precede product code | Pass |
| Vision vs delivery | Finance docs are linked; 018 boundary narrows 019/020 explicitly | Pass |
| Thin vertical slice | One useful actual ledger from persistence through client; no adjacent aggregates | Pass |
| Deterministic core | Exact arithmetic/rates/reconciliation are programmatic; no AI | Pass |
| Ownership/privacy | Every private row has `user_id`; references and unique keys are owner-safe | Pass |
| Contracts/tests | Schema/domain/API/OpenAPI/types/client/browser move together | Pass |
| Complete localization | Every new string/enum/error ships EN/RU/UK and passes guards/screenshots | Pass |

No deviation or complexity exception is required.

## Architecture Gates

1. **Owner**: Finance owns accounts, categories, exchange rates, action groups, ledger entries, balances,
   and actual period totals. Currency is immutable global reference metadata, not user data.
2. **Inputs**: Profile owns base currency, locale, and timezone. Finance reads them and copies none.
3. **Time**: actuals/rates use explicit local calendar dates; current-date validation uses Profile
   timezone; timestamps remain UTC. No scheduled instant is introduced.
4. **Scheduling**: 018 has no recurring behavior. Feature 019 will attach planned operations to the
   existing `RecurringRule`/`PlannedOccurrence`; no Finance schedule table is created.
5. **Cross-module links**: none in 018. Money-side polymorphic purchase/Supplement sources wait for 020,
   preserving one authoritative direction and avoiding a placeholder invariant.
6. **Evolution**: one additive migration creates five Finance/private tables plus Currency reference,
   seeds reference rows idempotently, uses MySQL-safe names/restrictive domain FKs, and rolls back only
   018. No live row is rewritten.
7. **Contracts**: 15 routes, closed OpenAPI schemas, resources, TypeScript models/client operations,
   locale dictionaries, web components, and affected navigation/changelog change together.
8. **Aggregates**: Finance services compute balance/consolidation/range totals from entries. Review and
   Analytics receive no copied facts. Cache/rollup extraction waits for measured need/feature 023.
9. **Privacy**: all Finance data is private, owner-filtered, same-owner validated, 404-isolated, absent
   from logs, and cascades only with account deletion. No external data or tokens exist.
10. **Deferral**: budgets/recurrence (019), debts/funds/goals/purchases/restocks (020), attachments (021),
    analytics (023), export (024), integrations (025), AI (026), investments/offline/deployment remain out.

## Project Structure

### Specification artifacts

```text
specs/018-finance-ledger-foundation/
├── spec.md
├── checklists/requirements.md
├── research.md
├── plan.md
├── data-model.md
├── contracts/openapi.yaml
├── quickstart.md
├── tasks.md
└── analysis.md
```

### Backend

```text
apps/api/
├── database/migrations/*create_finance_ledger_foundation.php
├── database/factories/{Finance*,Currency*}Factory.php
├── app/ValueObjects/Money.php
├── app/Models/{Currency,FinanceAccount,FinanceCategory,FinanceExchangeRate,
│   FinanceTransactionGroup,FinanceLedgerEntry}.php
├── app/Services/Finance/{Account,Category,Ledger,ExchangeRate,Summary}Service.php
├── app/Http/Requests/Finance/*.php
├── app/Http/Resources/Finance/*.php
├── app/Http/Controllers/Finance/*.php
├── routes/api.php
├── lang/{en,ru,uk}/{messages.php}
└── tests/{Feature,Unit}/Finance/
```

### Shared client

```text
apps/web/
├── src/api/{types.ts,client.ts}
├── src/finance/money.ts
├── src/components/finance/*.vue
├── src/views/FinanceView.vue
├── src/{router.ts,style.css}
├── src/layouts/AppShell.vue
├── src/i18n/locales/{en,ru,uk}.ts
├── src/content/changelog.ts
├── src/__tests__/finance-contracts.test.ts
└── e2e/finance/{finance-flow,finance-visual}.spec.ts
```

## Delivery Phases

1. Freeze tests/contracts and record the expected missing-feature RED baseline.
2. Add portable schema, Money, owner/immutability invariants, factories, and exact arithmetic.
3. Deliver accounts/opening/reconciliation and categories/starter hierarchy.
4. Deliver income/expense/idempotency/reversal and paired transfers.
5. Deliver manual FX, consolidation, and bounded actual summaries.
6. Deliver closed API/resources/OpenAPI and full EN/RU/UK shared client.
7. Run visual/full regressions, rollback/security/protected-path/GitNexus audits, docs/memory, atomic push.

## Verification Strategy

- RED-first permanent tests at schema/value/domain/API/client/browser boundaries.
- Focused Finance tests plus affected Profile/auth/date/locale tests.
- Full Laravel, Pint, strict Composer validation and locked security audit.
- OpenAPI parse/ref closure/closed-object/route parity.
- i18n parity/used-key/hardcoded-copy, typecheck, Vitest, production build.
- Focused and full Playwright on desktop and mobile; EN/RU/UK × light/dark visual matrix inspected.
- Mobile Node, npm audit, Capacitor sync and bundle fingerprint.
- Isolated fresh migration + one-step rollback preserving 017 and all prior tables.
- Secret/dependency/large-file/diff/protected deployment/handoff/status audits.
- GitNexus refresh, full and staged change detection, direct-consumer/high-risk flow review.

## Complexity Tracking

None. Five private tables plus one immutable reference table directly represent current facts. Group +
entry is required by the locked paired-transfer decision and append-only correction; it is not a future
abstraction.
