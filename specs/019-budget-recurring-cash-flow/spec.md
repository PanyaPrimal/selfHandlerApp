# Feature Specification: Budget and Recurring Cash Flow

**Feature ID**: `019-budget-recurring-cash-flow`

**Created**: 2026-08-13

**Status**: Complete

**Input**: Deliver the second Finance vertical slice from the canonical roadmap: monthly expense
budgets with derived actuals and threshold warnings, recurring income and expenses on the shared
recurrence engine, explicit planned-occurrence realization or skip, planned mandatory cash flow,
Planner and in-app notification adapters, and a complete EN/RU/UK shared client. Debts, saving and
emergency funds, financial goals, purchase/restock links, investments, provider FX, imports, exports,
integrations, AI, native offline authority, and deployment remain outside this increment.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Set Monthly Expense Budgets (Priority: P1)

The user sets an exact monthly limit for an expense category in a chosen supported currency. They see
actual spending derived from the immutable ledger, remaining amount, utilization, conversion evidence,
and a truthful incomplete state when a required historical rate is missing.

**Independent Test**: create root and child expense categories, set/edit/remove limits, post and reverse
multi-currency expenses, then verify exact actuals, threshold states, overlap prevention, missing FX,
archived-history visibility, and foreign-owner isolation.

**Acceptance Scenarios**:

1. **Given** a UAH limit of `1000.0000`, **When** categorized expenses total `799.9900`, `800.0000`,
   and `1000.0100`, **Then** states are respectively within, approaching, and exceeded.
2. **Given** a root budget, **When** expenses use that root or either child, **Then** all are counted once.
3. **Given** a limit on a root category, **When** the user attempts a same-month child limit, **Then**
   the overlapping scope is rejected so the monthly total cannot double-count.
4. **Given** a non-zero foreign-currency expense without a historical rate into the budget currency,
   **When** the budget is read, **Then** actual/remaining/utilization are null and missing currency is named.

---

### User Story 2 - Define Recurring Income and Expenses (Priority: P1)

The user creates a private monthly recurring operation tied to one account and a matching income or
expense category. One exact amount can recur on several selected days of each eligible month, with an
optional Profile-local reminder time, active/archive lifecycle, and a bounded date range.

**Independent Test**: create salary and subscription operations, expand several months including
February/leap year, edit future values, pause/archive/restore, and prove old owners and foreign IDs
remain unaffected.

**Acceptance Scenarios**:

1. **Given** salary days 5, 15, and 25, **When** a month is expanded twice, **Then** exactly three stable
   occurrences exist with no duplicates.
2. **Given** month-day 31, **When** February is expanded, **Then** no February occurrence is invented or
   shifted; March 31 still occurs.
3. **Given** a materialized future occurrence without a result, **When** amount/account/category/time is
   edited, **Then** its owner snapshot updates; settled or explicitly moved occurrences keep history.
4. **Given** an inactive or archived operation, **When** materialization runs, **Then** no new live
   occurrences remain while settled history is preserved.

---

### User Story 3 - Turn a Plan into an Actual Fact (Priority: P1)

The user explicitly marks a planned Finance occurrence received, paid, or skipped. Realization writes
one ordinary 018 ledger group from the immutable occurrence snapshot and links one Finance outcome;
retry returns the same result and never changes a balance twice.

**Independent Test**: realize income/expense occurrences, retry concurrently, skip/clear a pending
occurrence, reject foreign/future/settled inputs, reverse the created transaction, and reconcile the
shared occurrence mirror from Finance facts.

**Acceptance Scenarios**:

1. **Given** a pending expense occurrence, **When** it is realized twice, **Then** one expense group and
   one outcome fact exist and the account is debited once.
2. **Given** a pending occurrence, **When** it is skipped and then cleared, **Then** no ledger entry is
   created and the same occurrence returns to planned.
3. **Given** a realized occurrence, **When** its outcome-clear endpoint is called, **Then** clearing is
   rejected; accepted money can only be corrected through the existing linked reversal.
4. **Given** another user's occurrence, **When** it is read or settled, **Then** it is indistinguishable
   from an unknown occurrence and no private owner data leaks.

---

### User Story 4 - Understand Monthly Planned Cash Flow (Priority: P2)

