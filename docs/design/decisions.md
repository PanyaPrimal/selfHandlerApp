# SelfHandler — Decisions Log

> Product and architectural decisions recorded over the course of design. Complements the [Modules Spec](modules.md) (which holds the per-module detail) — this is the "why" and the cross-cutting agreements.

---

## Cross-cutting architectural principles

- **AI is an optional layer, not a foundation.** The core is implemented programmatically (deterministically). The app works fully without an LLM. AI augments on top. Every smart feature has a baseline programmatic layer underneath. ✅ The per-module Level-2 scenarios are designed in [LLM Layer](llm-layer.md) (2026-06-13).
- **Each module computes its own aggregates** (aggregation lives next to the data). Analytics is a presentation surface that reads ready-made results.
- **The pattern of almost every smart feature:** manual baseline → ready-made templates/integrations → LLM on top.
- **Candidates for shared mechanisms** (plan for them, don't duplicate): the recurrence engine for recurring rules (schedules/courses/events); supply forecasting and restocking (consumable resources); milestones ↔ stepped limit for anti-habits. We are NOT over-engineering right now — focus is on the current modules.

## AI assistant (Module 11)

- A cross-cutting layer with visibility into the context of every module. Use cases: recommendations, workout analysis, analytics insights, Q&A.
- **BYOK (Bring Your Own Key):** the provider is configured BY THE USER through a form (their own paid API: Claude/OpenAI/custom). Not in the app config. The user pays for tokens.
- LLM credentials = user data (provider/key/model; several, with an active one selected). A `LlmProvider` contract plus adapters, resolved at runtime (Laravel Service Container).
- **Key security:** encrypt in the database, never expose to the frontend in cleartext.
- The reference provider is the Claude API; verify models/prices, don't rely on memory.

## Per-module decisions

### Module 0 — Profile
- Anthropometry (weight/height/age/sex/body-fat %/activity) lives in the profile — both nutrition and training rely on it.
- **Body measurements log** = a periodic snapshot (weight, arms, legs, waist, chest, glutes/hips; extensible), recorded with a date. Monthly reminder.
- **Outcome marker:** closes the loop goal → nutrition + workouts → body changes → conclusions → adjustment.

### Module 2 — Nutrition
- Built on a friend's app, [calorie-tracker](https://github.com/Podvodila/calorie-tracker) (Vue/TS/Dexie, no backend). We reuse the frontend and the product model, and port the storage to Laravel + MySQL. We adapt it ourselves.
- Meals are **dynamic** (categories, or free-form by time of day).
- An entry is a whole dish + weight OR by components + weight (atomic/composite = a recipe).
- Drinks: water + tea/coffee/energy drinks/cola (with calories and hydration). Water target comes from the profile.
- Macro/calorie targets: Mifflin (default) plus Katch-McArdle as an option. The goal acts as a modifier (surplus/deficit, within a range). **Dynamic TDEE** — accounts for energy burned in workouts.
- Diet scoring = calories + macros + food "quality" (a product attribute).
- Photo recognition (future): components → macros → calories + healthiness.

### Module 2a — Supplements and vitamins
- Reference catalog: name, category (vitamin/sports nutrition/nootropic/**anabolic**/other), form (incl. injection), dose, volume + price.
- **Framing for anabolics/cycled substances:** a neutral monitoring tracker (what/when/how much/actual intake/remaining/reminder). Do NOT provide protocols/regimens/dosages/usage advice. AI only monitors as well.
- A cycle plus a **flexible schedule engine** (N/day, N/week on specific days, complex cycles, every other day, on-week/off-week).
- Reminder + a repeat reminder if missed + recording actual intake.
- **Supply → forecast → finance link:** remaining quantity, a "how long it will last" forecast, restock reminder, cost per dose, a hint to save by buying a larger pack.

### Module 3 — Workouts
- Types (polymorphism): strength, cardio (running/cycling/walking/swimming), flexibility/yoga, sports.
- Strength training has **2 modes**: simple (just logging it happened) / detailed (sets × weight × reps + rest).
- Exercise catalog + ready-made programs + custom splits.
- Progression: history + PRs + suggestions. Tier 1 rules (+2.5 kg, etc.) plus Tier 2 LLM program builder. The next session date flows into the Planner.
- **Running** is a specialized subtype: level (self-assessment + adjustment), a target event (a race = date + distance), a training plan at 3 tiers (manual/templates/LLM), metrics (pace/heart rate/type/geo), data sources (manual + Strava/Garmin/Apple Health integrations + LLM).

### Module 4 — Goals
- **A shared mechanism with types** (body/training/finance/...). Common fields plus type-specific detail. Several can be active at once. We come back to this module during Workouts/Finance.
- **Milestones** — stages of a goal.
- "Body" type: enter a target weight + deadline ↔ pace/week, with a warning for an unhealthy pace.
- "Training" type: working weight / cardio result / consistency; progress derived from history.
- Progress for different types comes from different sources (body measurements/weights/balance).

### Module 5 — Planner
- **A hub for events from every module** plus its own tasks plus day planning (time blocks). It does not produce data; it plans/displays/reminds.
- Reuses the **schedule engine** from Supplements (a candidate for a shared mechanism).
- Notifications: push + in-app + email/Telegram.
- External calendar optional (app only / + Google/Apple).
- Misses: log the miss OR reschedule — the user decides.

### Module 6 — Daily Review
- **An evening ritual:** the day's summary + planning tomorrow (via the Planner). Plus a live recap of the day.
- A summary drawn from every module. Manual input: self-assessment + wellbeing (energy/stress/mood) + a journal entry.
- **A day score** derived from completion. Delivery: a reminder + an optional LLM summary.
- Boundary with Analytics: the review is a single-day slice; analytics is trends over time.

### Module 7 — Storage
- **Hybrid architecture:** a shared "Item" base (capture/title/status/inbox) plus polymorphic, type-specific detail (task/idea/purchase/list item). Same pattern as Goals/Debts. A single fast capture plus idea → task conversion.
- **Tasks:** projects (the primary grouping) + tags (cross-cutting contexts) + priority + status.
- **Dependencies:** a parent → children hierarchy (self-referencing parent_id, like two-level categories) plus a "blocker" flag on a child. An open blocker prevents closing the parent.
- **Idea:** quick entry → inbox → triage → promotion into a project/goal. Dependent purchases/tasks live as children (they live alongside the idea).
- **Purchase (wishlist):** a child of an idea OR standalone; on purchase → an expense transaction/installment plan (Finance), and a "bought" status unblocks the idea.
- **Lists:** simple items + status/rating; custom fields per type (book/movie) — later.
- **Containers:** Project (groups tasks/ideas, optional link to a goal) + List (a collection of items). ❓ one shared "container with a type" vs. two separate entities.
- **Tags** — cross-cutting classification (contexts @home/@work); a candidate for an app-wide shared mechanism.
- Links: Finance (purchase → expense), Planner (a dated task → an event), Goals (idea → project/goal), Daily Review (done today → day score).

### Module 8 — Habits and anti-habits
- Habits: completion fact + numeric metrics + time. **A builder based on "Atomic Habits"** (habit stacking → routines; implementation intention → Planner; the two-minute rule; don't break the chain).
- Anti-habits, 2 modes: full abstinence (a streak) OR a **stepped limit** (energy drinks 1/day → 5/week → 3/week, tied to milestones).
- Gamification: streaks + statistics.

### Module 9 — Analytics
- Core: **trends + correlations** (the headline feature — conclusions about what works: sleep → energy, nutrition → weight, etc.).
- Periods: day/week/month + custom + period-over-period comparison.
- Conclusions: rules (always) + LLM (optional).
- **Export** (important): CSV/PDF (for a doctor/coach, and backup).

### Module 10 — Finance
- **Multiple accounts + transfers** (cash/cards/savings/foreign-currency, each with its own balance; a transfer is a paired transaction, not income/expense).
- **Multi-currency from day one** — accounts in different currencies, exchange rates, conversion to a base currency for consolidated analytics. Store the historical rate as of the transfer date.
- **Investments deferred** — an asset portfolio with quotes comes later; savings = a saving fund account.
- **Two-level categories** (group → subcategory), income and expense directions are kept separate, archiving instead of deletion.
- **Budget = per-category monthly limits** (plan/actual, a warning on approaching/exceeding → routed through the Planner's channel).
- **Financial goals** (the implementation of the "Finance" type from Module 4): save N / pay off debt, with progress from the saving-fund account balance (save) or the linked debt's remaining balance (pay off debt), milestones, a target amount + deadline ↔ pace/month.
- **Debts and obligations** — a shared debt mechanism with types (same pattern as Goals). Cases: an installment plan (N payments of X/month), money owed to a person, paying off a credit card.
  - **Both directions:** "I owe" / "owed to me" (a direction flag).
  - **Optional interest:** rate/overpayment if desired (a 0% installment plan and money owed to a person work without it); full amortization with accruing interest — later.
  - **Two schedule modes (a flag):** fixed (an installment plan → a payment schedule is rolled out) / free repayment (credit card/person → only the remaining balance, payments whenever).
  - A payment = a transaction referencing the debt, reducing the remaining balance. A "pay off debt" goal is linked to the debt (progress = remaining balance to zero). Reminders/recurring payments go through the shared schedule engine + Planner.
- **Saving funds / goal savings** (sinking funds, added 2026-06-13) — "save X for a renovation/trip". A target amount + amount saved, with an optional link to a category and a deadline.
  - **Where the money lives — two modes (a choice):** a virtual saving fund (an envelope on top of a balance, so we don't proliferate accounts) OR a link to a real savings account.
  - Funding is one-off or recurring (via the schedule engine). The "save N" financial goal is a wrapper with a deadline/milestones on top of a saving fund.
- **Emergency fund** (added 2026-06-13) — broken out separately: **mandatory** monthly contributions, **open-ended** (not a one-off goal). Counts as a mandatory monthly expense.
  - **Contribution rule — three modes (a choice):** a fixed amount/month / a % of monthly income / a target size = N months of expenses (the system computes it from average spending).
  - A drawdown → it becomes "underfunded" again → contributions resume. Conceptually a special case of a saving fund with "mandatory + open-ended" flags.
- **Recurring operations = income AND expenses** (symmetric, added 2026-06-13). The recurrence engine runs in both directions.
  - **Expenses:** subscriptions/utilities/loan/rent.
  - **Income:** salary (case: 3 times/month in fixed amounts, UAH for now) → an **income calendar** (when and how much will arrive). Planned monthly income is the basis for the budget, saving funds, and the emergency fund's "% of income".
  - **Cash flow:** planned income − mandatory expenses (recurring + debt payments + the mandatory emergency-fund contribution) = the month's free money.
  - These roll out into planned transactions, with actuals of received/paid/missed.
- **Categories — example "Healthcare → Dentistry"**: routine prevention = a planned expense under the subcategory + a visit reminder (Planner). The pattern "cheap prevention now vs. an expensive emergency later" (applies to car maintenance, etc., too).
- **Each module computes its own aggregates** (balances, the consolidated total in the base currency, budget actuals, income/expense/net); Analytics is a presentation surface.
- The AI layer is optional: rules (spending analysis, forecasting mandatory payments) are mandatory; LLM insights/saving advice are optional.

---

## Review pass and contradiction fix (2026-06-13)

> A cross-cutting review of the concept (4 lenses: gaps / consistency / product-UX / architecture). Resolved contradictions (do NOT reopen):

- **Progress for the "save N" financial goal** = from the linked saving fund (not from the "account balance" — a saving fund can be virtual). Fixed in Modules 4 and 10.
- **The purchase ↔ transaction link** is defined: a polymorphic `TRANSACTION.source` (the FK on the money side) plus the invariant "bought ⟺ a transaction/installment plan exists". PURCHASE was added to the ER diagram.
- **Dynamic TDEE:** the target doesn't "drift" retroactively — it's computed from the day's **planned** activity; actuals refine it at end of day. The profile's activity level is the **baseline/non-exercise** level (to avoid double-counting workouts).
- **An anti-habit's stepped limit ≠ a milestone** (an achievement vs. a constraint with a changing unit). Don't blindly model them as one entity.
- **A single recurrence engine** is canonized: `RecurringRule` + `PlannedOccurrence`. It used to be named differently in 2a/5/8/10 — it is ONE AND THE SAME. The canonical definition is in the Modules Spec, the Module 5 section. **Full spec (2026-06-13): [Recurrence Engine](recurrence-engine.md).**
  - Rule format: **a custom field set** (freq/interval/by_weekday/by_monthday/times_per_day + `cycle_on`/`cycle_off` for "on-week/off-week") plus an optional **`rrule` string as a fallback**.
  - Expansion: **materialization with a forward window** (+90d) + a unique `(rule_id, date, slot)` (idempotency).
  - The engine stores the instance status (planned/done/missed/rescheduled); **escalation and reminder delivery are NOT here, but in the Notifications subsystem**.
- **The Notifications subsystem** is designed (2026-06-13): **[Notifications](notifications.md)**. Separated from the Planner (what vs. how) and from the engine (status vs. delivery).
  - Channels: a single contract (Strategy/Adapter over Laravel Notifications), **in-app first**, with push/email/Telegram as adapters added without rework (the same pattern as BYOK in M11). Closes the Vision question "push/telegram/email?".
  - Escalation: repeat at an interval until acknowledged (max K times), configurable per type. Reads status from the engine, lives in Notifications.
  - Anti-spam: quiet hours (overnight) + a daily digest of non-urgent items. Settings per category/channel.
  - Delivery: Laravel Scheduler + queue, idempotency by `(source, escalation_count)`. A notification doesn't duplicate the domain status — when the item is marked done, it auto-closes.
- **Cross-cutting schema rules** are consolidated (2026-06-13): **[Data Conventions](data-conventions.md)**.
  - **Money:** DECIMAL(19,4) + a `Money` value object (amount + currency), not float. Currency conversion at read time (current/historical rate).
  - **Polymorphism — a hybrid:** class-table (a base + detail tables) for types with divergent fields (Workouts: strength/cardio/running); single-table + nullable/JSON for similar ones (Goals/Storage/Debts/Saving fund — Emergency fund). **No STI magic** (`parental`, etc.) — plain Eloquent models + explicit per-type scopes.
  - **`user_id` on every table from day one** + a global scope (multi-user on the cheap).
  - **Deletion ≠ archival:** SoftDeletes (a technical trash bin) ≠ `is_archived` (a domain flag, still visible in history/analytics).
  - **Dates in UTC**, the timezone from the profile. **Units in base units** (grams/ml/meters/seconds), converted on display.
  - **Aggregates:** a cached value + event-driven recompute (an Observer) for hot ones (balance/remaining/saved) + a daily rollup for analytics over long periods.
- **Attachments** are designed (2026-06-13): **[Attachments](attachments.md)**. A polymorphic `Attachment` + a single `FileStorage` service. Storage is a **local disk + a disk abstraction** (Laravel Filesystem, switchable to S3/MinIO by changing the driver; NOT a BLOB in the database). Files are private (signed URLs). Consumers: food/body photos, receipts, GPX.
- **External integrations** are designed (2026-06-13): **[Integrations](integrations.md)**. A **shared layer** (a contract + adapters, like BYOK-LLM/channels), with calendars as the first member; later Strava/Garmin (fitness), bank statements. An `Integration` (encrypted OAuth tokens) + a `SyncedItem` (a local ↔ external mapping for dedup/conflicts). Calendars use **two-way** sync (exporting occurrences/events + importing external ones as "busy" time). Conflict handling at the start — last-write-wins.
- **Profile is the single source of user input** (anthropometry, **base currency**, timezone, units, tone). The modules do the computing. This closes the open question "where the base currency lives" → the profile.
- **Supply forecasting (2a) ≠ recurring (5/10)** — different mechanisms; restocking a supplement is a one-off planned expense.
- **Composite metrics (the day score, cash flow)** — a deliberate exception to "each module computes its own aggregates": an aggregator (Daily Review/Analytics) computes them on top of ready-made numbers.
- ER open questions are partially closed with recommendations: a transfer = two linked records; a virtual envelope = "free balance"; money = DECIMAL(19,4) + a Money VO; currency conversion at read time.

### Identified gaps (NOT yet in the spec — next-phase backlog)
- A weekly/monthly **review** as an entity (present in the Vision, but the spec has only the daily review).
- **Attachments/photos** (a cross-cutting Attachment): food/body-progress photos, receipts.
- **Notifications** as a separate subsystem (not part of the Planner): channels + escalation + snooze.
- **Import/export/backup** of everything (the Vision requires being able to "pull everything out of a dozen places").
- A **"Today"** screen as the root + a single app-wide **quick capture** into one inbox (not buried inside M7).
- **Settings** as a single home; units/timezone/locale.
- Module 1 (Routine/sleep) — the thinnest: "day templates" and schedule planning were lost.
- A shared mechanism for **tags** and **templates** (day/workout/diet) — to be factored out.
- The MVP slice is out of sync with the concept (see docs/MVP.md) — a product risk.

## Design status

- ✅ Designed: 0, 1, 2, 2a, 3, 4, 5, 6, 7, 8, 9, 10, 11 — **all modules designed; contradictions cleaned up (2026-06-13)**
- ⬜ Remaining per module: —
- 📌 Deferred (cross-cutting / next phase): the gap backlog above (the review entity, "Today", quick capture, settings, import/export); the shared tags/templates/supply mechanism; investments/portfolio (M10); auth single → multi-user
- ✅ Designed (separate docs): recurrence engine (RRULE), notifications, attachments, integrations, data conventions (Money/DECIMAL, polymorphism, user_id, aggregates/rollup), Finance ER, **LLM layer** (per-module AI scenarios)
