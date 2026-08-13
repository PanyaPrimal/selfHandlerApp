# SelfHandler — Finance: ER Diagram

> Conceptual ER diagram for the complete Module 10. Features 018 and 019 implement the ledger,
> monthly-budget, and recurring-cash-flow subsets documented below; debt, fund, goal, and purchase relationships remain future
> scope. The feature contract and migration are authoritative for physical names and constraints.
>
> Spec: [Modules Spec](modules.md) · Decisions: [Decisions Log](decisions.md)

---

## Implemented foundation — feature 018 (2026-08-13)

```mermaid
erDiagram
    USER ||--o{ FINANCE_ACCOUNT : owns
    USER ||--o{ FINANCE_CATEGORY : owns
    USER ||--o{ FINANCE_EXCHANGE_RATE : owns
    USER ||--o{ FINANCE_TRANSACTION_GROUP : owns
    USER ||--o{ FINANCE_LEDGER_ENTRY : owns
    CURRENCY ||--o{ FINANCE_ACCOUNT : denominates
    FINANCE_CATEGORY ||--o{ FINANCE_CATEGORY : parent_of
    FINANCE_TRANSACTION_GROUP ||--|{ FINANCE_LEDGER_ENTRY : contains
    FINANCE_TRANSACTION_GROUP ||--o| FINANCE_TRANSACTION_GROUP : reverses
    FINANCE_ACCOUNT ||--o{ FINANCE_LEDGER_ENTRY : receives
    FINANCE_CATEGORY ||--o{ FINANCE_LEDGER_ENTRY : classifies
```

- `currencies` holds global UAH/USD/EUR references. `finance_accounts`, `finance_categories`,
  `finance_exchange_rates`, `finance_transaction_groups`, and `finance_ledger_entries` are private.
- A group is one immutable idempotent action. Its signed `DECIMAL(19,4)` entries are authoritative:
  one leg for actual/adjustment, two for transfer, and opposite legs in one linked reversal group.
- An account has no balance/opening column. Balances and bounded summaries are exact grouped queries.
- Category depth is root plus one child; direction is immutable. Historical references survive archive.
- A cross-currency transfer snapshots both original amounts and its effective twelve-place rate.
  General manual rates are owner-owned date facts and are selected directly or inversely at or before
  the requested Profile-local date.
- Base currency remains on Profile. Missing required FX returns an incomplete projection with no total.

The larger diagram below is a future design map, not a claim that deferred entities exist.

## Implemented planning slice — feature 019 (2026-08-13)

- `finance_budget_limits` stores one exact monthly category limit. Actual, remaining, utilization,
  state, conversion evidence, and completeness are read-time projections over ledger facts. A root
  limit includes direct and child expenses; a same-month ancestor/child overlap is rejected.
- `finance_recurring_operations` owns one shared monthly `recurring_rule`; normalized
  `recurring_rule_monthdays` holds 1–10 selected days. The interval is anchored to the start month,
  absent short-month days are skipped, and a null end has a deterministic inclusive ten-year ceiling.
- Each materialized Finance occurrence owns an immutable `finance_occurrence_detail` snapshot.
  `finance_occurrence_facts` records either actual or skipped. Actual links exactly one ordinary 018
  transaction group and cannot be cleared; skipped creates no ledger fact and may be cleared.
- Planned monthly cash flow includes pending and actual snapshots, excludes skips, and reports income,
  mandatory expense, discretionary expense, and free cash flow in the current Profile base currency.
  Any missing historical FX makes all consolidated totals null instead of inventing a partial result.
- Planner and Notifications are adapters. Finance remains the owner of money, outcome, budget, and
  snapshot truth. Debts, funds, goals, and purchase/restock links remain feature 020.

---

## Diagram

```mermaid
erDiagram
    USER ||--o{ ACCOUNT : owns
    USER ||--o{ CATEGORY : owns
    USER ||--o{ TRANSACTION : owns
    USER ||--o{ BUDGET : owns
    USER ||--o{ DEBT : owns
    USER ||--o{ SAVING_FUND : owns
    USER ||--o{ RECURRING_RULE : owns
    USER ||--o{ COUNTERPARTY : owns

    CURRENCY ||--o{ ACCOUNT : denominates
    CURRENCY ||--o{ DEBT : denominates
    CURRENCY ||--o{ SAVING_FUND : denominates
    CURRENCY ||--o{ EXCHANGE_RATE : "from/to"

    ACCOUNT ||--o{ TRANSACTION : "source of"
    ACCOUNT ||--o{ TRANSACTION : "destination of (transfer)"
    ACCOUNT ||--o{ SAVING_FUND : "backs (real-account mode)"

    CATEGORY ||--o{ CATEGORY : "parent of (group->sub)"
    CATEGORY ||--o{ TRANSACTION : classifies
    CATEGORY ||--o{ BUDGET : "limited by"
    CATEGORY ||--o{ SAVING_FUND : "spent into"

    DEBT ||--o{ DEBT_PAYMENT : "scheduled as"
    DEBT_PAYMENT ||--o| TRANSACTION : "settled by"
    DEBT ||--o{ TRANSACTION : "paid via"

    SAVING_FUND ||--o{ TRANSACTION : "funded via"

    PURCHASE ||--o| TRANSACTION : "source of (bought)"
    PURCHASE ||--o| DEBT : "bought on installment"

    COUNTERPARTY ||--o{ DEBT : "with"

    RECURRING_RULE ||--o{ PLANNED_OCCURRENCE : generates
    PLANNED_OCCURRENCE ||--o| TRANSACTION : "realized as"
    RECURRING_RULE ||--o| DEBT : "drives payments of"
    RECURRING_RULE ||--o| SAVING_FUND : "drives top-up of"

    GOAL ||--o| DEBT : "tracks (close-debt)"
    GOAL ||--o| SAVING_FUND : "tracks (save-N)"
```

