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

## 7. Aggregates — "the module computes the totals" — strategy (important for performance)

> Balances/remaining amounts/streaks/actual budget figures are derived. So that the "Today" dashboard and Analytics don't grind to a halt:
- **Cached value + event-driven recomputation** for hot derived values (account balance, remaining debt, amount saved in a fund): an Observer on the source record (Transaction, etc.) updates the cache within the same DB transaction. The "on the fly" computation is the source of truth for reconciliation/recomputation.
- **A daily-rollup layer** for analytics over long periods: a `daily_metrics` table (date, metric, value), populated on write or by a nightly job. Analytics reads the rollup, not years of raw logs.
- Decide this before the "Today" dashboard and Module 9 (otherwise you'll be rewriting queries).
- ⚠️ This is the physical implementation of the principle in [Modules Spec](modules.md).

---

## Checklist "before the first migration"

- [ ] Money VO + DECIMAL(19,4) cast ready
- [ ] Decided for each polymorphic entity: class-table or single-table (see §2)
- [ ] `user_id` + global scope — in the migration/model template
- [ ] SoftDeletes vs is_archived — defined per entity
- [ ] UTC + timezone from the profile — date policy
- [ ] Base units of measurement fixed
- [ ] Aggregate strategy (cache + rollup) — before analytics/dashboard

## Open questions

1. Money: store DECIMAL or minor units as BIGINT (cents) — both are valid, pick one.
2. Daily-rollup: which metrics go in the rollup, and the recomputation frequency (nightly vs. on write).
3. Audit trail for changes to financial records (who edited a transaction and when) — do we need `laravel-auditable` selectively, or are timestamps enough?
4. JSON vs. nullable columns for the specifics of similar types — finalize per entity at migration time.
