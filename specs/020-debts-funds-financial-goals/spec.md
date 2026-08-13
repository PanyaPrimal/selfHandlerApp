# Feature Specification: Debts, Funds, Financial Goals, and Purchase Links

**Feature ID**: `020-debts-funds-financial-goals`

**Created**: 2026-08-13

**Status**: Complete

**Input**: Deliver the final pre-analytics Finance vertical slice from the canonical roadmap: debts in
both directions, exact fixed or flexible repayment, regular and emergency saving funds, Finance goals
whose progress comes from those aggregates, Storage purchases that become expenses or installment
debts, and Supplement restock expenses with one-way Finance source links. Preserve the immutable
ledger, shared recurrence, owner boundaries, and EN/RU/UK shared client. Investments, compound-interest
amortization, providers, imports/exports, calendar integration, AI, native offline authority, and
deployment remain outside this increment.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Track What I Owe and What Is Owed to Me (Priority: P1)

The user keeps a private counterparty directory and records a principal-only debt in either direction.
They always see the original principal, exact active payments, remaining principal, deadline, and a
derived active/overdue/settled state without maintaining a second balance by hand.

**Independent Test**: create counterparties and both debt directions, edit/archive safe metadata,
attempt foreign/cross-currency links, record and reverse payments, and prove the projection changes
only from valid owned money facts.

**Acceptance Scenarios**:

1. **Given** an owned counterparty and matching account/category, **When** an "I owe" or "owed to me"
   debt is created, **Then** its principal and direction remain exact and its remaining value starts at
   the original principal.
2. **Given** an active flexible debt, **When** an arbitrary valid payment is recorded, **Then** one
   ordinary expense or income fact is linked and remaining principal decreases exactly once.
3. **Given** a linked payment is reversed, **When** the debt is read again, **Then** that payment is
   historical but inactive and the remaining principal increases by the same exact amount.
4. **Given** a foreign counterparty, account, category, debt, purchase, or payment reference, **When** a
   user reads or mutates it, **Then** the system behaves as though it does not exist and changes nothing.

---

### User Story 2 - Follow a Fixed Debt Schedule (Priority: P1)

The user can define a principal-only installment schedule with one exact amount, count, monthly day,
interval, first due date, optional local reminder time, and matching total. Concrete due payments appear
once, become overdue after their effective date, and can be paid or rescheduled explicitly.

**Independent Test**: create a fixed debt across short/leap months, materialize repeatedly, pay/reverse
one occurrence, move another, process reminders, and verify schedule, balance, and overdue state.

**Acceptance Scenarios**:

1. **Given** a fixed debt whose installment amount multiplied by count equals principal, **When** its
   schedule is generated repeatedly, **Then** the same bounded occurrence identities appear once.
2. **Given** day 31, **When** a month has no such day, **Then** that month is skipped rather than clamped
   and the promised number of installments is still produced over later valid months.
3. **Given** an unpaid effective due date before the user's current date, **When** debt details are read,
   **Then** that installment and debt are overdue; future or rescheduled dates are not.
4. **Given** a fixed occurrence is paid, retried, reversed, and paid again, **When** history is inspected,
   **Then** each money fact is preserved, only one payment is active, and the schedule is not duplicated.

---

### User Story 3 - Reserve Money in Saving Funds (Priority: P1)

The user creates a regular saving fund either as a virtual envelope backed by an account or as a link to
one dedicated savings account. Contributions, withdrawals, target progress, due pace, and reached/spent
state stay exact without double-counting account money.

**Independent Test**: exercise both storage modes, capacity conflicts, contribution retry, linked-account
transfers, reversal/correction, target/deadline pace, account available balance, lifecycle, and ownership.

**Acceptance Scenarios**:

1. **Given** a virtual fund backed by an account in the same currency, **When** money is reserved, **Then**
   the account balance is unchanged, the fund grows, and account available balance falls by that reserve.
