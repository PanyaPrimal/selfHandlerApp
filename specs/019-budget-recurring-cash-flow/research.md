# Research: Budget and Recurring Cash Flow

**Feature**: `019-budget-recurring-cash-flow`
**Date**: 2026-08-13

## Sources Reviewed

- Module 10, Finance ER, recurrence, notifications, data conventions, decisions, roadmap 019
- Constitution 1.2.0 and completed 006/009/011/012/013–018 contracts and implementation
- Current exact ledger, FX, Profile, Planner source registry, recurrence materializer/fact reconcile,
  notification synchronizer/settings/copy/channel pipeline, typed client and locale guards
- GitNexus pre-change impact for RecurringRuleExpander (low), PlannerController (low),
  RecurrenceMaterializer/OccurrenceFactSynchronizer/NotificationSourceSynchronizer (medium)

## Decision 1: Budget Scope Is Monthly Category Money

One `FinanceBudgetLimit` stores owner, expense category, calendar month, exact positive limit, and
currency. Actual is a grouped ledger projection. A root includes itself and its children; a child
includes only itself. Same-month ancestor/descendant limits are rejected, making consolidated limit
and spend totals unambiguous without envelope allocation.

The fixed approach threshold is 80%; equality at 80 through 100 is approaching and only greater than
100 is exceeded. A zero limit is rejected. Archived categories remain visible to existing budgets but
cannot receive a new one. Carry-over, envelopes, allocation and custom thresholds are deferred.

## Decision 2: Historical FX Is Applied Per Economic Date

Each non-zero signed expense aggregate is converted from its entry currency into the budget currency
using the existing latest direct/inverse manual rate at or before the group date. Missing conversion
nulls actual, remaining, utilization and state together. Planned cash flow similarly converts each
planned date into current Profile base currency. Original Money is never overwritten.

## Decision 3: Monthly Recurrence Extends the Shared Engine Minimally

Add `monthly` frequency and owner type `finance_recurring_operation`, plus normalized
`recurring_rule_monthdays` rows. Month intervals anchor to the start month. Selected day 29–31 simply
does not occur in a short month. The shared rule stores schedule only; all money/category semantics live
on the Finance owner. A null explicit end means an inclusive ten-year ceiling from `starts_on`, so no
Finance rule expands forever. Daily/weekly/yearly/arbitrary RRULE Finance plans are outside this slice.

## Decision 4: Materialized Finance Plans Carry Owner Snapshots

One `FinanceOccurrenceDetail` snapshots operation name, direction, account, category, amount, currency,
and mandatory flag for each Finance occurrence. This lets explicit realization preserve what was
accepted even if the operation changes later. Edits update unfactored/unmoved future snapshots;
fact-bound or moved identity remains. A materializer hook is justified by the concrete Finance consumer,
matching the existing Sleep detail hook without generic JSON payload.

## Decision 5: Outcomes Are Domain Facts, Ledger Groups Remain Money Facts

One `FinanceOccurrenceFact` per occurrence records `actual` or `skipped`. Actualization locks owner,
occurrence, snapshot, account/category, and creates one ordinary immutable 018 group/entry using a
stable occurrence-derived idempotency key in the same transaction. Skip creates no ledger fact and may
be cleared. Actual cannot be cleared; its group is corrected only by 018 reversal. Occurrence status
and `finance_occurrence_fact_id` are derived mirrors rebuilt by shared reconcile.

## Decision 6: Cash Flow Is a Bounded Read Projection

For a current/future selected month, Finance expands active operations deterministically and groups
planned income, mandatory expense, and discretionary expense. Free cash flow is income minus mandatory
expense; discretionary remains visible but does not redefine the canonical figure. Missing FX nulls
all consolidated monetary totals. No rollup/cache or past schedule reconstruction is introduced.

## Decision 7: Planner and Notifications Stay Adapters

`FinanceOccurrenceSource` returns one Planner entry per materialized occurrence. Reschedule retains the
shared occurrence identity; skip delegates to Finance outcome service; actualize deep-links to Finance.
Timed pending occurrences produce Finance reminders through existing quiet-hours/escalation/locale/
Android paths. Budget warnings use separate source types for approaching and exceeded so one budget can
cross both thresholds idempotently; falling below cancels active warning state and later crossing may
re-arm it. Finance is a backwards-compatible enabled notification category.

## Decision 8: Closed API and Client Surface

Seven paths expose eleven authenticated operations: budget list/create/update/delete; recurring
operation list/create/update; cash-flow month; planned occurrence range; outcome upsert/clear. Exact
decimal strings, closed inputs/objects, owner-as-404 references, 366-day bounds, full EN/RU/UK copy and
one responsive shared client are mandatory.

## Rejected Alternatives

- Budget counters or monthly rollup rows: second truth before measured need.
- Finance-local schedule table or JSON month-days: duplicates shared recurrence and weakens constraints.
- Clamp day 31 to month end: changes user intent silently.
- Link occurrence directly to group without skip fact: cannot rebuild explicit skipped outcome.
- Delete realized outcomes: would orphan accepted ledger money and undermine append-only correction.
- Put amounts/categories on RecurringRule payload: makes the shared engine own Finance semantics.
- One warning identity for both thresholds: an already-sent approaching record suppresses exceeded.
- Implement debts/funds/one-off plans now: violates thin-slice ownership and feature 020 boundary.
