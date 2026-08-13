# Feature Specification: Finance Ledger Foundation

**Feature ID**: `018-finance-ledger-foundation`

**Created**: 2026-08-13

**Status**: Complete

**Input**: Deliver the first non-deployment Finance vertical slice from the canonical roadmap: exact
multi-currency accounts, two-level categories, actual income and expenses, paired transfers,
historical manual exchange rates, derived balances, append-only reconciliation, archival, and a full
EN/RU/UK shared client. Budgets, recurrence, debts, saving funds, purchase/restock money links,
investments, bank integrations, and AI remain outside this increment.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Maintain Exact Accounts (Priority: P1)

The user creates private cash, card, savings, or currency accounts in an allowed currency. An optional
opening amount becomes an auditable adjustment entry. They can rename, change type, archive a zero-
balance account, restore it, and always see a balance derived from ledger entries rather than a mutable
counter.

**Why this priority**: every actual transaction and later Finance feature depends on a trustworthy
account and balance boundary.

**Independent Test**: create accounts in each supported currency, verify exact opening adjustment and
derived balances, exercise archive/restore and currency immutability, then prove foreign-account 404s.

**Acceptance Scenarios**:

1. **Given** a user with Profile base currency UAH, **When** they create a USD cash account with opening
   amount `10.1250`, **Then** one adjustment group records the exact amount and the derived USD balance
   is `10.1250`.
2. **Given** an account with entries, **When** the user tries to change its currency, **Then** the change
   is rejected without changing account or ledger history.
3. **Given** a non-zero account, **When** archive is requested, **Then** the request explains that the
   account must first be reconciled to zero; a zero-balance account can be archived and restored.
4. **Given** another user's account identifier, **When** it is read or mutated, **Then** the API returns
   the same not-found boundary as for an unknown identifier.

---

### User Story 2 - Organise Income and Expense Categories (Priority: P1)

The user receives a small localized starter set and can add private income or expense groups and one
level of subcategories. They can rename/archive/restore categories while historical transactions keep
their references. Direction and depth cannot become ambiguous.

**Why this priority**: actual income/expense facts require stable classification and feature 019 budgets
will reuse exactly this hierarchy.

**Independent Test**: materialize starter categories idempotently, create group and child categories in
both directions, reject cross-direction parents/grandchildren/duplicates, archive/restore, and verify
localized labels plus owner isolation.

**Acceptance Scenarios**:

1. **Given** a new Finance workspace, **When** categories are first read, **Then** each starter category
   is created once and presented in the request locale without storing translated user content.
2. **Given** an expense group, **When** the user adds an expense subcategory, **Then** it appears under
   the group; a child of that subcategory and an income child are rejected atomically.
3. **Given** a category referenced by history, **When** it is archived, **Then** it remains in historical
   resources but cannot classify a new transaction until restored.

---

### User Story 3 - Record and Correct Actual Cash Flow (Priority: P1)

The user records actual income and expenses against an active account and matching active category.
Every accepted action is an immutable transaction group with exact ledger delta. A mistake is corrected
by one append-only reversal group, so balances and category totals recalculate without erasing evidence.

**Why this priority**: actual facts are the core user outcome and the source for every later budget,
debt, saving, and analytics aggregate.

**Independent Test**: post income/expense facts, retry with an idempotency key, reverse each once, and
verify exact account balance, period totals, category matching, local-date bounds, and audit history.

**Acceptance Scenarios**:

1. **Given** an active UAH account and expense category, **When** `123.4567` is spent, **Then** one group
   contains a `-123.4567 UAH` ledger entry and totals update exactly once.
2. **Given** the same accepted idempotency key and payload, **When** the client retries, **Then** the
   original group is returned without a duplicate entry; a conflicting payload is rejected.
3. **Given** a posted income or expense, **When** the user reverses it with a reason, **Then** one linked
   opposite entry restores the balance and aggregate while both groups remain readable.
4. **Given** an archived account/category, future Profile-local date, mismatched category direction, or
   foreign reference, **When** posting is attempted, **Then** no partial group or entry is stored.

---

### User Story 4 - Transfer Between Accounts (Priority: P1)

The user transfers money between two active owned accounts. One user action creates one group with
exactly one debit and one credit leg. Same-currency amounts match; cross-currency transfers preserve
both original amounts and the effective historical rate. Transfers never count as income or expense.

**Why this priority**: multiple accounts are not trustworthy if moving money changes net cash flow or
can create only one side of a transfer.