2. **Given** all active virtual envelopes on an account, **When** a contribution would reserve more than
   its current balance, **Then** the complete action is rejected and no partial movement is stored.
3. **Given** a linked-account fund, **When** it is topped up, **Then** an ordinary transfer changes the
   real account balances and the fund reads progress from the linked account exactly once.
4. **Given** a target and optional deadline, **When** progress is read, **Then** saved, remaining,
   percentage, reached state, and required monthly pace are exact or explicitly unavailable.

---

### User Story 4 - Maintain an Emergency Fund and Honest Cash Flow (Priority: P1)

The user marks a fund as mandatory and perpetual and chooses a monthly top-up based on a fixed amount,
a percentage of planned income, or a target of N months of average actual expenses. The amount appears
in the existing monthly cash-flow view, reopens after a drawdown, and never invents missing history or FX.

**Independent Test**: calculate all three rule modes, materialize/skip/actualize a top-up, draw below the
target, change Profile currency/rates/plans, and compare the fund, schedule, notifications, and cash flow.

**Acceptance Scenarios**:

1. **Given** a fixed or planned-income-percent emergency rule, **When** a month is projected, **Then** one
   mandatory top-up is calculated on the selected local day and included in free cash flow.
2. **Given** the N-months mode, **When** three complete prior months exist, **Then** the target uses their
   average actual expenses and the top-up closes the shortfall over the configured build horizon.
3. **Given** missing prior history or required conversion, **When** the emergency projection is read,
   **Then** the affected target/top-up and consolidated cash-flow totals are unavailable together with
   explicit evidence rather than treated as zero.
4. **Given** a full emergency fund is drawn down, **When** it is projected again, **Then** it becomes
   under-funded and mandatory future top-ups resume under the same rule.

---

### User Story 5 - Use Real Finance Goals (Priority: P2)

The user creates a Finance goal linked to exactly one saving fund or debt. Progress and milestones are
derived from that aggregate, while the common Goal keeps its name, deadline, lifecycle, and unified list.

**Independent Test**: create both subtypes, contribute/pay/reverse, check milestones and progress, try
duplicate/foreign targets, and verify the same typed goal appears in Finance and the unified Goals view.

**Acceptance Scenarios**:

1. **Given** a save goal, **When** its fund changes, **Then** current value and progress come from the
   fund's own saved projection rather than any unrelated account balance.
2. **Given** a pay-off goal, **When** its debt balance falls or a payment is reversed, **Then** progress
   moves toward or away from zero without a stored progress counter.
3. **Given** ordered monetary milestones, **When** current value crosses them, **Then** achievement is
   derived consistently in the correct increasing or decreasing direction.
4. **Given** an existing active Finance goal for an aggregate, **When** another is attempted, **Then** the
   duplicate is rejected without affecting the existing goal.

---

### User Story 6 - Turn Purchases and Restocks into Money Facts (Priority: P2)

The user can triage a Storage item as a purchase with an estimated Money value. A purchase becomes bought
only through a linked expense or installment debt. A Supplement restock proposal can create a linked
expense, while Supplements remains the sole owner of proposal and stock lifecycle.

**Independent Test**: create/cancel purchases, buy directly or via debt, reverse a direct purchase, check
idea blockers, post/reverse a restock expense, retry, and prove neither source module receives money logic.

**Acceptance Scenarios**:

1. **Given** a wanted purchase, **When** an owned expense is posted from it, **Then** one immutable source
   link exists and the purchase becomes bought, unblocking its parent idea.
2. **Given** a wanted purchase, **When** an "I owe" installment debt is created from it, **Then** the same
   bought invariant holds without also creating an immediate expense.
3. **Given** the only direct purchase expense is reversed, **When** Storage is read, **Then** the purchase
   returns to want and blocks its parent again; the old money history remains visible.
4. **Given** an open Supplement restock proposal, **When** its expense is posted, **Then** Finance stores
   the one-way source link but does not add stock, resolve the proposal, or own arrival semantics.

---

### User Story 7 - Use One Complete Shared Client (Priority: P3)

