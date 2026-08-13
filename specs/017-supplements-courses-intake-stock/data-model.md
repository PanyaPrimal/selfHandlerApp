# Data Model: Supplements, Courses, Intake, and Stock

## Conventions

- Every domain row and shared child has `user_id`; model hooks/services reject owner mismatch.
- Quantities are decimal strings backed by `DECIMAL(14,6)`, never floats. Canonical units are
  `gram`, `millilitre`, and `piece`; display units are `mg`, `g`, `ml`, and `piece`.
- Calendar dates and recurrence times are Profile-local. `taken_at` is a UTC instant.
- Supplement/Course/Proposal use domain lifecycle. Intake and StockMovement are accepted facts.
- No stock, forecast, adherence, notification, or day-summary rollup is persisted.

## Additive Migration

`2026_08_14_000000_create_supplements_courses_intake_stock.php`

### `supplements`

| Column | Type | Rules |
|---|---|---|
| id | bigint | primary key |
| user_id | FK users | cascade account deletion |
| name | string(160) | user content |
| category | string(32) | `vitamin|sports_nutrition|nootropic|medication|other` |
| form | string(24) | `capsule|tablet|powder|liquid|injection|other` |
| stock_unit | string(16) | `gram|millilitre|piece` |
| preferred_display_unit | string(8) | compatible `mg|g|ml|piece` |
| usual_dose_quantity | decimal(14,6) | positive canonical amount |
| package_quantity | nullable decimal(14,6) | positive canonical amount |
| restock_lead_days | unsigned smallint | 0..90; default 7 |
| note | nullable text | max 5,000 at API |
| is_archived | boolean | default false |
| archived_at | nullable timestamp | lifecycle-derived |
| timestamps | timestamps | |

Indexes: `(user_id,is_archived,name,id)`. No public rows and no hard-delete API.
`stock_unit` is editable only while no Course, Intake, or StockMovement references the row.

### `supplement_courses`

| Column | Type | Rules |
|---|---|---|
| id/user_id | bigint/FK | private owner |
| supplement_id | FK supplements | restrict hard deletion |
| goal_id | nullable FK goals | null on hard Goal deletion |
| name | nullable string(160) | user label; reference name remains available |
| dose_quantity | decimal(14,6) | positive canonical snapshot, same stock unit |
| dose_display_unit | string(8) | compatible historical display preference |
| starts_on/ends_on | date/date | required inclusive Profile-local bounds |
| is_active | boolean | default true; false means paused |
| is_archived | boolean | default false |
| archived_at | nullable timestamp | lifecycle-derived |
| timestamps | timestamps | |

Indexes: `(user_id,is_archived,is_active,starts_on,ends_on)` and `(user_id,supplement_id)`. Exactly one
RecurringRule points to the course by unique `(owner_type='supplement_course', owner_id=id)`.
Create requires `starts_on >= current Profile-local date`; `supplement_id` is immutable thereafter.

### `recurring_rules` additive columns

| Column | Type | Rules |
|---|---|---|
| interval_count | unsigned smallint | default 1; 1..52 for accepted course input |
| cycle_on_days | nullable unsigned smallint | paired with off; 1..366 |
| cycle_off_days | nullable unsigned smallint | paired with on; 1..366 |

Legacy rows need no backfill: database defaults/nulls preserve daily/weekly behavior. New course rules
always have non-null `starts_on`/`ends_on`; existing owner rules may keep null bounds.

### `recurring_rule_slots`

| Column | Type | Rules |
|---|---|---|
| id/user_id | bigint/FK | shared child owner |
| recurring_rule_id | FK recurring_rules | cascade with rule |
| slot | string(32) | non-empty stable code |
| occurrence_time | time | required for feature 017 |
| sort_order | unsigned tinyint | 0..7 |
| timestamps | timestamps | |

Unique `(recurring_rule_id,slot)` and `(recurring_rule_id,sort_order)`; owner/time lookup index. Rules
without slot rows use the legacy empty slot and `slot_time` exactly as before.

### `supplement_course_slots`

