# Research: Supplements, Courses, Intake, and Stock

## Canonical Inputs and Current Boundaries

- `docs/design/modules.md`, Module 2a plus consumable-stock and Planner ownership boundaries
- `docs/design/recurrence-engine.md`, especially interval/cycle/multi-slot expansion and fact ownership
- `docs/design/notifications.md`, source-backed escalation, quiet hours, localization, and closure
- `docs/design/data-conventions.md`, exact decimals, base units, owner rows, archival, and aggregates
- `docs/design/decisions.md`, neutral medication framing and cross-module links
- `docs/design/delivery-roadmap.md`, feature 017 outcome, prerequisites, and deferrals
- Features 003–016 and current Profile, Goal, Recurrence, Planner, Notifications, Today/Review, i18n,
  responsive shell, and Capacitor implementation

The repository has no Supplement owner or stock fact. Shared recurrence supports one occurrence per
day and daily/weekly selection; Notifications and Planner already dispatch by owner type. The safe
slice therefore adds one domain and extends those shared seams without adding a second scheduler,
notification store, daily aggregate, finance record, native domain store, or medical intelligence.

## Decisions

### 1. Neutral user-entered reference, not a clinical catalogue

**Decision**: `Supplement` is a private user-owned reference with a name, neutral category/form,
canonical stock unit, usual dose, optional package quantity, preferred display unit, note, restock lead
days, and archive lifecycle. Categories are `vitamin`, `sports_nutrition`, `nootropic`, `medication`,
and `other`; forms are `capsule`, `tablet`, `powder`, `liquid`, `injection`, and `other`. Product copy
states that the user supplied the substance, dose, timing, and course.

**Why**: It fulfils the canonical reference while avoiding any implication that the application
selected or approved a substance. Private rows also avoid licensing/provider and medical-content risk.

**Rejected**: Seeded branded products, recommended regimens, interaction warnings, dosage validation,
or an AI regimen. Those are not reliable neutral tracking and are explicitly deferred.

### 2. Canonical quantity is exact and display units never become truth

**Decision**: Persist `DECIMAL(14,6)` quantities in `gram`, `millilitre`, or `piece`. Inputs may use
`mg`, `g`, `ml`, or `piece`; the service converts mg to grams using decimal-string arithmetic. Pieces
must be positive whole numbers. Each reference/course/intake preserves a compatible preferred display
unit for rendering, but calculations use canonical decimal strings only.

**Why**: The data conventions require base units and no float. Six decimal places preserve practical
mg input while leaving substantial inventory range. Storing display preference prevents an edit from
making historical labels awkward without creating a second amount truth.

**Rejected**: Floats, mixed stored units, integer micrograms for every form, or silently coercing an
incompatible unit. All make aggregation or contracts harder to reason about.

Once a course, intake, or stock movement exists, `stock_unit` is immutable; changing the physical
dimension requires a new reference. Other descriptive/default fields remain correctable.

### 3. A bounded course is the only supplement schedule owner

**Decision**: `SupplementCourse` owns exactly one existing `RecurringRule` with owner type
`supplement_course`. A course links one Supplement and optional accessible Goal, snapshots one dose,
and has required inclusive start/end bounds plus active/paused/archive lifecycle. Input may use either
`ends_on` or `duration_days`; the accepted row stores the derived end date. A new course cannot start
before the current Profile-local date, and its Supplement scope is immutable after creation.

**Why**: The shared rule remains the single schedule truth while Supplement keeps regimen semantics.
All courses are bounded, which keeps forecast truth and course adherence explainable.

**Rejected**: Dates or weekdays directly on the course, an unbounded default, or one rule per slot.
Those duplicate recurrence and make one course appear as several unrelated plans.

### 4. Extend recurrence once for interval, cycles, and normalized slots

**Decision**: Add `interval_count` (default 1), nullable paired `cycle_on_days/cycle_off_days`, and a
normalized `recurring_rule_slots` child (`slot`, local `occurrence_time`, `sort_order`). Legacy rules
without child slots retain their exact `slot=''`/`slot_time` behavior. The pure expander adds interval
and cycle filters anchored to `starts_on`; the materializer expands date × slot and preserves existing
fact/reschedule safeguards.

Weekly interval is based on Profile-local Monday calendar weeks containing `starts_on`; cycles use
elapsed local calendar days. Daily interval uses elapsed local days. Cycle filtering and frequency/
weekday filtering must both pass. New courses require 1–8 unique timed slots.

**Why**: Interval, on/off cycles, and multiple daily times are canonical cross-cutting recurrence
capabilities, not Supplement-specific schedule columns. Slot rows have identity/order and are queried,
so normalized storage is preferable to JSON under the data conventions.