Finance gains Debts, Funds, and Goals surfaces, while Storage and Supplements expose their Finance actions.
Debt/fund occurrences appear in Planner and use existing Finance notification settings. Every lifecycle,
projection, state, validation, and deep link works in English, Russian, and Ukrainian on desktop and the
exact mobile/Android viewport.

**Independent Test**: complete all seven journeys in every locale and both schemes, reject mutations and
recover drafts, navigate Planner/notification/source deep links, inspect screenshots, and synchronize the
production bundle into Android.

**Acceptance Scenarios**:

1. **Given** any supported locale/theme/viewport, **When** the new surfaces open, **Then** all labels,
   money/dates, enums, evidence, feedback, and accessibility text are localized and fit without overflow.
2. **Given** a rejected debt, fund, goal, purchase, restock, or payment action, **When** feedback appears,
   **Then** accepted data and the user's recoverable draft remain intact and focus reaches the error.
3. **Given** a debt/fund occurrence in Planner or a Finance notification, **When** the user acts or follows
   its link, **Then** Finance opens the same identity and never creates a duplicate schedule or money fact.
4. **Given** a production shared-client build, **When** Android is synchronized, **Then** it contains the
   same verified feature without a second native financial implementation.

## Edge Cases

- A fixed schedule total does not equal principal, a payment exceeds remaining principal, or a zero/
  over-precision amount is submitted.
- Day 29–31 skips absent calendar days; intervals and reschedules still preserve the exact installment count.
- A debt deadline or scheduled payment crosses the user's local date while the server is on another day.
- A category/account is archived after a debt or source snapshot exists; history remains readable but new
  facts cannot select an archived reference.
- A virtual reserve becomes larger than account balance after unrelated spending; the projection reports an
  explicit over-reserved state and blocks further positive reservation without deleting history.
- A linked account is archived or its balance becomes negative; progress remains honest and new transfers
  are rejected by the existing account lifecycle.
- A historical rate exists only in the inverse direction; a non-zero conversion has no usable rate.
- Three prior expense months include reversals, transfers, mixed currencies, or no expense facts at all.
- Profile base currency changes after a debt, fund, goal, or emergency rule was created.
- Two retries race to pay the same occurrence or buy the same purchase/restock proposal.
- A source expense is reversed after its Storage/Supplement source was archived or changed.
- A linked purchase debt is settled or archived; buying remains historical and is not undone by repayment.
- A fund/debt backing a Goal is archived; the goal remains readable with historical progress and cannot be
  silently retargeted.

## Functional Requirements

- **FR-001**: The system MUST maintain a private owner-scoped Counterparty directory with name, kind,
  optional note, lifecycle, deterministic ordering, and duplicate-name protection.
- **FR-002**: A Debt MUST record one owner, counterparty, direction (`owe` or `owed_to_me`), repayment mode
  (`fixed` or `flexible`), exact original principal/currency, origination date, optional final deadline,
  optional default account/category, note, and lifecycle.
- **FR-003**: Debt account currency and payment-category direction MUST match the debt; archived, foreign,
  unsupported, or direction-mismatched references MUST be rejected.
- **FR-004**: Remaining principal MUST derive from active linked payment facts and reversals, never from a
  mutable balance field, and MUST remain between zero and original principal.
- **FR-005**: A flexible debt MUST have no generated schedule; any owned positive payment up to remaining
  principal MAY be recorded, and overdue is derived only from its final deadline.
- **FR-006**: A fixed debt MUST define one positive installment amount, count 1–120, monthly interval 1–12,
  one day 1–31, first due date, and optional local reminder time; installment × count MUST equal principal
  and the final valid due date MUST fall within ten years of the first.
- **FR-007**: Fixed debt recurrence MUST use the shared monthly engine, preserve the promised occurrence
  count despite short-month skips, materialize idempotently, and retain moved or fact-bearing history.