| Column | Type | Rules |
|---|---|---|
| id/user_id | bigint/FK | private detail owner |
| supplement_course_id | FK courses | cascade with course |
| recurring_rule_slot_id | unique FK rule slots | cascade with slot |
| intake_context | string(24) | `unspecified|with_food|empty_stomach` |
| timestamps | timestamps | |

Unique `(supplement_course_id,recurring_rule_slot_id)` plus owner/course lookup. The service verifies
that the rule slot belongs to the course's own rule.

### `supplement_intakes`

| Column | Type | Rules |
|---|---|---|
| id/user_id | bigint/FK | private fact owner |
| supplement_course_id | FK courses | restrict deletion |
| supplement_id | FK supplements | restrict deletion; snapshot relationship |
| planned_on | date | original occurrence date |
| effective_on | date | original or rescheduled Profile-local date |
| slot | string(32) | original occurrence slot |
| outcome | string(16) | `taken|skipped` |
| dose_quantity | decimal(14,6) | accepted canonical course/action snapshot |
| dose_display_unit | string(8) | accepted compatible display unit |
| supplement_name | string(160) | label snapshot |
| taken_at | nullable UTC timestamp | required taken; null skipped |
| note | nullable text | max 5,000 at API |
| timestamps | timestamps | correction audit timestamps |

Unique `(supplement_course_id,planned_on,slot)` (`supp_intake_course_day_slot_unique`) and indexes
`(user_id,effective_on,id)` and `(user_id,supplement_id,outcome)`.

### `planned_occurrences` extension

Add nullable unique FK `supplement_intake_id` to `supplement_intakes` with `nullOnDelete`. The model's
same-owner hook and mutually exclusive fact invariant include it; `hasFact()` includes all five fact
columns. Deleting an intake unlinks at the database boundary while the service explicitly restores
`status=planned` in its transaction.

### `supplement_stock_movements`

| Column | Type | Rules |
|---|---|---|
| id/user_id | bigint/FK | immutable private fact |
| supplement_id | FK supplements | restrict deletion |
| kind | string(16) | `restock|correction` |
| quantity_delta | decimal(14,6) | restock >0; correction non-zero signed |
| effective_on | date | Profile-local, not future |
| reason | nullable string(500) | required for correction; absent for restock |
| note | nullable text | max 5,000 at API |
| timestamps | timestamps | |

Indexes `(user_id,supplement_id,effective_on,id)` and `(user_id,effective_on,id)`. There is no PATCH
or DELETE route; a compensating correction preserves history.

### `supplement_restock_proposals`

| Column | Type | Rules |
|---|---|---|
| id/user_id | bigint/FK | private action owner |
| supplement_id | FK supplements | cascade with account/reference |
| active_supplement_id | nullable unique FK supplements | equals supplement while open; null terminal |
| shortage_fingerprint | string(64) | deterministic SHA-256 material shortage identity |
| forecast_runout_on | date | projected exhaustion date |
| needed_by | date | runout less lead, clamped to reconciliation date |
| suggested_quantity | nullable decimal(14,6) | package quantity snapshot |
| stock_unit | string(16) | reference unit snapshot |
| status | string(16) | `open|dismissed|resolved` |
| dismissed_at/resolved_at | nullable timestamps | lifecycle-derived |
| timestamps | timestamps | |

Unique `(supplement_id,shortage_fingerprint)` and unique nullable `active_supplement_id`. Index
`(user_id,status,needed_by)`. Same-owner hooks verify both supplement columns.

## Relationships and Owner Rules

- User has many Supplements, Courses, Intakes, StockMovements, and RestockProposals.
- Supplement has many Courses/Intakes/Movements/Proposals; no API hard-delete exists.
- Course belongs to Supplement/optional Goal, has one RecurringRule and has many detail slots/intakes.
- RecurringRule has many RecurringRuleSlots; a Supplement course detail points to one of its own slots.
- PlannedOccurrence belongs to at most one domain fact across routine/habit/sleep/workout/supplement.
- Every request starts from an owned route root. Nested IDs are fetched through that root or checked
  against authenticated ownership before any transaction commits. Foreign IDs return 404.

