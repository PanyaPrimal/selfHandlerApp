# Research: Debts, Funds, Financial Goals, and Purchase Links

**Date**: 2026-08-13

## Inputs Reviewed

- Canonical roadmap, Modules 4/7/10, Finance ER, recurrence, notifications, data conventions,
  decisions, vision, and Constitution 1.2.0
- Features 006, 008, 009, 011–013, 017–019 specifications, contracts, migrations, services, tests,
  web surfaces, and delivered route shapes
- GitNexus pre-change impact for Goal, Item, FinanceLedgerService, RecurringRule, Planner, and
  Notifications at `23b5aa0cda4ef2a925c8bb951567f8145d66152d`

## Decisions

### R-001 — Counterparties are normalized owner entities

**Decision**: add one private counterparty directory with `person`, `bank`, `store`, and `other` kinds;
names are trimmed and unique per owner while rows archive rather than disappear.

**Why**: both debt directions reuse names, ownership is enforceable at the model/database boundary, and
normalizing now avoids later deduplication. Free text remains in debt notes.

**Rejected**: counterparty name copied into every debt. It permits drift and cannot support safe reuse.

### R-002 — Debt is principal-only and balance is derived

**Decision**: store original principal and immutable payment facts. An active payment is a normal 018
expense when the user owes or income when they are owed; a linked reversal removes it from the derived
remaining principal. No mutable remaining column exists.

**Why**: it preserves the append-only ledger/reversal boundary and makes correction auditable.

**Rejected**: a mutable balance, interest-accrual engine, or stored principal/interest split. Those either
drift from money facts or claim banking calculations outside the roadmap slice.

### R-003 — Fixed schedules are one exact monthly series; flexible debts have none

**Decision**: a fixed debt owns the existing monthly RecurringRule with one normalized monthday. Amount ×
count must equal principal, and the service expands valid dates until it finds the Nth installment; skipped
short months therefore do not reduce the promised count. The final date must remain within ten years.
Flexible repayment stores no rule or schedule row.

**Why**: this is deterministic, reuses 019 monthly semantics, and makes every installment identical and
independently payable. Variable schedules can later be versioned without weakening this contract.

**Rejected**: clamping day 31, partial fixed installments, arbitrary installment arrays, or a local debt
schedule table disconnected from shared recurrence.

### R-004 — Historical payment attempts remain while one pointer marks the latest fact

**Decision**: every DebtPaymentFact is immutable and links one ordinary transaction group. A fixed
occurrence has a nullable pointer to its latest payment fact; read/reconcile treats a reversed group as
open and may replace the pointer with a later payment. The pointer still protects the accepted occurrence
identity after reversal.

**Why**: one fact table preserves every correction attempt without forcing the immutable group to change.

### R-005 — Regular and emergency savings share one aggregate

**Decision**: one FinanceSavingFund model has `regular` or `emergency` type, `virtual` or
`linked_account` storage, exact currency, and explicit rule fields. Emergency implies mandatory and
perpetual. Regular funds require a positive explicit target; emergency funds may use explicit or derived
expense-month targets.

**Why**: most lifecycle/storage/progress behavior is identical and canonical data conventions already
choose one table plus flags for similar variants.

**Rejected**: separate emergency tables or JSON rule payloads. Both duplicate queryable invariants.

### R-006 — Virtual envelopes reserve one real account without ledger money

**Decision**: a virtual fund chooses a same-currency backing account. Its append-only FundMovement ledger
changes only reserved allocation; the account balance does not move. Available account balance equals
ledger balance minus active virtual reserves. Positive allocation locks the account and all its funds and
rejects aggregate reserve above current balance. Unrelated later spending may produce an honest
`over_reserved` read state but never deletes allocation history.

**Why**: a reserve is a decision about existing money, not a second currency balance or transfer.

### R-007 — Linked funds claim a dedicated savings account

**Decision**: a linked fund reads saved value from one uniquely claimed account. Top-up and drawdown use
the existing paired transfer action; scheduled top-ups require a distinct active same-currency funding
account, while manual cross-currency movement remains available through ordinary Finance transfers.

**Why**: one dedicated link prevents the same balance satisfying several goals and reuses exact transfer
truth without adding a fund counter.