- **FR-008**: Fixed occurrence state MUST derive as scheduled, paid, or overdue from effective Profile-local
  date and its active payment; flexible debt state MUST derive as active, overdue, or settled.
- **FR-009**: Debt payment MUST atomically create one ordinary exact expense (`owe`) or income
  (`owed_to_me`) money fact and one debt-payment fact under one stable idempotency identity.
- **FR-010**: A fixed occurrence MUST accept one active payment at a time; retry/concurrency MUST return the
  same result, and reversing it MUST preserve history, reopen the occurrence, and permit a later repayment.
- **FR-011**: Fixed payments MUST use their exact scheduled principal; flexible payments MUST reject an
  amount above remaining principal; partial fixed installments and interest allocation are not claimed.
- **FR-012**: Debt edits/archival MUST preserve payment/schedule history; fields that define a fact-bearing
  or explicitly moved schedule MUST not rewrite that accepted history.
- **FR-013**: A regular or emergency Saving Fund MUST record exact currency, target semantics, deadline,
  storage mode (`virtual` or `linked_account`), backing/linked account, optional category, and lifecycle.
- **FR-014**: Emergency Fund MUST be a Saving Fund subtype that is mandatory and perpetual; a regular fund
  MUST have a positive target, while an emergency target MAY be explicit or derived by its rule.
- **FR-015**: A virtual fund MUST reserve exact value on one same-currency backing account without moving
  ledger money; total active virtual reserves MUST NOT exceed that account's current balance on a positive
  contribution, and account available balance MUST subtract those reserves exactly once.
- **FR-016**: A linked-account fund MUST read saved value from one dedicated same-currency account; one
  active linked-account fund may claim an account, and top-up/drawdown MUST use ordinary transfers.
- **FR-017**: Virtual contributions/withdrawals MUST be append-only exact allocation movements with stable
  idempotency; they MUST reject negative saved value and preserve correction history.
- **FR-018**: Fund projection MUST derive saved, target, remaining, progress, reached/under-funded/over-
  reserved state, and required pace without mutable aggregate counters.
- **FR-019**: Emergency top-up mode MUST support fixed Money, percentage of that month's planned income,
  or a target of N months of average actual expenses; inputs MUST be bounded and mutually exclusive.
- **FR-020**: N-month expense target MUST use all actual expense money facts from the three complete prior
  Profile-local months, including reversals and excluding transfers/adjustments; required top-up MUST close
  the current shortfall over an explicit 1–60 month build horizon.
- **FR-021**: Percentage and expense-month calculations MUST use exact historical direct/inverse conversion;
  missing non-zero history/rate MUST make the affected target/top-up explicitly unavailable, never zero.
- **FR-022**: Any recurring regular fixed top-up and every emergency top-up MUST use one shared monthly
  recurrence identity with immutable amount/mode evidence for moved or fact-bearing occurrences.
- **FR-023**: A fund occurrence MUST support explicit actual or skipped outcome; actual MUST create one
  virtual allocation or linked-account transfer, while skip creates no money/reserve and may be cleared.
- **FR-024**: Debt due payments and emergency top-ups MUST extend existing planned cash flow as mandatory
  expense, without double-counting recurring operations or actual outcomes and with whole-result FX
  incompleteness.
- **FR-025**: Debt/fund occurrences MUST appear once in Planner and timed pending occurrences MUST reuse the
  existing Finance notification setting, locale, quiet hours, escalation, snooze, closure, and safe links.
- **FR-026**: The common Goal mechanism MUST gain a Finance type linked to exactly one owned active Debt or
  Saving Fund, with no second active Finance Goal for the same aggregate.
- **FR-027**: Save-goal progress MUST come from the linked fund's saved/target values; pay-off progress MUST
  come from original versus remaining debt principal; neither may copy an account balance or progress counter.
- **FR-028**: Finance Goal milestones MUST be exact Money values in the aggregate currency, ordered along
  the increasing save or decreasing debt direction, with achievement derived from current progress.