**Rejected**: A domain-only expander, `times_per_day` JSON, arbitrary RRULE parsing, or destructive
backfill of legacy rules. Normalized generic slots keep the engine domain-unaware and portable.

### 5. Slot context remains domain-owned

**Decision**: `supplement_course_slots` is a one-to-one detail of a generic RecurringRuleSlot and owns
only the neutral context `unspecified|with_food|empty_stomach`. Slot code/time/order stay in the shared
row. Replacing a course schedule atomically replaces its future slot configuration; occurrence
date/slot values and intake snapshots preserve acted history.

**Why**: Recurrence needs time and stable slot identity, but food context is not a scheduling-engine
concept. A small detail table avoids generic JSON payload while keeping context normalized/validated.

### 6. One correction-safe intake fact per occurrence

**Decision**: `SupplementIntake` stores course/supplement, original planned date, effective date,
slot, `taken|skipped`, dose/label/display-unit snapshots, nullable taken UTC instant, and note. Unique
`(supplement_course_id, planned_on, slot)` plus a locked PlannedOccurrence gives idempotent identity.
`planned_occurrences.supplement_intake_id` is a nullable unique fact FK and joins the existing mutually
exclusive fact set. PUT corrects/upserts; DELETE clears/unlinks.

**Why**: Original date/slot are stable even after reschedule, while effective date supplies the day
summary. Snapshot facts do not drift when the reference/course changes. Skips are explicit domain
facts and close reminders without pretending consumption.

**Rejected**: A mutable occurrence-only outcome, a generic polymorphic fact table, one intake per
course/date, or append-only duplicate corrections. They weaken identity, multi-slot support, or the
implemented recurrence fact convention.

### 7. Stock is an immutable ledger plus intake facts

**Decision**: `SupplementStockMovement` is append-only and stores `restock|correction`, a signed exact
quantity delta, effective local date, note, and required correction reason. Restock must be positive;
correction may be positive or negative but never zero. Remaining stock is `SUM(movement deltas) -
SUM(taken intake snapshot doses)`. The API exposes list/create only; mistakes are compensated by a
new correction.

**Why**: A mutable remaining counter cannot be reconstructed after intake corrections or retries.
The formula keeps intake as the sole consumption fact and allows an honest negative discrepancy.

**Rejected**: Auto-created consumption movement per intake (duplicate facts), editing/deleting stock
history, or clamping at zero.

### 8. Forecast uses exact future occurrences with a transparent bound

**Decision**: `SupplementStockService` owns remainder and `SupplementStockForecastService` projects at
most 730 Profile-local dates. It combines all active nonarchived courses for a reference. Within the
materialized window it uses durable occurrence status/reschedule; beyond it uses the same pure rule
expansion and slots, excluding date/slot keys already represented durably. Sorted planned doses are
subtracted until stock reaches zero.

States are `ready`, `already_depleted`, `no_stock`, `no_active_course`, `no_consumption`,
`course_ends_with_stock`, and `beyond_horizon`, each with exact inputs and nullable date. Taken facts
already affect remaining and are not predicted again; skipped facts do not consume.

`no_stock` is reserved for a reference with neither inventory nor taken-intake facts; skipped facts
do not establish stock. Once any stock or taken fact exists, a zero/negative remainder is
`already_depleted`, with `runout_on=as_of`; this is an immediately
actionable shortage. The distinction tells the user whether to establish opening inventory or restock.

**Why**: This produces an exact explainable forecast for actionable periods without an average-rate
guess. The overlay respects user reschedules and corrections where stable occurrences exist.

**Rejected**: Stored forecast columns, average daily burn, a background rollup, or silent extrapolation
past the bound.

### 9. Restock proposal is one-off and concurrency-safe

**Decision**: `SupplementRestockProposal` persists shortage fingerprint, run-out/needed-by dates,
optional package-sized suggestion, unit, and `open|dismissed|resolved` lifecycle. Nullable unique
`active_supplement_id` equals `supplement_id` only while open, giving one active row portably. The
fingerprint uses supplement, run-out/needed-by date, and suggested quantity—not day-to-day noise.
Reconciliation locks the Supplement, resolves stale open rows, honours a dismissed identical
fingerprint, and opens only a materially new shortage inside lead time.

Both `ready` within lead time and `already_depleted` can open a proposal. Reconciliation runs after
course/intake/stock mutations and before Supplement workspace reads. A GET
may therefore perform an idempotent projection reconciliation, like existing settings/target GETs.

**Why**: This is a durable action another module can consume later, but explicitly not recurrence or
finance. The unique active key protects concurrent first reads.

**Rejected**: Recurring restock rules, ephemeral Vue suggestions, one proposal per read, or creating a
transaction before Money/base-currency ownership exists.