## State Machines

### Supplement

`active (is_archived=0)` ↔ `archived (1)`. Archive blocks new courses but preserves all reads/facts.

### Course

`active (is_active=1,is_archived=0)` ↔ `paused (0,0)` → `archived (*,1)` → active/paused restore.
Archive forces inactive. Active materializes; paused/archived removes untouched predictions only.

### Planned intake

- no SupplementIntake → occurrence `planned`
- linked `taken` → `done`
- linked `skipped` → `skipped`
- clear intake → link null and `planned`
- a fact-bearing occurrence cannot reschedule; edits use the same fact identity

### Stock movement

Created once and immutable. An error is offset by a later `correction`; no hidden state transition.

### Restock proposal

`open` → `dismissed|resolved`. Reconciliation may resolve a stale open proposal and create a new open
proposal only for a different actionable fingerprint. Terminal rows never reopen.

## Deterministic Derived Values

### Unit conversion

- `mg → gram`: decimal divide by 1,000
- `g → gram`, `ml → millilitre`, `piece → piece`: identity
- pieces reject fractional canonical quantity
- response quantities remain fixed-scale normalized decimal strings

### Remaining stock

`sum(all quantity_delta) - sum(dose_quantity where intake outcome=taken)` for one owned Supplement.
Zero and negative remain exact; no clamp or stored counter.

### Forecast

1. Start at current exact remainder.
2. Select all active nonarchived bounded courses for the Supplement.
3. Build planned future dose events from durable effective occurrences and pure expansion up to 730
   inclusive local dates; durable original date/slot keys suppress generated duplicates.
4. Exclude linked done/skipped occurrences; taken facts already reduced current stock.
5. Sort by effective date, time, course ID, slot order, and occurrence identity.
6. With no inventory and no taken-intake facts, return `no_stock`; skipped facts do not establish stock.
7. If factual remainder is already `<= 0`, return `already_depleted` with `runout_on=as_of`.
8. Otherwise subtract each course dose. First event yielding remainder `<= 0` is `ready.runout_on`;
   absent one, return the explicit no-course/no-consumption/end/horizon state.

### Proposal fingerprint

SHA-256 of stable canonical JSON containing supplement ID, `forecast_runout_on`, `needed_by`,
`suggested_quantity`, and `stock_unit`. It deliberately omits timestamps and remaining-stock noise.

### Adherence

For the inclusive range and current Profile-local instant:

- done: linked taken
- skipped: linked skipped
- overdue: planned and effective date/time elapsed
- pending: planned and not elapsed
- eligible = done + skipped + overdue
- adherence percentage = `done / eligible × 100`, rounded once to two decimals; null if eligible zero

## Query Plan

- Workspace catalogue: one Supplement set plus grouped movement/intake sums, active courses/rules/slots,
  and proposal set. No per-reference query.
- Course index/day: courses/rules/weekdays/slots/details/supplements plus occurrences/intakes in fixed
  eager/set queries.
- Stock: grouped aggregate queries by requested supplement IDs; decimal arithmetic in PHP strings.
- Forecast: bounded course/rule/slot and occurrence/intake queries, then in-memory pure expansion;
  query count is independent of 730 days and row count.
- Adherence: one bounded occurrence query with rules/courses/supplements/intakes and set grouping;
  query count is independent of 366 dates.
- Today/Review reuse one Supplement day summary DTO; Planner and Notifications query occurrences/
  courses/supplements in sets.

## Rollback Order

1. Close/drop notification rows only through existing account/source lifecycle; schema needs no change.
2. Drop `planned_occurrences.supplement_intake_id` unique FK/column.
3. Drop proposals, stock movements, intakes, course-slot details, recurring rule slots, courses, and
   supplements in dependency order.
4. Drop `recurring_rules.cycle_off_days`, `cycle_on_days`, and `interval_count`.
5. Never alter/drop legacy rule/occurrence/fact rows, users, goals, settings, reviews, or prior tables.