- **FR-029**: Finance Goals MUST preserve common Goal naming, deadline, active/completed/abandoned/archive
  lifecycle and appear in both the Finance workspace and unified Goal list without duplicated Goal records.
- **FR-030**: Storage Item MUST gain a purchase type with optional exact estimated amount/currency; its
  wanted/bought/canceled meaning MUST coexist with quick capture, parent blockers, tags, and projects.
- **FR-031**: A purchase MUST become bought if and only if it has one active linked expense or an installment
  Debt; direct client attempts to claim bought without one MUST be rejected.
- **FR-032**: Reversing the only direct purchase expense MUST return it to wanted and restore blocker
  behavior; a linked installment Debt keeps the purchase bought through debt repayment/archival.
- **FR-033**: An owned wanted purchase MAY create one direct ordinary expense using its estimate as a draft
  or MAY become the unique source of one `owe` fixed Debt, but MUST NOT have both active paths.
- **FR-034**: An open owned Supplement restock proposal MAY create one direct ordinary expense with an
  immutable one-way source link; Finance MUST NOT mutate stock, create a stock movement, or decide proposal
  arrival/resolution.
- **FR-035**: Purchase/restock source posting MUST be owner-scoped, idempotent, concurrency-safe, reversible,
  and limited to one active source money fact; reversal MUST release the source for a corrected retry.
- **FR-036**: Money history MUST expose immutable source type/id and safe source context/deep links without
  leaking another owner or requiring the source row to remain active.
- **FR-037**: Every new private entity, relationship, query, uniqueness rule, aggregate, shared adapter,
  request, and mutation MUST enforce current-user ownership and foreign-as-missing behavior.
- **FR-038**: All reads MUST be bounded and grouped; list/projection query counts MUST remain fixed as debts,
  funds, payments, milestones, and source rows grow.
- **FR-039**: Closed authenticated contracts, exact decimal strings, registered operations, client types,
  and consumer behavior MUST remain synchronized, including backward-compatible 008/009/011/018/019 shapes.
- **FR-040**: The shared client MUST deliver every new lifecycle and source action simultaneously in EN/RU/UK
  with localized money/dates/enums/errors, accessible semantics, recoverable drafts, and no translated user data.
- **FR-041**: Desktop and exact 390×844 UI MUST preserve 44px targets, keyboard/focus/live-region behavior,
  safe areas, both schemes, deep-link/reload state, no horizontal overflow, and Android bundle parity.
- **FR-042**: Additive reversible data evolution MUST preserve all existing rows/contracts, use safe
  identifiers, remove only 020 additions on rollback, and never touch deployment or live data.
- **FR-043**: Counterparties, debts, funds, Finance goals, and source links MUST remain Finance-owned or
  one-way adapters; Storage/Supplements MUST retain their own lifecycle truth and Review/Analytics receive
  no copied Finance totals.
- **FR-044**: Compound-interest/amortization formulas, arbitrary one-off plans, investments, provider rates,
  bank sync, imports/exports/reports, calendar integration, AI, native offline writes, and deployment MUST
  stay absent and explicitly deferred.

## Key Entities

- **FinanceCounterparty**: reusable private bank/store/person/other identity for debts.
- **FinanceDebt**: principal-only obligation/claim plus fixed/flexible policy and optional purchase source.
- **FinanceDebtOccurrenceDetail / FinanceDebtPaymentFact**: fixed-payment snapshot and durable linked fact.
- **FinanceSavingFund**: regular/emergency aggregate with virtual/linked storage and top-up rule.
- **FinanceFundMovement / FinanceFundOccurrenceDetail / FinanceFundOccurrenceFact**: virtual reserve history
  and scheduled top-up evidence/outcomes.
- **FinanceGoalDetail**: one-to-one typed link from common Goal to one debt or fund.
- **Existing shared entities**: Goal/Milestone, Item, SupplementRestockProposal, RecurringRule/
  PlannedOccurrence, Finance accounts/categories/groups/entries/rates, Planner and Notifications.

## Assumptions and Dependencies