> ⚠️ Mermaid ER doesn't render two relationships between the same pair of entities with different roles cleanly (ACCOUNT↔TRANSACTION source/destination, CURRENCY↔EXCHANGE_RATE from/to) — in the real schema these are two FKs on a single table. The textual breakdown below is the source of truth.

---

## Entities (logical)

### Money core
- **USER** — the owner boundary. The related Profile stores the **base currency**.
- **CURRENCY** — currency reference table (UAH/USD/EUR…). Currency code.
- **EXCHANGE_RATE** — exchange rate: currency_from + currency_to + **date** + rate. Historical (as of the operation date), not just the current one.
- **ACCOUNT** — account: name, type (cash/card/savings/foreign-currency), **currency** (single), archived flag. The balance is a derived aggregate, never a mutable column in feature 018.
- **CATEGORY** — category: name, direction (income/expense), **parent_id** (self-reference: group → subcategory, 2 levels), archived flag. Example: Medical → Dentistry.
- **TRANSACTION** — transaction: type (income/expense/transfer), amount+currency, source account, destination account (transfer only), category (income/expense), date, note, tag. For a cross-currency transfer — both amounts + the effective exchange rate. Optional references: to DEBT (debt payment), to SAVING_FUND (top-up).
  - **`source` — polymorphic reference to the origin** (`source_type` + `source_id`): supplement (Module 2a, restock) / **purchase item (Module 7)** / null. This is the connection point between Storage↔Finance and Supplements↔Finance. The FK lives here (on the money side); the domain entities know nothing about money.
- **PURCHASE (item, Module 7)** — an external Storage entity (not a Finance table), shown here for completeness of the relationship. A purchase from the wish list. Invariant: status "bought" ⟺ a TRANSACTION exists with `source` = this purchase (or a linked installment DEBT).

### Budget
- **BUDGET** — limit: category + period (month/year) + limit amount. The actual is computed as an aggregate of the category's transactions over the period (not stored).

### Debts
- **COUNTERPARTY** — counterparty (bank/store/person). ⚠️ open: a separate entity vs free text in DEBT.
- **DEBT** — debt: direction (I owe / owed to me), counterparty, original amount + remaining, currency, schedule mode (fixed / flexible), optional interest/overpayment, deadline, status, optional charge account.
- **DEBT_PAYMENT** — a scheduled payment in the schedule (fixed mode only): date, amount, status (scheduled/paid/overdue). The fact of payment = a reference to a TRANSACTION.

### Savings
- **SAVING_FUND** — saving fund / emergency fund (a single entity with flags): name, target amount + accumulated, currency, optional category and term, storage mode (virtual envelope / linked to an ACCOUNT), status.
  - **Emergency Fund** flags: `is_emergency` (mandatory) + `is_perpetual` (open-ended) + a top-up rule (fixed amount / % of income / N months of expenses).
  - ⚠️ open: a single entity with flags vs separate FUND/EMERGENCY_FUND tables.

### Recurrence (CROSS-CUTTING engine — NOT local to Finance)
> ⚠️ `RECURRING_RULE` / `PLANNED_OCCURRENCE` here are **the same cross-cutting mechanism** canonically defined in the [Modules Spec](modules.md). Finance is just one of 6+ consumers (supplements/workouts/measurements/tasks/habits/finance). Don't duplicate the table for Finance — it's shared, with a polymorphic binding to the owner.
- **RECURRING_RULE** — a recurring rule (RRULE/RFC 5545 recommended): pattern, dtstart, until/count, timezone, polymorphic owner. For Finance the owner = a financial operation / debt / emergency saving fund; carries direction (income/expense), amount, currency, account, category.
- **PLANNED_OCCURRENCE** — a planned instance generated from a rule: planned date + amount + status (planned/received/paid/skipped/rescheduled). The fact = a reference to a TRANSACTION. Idempotency: a unique `(rule_id, occurrence_date)`.

