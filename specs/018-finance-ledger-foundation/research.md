# Research: Finance Ledger Foundation

**Feature**: `018-finance-ledger-foundation`
**Date**: 2026-08-13

## Sources Reviewed

- `docs/design/modules.md` Module 10
- `docs/design/finance-er.md`
- `docs/design/data-conventions.md`
- `docs/design/decisions.md`
- `docs/design/delivery-roadmap.md` feature 018 and architecture gates
- Constitution 1.2.0 and completed Profile/interface/ledger-adjacent feature contracts
- Current Profile, owner, decimal arithmetic, strict request, resource, client, locale, and browser code
- Refreshed GitNexus queries for Profile base currency, owner-safe CRUD, and exact aggregates

## Decision 1: One Append-only Ledger, No Balance Column

**Decision**: `FinanceAccount` stores identity, type, currency, and lifecycle only. Every monetary change
is a `FinanceTransactionGroup` containing immutable signed `FinanceLedgerEntry` rows. Balance is the
sum of entry deltas.

**Opening balance**: account creation with a non-zero opening value writes an `adjustment` group and one
entry in the same database transaction.

**Reconciliation**: under an account lock, compute `observed - derived`; append one adjustment group only
when the delta is non-zero.

**Rationale**: this closes the canonical open question without introducing a counter that can drift.
Opening and reconciliation remain auditable and use the same invariant as every other balance change.

**Rejected**:

- mutable `accounts.balance`: a second truth and race-prone;
- mutable `opening_balance`: edits silently rewrite all historical balances;
- a separate reconciliation table: duplicates the ledger fact mechanism.

## Decision 2: User Action Group plus Ledger Legs

**Decision**: one group represents one accepted action. Income, expense, opening, and reconciliation
have one `primary` entry. Transfer has exactly `source` and `destination` entries. Every group and entry
is user-owned; relationship hooks verify the same owner.

**Rationale**: paired transfers are atomic, cross-currency amounts retain their account denominations,
and list/history resources can present one user action rather than two unrelated rows.

**Rejected**:

- a single transfer row with two account FKs: harder to aggregate each account and conflicts with the
  locked Finance ER recommendation;
- two ungrouped transactions: cannot prove atomic identity or safely reverse both legs.

## Decision 3: Correction Is a Linked Reversal Group

**Decision**: posted groups cannot update/delete. A correction creates exactly one new group with
opposite entry deltas, copies the economic kind/category/transfer roles, requires a reason, and links
through `reverses_group_id`. A reversal cannot be reversed in this slice.

**Rationale**: exact balance and category aggregates cancel naturally while evidence remains. The unique
reversal link is a database concurrency backstop.

**Rejected**:

- PATCH/DELETE transaction: destroys accepted financial history;
- editable audit JSON: introduces a second reconstruction path;
- reversing only one transfer leg: violates atomic transfer identity.

## Decision 4: Signed Delta Internally, Positive Closed Inputs Publicly

**Decision**: public income/expense/transfer amounts are positive exact decimal strings. The service
creates signed `DECIMAL(19,4)` deltas: income/credit positive, expense/debit negative. Reconciliation
accepts a signed observed balance because real accounts can be overdrawn.

**Rationale**: one sum computes account balance and reversal is exact negation. Public validation stays
clear and cannot accidentally invert economic meaning.

## Decision 5: Money Boundary and Rounding

**Decision**: a `Money` value object canonicalizes amount+currency and delegates string arithmetic to
the existing BCMath-backed decimal support. Inputs forbid exponent notation and more than four decimal
places. Multiplication/division for FX uses guard precision and one half-up rounding at output.

**Rationale**: follows the canonical `DECIMAL(19,4)` decision and avoids PHP/JavaScript binary floats.
The client treats amounts as strings and only formats for display.

## Decision 6: Currency Reference and Profile Authority

**Decision**: a global immutable `currencies` reference contains the currently supported Profile codes
UAH/USD/EUR. Finance accounts and rates reference it. Consolidation reads `UserProfile.base_currency`
at request time; Finance stores no duplicate base setting.

**Rationale**: Profile is the locked cross-module input owner. Adding a code later is reference/config
evolution rather than ledger migration.

## Decision 7: Manual Historical Rates and Explicit Incompleteness

**Decision**: owned rates are unique by `(user, from, to, rate_date)`. Read-time conversion chooses the
latest direct rate on/before `as_of`, otherwise the latest inverse and calculates its reciprocal. Rate
date and direct/inverse provenance are returned. If a non-zero account currency cannot convert, total
base balance is `null` and missing codes are listed.

**Rationale**: historical facts do not drift, and the UI never adds unlike units or implies an invented
rate. Provider feeds belong to Integrations after the local source of truth stabilizes.

**Rejected**:

- current-only config rate: rewrites history;
- implicit 1:1 or zero for missing pairs: false financial claim;
- storing converted balances: duplicated mutable truth.

## Decision 8: Localized Starter Categories

**Decision**: Finance materializes a small user-owned starter tree with stable `builtin_key` values.
Resources translate the label in the active locale. Custom category `name` is stored and returned
verbatim. A `parent_scope` integer (`0` for root, parent ID for child) supports a portable database
unique key despite SQL nullable uniqueness behavior.

**Starter structure**:

- expense: Housing; Food → Groceries, Cafe; Health → Medicine; Transport; Other expense
- income: Salary; Other income

**Rationale**: users can archive built-ins like any owned category, locale switching is correct, and
custom content is never translated. Two levels are sufficient for 018/019.

## Decision 9: Lifecycle Rules

**Decision**:

- account currency is immutable after the first entry;
- account archival requires exact zero and blocks new facts;
- category direction is immutable;
- a used category cannot reparent;
- archived categories/accounts remain eagerly readable through history;
- domain references use restrictive FKs while account deletion cascades all private rows.

**Rationale**: history keeps stable meaning and archival cannot hide money from consolidation.

## Decision 10: Dates, Bounds, and Aggregates

**Decision**: actual facts store Profile-local `occurred_on` dates, reject dates after Profile-local
today, and have server timestamps in UTC. Summary ranges are inclusive and at most 366 dates. Finance
owns account balance, income, expense, and net calculations. Transfers and adjustments are excluded
from cash-flow totals. No cache/rollup is introduced yet; grouped queries have fixed budgets.

**Rationale**: actuals are calendar facts, not scheduled instants. Feature 019 will use shared recurrence
for planned facts without contaminating this actual ledger.

## Decision 11: Closed REST Surface

**Decision**: 15 authenticated operations cover currencies, rate list/upsert, account list/create/update,
reconcile, category list/create/update, transaction list/create, transfer create, reversal, and summary.
All mutations reject unknown fields; OpenAPI object schemas are closed and TypeScript mirrors them.

## Decision 12: Client and Accessibility

**Decision**: `/finance` is one shared responsive workspace split into owned account, category, rate,
entry, transfer, balance, and history components. It uses the established global locale/theme state,
native date controls, decimal strings, live regions, draft preservation, and 44px mobile actions. The
same production web build synchronizes to Capacitor.

## Explicit Non-decisions

- No budgets, recurrence, notification source, planned occurrence, or cash-flow forecast (019).
- No debt/fund/goal/purchase/Supplement money links (020).
- No rates provider, bank import, investment quotes, receipts/OCR, long rollups/export, AI, offline
  mutation authority, or deployment.
