# Data Model: Finance Ledger Foundation

**Feature**: `018-finance-ledger-foundation`
**Date**: 2026-08-13

## Exact Types

- Money amount/delta: `DECIMAL(19,4)`, canonical signed decimal string internally.
- Public amount input: positive canonicalizable decimal string with at most four fractional digits.
- Exchange rate: `DECIMAL(24,12)`, strictly positive.
- Currency: uppercase three-letter code referencing `currencies.code`.
- Dates: Profile-local `DATE`; server timestamps are UTC.
- Idempotency/action identity: UUID/string keys; payload hashes are SHA-256 hex.

## `currencies`

Immutable reference data, not private user data.

| Column | Type | Rules |
|---|---|---|
| `code` | `CHAR(3)` PK | UAH/USD/EUR initially |
| `decimal_places` | unsigned tiny integer | display metadata, `2` initially |
| `is_active` | boolean | default true |
| timestamps | timestamps | reference audit |

Rows are inserted idempotently by the additive migration and match Profile configuration.

## `finance_accounts`

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascade only with account deletion |
| `name` | varchar(120) | trimmed, private user content |
| `type` | varchar(24) | cash/card/savings/currency |
| `currency_code` | FK currencies | restrict; immutable after first entry |
| `archived_at` | nullable UTC timestamp | domain archive; only exact-zero balance |
| timestamps | timestamps | |

No balance/opening-balance column exists. Balance = grouped sum of owned ledger deltas.

Indexes: `(user_id, archived_at, name)`, `(user_id, currency_code)`.

## `finance_categories`

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascade with account deletion |
| `direction` | varchar(12) | income/expense; immutable |
| `parent_id` | nullable FK self | restrict; same owner/direction; parent must be root |
| `parent_scope` | bigint unsigned | `0` root or exact `parent_id`; uniqueness helper |
| `builtin_key` | nullable varchar(64) | stable localized starter identity |
| `name` | nullable varchar(120) | required for custom, null for built-in |
| `name_normalized` | varchar(120) | lowercase custom name or builtin key |
| `archived_at` | nullable UTC timestamp | history-visible |
| timestamps | timestamps | |

Unique: `(user_id, builtin_key)` and `(user_id, direction, parent_scope, name_normalized)`.

## `finance_exchange_rates`

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascade with account deletion |
| `from_currency` | FK currencies | differs from `to_currency` |
| `to_currency` | FK currencies | |
| `rate_date` | date | no later than Profile-local today |
| `rate` | decimal(24,12) | positive exact decimal |
| `source` | varchar(16) | `manual` only in 018 |
| timestamps | timestamps | correction audit timestamp |

Unique: `(user_id, from_currency, to_currency, rate_date)`.

## `finance_transaction_groups`

Immutable root for one accepted user action.

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascade with account deletion |
| `public_id` | UUID | unique stable client identity |
| `kind` | varchar(16) | income/expense/transfer/adjustment |
| `occurred_on` | date | actual local date, no future |
| `idempotency_key` | varchar(120) | unique per user |
| `payload_hash` | char(64) | normalized accepted input hash |
| `note` | nullable varchar(1000) | private user content |
| `tag` | nullable varchar(80) | private user content; not shared tag system |
| `reverses_group_id` | nullable FK same table | unique; one correction only |
| `reversal_reason` | nullable varchar(500) | required iff reversal |
| `fx_from_currency` | nullable FK currencies | cross-currency transfer only |
| `fx_to_currency` | nullable FK currencies | cross-currency transfer only |
| `effective_rate` | nullable decimal(24,12) | `destination/source` snapshot |
| timestamps | timestamps | accepted action timestamp |

Unique: `(user_id, public_id)`, `(user_id, idempotency_key)`, `reverses_group_id`.
The model refuses update/delete after creation.

## `finance_ledger_entries`

Immutable account-currency delta facts.

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascade with account deletion |
| `transaction_group_id` | FK groups | restrict; same owner |
| `account_id` | FK finance_accounts | restrict; same owner |
| `category_id` | nullable FK finance_categories | restrict; same owner/direction |
| `role` | varchar(16) | primary/source/destination |
| `delta_amount` | decimal(19,4) | non-zero signed exact delta |
| `currency_code` | FK currencies | immutable snapshot, equals account currency |
| timestamps | timestamps | |

Unique: `(transaction_group_id, role)`. The model refuses update/delete after creation.

## Relationships and Invariants

- User has many accounts/categories/rates/groups/entries.
- Group has one primary entry or exactly source+destination transfer entries.
- Entry account/category/group owners all match `entry.user_id`.
- Income: `kind=income`, one primary positive delta, active income category.
- Expense: `kind=expense`, one primary negative delta, active expense category.
- Adjustment: one signed primary delta, no category.
- Transfer: distinct active accounts, no category, source negative, destination positive.
- Cross-currency group snapshots both currency codes and exact effective rate; same-currency does not.
- Reversal group copies kind/date context as a new current action, points to an unreversed non-reversal
  group, and contains exact opposite entries.
- Starter category materialization uses database unique keys and retry-after-conflict reads.
- Archived accounts/categories remain eager-loadable through `withArchived` relations.

## Derived Projections

### Account balance

`SUM(finance_ledger_entries.delta_amount)` grouped by owned account. Missing sum is `0.0000`.

### Actual range totals

- income = signed sum of entries in groups with `kind=income`;
- expense = absolute value of signed sum in groups with `kind=expense`;
- net = income − expense;
- transfer and adjustment groups are excluded.

Reversal entries use the original kind with opposite signs and therefore cancel exact totals.

### Consolidated balance

1. Group all account balances by currency, including archived non-zero accounts.
2. Base-currency amount converts 1:1.
3. For every other non-zero currency choose latest direct or inverse owned rate `<= as_of`.
4. Convert with guard precision and one half-up round to four decimals.
5. If any pair is missing, return `total=null`, `complete=false`, and sorted missing currencies.

## Lifecycle

```text
FinanceAccount: active ⇄ archived (archive only at zero)
FinanceCategory: active ⇄ archived
FinanceTransactionGroup: posted → reversed-by-one-linked-group
FinanceLedgerEntry: created → immutable forever
FinanceExchangeRate: unique date fact may be corrected in place with updated_at
```

## Migration and Rollback

- Add the six tables in dependency order; no existing table is altered.
- Seed currencies after the reference table is available.
- All explicit index/constraint names remain within MySQL's 64-character limit.
- Domain reference FKs restrict deletion; every private table also directly references users with
  cascade so account deletion can remove the whole private graph.
- `down()` drops ledger entries, groups, rates, categories, accounts, then currencies, and nothing else.