**Independent Test**: post same- and cross-currency transfers, simulate retries and validation failures,
reverse the pair, and verify atomic legs, group identity, balances, effective rate, and zero cash-flow
effect.

**Acceptance Scenarios**:

1. **Given** two UAH accounts, **When** `500.0000` is transferred, **Then** the source gets `-500.0000`,
   destination `+500.0000`, and income/expense/net totals remain unchanged.
2. **Given** UAH and USD accounts, **When** `1000.0000 UAH` becomes `24.2500 USD`, **Then** both amounts
   and effective rate `0.024250000000` are immutable snapshots on the group.
3. **Given** any invalid/foreign/identical/archived account or conflicting retry, **When** transfer is
   submitted, **Then** neither leg is stored.
4. **Given** a posted transfer, **When** it is reversed, **Then** both opposite legs are created in one
   linked group and each account returns to its prior balance.

---

### User Story 5 - Consolidate Multi-currency Balances (Priority: P2)

The user records dated manual exchange rates and sees every account in its own currency plus a
consolidated balance in the Profile base currency. Conversion uses the latest owned rate on or before
the selected date, accepts an exact inverse, and explicitly reports currencies that cannot be converted.

**Why this priority**: a multi-currency account list must not silently add unlike units or invent a rate.

**Independent Test**: upsert dated rates, convert direct/inverse fixtures, change Profile base currency,
exercise missing/stale dates, and verify bounded query counts and exact non-drifting values.

**Acceptance Scenarios**:

1. **Given** a USD balance and a USD→UAH rate dated today, **When** the workspace is read, **Then** the
   exact converted value contributes to the UAH consolidated total.
2. **Given** only UAH→USD, **When** USD→UAH is needed, **Then** the exact inverse is used with one
   half-up rounding at four decimals and its provenance is shown.
3. **Given** no applicable rate for a non-base currency, **When** consolidation is read, **Then** the
   total is `null`, missing currencies are listed, and no false partial total is labelled complete.
4. **Given** Profile base currency changes, **When** Finance is reloaded, **Then** no Finance setting is
   copied or migrated; the new Profile input is used immediately.

---

### User Story 6 - Use Finance Across Current Clients (Priority: P3)

The user completes the accounts/categories/rates/income/expense/transfer/reversal/reconciliation loop
in the shared responsive client. Every state is available in English, Russian, and Ukrainian, both
schemes, desktop and exact 390×844, and the same bundle synchronizes into Android.

**Why this priority**: the ledger is not independently useful if it exists only as an API.

**Independent Test**: run the complete flow in desktop and mobile browser projects, switch locale/theme,
reload, reject a mutation and verify rollback, inspect all locale/theme screenshots, and validate the
Capacitor bundle.

**Acceptance Scenarios**:

1. **Given** any supported locale/theme, **When** the user opens `/finance`, **Then** accounts,
   consolidated state, categories, rates, entry forms, transfers, history, reversal, and reconciliation
   are readable without overflow or untranslated product copy.
2. **Given** an API rejection, **When** an optimistic control is used, **Then** the prior data/draft is
   restored, focus remains useful, and a localized live-region error is announced.
3. **Given** exact 390×844, **When** long RU/UK labels and currency values render, **Then** navigation,
   controls, dialogs, cards, and safe areas do not overlap and actionable controls are at least 44px.

## Edge Cases

- Values with exponent notation, more than four decimals, zero/negative user amounts, overflow, `NaN`,
  and currency mismatches reject before persistence.
- Signed ledger deltas are created only by domain services; public income/expense/transfer inputs are
  strictly positive exact decimal strings.
- Account currency is immutable after the first ledger entry; category direction is immutable, and a
  used category cannot be reparented.
- An archived reference remains readable through history but is unavailable for new facts.
- Category depth is at most two; parent and child directions and owners must match.
- Future actual transactions use the Profile calendar date and reject; historical dates remain valid.
- Same-account transfers and partial transfer legs are impossible. Cross-currency effective rate is
  derived from accepted debit/credit amounts, never a binary float.
- A group can be reversed once; reversals cannot themselves be reversed in this increment.
- Reconciliation computes the exact delta under a lock and creates no entry when already equal.
- Consolidation includes active and archived non-zero accounts so archival cannot hide money.
- Missing FX rates produce an incomplete result, never a guessed, zero, or silently partial total.
- Concurrent starter-category reads, idempotent retries, reversals, and reconciliations converge.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Currency amounts MUST be canonical decimal strings persisted as `DECIMAL(19,4)` and
  manipulated by one Money/value arithmetic boundary; application code MUST NOT use binary float.