The user selects the current or a future month and sees planned income, mandatory recurring expense,
discretionary recurring expense, and free cash flow in Profile base currency, with occurrence counts
and explicit conversion completeness.

**Independent Test**: combine several operations/currencies/month-days/statuses, direct and inverse
historical rates, Profile base changes, and missing rates; verify exact grouped totals and bounded query
count without persisted rollups.

**Acceptance Scenarios**:

1. **Given** planned income `3000.0000` and mandatory expense `1200.0000` in complete conversion data,
   **When** the month is read, **Then** free cash flow is exactly `1800.0000`.
2. **Given** an optional recurring expense, **When** cash flow is read, **Then** it appears separately and
   does not reduce the mandatory free-cash-flow figure.
3. **Given** a missing non-zero conversion, **When** cash flow is read, **Then** all consolidated monetary
   totals are null together rather than presenting a partial plan.
4. **Given** Profile base currency changes, **When** the same month is read, **Then** conversion is
   recomputed into the new base while original operation amounts remain unchanged.

---

### User Story 5 - See Plans and Warnings in Shared Surfaces (Priority: P2)

Finance occurrences appear in Planner and timed due operations use the existing reminder pipeline.
Current-month budgets create one localized approaching and one localized exceeded warning per threshold
episode; corrections or limit edits close stale active warnings.

**Independent Test**: inspect Planner entries/actions, move/skip/realize through owner boundaries,
process notifications across locale/quiet-hours/settings, cross both budget thresholds, correct below
them, and prove retry-safe re-arming without duplicate delivery.

**Acceptance Scenarios**:

1. **Given** a timed pending Finance occurrence today, **When** notifications process, **Then** one
   Finance reminder is localized at delivery and deep-links to that occurrence.
2. **Given** Finance reminders disabled, **When** sources synchronize, **Then** no new Finance reminder or
   budget warning is delivered and stale active Finance notifications close.
3. **Given** actual utilization crosses 80% and later 100%, **When** notification processing repeats,
   **Then** one approaching and one exceeded warning exist, with no duplicates.
4. **Given** an occurrence shown in Planner, **When** it is skipped or moved, **Then** Planner delegates
   to Finance/shared recurrence and never creates a second plan or money fact.

---

### User Story 6 - Use One Complete Shared Finance Client (Priority: P3)

The existing Finance workspace gains Budget and Plans/Cash Flow surfaces. It supports the complete
budget and recurring-operation lifecycle, a monthly occurrence calendar/list, explicit realize/skip,
cash-flow completeness, and notification setting in English, Russian, and Ukrainian on desktop and
exact 390×844 mobile/Android.

**Independent Test**: complete the full journey in every locale and both schemes, inspect desktop/mobile
screenshots, reject mutations and recover drafts, use keyboard/screen reader semantics, reload/deep-link,
and synchronize the final bundle into the Android shell.

**Acceptance Scenarios**:

1. **Given** any supported locale/theme/viewport, **When** the user opens Budget and Plans, **Then** all
   labels, enums, states, money/dates, feedback, and ARIA copy are localized and fit without overflow.
2. **Given** a rejected limit or recurring-operation save, **When** the error appears, **Then** the draft,
   accepted projections, focus, and live error remain recoverable.
3. **Given** a deep link from Planner/Notifications, **When** Finance opens or reloads, **Then** the intended
   month/occurrence/tab is restored without duplicating actions.
4. **Given** the production web build, **When** Capacitor sync runs, **Then** Android contains the same
   verified Finance feature without a second native domain implementation.

## Functional Requirements

- **FR-001**: The system MUST store owner-scoped monthly expense budget limits as exact Money values.
- **FR-002**: A budget MUST reference an owned expense category and supported currency; archived or
  income categories cannot receive a new limit.
- **FR-003**: `(user, category, month)` MUST be unique and monthly inputs MUST use canonical `YYYY-MM`.
- **FR-004**: Same-month ancestor/descendant budget scopes MUST NOT overlap.
- **FR-005**: Root budget actual MUST include direct and child categorized expense entries exactly once.
- **FR-006**: Budget actuals MUST derive from immutable ledger entries and historical conversion, including
  reversals, without mutable counters or stored rollups.