### R-008 — Emergency rules produce exact monthly snapshots

**Decision**: modes are fixed Money, percent of current-month planned recurring income, or N months of
average actual expenses over the three complete preceding Profile-local months. Expense history includes
ordinary expense groups and reversals, excludes transfer/adjustment, and converts each date historically.
Expense-month mode divides the current shortfall over a bounded 1–60 month build horizon. Missing non-zero
history/rates yields unavailable evidence and suppresses actualization.

**Why**: three complete months is transparent and bounded; all-expense averaging matches canonical
"N months of expenses" wording and avoids a new mandatory-category classification.

**Rejected**: current month, silent zero history, stored converted totals, or external/provider rates.

### R-009 — Fund/debt schedules extend shared Finance occurrence handling

**Decision**: add typed RecurringRule owners, immutable DebtOccurrenceDetail/FundOccurrenceDetail, and
domain facts, then extend the existing `/finance/planned-occurrences` outcome routes and Finance Planner/
Notifications adapter. Existing recurring-operation response members remain compatible; new `kind` and
kind-specific context are additive.

**Why**: users need one Finance plan timeline and one action identity, not three schedulers or settings.

### R-010 — Cash-flow inputs are composed, not recomputed in consumers

**Decision**: extract the current recurring-operation row projection into a Finance-owned collaborator.
CashFlow combines those rows with fixed debt payments (owe = mandatory expense, owed-to-me = income) and
emergency top-ups (mandatory expense), then performs one historical-FX completeness fold.

**Why**: Planner/Review/Analytics remain consumers, and the income-percent fund rule can reuse the same
planned-income truth without a dependency cycle.

### R-011 — Finance goals extend the common Goal with a typed detail

**Decision**: Goal gains type `finance`; FinanceGoalDetail links exactly one Debt or SavingFund and stores
the subtype. Existing GoalMilestone rows store exact values. A FinanceGoalProgressService bulk-loads
aggregate projections and derives progress/milestone achievement; no progress is persisted.

**Why**: this matches body/training typed details and preserves one unified Goal identity/list.

### R-012 — Purchase type uses Storage identity but Finance controls bought transitions

**Decision**: add Item type `purchase` plus optional estimated Money. Its existing lifecycle maps
active→wanted, done→bought, dropped→canceled. Direct Storage mutation to done is rejected; the Finance
source service performs the transition only after a linked active expense or installment debt exists.
Reversal of the only direct expense restores active/wanted. The completion guard observes that state.

**Why**: quick capture, tags/projects/parent blockers remain Storage-owned, while the locked bought
invariant cannot be forged outside money truth.

### R-013 — Source links live on immutable transaction groups

**Decision**: add nullable `source_type`/`source_id` to original transaction groups for `purchase_item`
and `supplement_restock_proposal`. Reversal groups do not copy the source. Source rows are locked before
posting; an unreversed group or purchase debt blocks a second active path. Historical groups may retain
the same source after reversal so audit history is not rewritten.

**Why**: the FK direction remains Finance→source as designed, and existing group idempotency/reversal is
unchanged. Polymorphic owner checks occur before every write and on safe context decoration.

### R-014 — Restock expense does not mean stock arrival

**Decision**: an open proposal may seed a one-way expense, but Finance never creates a stock movement or
changes proposal status. The proposal remains actionable until Supplements records/deduces physical stock.

**Why**: payment and inventory arrival are distinct facts; Supplement owns forecast/proposal lifecycle.

### R-015 — Additive schema and compatibility

**Decision**: one reversible 020 migration creates normalized private tables/detail/fact tables, adds
nullable occurrence fact links and transaction source columns, extends Goal/Item only through accepted
enum behavior and nullable purchase fields, and uses named MySQL-safe indexes. No dependency upgrades.

**Why**: existing rows receive null/default-compatible values and every earlier API retains its shape.

## Explicit Deferrals

Compound interest/amortization, fees, variable installment arrays, one-off generic plans, investments,
provider FX/bank/payment integration, automatic ordering/stock arrival, review/analytics rollups,
reports/portability, calendar sync, AI, native offline authority, and deployment.
