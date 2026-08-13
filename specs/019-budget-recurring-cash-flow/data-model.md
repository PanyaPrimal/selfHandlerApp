# Data Model: Budget and Recurring Cash Flow

**Feature**: `019-budget-recurring-cash-flow`
**Date**: 2026-08-13

## Exact Types and Calendar Rules

- Money values use `DECIMAL(19,4)` and canonical decimal strings; no binary floating-point arithmetic.
- Currency codes reference the immutable 018 `currencies.code` rows.
- Budget months are stored as the first Profile-local calendar date of a month and exposed as `YYYY-MM`.
- Occurrence dates and ledger `occurred_on` values are Profile-local `DATE`; reminder times are local
  `TIME`; timestamps are UTC.
- Monthly interval eligibility is anchored to the calendar month containing `starts_on`.
- A null explicit `ends_on` resolves to the inclusive date ten years after `starts_on`; it is not an
  unbounded rule. An earlier explicit end remains inclusive.
- A selected month-day 29–31 that is absent from an eligible month produces no occurrence and is never
  clamped to the last day.

## `finance_budget_limits`

One private exact limit for one non-overlapping category scope and month.

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascade on user deletion |
| `category_id` | FK finance_categories | restrict; owned active expense category on create |
| `budget_month` | date | first day of month |
| `limit_amount` | decimal(19,4) | strictly positive |
| `currency_code` | FK currencies | active supported reference, restrict |
| timestamps | timestamps | |

Unique: `(user_id, category_id, budget_month)`.

The service locks the owner's same-month budget rows before create/update and rejects any other row
whose category is the selected category's parent or child. This two-level hierarchy invariant cannot be
expressed by a portable ordinary unique constraint. Archived categories remain readable for existing
budgets but cannot receive a new or moved limit.

## `finance_recurring_operations`

Finance-owned monetary semantics for one shared monthly rule.

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascade on user deletion |
| `name` | varchar(160) | trimmed private user content |
| `direction` | varchar(12) | `income` or `expense` |
| `account_id` | FK finance_accounts | restrict; same owner and active on accepted edit |
| `category_id` | FK finance_categories | restrict; same owner/direction and active on accepted edit |
| `amount` | decimal(19,4) | strictly positive |
| `currency_code` | FK currencies | equals account currency; immutable snapshot source |
| `is_mandatory` | boolean | true only for expense |
| `is_active` | boolean | paused operations do not project future occurrences |
| `is_archived` | boolean | history-only lifecycle flag |
| `archived_at` | nullable UTC timestamp | present iff archived |
| timestamps | timestamps | |

Indexes: `(user_id, is_archived, is_active, name, id)`, `(user_id, account_id)`, and
`(user_id, category_id)`.

Each operation owns exactly one existing `recurring_rules` row through
`owner_type=finance_recurring_operation`; the rule remains the only schedule authority. Operations with
materialized or settled history are archived rather than hard-deleted.

## `recurring_rule_monthdays`

Normalized selected calendar days for the shared rule.

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascade on user deletion; equals rule owner |
| `recurring_rule_id` | FK recurring_rules | cascade on rule deletion |
| `monthday` | unsigned tiny integer | 1–31 |
| timestamps | timestamps | |

Unique: `(recurring_rule_id, monthday)`. A Finance monthly rule has 1–10 members. Legacy daily/weekly
rules have none. Existing `interval_count` stores 1–12 months, existing `starts_on` and optional
`ends_on` define the inclusive range with the ten-year implicit ceiling above, and existing `slot_time`
stores the optional reminder time.

## `finance_occurrence_details`

One Finance snapshot for one shared materialized occurrence.

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascade on user deletion |
| `planned_occurrence_id` | FK planned_occurrences | unique; cascade only with an unfactored occurrence |
| `finance_recurring_operation_id` | FK operations | restrict; same owner |
| `operation_name` | varchar(160) | accepted private-name snapshot |
| `direction` | varchar(12) | income/expense snapshot |
| `account_id` | FK finance_accounts | restrict; same owner |
| `category_id` | FK finance_categories | restrict; same owner/direction |
| `amount` | decimal(19,4) | positive exact snapshot |
| `currency_code` | FK currencies | account-currency snapshot |
| `is_mandatory` | boolean | allowed only for expense |
| timestamps | timestamps | |

Indexes: `(user_id, finance_recurring_operation_id)` and `(user_id, direction)`. Materialization inserts
the occurrence and detail together. Accepted operation edits update only details whose occurrence is
future, has no fact, and has never been rescheduled. Fact-bound or moved snapshots remain historical.

## `finance_occurrence_facts`

One explicit result for one Finance occurrence.