- **FR-007**: Budget states MUST be within below 80%, approaching at 80–100% inclusive, and exceeded above
  100%; incomplete conversion MUST null actual/remaining/utilization/state together.
- **FR-008**: Budget limit edits and deletion MUST preserve ledger/category history and owner isolation.
- **FR-009**: The system MUST store owner-scoped recurring Finance operations for income or expense with
  name, account, category, exact amount, mandatory flag, lifecycle, and bounded monthly rule.
- **FR-010**: Account currency and category direction MUST match the operation; mandatory is allowed only
  for expense.
- **FR-011**: Shared recurrence MUST add a typed Finance owner and normalized unique month-days 1–31.
- **FR-012**: Monthly interval MUST anchor to `starts_on`; invalid month-days in short months MUST skip.
- **FR-013**: One operation MAY select 1–10 unique month-days, optional local time, interval 1–12 months,
  and optional explicit end date; a missing end date MUST mean the deterministic inclusive ten-year
  ceiling from `starts_on`, never an unbounded schedule.
- **FR-014**: Materialization MUST remain idempotent and query-bounded, preserving legacy owner behavior.
- **FR-015**: Each materialized Finance occurrence MUST have an owner snapshot of direction, account,
  category, amount, currency, mandatory flag, and operation name.
- **FR-016**: Editing an operation MUST update only unfactored, unmoved future snapshots and MUST preserve
  settled or explicitly rescheduled identity/history.
- **FR-017**: Pausing/archiving MUST remove only unfactored future projections and preserve facts/history.
- **FR-018**: A pending occurrence MUST support explicit `actual` or `skipped` outcomes through Finance.
- **FR-019**: Actualization MUST atomically create one ordinary 018 group/entry from the snapshot and one
  unique outcome fact; retry/concurrency MUST return one result.
- **FR-020**: Skipping MUST create no ledger entry and MAY be cleared; actual outcomes MUST NOT be cleared.
- **FR-021**: Actual money correction MUST continue to use the 018 reversal boundary.
- **FR-022**: `PlannedOccurrence` fact status/link MUST be derived and rebuildable from Finance outcomes.
- **FR-023**: Monthly cash flow MUST compute planned income, mandatory expense, discretionary expense, and
  free cash flow (`income - mandatory`) from Finance-owned rules in Profile base currency.
- **FR-024**: Cash-flow conversion MUST use historical direct/inverse manual rates at each planned date;
  any missing non-zero rate MUST make the complete set of consolidated totals null.
- **FR-025**: Cash-flow and occurrence list inputs MUST be closed, Profile-local, current/future bounded,
  inclusive, and limited to 366 days.
- **FR-026**: Finance occurrences MUST appear once through `SchedulableSource`; Planner writes MUST delegate
  skip to Finance and move to the existing occurrence identity.
- **FR-027**: Timed pending Finance occurrences MUST use Notifications identity, quiet hours, locale,
  settings, delivery, escalation, snooze, disposition, and Android presentation unchanged.
- **FR-028**: Current-month budget crossings MUST create separate idempotent approaching/exceeded Finance
  warnings and close stale active warnings after correction/limit/lifecycle/settings changes.
- **FR-029**: Existing notification settings MUST gain a backward-compatible enabled Finance category.
- **FR-030**: All new private entities, queries, references, uniqueness, factories, API reads/writes, and
  notification/Planner adapters MUST enforce current-user ownership and foreign-as-missing behavior.
- **FR-031**: The API MUST expose closed authenticated budget, recurring-operation, planned-occurrence,
  outcome, and cash-flow contracts with exact decimal strings and no owner internals.
- **FR-032**: OpenAPI, Laravel routes/resources/tests, TypeScript types/client consumers, and documentation
  MUST change together.
- **FR-033**: The shared client MUST deliver the entire feature simultaneously in EN/RU/UK with localized
  formatting, validation/domain feedback, accessibility text, and no translated user content.
- **FR-034**: Desktop and exact 390×844 UI MUST preserve 44px targets, keyboard/focus/live-region behavior,
  safe areas, draft rollback, no horizontal overflow, deep links, both schemes, and Android bundle parity.
