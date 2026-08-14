# SelfHandler — Data Conventions (schema-wide rules)

> "How to model" decisions that apply to ALL tables. Adopt these before the first migration — otherwise you'll be retrofitting changes across every module after the fact. Not a subsystem, but a set of rules.

>
> Related: [Finance ER](finance-er.md) · [Modules Spec](modules.md) · decisions: [Decisions Log](decisions.md)

---

## 1. Money — `Money` (DECIMAL + value object)

- **Storage type:** `DECIMAL(19,4)` for all monetary amounts. **Never float** (it loses cents in balance/cash flow aggregates).
- **Value Object `Money`** = `amount` (DECIMAL) + `currency` (code). An Eloquent attribute cast through the VO — the model returns a `Money`, not a bare number.
- **Currency alongside the amount** everywhere an amount is multi-currency (account, transaction, debt, saving fund, price of an add-on/purchase). The reference table is `currencies`.
- **Currency conversion for summaries happens at read time**, using the chosen rate: the current rate for "how much do I have now", the historical rate (as of a date) for "how much it cost back then". Do NOT store the converted value (otherwise the past "drifts"). See [Finance ER](finance-er.md).
- Where this applies: all monetary fields in Finance (M10), price in Add-ons (M2a), the estimated price of a Purchase (M7).

## 2. Polymorphism "shared base + type" — hybrid

> This pattern shows up in Goals (M4), Workouts (M3), Storage/Item (M7), Debts (M10), and Saving Fund vs Emergency Fund (M10). **A single rule for choosing the strategy:**

### Selection rule
- **Class-table inheritance (base + a separate detail table per type)** — when the types have **MANY DIVERGENT fields**:
  - **Workout** (M3): base `workouts` (date/type/duration/note) + `strength_sets` / `cardio_logs` / `run_logs` (each with its own fields). Sets×weight×reps do NOT go in JSON — this is relational data, you need queries like "bench press history", PRs, and aggregates.
- **Single-table + type + nullable/JSON** — when the types are **similar** (many shared fields, little specifics):
  - **Goal** (M4): a single `goals` table + `type` + the specifics (target weight / working weight / amount) in nullable columns or a `payload` JSON.
  - **Storage Item** (M7): `items` + `type` (task/idea/purchase/note) + shared fields; rare specifics go in nullable/JSON.
  - **Debt** (M10): `debts` + direction/mode — similar types, single-table.
  - **Saving Fund/Emergency Fund** (M10): `saving_funds` + `is_emergency`/`is_perpetual` flags.