### 10. Shared notifications own both intake escalation and proposal delivery

**Decision**: Add enabled-by-default `supplement` category, `supplement_intake` type, and
`supplement_restock` type/source. Timed planned occurrences receive initial reminders with a safe
`/supplements?date=...&course=...&slot=...` link and up to three repeats at a configured 30-minute
interval. Done/skipped/fact clear/reschedule/pause/archive/missing owner/disabled/dismissed rules use
the existing source disposition mechanics. Open proposals may create one non-escalating notification.

**Why**: Escalation, quiet hours, locale-at-delivery, dedupe, snooze, and Android presentation already
have one owner. Supplements only supplies actionable source state and rendering parameters.

**Rejected**: Per-course alarms, a Supplement inbox, push/FCM, or marking an occurrence missed merely
because reminder attempts ended.

### 11. Adherence and cross-module projections stay read-only

**Decision**: `SupplementAdherenceService` owns selected-day and max-366-day projections from rules,
occurrences, and facts. Eligible means an occurrence whose effective local time has elapsed. `done`
is numerator; done + skipped + overdue planned is denominator; future planned is pending and excluded.
No facts means percentage null, not zero. Planner registers `SupplementOccurrenceSource`; Planner skip
delegates to SupplementIntakeService. Today transports and Review presents the same day DTO.

**Why**: This follows Workout/Sleep/Nutrition ownership. Corrections recompute truth with no mutable
rollup or Review-owned copy.

**Rejected**: A Planner skip flag, Vue arithmetic, DailyReview columns, or feature-022 charts.

### 12. Thirteen authenticated operations form one closed contract

**Decision**: Deliver:

1. GET/POST/PATCH `/supplements[/{supplement}]`
2. GET/POST `/supplements/{supplement}/stock-movements`
3. GET/POST/PATCH `/supplement-courses[/{course}]`
4. GET `/supplements/days/{date}`
5. PUT/DELETE `/supplement-occurrences/{occurrence}/intake`
6. GET `/supplements/adherence`
7. PATCH `/supplement-restock-proposals/{proposal}`

All requests are closed and owner-scoped. Responses use decimal strings. One responsive
`/supplements` workspace plus navigation, Planner, Today, Review, Notifications, EN/RU/UK, theme,
exact-mobile, and Capacitor changes ship together.

**Why**: The operations cover reference, plan, fact, stock, forecast, and action lifecycles without
speculative delete/export/provider endpoints.

### 13. Money and Finance are deliberately postponed

**Decision**: Feature 017 stores package quantity but no price/currency/cost. The restock proposal has
stable identity and quantity/date fields so feature 020 can add an optional finance proposal link after
feature 018 establishes Money, currencies, accounts, and Profile base-currency use.

**Why**: Adding price now would either duplicate future Money semantics or create an unconvertible bare
decimal. The roadmap explicitly defers finance transaction creation.

### 14. Additive migration and broad recurrence regression are mandatory

**Decision**: One reversible migration creates seven domain/shared tables, adds three default/
nullable recurrence columns, and adds one nullable fact FK. Existing rows are neither rewritten nor
deleted. Legacy rules have `interval_count=1`, null cycles, no child slots, and continue using
`slot_time`/empty slot. Rollback removes the fact FK and new tables before the new rule columns.

**Why**: This preserves current MySQL/SQLite data and every owner. Short explicit index names avoid
MySQL's identifier limit.

## GitNexus Architecture and Impact Evidence

The index was refreshed at baseline commit `9cd7c116888ee7c63bcaa52a8d8b995c7fec1f14`
(6,935 nodes, 16,980 edges, 300 flows). Pre-change depth-1 analysis found the intentionally shared
recurrence model to be the critical regression surface:

| Boundary | Risk | Direct evidence and closure scope |
|---|---:|---|
| `RecurringRule` | High | 29 direct imports across recurrence, routine, habit, sleep, workout, nutrition, Planner, notifications, commands, and tests |
| `PlannedOccurrence` | Critical | 38 direct imports across the same fact/projection surfaces |
| `RecurrenceMaterializer` | Medium | 7 direct command/test imports |
| `OccurrenceFactSynchronizer` | Medium | 6 direct command/controller/test imports |
| `NotificationSourceSynchronizer` | Medium | 6 direct job/integration/test imports |
| `NotificationDispatcher` | Medium | 5 direct job/integration/test imports |
| `SourceRegistry`, `PlannerController`, `NotificationSettings`, `TodayController`, expander | Low | 1–3 direct route/controller/contract consumers each |

No shared symbol may change semantics for an existing owner. Every depth-1 module above is included in
affected and full gates, legacy recurrence fixture equality is explicit, and GitNexus
`detect_changes(all/staged)` is required before commit.