- **FR-002**: Supported currencies MUST be reference rows keyed by ISO-style three-letter codes and the
  active Profile `base_currency` MUST be the only base-currency input.
- **FR-003**: FinanceAccount MUST be private/user-owned with name, type, currency, archival lifecycle,
  timestamps, and no mutable balance column.
- **FR-004**: Optional opening amount MUST create an append-only adjustment group atomically with
  account creation; account balance MUST equal the sum of owned ledger deltas.
- **FR-005**: Account currency MUST become immutable after its first entry; an account MUST reach exact
  zero before archival and archived accounts MUST reject new entries.
- **FR-006**: FinanceCategory MUST be private/user-owned, direction-specific, at most two levels deep,
  same-owner/same-direction with its optional parent, and archived rather than deleted.
- **FR-007**: A localized starter category set MUST materialize idempotently per user through stable
  built-in keys while custom names remain user content and are never translated.
- **FR-008**: Category direction MUST be immutable; a category referenced by ledger history MUST reject
  reparenting while rename/archive/restore preserves historical references.
- **FR-009**: One actual user action MUST create one user-owned FinanceTransactionGroup and one or two
  user-owned FinanceLedgerEntry facts in one database transaction.
- **FR-010**: Income MUST produce one positive account delta and require an active income category;
  expense MUST produce one negative delta and require an active expense category.
- **FR-011**: Actual transaction dates MUST be Profile-local calendar dates no later than today and all
  request references MUST preserve the authenticated owner boundary.
- **FR-012**: Actual create operations MUST accept a caller idempotency key, return the prior matching
  result on retry, and reject reuse with a different normalized payload.
- **FR-013**: Accepted transaction groups and entries MUST be append-only. Correction MUST create one
  linked reversal group with opposite deltas and a required reason, at most once.
- **FR-014**: A transfer MUST atomically create exactly one debit and one credit entry in distinct active
  owned accounts and MUST carry no category.
- **FR-015**: Same-currency transfer amounts MUST match exactly; cross-currency transfers MUST store both
  account-currency amounts plus a derived exact effective rate snapshot.
- **FR-016**: Transfer and its reversal MUST contribute zero to income, expense, and net cash-flow totals.
- **FR-017**: Reconciliation MUST lock the account, compare a positive/negative observed balance with
  the derived balance, and append only the exact adjustment delta and reason when non-zero.
- **FR-018**: FinanceExchangeRate MUST be private/user-owned, dated, exact, manual in this increment,
  reject same-currency pairs, and upsert uniquely by owner/pair/date.
- **FR-019**: Consolidation MUST use the latest applicable direct or inverse owned rate on/before the
  requested date, round half-up once to four decimals, and expose rate date/direction provenance.
- **FR-020**: If any non-zero currency lacks an applicable rate, consolidated total MUST be nullable and
  enumerate missing currencies; it MUST NOT claim a partial total as complete.
- **FR-021**: Finance MUST expose module-owned account balances and bounded date-range income/expense/net
  totals; no persisted mutable rollup or cross-module recomputation is allowed in this increment.
- **FR-022**: List/range queries MUST be bounded and eager/grouped without per-account, per-category, or
  per-day N+1 behavior.
- **FR-023**: All Finance endpoints MUST require Sanctum, use strict closed request validation, return
  owner-safe 404s, and publish closed OpenAPI 3.1 schemas matching registered routes.
- **FR-024**: `/finance` MUST provide the complete account/category/rate/actual/transfer/reversal/
  reconciliation flow with localized loading, empty, success, validation, and error states.
- **FR-025**: All new user-visible copy, enum labels, validation/domain messages, accessibility text,
  dates, and numbers MUST ship simultaneously in EN/RU/UK with English fallback and existing guards.
- **FR-026**: Desktop and exact 390×844 layouts MUST support light/dark, keyboard, screen readers, 44px
  touch targets, safe areas, no horizontal overflow, and useful focus/live-region feedback.
- **FR-027**: The web build MUST synchronize into the existing Capacitor Android shell without a native
  Finance authority, remote HTML, or offline ledger writes.
- **FR-028**: Schema evolution MUST be additive, MySQL-safe, reversible in isolation, preserve every
  existing row, restrict destructive deletion of ledger references, and cascade private data only when
  its owning account is deleted.
- **FR-029**: Finance docs, ER decisions, data conventions, roadmap, changelog, OpenAPI, TypeScript
  contracts, Spec Kit artifacts, and durable memory MUST describe the delivered boundary consistently.