| Column | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users | cascade on user deletion |
| `planned_occurrence_id` | FK planned_occurrences | unique; restrict ordinary deletion |
| `outcome` | varchar(16) | `actual` or `skipped` |
| `transaction_group_id` | nullable FK finance_transaction_groups | unique, restrict; required only for actual |
| `occurred_on` | nullable date | effective occurrence date; required only for actual |
| timestamps | timestamps | accepted action audit |

Actualization locks the occurrence/detail/account/category, writes one normal 018 income/expense group
with the stable idempotency key `finance-occurrence:{planned_occurrence_id}`, and inserts the fact in one
transaction. Retrying the same action returns that identity; a conflicting outcome is rejected. A
skipped fact has no group/date and may be deleted by the domain clear action. An actual fact is
immutable; its group can only be corrected by the existing one-linked-reversal action.

## Existing `planned_occurrences` Additive Column

| Column | Type | Rules |
|---|---|---|
| `finance_occurrence_fact_id` | nullable FK finance_occurrence_facts | unique; null on fact deletion |

This column and shared `status` are rebuildable mirrors, not a second fact authority.
`OccurrenceFactSynchronizer` matches owned Finance facts by exact occurrence identity, sets `actual`
facts to shared `done`, sets `skipped` facts to shared `skipped`, and restores unmatched occurrences to
`planned`. The direct fact-to-occurrence unique key is authoritative; the mirror exists for the common
Planner/materializer query shape.

## Relationships and Ownership Invariants

- User has many budgets, operations, month-days, occurrence details and occurrence facts.
- Every child repeats `user_id`; model hooks and services reject any cross-owner reference before write.
- Operation account currency equals `currency_code`; category direction equals operation direction.
- Budget category is expense. A root scope contains itself and every direct child; a child scope contains
  only itself. Same-month stored scopes therefore never overlap.
- `RecurringRule.owner_id` points to an operation only when its typed owner is Finance; the polymorphic
  owner pair remains unique globally.
- Every Finance planned occurrence has exactly one detail; legacy owners have none.
- Every Finance fact belongs to exactly one Finance occurrence/detail. At most one fact and one linked
  transaction group exist per occurrence.
- Foreign or unknown entity identifiers resolve through the current owner's relation and produce the
  same not-found response.

## Derived Budget Projection

1. Resolve the budget category scope and select owned primary ledger entries whose group kind is
   `expense`, `occurred_on` lies in the month, and category is in that scope.
2. Include linked reversal groups because their opposite signed deltas cancel prior expense exactly;
   exclude transfer and adjustment groups.
3. Aggregate absolute spend by economic date and entry currency.
4. Convert every non-zero bucket into the budget currency through the latest direct/inverse owned rate
   on or before that date, retaining rate/date/direction evidence.
5. If any required rate is absent, return `complete=false`, sorted missing currencies, and null actual,
   remaining, utilization and state together.
6. Otherwise actual is the exact converted total; remaining is limit minus actual; utilization is
   `(actual / limit) × 100`, rounded half-up to four decimals. State is `within` below 80%, `approaching`
   from 80% through 100% inclusive, and `exceeded` above 100%.

No actual, remaining, utilization, state or warning counter is persisted.

## Derived Monthly Cash Flow

For the current or a future Profile-local month, expand active Finance rules within the requested month
and the rule bounds. Use occurrence snapshots where materialized and deterministic operation snapshots
otherwise. Pending and actual occurrences retain their planned amount; explicitly skipped occurrences
contribute zero. Group income, mandatory expense and discretionary expense by planned effective date and
currency, convert each group into the current Profile base currency, and compute
`free_cash_flow = planned_income - mandatory_expense`.

If any non-zero conversion is missing, all four consolidated amounts are null together and missing
currencies are sorted. Counts remain available by outcome/direction so the incomplete plan is still
explainable. The projection stores no rollup and is limited to one selected month.

## Lifecycle

```text
FinanceBudgetLimit: absent → active ⇄ edited → deleted (ledger history untouched)
FinanceRecurringOperation: active ⇄ paused; active/paused ⇄ archived
Finance occurrence: planned ⇄ moved → actual (terminal; ledger correction by reversal)
                                  ↘ skipped ⇄ planned
Budget warning episode: ineligible → approaching → exceeded → closed → eligible to re-arm
```

Pausing/archiving removes only unfactored future projections. Settled occurrences and ledger groups
remain. Restoring permits future materialization from the unchanged bounded shared rule.

## Migration and Rollback

- One additive migration creates operations, budget limits, month-days, occurrence details and facts in
  dependency order, then adds the nullable unique occurrence mirror.
- All explicit index/constraint names remain below MySQL's 64-character limit.
- `down()` first drops the mirror, then facts, details, month-days, budgets and operations; no 018 or
  earlier table/row/column is removed.
- The migration does not backfill or reinterpret legacy recurrence rows. New enum-like values are
  application-level strings, so existing data remains valid on MySQL and SQLite.