### No STI magic
- **Plain Eloquent models**, without `tightenco/parental` or any other STI magic (Laravel doesn't support STI natively — the packages hide the queries).
- For single-table: one model + a `type` column + **query scopes by type** (`Goal::ofType('body')`), or separate models with a global scope on the same table — but done explicitly.
- For class-table: a base model + `morphTo`/`hasOne` to the detail table (`$workout->details` → strength/cardio/run).
- Principle: **every query is visible**, no hidden magic (the learning goal is to understand Eloquent, not a wrapper over it).

### JSON — deliberately
- A JSON column ONLY for rare/optional fields that you do NOT need to query, index, or validate at the database level.
- If a field needs to be filtered/aggregated/validated, it's a column or a detail table, not JSON.

## 3. Owner and multi-user — `user_id` from day one

- **`user_id` on EVERY domain table** from the very start, even while single-user for now.
- A global scope by the current user (Laravel global scope) — so you don't later have to add 30 migrations and rewrite queries for multi-user.
- Relations and unique keys account for `user_id` (e.g. category uniqueness is scoped to the user).
- This is cheap insurance: laying it down now ≈ 0 effort, adding it later ≈ rewriting everything.

## 4. Deletion: soft delete ≠ archiving

> Two DIFFERENT things, don't conflate them (review finding):
- **`SoftDeletes` (`deleted_at`)** — technical hiding/trash/restore. The record is "deleted", not visible anywhere by default.
- **Domain flag `is_archived` / status** — an account is closed, a category is no longer used, but it's **still visible in history and analytics** (its transactions live on).
- **What analytics shows:** archived — YES (history matters), deleted — NO.
- For domain records (transaction/intake/workout/task) — the deletion policy is set per entity (soft delete vs. forbidding back-dated edits for finance).

## 5. The money of time — dates, timezones

- **Storage in the DB is UTC** everywhere.
- **The user's timezone lives in the profile** ([Modules Spec](modules.md)), with conversion at the boundary of display / schedule expansion.
- TZ-sensitive: bedtime/wake-up time, intake time, habit time, schedules with a time-of-day (see [Recurrence Engine](recurrence-engine.md)).
- `created_at`/`updated_at` (Laravel timestamps) — on all tables by default.

## 6. Units of measurement

- Store values in the **canonical base unit**: weight in grams, volume in ml, distance in meters, time in seconds. Display them in the user's preferred unit (kg/lbs, km/miles).
- A measurement metric (an extensible list) carries its own unit.
- Don't multiply `weight_in_kg` + `weight_in_g` — one column in the base unit + conversion at display time.
- Units/locale live in the profile/settings.

Feature 015 resolves the Workout-specific representation: strength loads use `DECIMAL(8,3)` kilograms
because plates, prescriptions, records, and progression all compare fractional kg directly; endurance
distance uses integer metres and duration uses integer seconds. Display conversion is never persisted.
Workout polymorphism is an explicit `workout_sessions` root plus one strength/endurance/timed detail,
with relational exercises and sets rather than JSON.

Feature 016 stores food nutrients and consumed quantities as fixed-scale decimals in their canonical
gram or millilitre basis. Calculations use shared decimal-string arithmetic with one half-up rounding
at the persistence/response boundary. A meal entry is an immutable accepted snapshot; Nutrition day
and bounded range totals are set-query projections over those snapshots, not mutable rollup columns.
Daily targets are separate immutable user/date estimate snapshots, so changing a Profile, goal, or
Workout plan never rewrites the reference used to assess an existing day.

Feature 017 stores supplement quantities as `DECIMAL(14,6)` decimal strings in canonical grams,
millilitres, or whole pieces. The accepted display units are `mg`, `g`, `ml`, and `piece`; conversion
is exact and incompatible dimensions or fractional pieces reject atomically. Courses snapshot a
canonical dose, and taken facts snapshot it again so later reference edits cannot rewrite history.
Remaining stock and adherence are bounded set-query projections over immutable facts, never mutable
counters or stored rollups.

Feature 018 resolves the Money and ledger choices. Public money inputs and outputs are canonical
decimal strings at four places, backed by `DECIMAL(19,4)`; exchange rates use twelve places. One
idempotent immutable transaction group owns signed ledger entries, so opening balances,
reconciliation, actuals, transfers, and reversals all share one append-only truth. Account and bounded
cash-flow totals are exact grouped queries, not mutable balance or rollup columns. Historical FX is
looked up at or before the Profile-local date, directly or inversely; missing conversion is an explicit
incomplete result.

Feature 019 keeps budget and plan aggregates derived. Budget actuals group immutable ledger entries
by their historical dates and category scope; recurring-plan projections use immutable occurrence
snapshots so later account/category/rule edits cannot rewrite accepted history. A Finance occurrence
actualizes through the existing ledger idempotency boundary, while its separate fact mirror can be
rebuilt from the module fact. Missing FX nulls the complete budget or cash-flow result.

Feature 020 keeps commitment truth relational and derived. Debt remaining is original principal minus
active immutable payment groups; fund saved/reserved/available values fold immutable movements or the
dedicated linked-account ledger; Finance Goal progress reads only those projections. Occurrence detail
rows are immutable historical snapshots, while rebuildable links on `planned_occurrences` mirror the
latest active fact. Purchase/restock source pairs live on transaction groups and are immutable together;
they never create a second money or stock ledger. Emergency expense-month targets require three
complete prior local months and return an explicit unavailable state when history or FX is incomplete.

## 7. Aggregates — "the module computes the totals" — strategy (important for performance)

> Balances/remaining amounts/streaks/actual budget figures are derived. So that the "Today" dashboard and Analytics don't grind to a halt:
- **Grouped source-of-truth queries first.** Feature 018 account balances use indexed grouped ledger
  sums without a mutable cache. A later feature may add a rebuildable cache only after measured need;
  the append-only ledger remains authoritative.
- **Bounded module-owned daily primitives first.** Feature 023 keeps each formula beside its source
  module, uses grouped indexed queries over strict maximum ranges, and lets Analytics compose buckets
  without importing raw models. No `daily_metrics` table is justified by current measured demand.
- A future rebuildable daily cache may preserve the same primitive contract if production evidence
  shows it is needed; it must not become a second source of truth.
- ⚠️ This is the physical implementation of the principle in [Modules Spec](modules.md).

---

## Checklist "before the first migration"

- [x] Money VO + DECIMAL(19,4) storage ready (feature 018)
- [ ] Decided for each polymorphic entity: class-table or single-table (see §2)
- [ ] `user_id` + global scope — in the migration/model template
- [ ] SoftDeletes vs is_archived — defined per entity
- [ ] UTC + timezone from the profile — date policy
- [ ] Base units of measurement fixed
- [x] Aggregate strategy — bounded grouped module primitives first; rebuildable cache only after measured need (feature 023)

## Open questions

1. ✅ Money uses `DECIMAL(19,4)` plus the exact `Money` value object (feature 018).
2. If measured demand requires a daily cache, define its rebuild/invalidation evidence and retain the
   feature 023 source-contract semantics.
3. Audit trail for changes to financial records (who edited a transaction and when) — do we need `laravel-auditable` selectively, or are timestamps enough?
4. JSON vs. nullable columns for the specifics of similar types — finalize per entity at migration time.