- **FR-030**: Budgets, recurring/planned cash flow, notifications, debts, saving/emergency funds,
  financial goals, purchase/supplement source links, investments, provider/bank imports, receipts,
  long-period rollups/export, AI, native offline ownership, and deployment MUST remain explicitly deferred.

### Key Entities

- **Currency**: immutable global reference code and display metadata for allowed account/profile money.
- **FinanceAccount**: owned account identity/lifecycle/currency; balance is always derived.
- **FinanceCategory**: owned localized-builtin or custom two-level direction-specific classification.
- **FinanceExchangeRate**: owned manual historical pair/date/rate fact used at read time.
- **FinanceTransactionGroup**: owned immutable user action, idempotency identity, optional reversal and
  cross-currency transfer snapshot.
- **FinanceLedgerEntry**: owned immutable signed account-currency delta, optional category, and group leg.
- **Money**: exact amount+currency boundary with four-decimal canonicalization and arithmetic.

## Success Criteria *(mandatory)*

- **SC-001**: Two accounts expose zero Finance accounts, categories, rates, groups, entries, balances,
  or totals to each other across every route and relationship.
- **SC-002**: Exact fixtures including `0.0001`, `10.1250`, `123.4567`, and values near schema limits
  round-trip without binary-float drift.
- **SC-003**: Opening, income, expense, reversal, transfer, transfer reversal, and reconciliation fixtures
  produce exact independently recomputable balances and no partial groups.
- **SC-004**: Concurrent/retried creates with the same key leave one group; conflicting keys, reversal
  races, and reconciliation races leave one accepted result or a deterministic validation response.
- **SC-005**: Category fixtures prove a maximum depth of two, direction consistency, localized starter
  labels, history preservation, and idempotent materialization.
- **SC-006**: Same- and cross-currency transfers always contain exactly two opposite-role entries;
  income/expense/net totals remain unchanged.
- **SC-007**: Direct, inverse, dated, missing-rate, and Profile base-currency fixtures produce exact,
  provenance-bearing consolidated results with no historical drift.
- **SC-008**: Maximum supported account/category/range fixtures remain within documented fixed query
  budgets and do not scale queries per row/day.
- **SC-009**: Every authenticated Finance operation matches a registered route, closed OpenAPI schema,
  frontend type, and tested client consumer.
- **SC-010**: EN/RU/UK light/dark desktop and exact 390×844 flows have no untranslated feature copy,
  runtime/page/console errors, horizontal overflow, overlap, or inaccessible primary action.
- **SC-011**: Rejected web mutations restore prior data/drafts and announce localized feedback while
  accepted actions survive reload and locale/theme changes.
- **SC-012**: Focused/full Laravel, Pint, Composer audit, i18n, typecheck, Vitest, build, full desktop/
  mobile Playwright, mobile Node/audit/sync, migration rollback, contract, secret, protected-path, and
  GitNexus impact gates all pass before the single feature commit is pushed.

## Assumptions

- The active Profile currencies (`UAH`, `USD`, `EUR`) are the initial Currency reference set. Expanding
  it is an additive reference/config change, not an account-schema change.
- Public amount inputs are positive decimal strings except observed reconciliation balance, which may be
  signed. Internally each ledger entry stores its signed delta.
- The opening balance ambiguity is resolved as an adjustment entry. No balance cache is introduced
  before demonstrated scale requires the canonical event-driven cache/rollup follow-up.
- Starter category labels are translated presentation keyed by immutable built-in keys; custom names are
  stored verbatim and never translated.
- A Finance workspace summary uses a maximum 366-day actual range and an as-of date no later than the
  Profile-local today.
- Clarification was not required because locked design decisions, existing Profile contracts, and the
  append-only audit priority resolve all scope-changing choices.

## Dependencies

- Delivered Profile/base currency and owner boundary from 003/004.
- Delivered global EN/RU/UK/theme/client foundation from 010 and Android shell from 012.
- Existing `UserOwned`, strict request, exact decimal, OpenAPI parity, and client error patterns.
- Features 019 and 020 depend on this ledger; this feature does not depend on recurrence or notifications.

## Explicit Deferrals

- Feature 019 owns budgets, planned/recurring income and expenses, cash-flow forecast, Planner projection,
  reminders, and explicit planned-to-actual realization.
- Feature 020 owns debts, counterparties, saving/emergency funds, financial goals, purchases, and the
  one-off Supplement restock-to-money link.
- Investments/quotes, provider currency feeds, bank import/sync, receipt attachments/OCR, exports and
  long-period rollups, anomaly/AI advice, shared finance access, native offline writes, and deployment
  require later dedicated increments.
