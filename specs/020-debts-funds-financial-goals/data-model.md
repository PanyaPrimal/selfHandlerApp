# Data Model: Debts, Funds, Financial Goals, and Purchase Links

**Date**: 2026-08-13

All private rows carry `user_id`; every relationship is validated for the same owner in service and model
boundaries. Money uses `DECIMAL(19,4)` plus a three-character currency reference. Dates are Profile-local
calendar dates. Facts and movements are append-only; lifecycle entities archive rather than erase history.

## FinanceCounterparty

| Field | Rules |
|---|---|
| `user_id` | owning user; cascade only with account deletion |
| `name` | trimmed 1–160; unique per owner |
| `kind` | `person`, `bank`, `store`, `other` |
| `note` | nullable, 5000 chars |
| `is_archived`, `archived_at` | derived lifecycle pair |

Archive is refused while an active debt references the counterparty; history remains readable.

## FinanceDebt

| Field | Rules |
|---|---|
| `user_id`, `counterparty_id` | same owner |
| `purchase_item_id` | nullable unique owned purchase; only `owe`; active direct source forbidden |
| `name`, `note` | trimmed name 1–160; nullable note |
| `direction` | `owe`, `owed_to_me` |
| `repayment_mode` | `fixed`, `flexible` |
| `original_amount`, `currency_code` | positive exact Money; immutable after payment/history |
| `originated_on`, `deadline` | local dates; deadline ≥ origin |
| `account_id`, `category_id` | optional defaults; currency/direction match, active for new facts |
| fixed fields | amount, count 1–120, interval 1–12, monthday 1–31, first due, reminder time |
| lifecycle | `is_active`, `is_archived`, `archived_at`; state is derived |

Fixed-only fields are all present or all null. `amount × count = original_amount`; first due matches the
selected monthday; the Nth valid due date is at most ten years from the first. One fixed debt owns exactly
one monthly RecurringRule; flexible debt owns none.

Derived projection: original, active paid, remaining, percent, next due, due/paid/overdue counts, and
`active|overdue|settled`. An active payment is a fact whose transaction group has no `reversedBy`.

## FinanceDebtOccurrenceDetail

One immutable-to-history row per materialized fixed debt occurrence:

- same-owner occurrence and debt
- debt/direction/name, installment amount/currency, account/category snapshot
- unique `planned_occurrence_id`

Unmoved occurrences without any payment history may receive future schedule edits; moved or ever-paid rows
retain their snapshot.

## FinanceDebtPaymentFact

| Field | Rules |
|---|---|
| `debt_id` | owned debt |
| `planned_occurrence_id` | nullable for flexible, owned fixed occurrence otherwise |
| `transaction_group_id` | unique ordinary income/expense group; owner/direction/source match |
| `principal_amount`, `currency_code`, `occurred_on` | immutable snapshot |

Facts never delete/update. `planned_occurrences.finance_debt_payment_fact_id` points to the latest attempt;
read/reconcile derives planned/done from whether its group has a reversal and may replace the pointer after
a corrected repayment.

## FinanceSavingFund

| Field | Rules |
|---|---|
| `name`, `note`, `user_id` | owned lifecycle entity |
| `fund_type` | `regular`, `emergency`; emergency implies mandatory/perpetual |
| `storage_mode` | `virtual`, `linked_account` |
| `account_id` | virtual backing or uniquely claimed linked savings account; same currency |
| `funding_account_id` | optional active distinct same-currency source for scheduled linked top-up |
| `category_id` | optional owned expense category describing intended spend |
| `currency_code` | exact target/movement currency |
| `target_mode` | `explicit`, `expense_months`; regular requires explicit |
| `target_amount` | positive for explicit, null for expense-months |
| `deadline` | optional local target date |
| `top_up_mode` | `none`, `fixed`, `income_percent`, `expense_months` |
| rule values | exact fixed amount, bounded percent, expense months, build months, monthday/time/start |
| lifecycle | active/archive timestamps; projection state derived |

Regular scheduled saving permits only fixed top-up. Emergency requires a non-none rule; fixed uses Money,
income percent uses 0.01–100%, expense-months uses 1–24 target months and 1–60 build months. An eligible
scheduled fund owns one monthly RecurringRule.

Derived projection: saved/reserved, target, remaining, progress, required pace, suggested top-up, evidence,
missing currencies/history, and `active|reached|under_funded|over_reserved|spent|unavailable`.

## FinanceFundMovement

Virtual-only immutable allocation ledger:

- owned virtual fund and backing-account snapshot
- `contribution`, `withdrawal`, or `reversal`; signed non-zero exact delta
- local effective date, stable idempotency key/payload hash
- nullable unique `reverses_movement_id`; one movement reversed at most once
- optional note and source occurrence fact link

Saved amount is the exact sum of movement deltas. Positive actions enforce aggregate account capacity;
withdrawal/reversal enforce a non-negative fund reserve.

## FinanceFundOccurrenceDetail / FinanceFundOccurrenceFact

Detail snapshots fund, name/type/storage/account/funding/category, calculated amount/currency, top-up mode,
and JSON-free evidence columns sufficient to explain fixed/percent/expense-month calculation. Fact is one
`actual|skipped` outcome and links exactly one virtual movement or one transfer group for actual. Skipped
links neither and may be cleared; actual is corrected via allocation or money reversal and retained.

`planned_occurrences.finance_fund_occurrence_fact_id` is nullable unique. The shared Finance outcome route
dispatches by RecurringRule owner type.

## FinanceGoalDetail

| Field | Rules |
|---|---|
| `goal_id`, `user_id` | unique same-owner common Goal, type `finance` |
| `kind` | `save`, `pay_off` |
| `saving_fund_id` | required only for save |
| `debt_id` | required only for pay_off |
| `currency_code` | immutable aggregate currency snapshot/reference |

Only one active non-archived Finance Goal may target an aggregate. GoalMilestone remains the shared exact
Money checkpoint table. Progress is derived in bulk: save from `0 → fund target`, pay-off from
`original → 0`, including backward movement after corrections.

## Existing Item Additions

- type adds `purchase`
- nullable `estimated_amount DECIMAL(19,4)` and `estimated_currency_code`
- purchase UI lifecycle maps existing active/done/dropped to wanted/bought/canceled
- estimate members are both null or positive Money; prohibited for task/idea
- done is server-controlled by active Finance source/debt; dropped cannot be financed

## Existing FinanceTransactionGroup Additions

- nullable `source_type` (`purchase_item`, `supplement_restock_proposal`) and unsigned `source_id`
- both null or both set; original `expense` group only; same-owner/type/open-state validation before post
- reversal group does not copy source
- historical duplicate source groups are permitted only when every earlier group is reversed; service row
  locks guarantee at most one active source expense/debt path

## Existing Shared Additions

- RecurringRule owner types add `finance_debt` and `finance_saving_fund`.
- PlannedOccurrence adds nullable unique latest debt payment and fund fact links; cross-fact XOR and
  `hasFact()` include them.
- User/Goal/Item/Account/Transaction relationships are additive.
- Finance account response adds exact `reserved_amount`, `available_balance`, and `over_reserved`, derived
  for all accounts (zero/default when no virtual fund exists).

## Deletion and Rollback

- Hard user deletion cascades all private 020 rows.
- Counterparty/debt/fund/account/category/source history uses restrictive/nulling links and archive.
- Facts, transaction groups, ledger entries, and fund movements reject update/delete.
- Rollback first removes shared nullable columns/constraints, then drops 020 children before parents; 019
  and all prior tables/data remain unchanged.