- **FR-035**: One additive reversible migration MUST preserve all existing rows, use MySQL-safe identifiers,
  and roll back only 019 additions/columns while keeping 018 and prior data.
- **FR-036**: Budgets/recurring Finance MUST remain module-owned deterministic projections; Review/Analytics
  receive no copied totals and no AI or external provider is required.
- **FR-037**: Debts, funds, financial goals, purchase/restock links, one-off planned operations, carry-over/
  envelopes, investments, provider FX/import/export/integrations/AI/offline authority/deployment MUST stay
  absent and explicitly deferred.

## Key Entities

- **FinanceBudgetLimit**: one exact monthly limit for one expense category/currency and owner.
- **FinanceRecurringOperation**: editable plan owner containing monetary/category/lifecycle semantics.
- **RecurringRuleMonthday**: normalized month-day member of the one shared recurrence rule.
- **FinanceOccurrenceDetail**: immutable-to-history snapshot attached one-to-one to a materialized plan.
- **FinanceOccurrenceFact**: unique explicit actual/skipped outcome; actual optionally links one ledger group.
- **Existing shared entities**: RecurringRule, PlannedOccurrence, FinanceTransactionGroup/entries,
  FinanceExchangeRate, PlannerEntry, InAppNotification, NotificationSettings, Profile.

## Assumptions and Dependencies

- Features 004, 006, 011, 012, and 018 are complete and authoritative.
- Profile owns locale, timezone, and base currency. Finance owns budget/cash-flow calculations.
- One recurring operation repeats one amount; different payday amounts use separate operations.
- A null recurring-operation end date is presentation shorthand for the implicit inclusive ten-year
  ceiling from its start; expansion never becomes unbounded.
- Budget warnings use a fixed 80% approach threshold in this increment; custom thresholds wait for need.
- Current/future monthly planning is bounded. Arbitrary historic plan reconstruction is not claimed.
- Month-day 29–31 skips a month where it does not exist; it never clamps.

## Out of Scope

- Carry-over/envelope budgets and custom warning thresholds.
- One-off expected income/expense plans and arbitrary RRULE/yearly recurrence.
- Debt/payment schedules, saving/emergency funds, financial goals, purchase/restock sources.
- Investments, bank/provider FX, import/export/reports, integrations, AI, offline-native authority.
- Deployment, feature 002, workflows, production data, containers, and live rollout.

## Success Criteria

- **SC-001**: Budget boundary fixtures at 79.999%, 80%, 100%, and above 100% return exact states.
- **SC-002**: Root/child budget actual and overlap tests show every expense is counted once.
- **SC-003**: Monthly fixtures across leap February and day 31 match exact expected sets and remain
  identical after repeated materialization.
- **SC-004**: Concurrent/retried actualization produces one outcome, one group, one entry, and one balance
  change in all automated runs.
- **SC-005**: Cash-flow fixtures produce exact 4-decimal totals or one complete null set with sorted missing
  currencies; bounded reads meet a fixed query budget.
- **SC-006**: Finance Planner entries and reminders appear once, delegate actions, localize at delivery,
  obey settings/quiet hours, and close with outcome/lifecycle changes.
- **SC-007**: Each budget threshold episode produces no more than one warning of each level under repeated
  jobs, and stale warnings close after projections fall below threshold.
- **SC-008**: Every new authenticated operation rejects foreign/unknown owner references without leakage
  and every request/object schema is closed.
- **SC-009**: Laravel focused/full, Pint, Composer validation/audit, OpenAPI, i18n, typecheck, Vitest, build,
  focused/full Playwright, mobile Node/audit/sync, rollback and safety gates pass.
- **SC-010**: EN/RU/UK × light/dark × desktop/exact-390 Budget/Plans/Cash Flow screenshots are inspected
  with no overflow, clipping, inaccessible contrast, or untranslated product copy.
- **SC-011**: Migration rollback removes only 019 additions and leaves 018 ledger plus every earlier
  migration/data row intact, then reapplies successfully.
- **SC-012**: Roadmap/docs/changelog/memory match delivery; one atomic non-coauthored commit is pushed with
  local HEAD equal to `origin/master`, protected deployment paths untouched, and handoff untracked.