- Features 004, 006, 008, 009, 011–013, 017–019 are complete and authoritative.
- Counterparties are entities from the start; duplicate cleanup later is more expensive than an owned directory.
- Debt interest, fees, and total overpayment are recorded only in free-form notes in this increment; all
  automated balances/schedules are principal-only.
- A fixed schedule has one amount/day/interval and exact count; unusual or changing installments use flexible
  repayment until a later schedule versioning feature exists.
- Three complete prior months means the three calendar months ending before the projected month; no facts is
  insufficient history for the expense-month rule rather than a zero average.
- A virtual envelope is backed by one real account so its reserved amount can reduce a meaningful available
  balance without pretending to create money.
- A linked-account fund claims one dedicated account to prevent the same balance from satisfying several funds.
- Purchase status uses existing Storage lifecycle internally: active means wanted, done means bought, and
  dropped means canceled; the user sees purchase-specific labels and cannot set done directly.
- A restock expense means money was spent, not that stock arrived; only a Supplements stock movement resolves
  physical inventory/proposal state.

## Out of Scope

- Compound interest, annuity/declining amortization, fees, principal/interest allocation, refinancing.
- Multiple changing installments, weekly schedules, partial fixed installments, automatic bank payments.
- Arbitrary one-off planned income/expense unrelated to a purchase/restock source.
- Fund portfolios, yield, investments, securities, quotes, shared/joint funds, automatic income interception.
- Bank/payment/FX providers, imports, exports, PDF/CSV reports, external calendar sync.
- Automated purchase ordering, supplier integration, receipt parsing, or automatic Supplement stock arrival.
- Review/Analytics rollups, AI advice, native offline write authority.
- Deployment, feature 002, workflows, production data, containers, and live rollout.

## Success Criteria

- **SC-001**: Debt fixtures in both directions produce exact principal, active/reversed payment history,
  remaining value, and active/overdue/settled state with no balance drift across retries.
- **SC-002**: Fixed schedules across day 29–31 and leap/non-leap months contain the promised count, remain
  identical after repeated generation, and reopen exactly one occurrence after payment reversal.
- **SC-003**: Concurrent/retried debt, fund, purchase, and restock actions create no duplicate active fact,
  reserve, transfer, source link, or balance change in automated runs.
- **SC-004**: Virtual envelope fixtures prove balance unchanged, available balance reduced once, aggregate
  reserve never above balance on contribution, and honest over-reserved state after unrelated spending.
- **SC-005**: Fixed, income-percent, and three-month-expense emergency fixtures produce exact target/top-up
  evidence or one explicit unavailable state for missing history/FX.
- **SC-006**: Cash flow includes each active debt due and mandatory emergency top-up exactly once and returns
  one complete null monetary set when any required conversion is missing.
- **SC-007**: Finance Goal fixtures move forward and backward solely with their fund/debt projections and
  derive every milestone without stored progress.
- **SC-008**: A purchase is bought exactly when backed by an active direct expense or installment Debt;
  reversal restores wanted/blocker state, while a restock expense never changes Supplement stock.
- **SC-009**: Every new authenticated operation rejects foreign/unknown references without leakage, all
  mutation inputs/domain objects are closed, and earlier consumer contracts remain compatible.
- **SC-010**: Focused/full backend, formatting/dependency, contract, localization, type, unit, build,
  desktop/mobile browser, Android sync, rollback, and safety gates pass with zero unexplained failure.
- **SC-011**: EN/RU/UK × light/dark × desktop/exact-390 Debts/Funds/Goals/source screenshots are inspected
  with no overflow, clipping, inaccessible control, stale state, or untranslated product copy.
- **SC-012**: Reads meet fixed query budgets as each collection grows, and every date/range remains bounded
  in the user's Profile timezone.
- **SC-013**: Roadmap/docs/changelog/memory match delivery; one atomic non-coauthored commit is pushed with
  local HEAD equal to `origin/master`, protected deployment paths untouched, and handoff untracked.