### Goals (from Module 4, not duplicated here)
- **GOAL** (type "Finance") — a wrapper with a term/milestones: "save N" → tracks SAVING_FUND; "close a loan" → tracks DEBT. Progress is taken from the linked entity.

---

## Key relationships and invariants

| Relationship | Cardinality | Meaning |
|-------|----------------|-------|
| ACCOUNT → TRANSACTION | 1 : N (×2 roles) | source_account_id (always) + dest_account_id (transfer only) |
| CATEGORY → CATEGORY | 1 : N (self) | parent_id; depth is exactly 2 (group/subcategory) |
| CATEGORY → TRANSACTION | 1 : N | income/expense only; a transfer has no category |
| BUDGET → CATEGORY | N : 1 | a limit per category/period; the actual = an aggregate |
| DEBT → DEBT_PAYMENT | 1 : N | fixed schedule only; flexible mode has no schedule rows |
| DEBT_PAYMENT → TRANSACTION | 1 : 0..1 | a scheduled payment is settled by an actual transaction |
| SAVING_FUND → ACCOUNT | N : 0..1 | "real account" mode only; virtual has no FK, the amount lives in the saving fund itself |
| RECURRING_RULE → PLANNED_OCCURRENCE | 1 : N | a rule expands into planned operations |
| PLANNED_OCCURRENCE → TRANSACTION | 1 : 0..1 | a plan is realized by an actual |
| GOAL → DEBT / SAVING_FUND | 1 : 0..1 | a "close a loan" / "save N" goal |
| PURCHASE → TRANSACTION (source) | 1 : 0..1 | polymorphic `TRANSACTION.source`; FK on the transaction side |
| PURCHASE → DEBT | 1 : 0..1 | an installment purchase → a debt (FK direction: debt.purchase_id) |

**Invariants:**
- Transfer: source_account and dest_account must differ; category is null; for differing currencies both amounts are stored.
- A transfer transaction counts as neither income nor expense (it doesn't enter the budget/cash flow as income or expense).
- Account balance = opening + Σ(credits) − Σ(debits); not edited directly.
- Debt remaining = original amount − Σ(debt payments).
- Monthly cash flow = Σ(planned income) − Σ(mandatory expenses: recurring expenses + this month's DEBT_PAYMENT + the mandatory emergency fund top-up).
- **A purchase is "bought" ⟺ a TRANSACTION exists with source = this purchase (or a linked installment DEBT).** Reverting the transaction → the purchase returns to "want".
- Progress of a "save N" financial goal = the amount accumulated in the linked SAVING_FUND (not the account balance directly — the fund may be virtual).

---

## Open schema questions (to resolve during migrations)

> Some were closed by the review pass on 2026-06-13 (a recommendation is given). Open ones — without a checkmark.

1. ✅ **Account opening balance:** the first immutable adjustment group; no opening column.
2. ✅ **Transfer:** one immutable transaction group with two signed ledger entries, one per account.
3. **Saving fund ↔ emergency fund:** a single SAVING_FUND with flags vs separate tables. ⬜ open (leaning toward a single one with flags).
4. **Virtual envelope:** ✅ decision — **"available balance" = account balance − Σ envelopes on it**, the envelope does NOT move money physically. Invariant: Σ envelopes ≤ balance. It's a computed value, not separate money.
5. **Counterparty:** COUNTERPARTY as an entity vs a string in DEBT. ⬜ open (recommendation — an entity from the start, cheaper than deduping later).
6. ✅ **Base currency → in the user's profile/settings** (Module 0), not in the Finance settings. Closed by the "Profile is the source of inputs" principle.
7. ✅ **RECURRING_RULE → RRULE (RFC 5545)** via an off-the-shelf library. A cross-cutting format, see the [Modules Spec](modules.md).
8. ✅ **PLANNED_OCCURRENCE → materialization with a look-ahead window** (+90 days) + a unique `(rule_id, occurrence_date)` for idempotency. (review recommendation)
9. ✅ **Money → DECIMAL(19,4)** (or minor units as BIGINT) + a `Money` value object (amount+currency). Globally, not float. Roll-up currency conversion happens **at read time** using the chosen rate (the current one for "now", the historical one for "back then"); don't store the converted value.
10. **Purchase ↔ transaction (Module 7):** ✅ polymorphic `TRANSACTION.source` + the "bought ⟺ a transaction exists" invariant. FK on the transaction side.
11. ✅ **Polymorphism by type (cross-cutting)** → a hybrid: class-table for entities with divergent fields (Workouts), single-table + nullable/JSON for similar ones (Goals/Storage/Debts). No STI magic. Pinned down in [Data Conventions](data-conventions.md).
12. ✅ **Aggregates** → a cached value + event-driven recompute (Observer) for hot derived values + a daily rollup for analytics. See [Data Conventions](data-conventions.md).

> Money (#9), transfer (#2), envelope (#4), currencies, user_id, deletion/archival, time zones — consolidated in [Data Conventions](data-conventions.md).
