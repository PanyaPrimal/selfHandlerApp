# SelfHandler — Module Specification

> Detailed description of each module: what we track, which fields, behavior. The basis for the database schema.

Back to the plan: [Vision & Plan](vision.md)

---

## Architectural principles

> Cross-cutting rules that apply to all modules.
> 📐 **Cross-cutting mechanisms and schema rules (separate docs):** [Recurrence Engine](recurrence-engine.md) (recurrence engine) · [Notifications](notifications.md) (notifications) · [Attachments](attachments.md) (attachments/files) · [Integrations](integrations.md) (external integrations: calendars, fitness, banks) · [LLM Layer](llm-layer.md) (optional AI scenarios per module) · [Data Conventions](data-conventions.md) (Money, polymorphism, user_id, deletion/archiving, time zones, aggregates) · [Finance ER](finance-er.md) (Finance schema).

### AI is an optional layer, not a foundation
- **The core of the system is implemented programmatically** (deterministic logic, rules, calculations on the backend)
- The application must be **fully functional without an LLM**: target calculations, the measurement log, rule-based recommendations, analytics — all work without AI
- "Module 11 — AI Assistant (cross-cutting layer)" is a "smarter/livelier" amplifier plugged in on top. If the provider is unavailable / there is no key / the user declines to send data → the system keeps working
- Practical implication: every "smart" feature has a programmatic baseline (cf. recommendations: Level 1 rules = mandatory, Level 2 LLM = optional)

> ✅ **Systematic LLM pass — done (2026-06-13):** the per-module AI (Level-2) scenarios are designed in [LLM Layer](llm-layer.md) — what AI adds on top of each module's deterministic baseline, the context the agent sees, interaction type, backend tool-calls, and privacy/safety boundaries.

### Each module computes its own aggregates
- Each module computes its own totals/aggregates per day/week/month (logic lives next to the data)
- "Module 9 — Analytics" is a display layer: it gathers ready-made figures from modules, it does not duplicate the aggregation logic
- **Exception — composite cross-module metrics** (clarified 2026-06-13): the "day score" ("Module 6 — Daily Review") and cash flow are assembled FROM ready-made totals of several modules. They have no "data-owner module" — they are computed by an aggregator (Review/Analytics) on top of other modules' ready-made figures. This is a deliberate exception to "logic lives next to the data"

### Profile is the single source of user input (added 2026-06-13)
- **The Profile ("Module 0 — User Profile") stores the INPUTS** that modules rely on: anthropometrics (weight/height/age/sex/body fat %), **baseline non-sport activity**, **base currency**, time zone, language/units, recommendation tone, choice of BMR formula
- **Calculations based on these inputs are done by the modules themselves** (macro/water targets — Nutrition; conversion to base currency — Finance). The Profile does not compute on their behalf
- ✅ Closes open questions: "where does the base currency live" → **in the user's profile/settings**, not in Finance settings. "Source of anthropometrics" → the Profile

### Stock forecast and restocking (a general pattern for consumable resources)
- The pattern "stock → consumption → run-out forecast → restock reminder → budget" is useful beyond nutrition/supplements (household: laundry detergent and the like also run out)
- 📌 Design it as a **general mechanism** for consumable resources (rather than duplicating it in every module)
- ⚠️ Not implementing it as universal right now — focus on the current modules (nutrition/supplements). Extracting it into a general mechanism comes later, to avoid over-engineering
- ⚠️ **"Stock forecast" ≠ "recurring rule"** (clarified 2026-06-13). These are two DIFFERENT mechanisms: the forecast based on remaining stock + dose (Module 2a) spawns a **one-off** planned expense when a supplement runs out; recurring ("Scheduling engine (reusable) — CANONICAL definition") covers fixed repeating operations (subscription/salary). Restocking a supplement uses the FIRST one, not recurring

---

## Module 0 — User Profile

> Added 2026-06-07. Common user data that other modules rely on.

### Anthropometrics (baseline)
- Weight, height, age, sex
- (body fat %) — optional
- Activity level
- → the basis for computing BMR/TDEE and the macro/calorie targets in "Module 2 — Nutrition" and for "Module 3 — Workouts"

### Measurement log (a profile snapshot with history)
- The profile is **revisited** periodically (for example, once a month)
- **Reminder**: a notification "time to weigh in and take measurements" arrives → intersects with "Module 5 — Planner" (notifications / a recurring event)
- The user takes the measurements and enters a set of metrics into a form: weight, **arms, legs, waist, chest, glutes/hips**, etc. (an extensible list)
- Each measurement is saved as a record with a **date** → the measurement log is tied to the profile
- This is a **log with history** (a snapshot over time), not a single value

### Role in the system — a result marker
- Body measurements are the **feedback / result marker** of the whole cycle: nutrition + workouts → body changes
- The data, with its trends, flows into "Module 9 — Analytics"
- On that basis, conclusions are drawn about the **effectiveness of the workouts and/or the nutrition system**
- A component of the process that closes the loop (goal → actions → result → adjustment)

### Recommendation mechanism (adjustment)
> The system not only shows figures but also gives **adjustment recommendations** based on the measurement trend + the goal + actual nutrition.

- Example messages: "weight is rising faster than the target — cut the surplus" / "you're undereating; with a 'mass' goal your weight is stalling — add calories"
- **Level 1 — rules (deterministic, on the backend):** matching the trend (measurement log) + goal type ("Module 4 — Goals") + actual nutrition → ready-made advice via `if`-rules. Predictable, with no external dependencies.
- **Level 2 — LLM (optional, later):** generating recommendations as living text with personality via the cross-cutting "Module 11 — AI Assistant (cross-cutting layer)". A dependency on an external service plus tokens.
- **Recommendation tone — a user setting:** harsh/snarky ("want to stay a scrawny weakling?") ↔ friendly ↔ neutral. Snark does not motivate everyone — give the choice.
- Intersections: "Module 9 — Analytics" (source of trends), "Module 4 — Goals" (goal context), "Module 6 — Daily Review" (where to show the advice?)

### TODO
- The remaining profile fields (name, avatar, settings, etc.)
- Design: which measurement metrics are fixed and which the user adds themselves
- Review cadence — configurable? (once a month by default)

---

## Module 1 — Daily Routine & Sleep

### Sleep
- Planned bedtime and wake-up time
- Actual bedtime and wake-up time
- Sleep quality: 1–10

### Routines
- A routine template — a name (morning / evening / anything, the user defines it themselves)
- Activities within the template:
  - Name
  - Order (required)
  - Time (optional)
  - Numeric progress (optional) — for example "yoga, session no. ___" out of 48
- When planning the day, a morning template and an evening template are chosen (independently of each other)
- Each activity is marked separately: done / skipped
- The data is aggregated into analytics by day

---

## Module 2 — Nutrition

> Status: in design (2026-06-07)

### Decisions
- **Foundation:** we take the work from a friend's app — [calorie-tracker](https://github.com/Podvodila/calorie-tracker) (Vue 3 + TS + Vite + Tailwind + Dexie/IndexedDB). It has no backend/MySQL — we reuse the frontend and the product data model, porting storage to Laravel + MySQL. We will adapt it to our needs later.
- **Food selection:** from a list of food items (food item reference).

### Entities and behavior

#### Meal (a daily log) — dynamic
- The user decides the structure themselves: either categories (breakfast / lunch / dinner) or free-form meals with a time — **dynamically**
- A meal = a record with:
  - category (optional: breakfast/lunch/dinner/custom) OR just a time
  - meal time
  - a list of items (what was eaten)
- Categories are not rigidly fixed

#### Meal item — two levels of detail
- The user can enter a **whole dish + weight**, OR **break it down into components and weights** — whichever they like
- → A dish can be **atomic** (a single item + weight) OR **composite** (a list of food-item components with weights)
- A composite dish is essentially a recipe: food item + food item + … each with its own weight
- The macros/calories of a dish = the sum over its components (if broken down) or from the dish itself
- ↔ important for the photo feature: recognition breaks a dish down into components with weights

#### Food item (reference)
- Macros + calories are stored **per 100 g**; actual consumption is computed as a proportion of the entered weight
- Fields: name, protein/fat/carbs per 100 g, calories, quality/healthiness (see below)
- ❓ cross-check against the food item model from calorie-tracker

#### Beverages (extended water tracking)
- We track **plain water** + other beverages: teas of various kinds, coffees of various types, energy drinks, kombucha, cola, etc.
- Beverages have BOTH macros/calories (cola ≠ water) AND a hydration contribution
- → a beverage = a subtype of a nutrition item (a beverage food item in the reference) + volume (ml)
- **The daily plain-water target is computed from the user's profile** (weight/activity → daily water target); the system monitors compliance
- Daily water progress: consumed / target, just like calories/macros

### Diet assessment and analytics
- Based on the diet, the app produces an **assessment** of how the user has been eating
- Analytics over periods: **the previous day / week / month**
- ✅ **Module 2 computes the aggregates itself** (Option A): Nutrition knows how to roll its meals up into a daily/weekly/monthly total. "Module 9 — Analytics" takes the ready-made figures. The aggregation logic lives next to the data.

#### Macro/calorie targets (where they come from)
- Computed from the **user's anthropometrics**: weight, height, age, sex, (body fat %), activity level → BMR/TDEE
- **Formula — chosen by the user:**
  - **Mifflin-St Jeor** (default) — does not require body fat %, suits most people
  - **Katch-McArdle** — based on lean mass, more accurate if body fat % is known
- The user's **goal** is a modifier of the target: gaining mass (surplus) / cutting (deficit) / maintenance (balance) / other
- **The goal accounts for the desired result, not just the type.** Example: an 80 kg man who wants to gain +10 kg → the target must provide a sufficient surplus (2000 kcal won't be enough). That is, the goal's magnitude (how many kg and over what timeframe) affects the size of the surplus/deficit in calories. → detailed in "Module 4 — Goals"
- ✅ **Dynamic TDEE — the target accounts for load and activity.** Calorie expenditure from workouts ("Module 3 — Workouts") is added to the target: on training/high-activity days the calorie target is higher. Target = base (BMR × activity) + expenditure from workouts.
  - ⚠️ **Avoid double-counting activity.** With dynamic TDEE, the "activity level" multiplier in the profile is taken as **baseline/sedentary** (BMR × ~1.2), and workouts are added **on top** as a separate line. Otherwise workouts get counted twice (the standard Mifflin multipliers already include "3–5 workouts/week"). Decision: the profile stores "baseline non-sport activity," sport comes from Module 3.
  - ⚠️ **The target does not "drift" retroactively.** The target daily figure used to assess the day is computed from the **day's planned activity** (planned workouts from "Module 5 — Planner"), not recomputed every time an actual entry is added. Otherwise "hitting the target" becomes an unattainable moving target (ate → then worked out → target grew → "shortfall"). Actual workout data refines the target **at the end of the day** (for the evening review/analytics), while during the day the reference is the planned target. ❓ finalize the exact policy (plan vs evening recompute) at implementation time
- Involves three blocks: **Nutrition** (target + actual), **Module 4 — Goals** (goal type), and **anthropometrics** (a new data source)
- ✅ Anthropometrics live in the **user's profile** (see "Module 0 — User Profile") — relied on by both nutrition and training

#### What the assessment is made of
- Hitting the **calorie** target
- Hitting the **macro** target (protein/fat/carbs separately)
- **Food "quality"** — an attribute of the food item (baked sweet potato + asparagus > cheese-flavored chips)
  - → the food item in the reference gains a "quality/healthiness" field
  - source of the value: set in the reference and/or from the photo feature (assessing the healthiness of a dish)

### Future feature — photo recognition
- A dish photo is stored as an attachment ([Attachments](attachments.md)); recognition is via AI ([Modules Spec](modules.md))
- Identifying the components of a dish from a photo
- Automatic macro calculation from the recognized components
- Calories — from macros
- Assessing the healthiness of the dish

### TODO (to design)
- Entities: food item (reference), meal / daily record (log), portions
- Food item fields: name, macros per 100 g, calories, **quality/healthiness**, etc. (cross-check against the calorie-tracker model)

---

## Module 2a — Supplements & Vitamins

> Split out of "Nutrition" into a separate module (2026-06-07): supplements have a different nature — intake courses, dosages, an intake schedule, different purposes.
> In design (2026-06-07).

### The "Supplement/medication" entity (reference)
- Name
- Type/purpose: vitamin / sports nutrition / nootropic / other course-based medications (extensible)
- Form: capsules / powder / tablets / liquid / injection
- Dose per intake
- Package volume + price (for stock forecasting and finance — see below)

> **Framing for any course-based/prescription medications:** the module is a **neutral monitoring tracker** (what/when/how much was taken, the fact of intake, remaining stock, reminders). The system does NOT provide protocols, course schemes, dosages, or medical advice on use/combinations. It only records what the user entered. The AI Assistant (Module 11) does not generate usage recommendations for this category — only neutral monitoring (taken/skipped, remaining stock, reminders).

### Course + schedule (a flexible engine)
- A **course** is an intake period (from date to date / N days). E.g. "take for 2 months"
- **Flexible schedule patterns** — the engine must cover:
  - several times a day (with times, morning/evening, with food/on an empty stomach)
  - every day
  - N times a week on specific days (e.g. 2 times/week: Mon, Thu)
  - complex cycles (e.g. "3 times in one week of the month," every other day, a week on / a week off)
- → a schedule = a recurring rule (pattern) + the concrete planned intakes expanded from the rule
- ✅ the rule model is defined: **a custom set of fields + an optional rrule output** — the shared [Recurrence Engine](recurrence-engine.md) (cycles like "intake week/off week" = `cycle_on`/`cycle_off`, multiple intakes = `times_per_day`). Supplements are a consumer, not their own implementation
- Several intakes/injections per day for a single item

### Reminders + intake fact (important)
- The system **reminds** about an intake
- If the user hasn't taken it — it **reminds again** (escalation), so they don't miss it → implemented by the [Notifications](notifications.md) subsystem (repeat after an interval until marked, configurable)
- Each planned intake (`PlannedOccurrence` from the [Recurrence Engine](recurrence-engine.md)) is marked **done / skipped** (actual)
- → the actual data flows into analytics and the daily review

### Stock + forecast + finance (the key link)
> Example: bought 1.5 kg of gainer for 900 UAH, dose 100 g/day → lasts 15 days → the system forecasts the run-out date → reminds "time to restock, set aside ~900 UAH" → a hint about saving on bulk (a 6 kg pack is cheaper per kg).

- Tracking the **remaining stock** of a supplement (how much is left)
- From package volume + dose per intake + frequency → a **forecast: how long it will last / the run-out date**
- A "time to restock" reminder ahead of time
- **Cost per intake**: unit price → cost per day/month of the course
- Link to "Module 10 — Finance": supplement expenses in the budget; planning the restock (set aside N in the budget)
- (opt.) a savings hint: comparing the price per gram across different pack sizes

### Relationships with other modules
- "Module 5 — Planner" — reminders about intakes and about the end of a course (required)
- "Module 9 — Analytics" — timeliness/course adherence (% of intakes), how it was taken
- "Module 6 — Daily Review" — intake marks for the day
- "Module 10 — Finance" — supplement expenses, restock forecast
- "Module 4 — Goals" — supplements optionally tied to a goal (mass → protein/creatine, cutting → fat burners). A conditional/soft link

### Aggregates
- ✅ Course adherence (% of intakes over a period) is computed by the module itself (see the aggregation principle)

---

## Module 3 — Workouts

> In design (2026-06-07).

### Workout types (polymorphism by type)
- **Strength** (iron) — exercises, sets, weight, reps
- **Cardio** — running, cycling, walking, **swimming** — duration/distance/heart rate/calories
- **Flexibility/yoga** — by time/sessions
- **Sports/activities** — football, martial arts, etc. — the fact + duration
- → a common "Workout" wrapper (date, type, duration, note) + type-specific details (as in "Module 4 — Goals")

### Strength — two levels of detail (user's choice)
> As in nutrition (a whole dish vs by components) — the pattern repeats.
- **Simple:** a statement — "benched 100, squatted 120" (exercise + result)
- **Detailed:** exercise → sets, each set = weight × reps + rest (optional)
- The detailed level is needed for progression on complex strength systems; the simple level is for quick logging

### Running (a specialized cardio subtype)
> Case: the user signed up for a 21 km (half-marathon) in the autumn, hasn't started preparing, level "beginner, but not from zero." Running is a separate discipline with its own structure, not just "cardio with distance."

#### Runner's fitness level
- Grades: complete beginner / beginner / amateur / advanced
- **Determination: self-assessment + adjustment based on actual data** (a test run, then refined by real results — pace, max distance, weekly volume)

#### Target event (race)
- Date + distance (5/10/21/42 km / custom)
- A deadline goal → a link to "Module 4 — Goals" (type "Training," subtype "race") and "Module 5 — Planner" (event date)

#### Training plan — variable (3 levels)
- **Manual** — for the experienced, they build it themselves (optionally with recommendations)
- **Computed from the date** — from distance + date + level → a weekly plan from ready-made templates (volume buildup → peak → taper before the start)
- **LLM agent (opt.)** — personalization/adjustment to the runner's realities via "Module 11 — AI Assistant (cross-cutting layer)"
- (the pattern as everywhere: a manual baseline + ready-made templates + an LLM on top)

#### Running metrics (ideally all of them)
- Distance + pace (min/km)
- Heart rate / heart-rate zones
- Run type: easy / tempo / intervals / long
- Geo/route: a GPS track (GPX as an attachment, see [Attachments](attachments.md)), elevation gain (harder — needs GPS/import)

#### Data source
- **Baseline — manual entry** (distance/time/heart rate after a run)
- **Integrations** (to be added): Strava / Garmin / Apple Health, etc. → via the shared [Integrations](integrations.md) layer (the same contract as calendars; added as providers with `kind=fitness`)
- **LLM agent (opt.)** for guidance/hints
- ❓ Geo/route is realistic mainly via integrations (manual track entry is inconvenient)

### Exercise reference + programs
- **Exercise reference:** name, muscles/group, equipment, type
- **Ready-made programs** "out of the box" (to be sourced somewhere) + **custom saved programs/splits** (template → plan, like routines in "Module 1 — Daily Routine & Sleep")
- Personal scenario: the user builds the workout for each day themselves (a program is not required)

### Progression, PRs, hints
- **Per-exercise history** — all sets/weights over time
- **PRs (personal records)** — auto-tracking of maximums, growth of working weights, trend charts
- **Progression hints** for the next workout (time to add weight/reps)
  - **Level 1 (rules, mandatory):** deterministic progression by rules (e.g. +2.5 kg if all reps were completed N times)
  - **Level 2 (LLM, optional):** a smart program builder/advisor via "Module 11 — AI Assistant (cross-cutting layer)" — builds/adjusts the workout
- The next workout gets a **date in "Module 5 — Planner"**

### Relationships with other modules
- "Module 4 — Goals" — goal type "Training" (squat 100 kg, run 10 km, N workouts/week); goal progress from workout history
- "Module 0 — User Profile" — body measurements as a marker of training results
- "Module 2 — Nutrition" — calorie expenditure from workouts (affects TDEE/target?)
- "Module 5 — Planner" — workout schedule, the date of the next one
- "Module 9 — Analytics" — volume/frequency/weight trends
- "Module 0 — User Profile" — anthropometrics as an input

### Aggregates
- ✅ Volume/frequency/PRs/trends are computed by the module itself (the aggregation principle)

### Workout calorie expenditure → nutrition
- ✅ Workouts **add expenditure** to the daily nutrition target (dynamic TDEE, see "Macro/calorie targets (where they come from)")
- Expenditure is computed by type/duration/intensity (MET coefficients or per-type formulas)

### TODO / open questions
- The rule-based progression model — which schemes (linear, double progression, etc.)
- Where to get ready-made programs (source/license)
- Accuracy of the calorie expenditure calculation (MET tables, heart rate?)

---

## Module 4 — Goals

> In design (2026-06-07).
> ⚠️ **Goals are a cross-cutting entity.** We return to this module when designing "Module 3 — Workouts," "Module 10 — Finance," and others. A body/nutrition goal (gain/lose) is just one kind; there will be training goals (squat 100 kg, run 10 km), finance goals (save N, pay off a loan), etc.
>
> ✅ **Decided: a general goal mechanism with types** — a single "Goal" entity for the whole app, the type defining the specifics (body / training / finance / ...). A unified list of goals, reusable progress/status/milestone logic.

### Architecture — a general mechanism
- A single **Goal** entity with a **type** (body, training, finance, …; extensible)
- **Common fields** (for any goal): name, type, deadline, status (active/completed/abandoned/overdue), current progress, creation date
- **Type-specific details** — separate (see each type below)
- **Several active goals at once**, including of different types (body + training + finance in parallel)

### Milestones (intermediate checkpoints)
- A goal can be broken into **stages**: e.g. 80→90 kg via 82, 84, 86, 88
- A milestone = an intermediate target value + (opt.) its own deadline + an achievement status
- They help track progress and provide motivation
- Milestones are a general mechanism for goals of any type

### Type "Body/composition" (nutrition + measurements)
- Direction: gaining mass / cutting / maintenance / other
- **Input — two ways (mutually convertible):**
  - target value + deadline (e.g. "80→90 kg in 4 months")
  - weekly rate (e.g. "+0.5 kg/week")
- The system converts one into the other and **warns about an unhealthy rate** (gaining/losing too fast)
- Link to "Module 2 — Nutrition": the calorie surplus/deficit and macro targets are derived from the goal
- Link to "Module 0 — User Profile": progress is measured by the measurement log (weight/girths)

### Type "Training" (see "Module 3 — Workouts")
- Goal subtypes: target working weight (squat 100 kg), a cardio result (run 10 km / under a time), regularity (N workouts/week)
- Goal progress is taken from **workout history** (PRs, working weights, number of sessions)
- Milestones are applicable (90 → 95 → 100 kg)

### Type "Finance" (see "Module 10 — Finance") — designed 2026-06-13
- Subtypes: **save N** (progress = amount saved in the linked "Saving Fund"), **pay off a loan/debt** (progress = the linked "Debt" balance approaching zero)
- ⚠️ The progress source for "save N" is **always the Saving Fund** (it knows itself whether it is virtual or on a real account), NOT the "account balance" directly. Otherwise a virtual Saving Fund without an account has no progress source
- Milestones are applicable (save 50k → 100k → 150k); rate target amount + deadline ↔ N/month

### TODO (goal types — detail when working on the adjacent modules)
- How "progress" is computed for each type (different sources: body measurements / working weights / **amount saved in the Saving Fund** / debt balance)

---

## Module 5 — Planner

> In design (2026-06-07). A hub module — reminders/events from all modules converge here.

### Role — a full-fledged day planner
- **A single event/reminder hub** for all modules: supplement intakes + the end of a course, workouts (the date of the next one), body measurements (recurring), races (an event with a date), planning the day from routines in "Module 1 — Daily Routine & Sleep"
- **+ the user's own tasks/events** not tied to modules (doctor, meeting)
- **+ day planning:** time blocks, the daily schedule, a link to routines
- The Planner does not produce domain data itself — it **plans/displays/reminds** about it; the sources are the modules

### Scheduling engine (reusable) — CANONICAL definition
> 📌 **A single cross-cutting mechanism for the whole app.** All modules that have recurrence (supplement courses, workouts, body measurements, tasks, financial operations/debts/emergency fund, habits) use THESE TWO entities with these names. Previously they were named differently in different modules ("planned intake," "event," "PLANNED_OCCURRENCE," "frequency") — these are ONE AND THE SAME. Unified 2026-06-13.
> 📐 **The full engine spec:** [Recurrence Engine](recurrence-engine.md) (rule format, materialization, skips/reschedule, time zones, the boundary with Notifications).

- **`RecurringRule`** — a recurring rule: the recurrence pattern (RRULE / RFC 5545 is recommended, covering "every other day," "intake week/off week," "N times/week on given days"), dtstart, until/count, timezone + **a polymorphic link to the owner** (supplement / workout program / body measurement / task / financial operation / debt / Saving Fund / habit).
- **`PlannedOccurrence`** — a concrete planned instance expanded from a rule: the planned date+time, the planned value/amount, the status (planned / done / skipped / rescheduled). The actual = a reference to a domain record (transaction, supplement intake, completed workout).
- **Materialization (important, see [Finance ER](finance-er.md) open Q):** a window ahead (e.g. +90 days) + **a unique key `(rule_id, occurrence_date)`** → idempotency (re-expanding = no-op, no duplicates). Solved ONCE for the engine.
- **This mechanism is the No. 1 candidate to "design before code"**: it is used by 6+ modules, and reworking it after writing is catastrophic. It must be ready BEFORE the schemas of modules 2a/3/5/8/10.
- All references like "scheduling engine," "pattern engine," "recurring rule" in other modules point HERE.

### Reminders / notifications
- ✅ Extracted into a separate **[Notifications](notifications.md)** subsystem (designed 2026-06-13). The Planner is the hub for WHAT is scheduled; delivery/channels/escalation/quiet hours are in Notifications
- Channels: in-app (now) + push/email/Telegram (adapters, added incrementally). Escalation "remind again if not taken," quiet hours, the daily digest — also there
- The Planner displays events/reminders and triggers notifications; it does not carry the delivery mechanics

### Sync with an external calendar (optional, user's choice)
- Modes to choose from: app calendar only / app + external (Google / Apple Calendar)
- ✅ Designed in **[Integrations](integrations.md)** (the shared integrations layer, calendars being the first representative): **two-way** sync, OAuth connection, local↔external mapping, conflicts. The Planner shows its own + imported external events in a single calendar

### Missed items — both options (the user decides)
- **Record the skip** → "skipped," flows into "Module 9 — Analytics" / "Module 6 — Daily Review" (discipline)
- **Reschedule** → to another day/time
- The user chooses what to do with a specific item

### Relationships with other modules
- All modules with reminders/dates: 0 (body measurements), 1 (routines), 2a (supplements), 3 (workouts/races), 4 (goal deadlines)
- "Module 9 — Analytics" / "Module 6 — Daily Review" — completion/skips of scheduled items

### TODO / open questions
- The "event" model: a common entity for module and user events? (candidate — a `Schedulable` contract, see [Recurrence Engine](recurrence-engine.md) open Q6)
- ✅ Push infrastructure / channels / who sends → extracted into [Notifications](notifications.md) (Laravel Scheduler+queue, in-app now, push/Telegram later)
- Sync with an external calendar (Google/Apple) — a separate integration, later

---

## Module 6 — Daily Review

> In design (2026-06-07).

### When and why — an evening ritual + an online summary
- **In the evening:** the summary of the day that passed + **planning the next day** (a double ritual: closed out today → planned tomorrow)
- Planning tomorrow lives in "Module 5 — Planner"; the review **triggers and displays** it (it does not duplicate the logic)
- **An online day summary** (a "today" dashboard) — available throughout the day, updating as data is entered; you can check in at any moment

### Contents — a summary from all modules
- Nutrition: calories/macros actual vs target, water
- Workouts: done or not, what was done
- Supplements: intakes done/skipped
- Habits/anti-habits: marks, streaks
- Planner: which scheduled items were done/skipped
- (each module provides ready-made totals — the aggregation principle)

### Manual input (all options)
- **Day self-rating** (1–10 / emoji)
- **Well-being:** energy, stress, mood (separate metrics — for correlations in analytics)
- **A free-form note / diary**

### The day score
- ✅ The system computes a **day score** from completion: nutrition on target + a workout + supplements + habits + the plan
- Gamification of discipline
- ❓ the composition and weights of the score — fixed or configurable (for now: we compute it, refine the composition later)

### Delivery
- An evening **reminder** "fill out / look at the review" via "Module 5 — Planner"
- **LLM summary (opt.):** "Module 11 — AI Assistant (cross-cutting layer)" writes a living summary of the day with conclusions (on top of the programmatic summary; without an LLM, the regular summary works)

### Relationships with other modules
- All modules (summary sources), "Module 5 — Planner" (tomorrow's plan + reminder), "Module 9 — Analytics" (well-being/score over time)

### TODO / open questions
- The composition and weights of the "day score"
- Where the Review ↔ Analytics boundary lies (review = a single-day cross-section; analytics = trends over a period)

---

## Module 7 — Storage

> In design (2026-06-13). A single place for **tasks, ideas, lists, and purchases** with quick capture into an inbox. A hub for "turning chaos into action": idea → project/goal, task → day, purchase → expense.

### Decisions (recorded 2026-06-13)
- **Hybrid architecture** — a common "Item" base (capture/title/status/inbox) + **polymorphic type-specific details** (task / idea / purchase / list item). The pattern as in "Module 4 — Goals" and Debts ("Debts and obligations (debt) — added 2026-06-13"). It gives a single quick capture and easy idea→task conversion
- **Tasks:** projects (the main grouping) + tags (cross-cutting contexts) + priority + status
- **Dependencies:** a **parent → children** hierarchy (self-reference, like the two-level categories in Finance) + a **"blocker"** flag on a child. An unclosed blocker prevents closing the parent
- **Lists:** simple items + status/rating; custom fields per list type — later

### The "Item" entity — a common base
- **Type:** task / idea / purchase / list item (polymorphism by type)
- Title (quick capture — minimum friction: one field and done), (opt.) description/note
- **Status:** inbox (unsorted) → active/in progress → done/closed/archive (the set of statuses depends on the type)
- **Inbox flag:** a just-captured item lands in the common inbox for sorting (quick capture → inbox → sorting → processing, see "Interaction principles")
- Tags (cross-cutting classification, the common tag mechanism)
- **parent_id** (self-reference) — parent/children; + an **is_blocker** flag on the item (whether it blocks closing the parent)
- Dates: created, (opt.) deadline/scheduled-for (→ Planner)
- Priority (opt., mainly for tasks)

### Type "Task" (task)
- **Project** (opt.) — the main grouping of tasks (see the "Project" entity below)
- Priority, status (inbox / in progress / done), deadline
- May have subtasks (via parent_id), be a sub-item of an idea
- Date/deadline → an event in "Module 5 — Planner" + reminders; completion during the day → "Module 6 — Daily Review" (a contribution to the day score)

### Type "Idea" (idea)
- **Flow:** quick entry → inbox → sorting → **processing into a plan/project/goal**
- An idea is a source of big work: it is processed into a **Project** or a goal in "Module 4 — Goals" ("an idea went into the plan" from the Vision)
- **Dependent purchases/tasks as children** (parent_id): an idea and its dependencies live together, they don't scatter across lists. A child purchase with a blocker flag → the idea is not "done" until the purchase is bought
- The idea's status accounts for blockers (an open child blocker keeps the idea open)

### Type "Purchase" (purchase) — wishlist
- What to buy, (opt.) estimated price + currency, priority, status (want / bought / canceled)
- May be a **child of an idea** (a purchase for the sake of realizing the idea) OR standalone in the wishlist
- **Link to "Module 10 — Finance" — the connection point (defined 2026-06-13):** an expense transaction (or an installment plan = a "Debt") references a purchase via a **polymorphic source reference** `TRANSACTION.source` (see [Finance ER](finance-er.md) — the "reference to the source module" field: supplement / **purchase item**). The FK lives on the transaction side (the purchase doesn't know about money, money knows about its source)
- **Invariant:** a purchase in the "bought" status ⟺ there exists a linked expense transaction or installment-plan debt. Canceling the transaction → the purchase returns to "want"
- A child blocker purchase is bought → it unblocks the parent idea

### Type "List item" (list item)
- Belongs to a **List** (books / movies / TV shows / anything)
- Fields: text + **status** (want / in progress / done/watched) + (opt.) rating/note
- A simple model for now; custom fields per list type (book: author/pages; movie: director/year) — later (see TODO)

### Containers — "Project" and "List"
- A **Project** — a grouping of tasks/ideas around a large body of work (renovation, a launch, learning). It has a name, a status, (opt.) a link to a goal in "Module 4 — Goals". Tasks and ideas reference the project
- A **List** — a named collection of items of a single purpose (a wishlist of books, movies, etc.). The list's type/purpose is free-form
- ❓ "Project" and "List" — a common "container with a type" mechanism or two entities — to decide at the schema stage

### Tags (cross-cutting classification)
- The common tag mechanism: contexts (@home/@work/@calls), themes. Many-to-many with items
- 📌 a candidate to become the **common tag mechanism** for the whole app (review notes, habits, etc.) — for now local to Storage

### Relationships with other modules
- "Module 10 — Finance" — **purchase → expense/installment plan**; the purchase status ↔ the transaction (closing the link laid out from the Finance side)
- "Module 5 — Planner" — **a task with a date/deadline → an event** + reminders; tasks are woven into day planning
- "Module 4 — Goals" — **idea → goal/project**; a project may realize a goal (goal progress from the project's tasks)
- "Module 6 — Daily Review" — tasks completed during the day → the day score; quick capture of ideas in the evening (the evening ritual)
- "Module 8 — Habits & Anti-habits" — (indirectly) a routine task vs a habit: a one-off matter is a task, a regular one is a habit/routine

### Aggregates (the module's principle)
- ✅ The count of tasks by status/project, the inbox size (how much is unsorted), completed over a period, open blockers — computed by the module itself. "Module 9 — Analytics" / "Module 6 — Daily Review" take the ready-made figures

### AI layer (opt., see "AI is an optional layer, not a foundation")
- **Level 1 (rules, mandatory):** manual inbox processing, sorting by tags/projects/priority — without an LLM
- **Level 2 (LLM, opt.):** auto-processing the inbox (suggest a type/project/tags for the captured text), break an idea down into tasks and purchases, a project summary — via "Module 11 — AI Assistant (cross-cutting layer)"

### TODO / open questions
- "Project" and "List" — a common "container with a type" vs separate entities
- The "Item" polymorphism model: single-table (type + nullable fields) vs STI vs separate detail tables — to decide at the schema stage (cf. the Goals/Workouts polymorphism)
- Custom list-item fields per type (book/movie) — a JSON field vs per-type schemas; whether they are needed in the MVP
- The set of statuses for each item type (a task/idea/purchase/item have different ones)
- Tags — local to Storage vs a common mechanism for the whole app (when to extract)
- The depth of the parent→children hierarchy: only 2 levels (idea→purchases) or arbitrary (subtasks of subtasks)
- What counts as the "inbox": a separate status vs a separate view (an "unsorted" filter)

---

## Module 8 — Habits & Anti-habits

> In design (2026-06-07).

### Habits (what we want to do)
- **The fact of completion** (yes/no) + **numeric metrics** (pages read, minutes, reps) — for "how much over the month" analytics
- **The time of completion** is recorded — important for "when I usually do it" analytics
- Frequency via the common "Scheduling engine (reusable)" (every day / N times/week / on specific days)

### A habit builder based on "Atomic Habits" (J. Clear)
> Not just a checklist — a **mechanism for embedding a habit into life** following the book's methodology.
- **Habit stacking** — "After [an existing action] I do [a new habit]" → a direct link to **routines** in "Module 1 — Daily Routine & Sleep" (a habit attaches to a routine)
- **Implementation intention** — "I do [habit] at [time] in [place]" → a link to "Module 5 — Planner"
- **The 2-minute rule** — start with a micro version of the habit
- **Don't break the chain** — a streak as the core of motivation
- (opt.) the 4 laws as hints when creating a habit: obvious / attractive / easy / satisfying

### Anti-habits (what we want to limit/quit) — two modes
- **Full abstinence:** an abstinence streak ("N days without"), recording relapses, the best streak record
- **Stepped limit:** a limit that **decreases on a plan over time**
  - example: energy drinks — max 1/day → then 5/week → then 3/week (a gradual reduction, not an abrupt quit)
  - ⚠️ **A stepped limit ≠ a milestone** (clarified 2026-06-13). A "Module 4 — Goals" milestone = an intermediate **achievable checkpoint** (a target value that must be reached). A stepped limit = an **active ceiling constraint** (which must not be exceeded) + a change of the unit of measurement (day→week). These are semantically different entities (we reach vs we constrain). They share only the structure: "an array of (value, deadline, status)." Do NOT model them with one entity blindly — see TODO

### Gamification — streaks + statistics
- Streak (the current streak), the best streak, % completion
- Without excessive game rewards — motivation through the chain and the numbers

### Relationships with other modules
- "Module 1 — Daily Routine & Sleep" — a habit as part of a routine (habit stacking)
- "Module 5 — Planner" — reminders, implementation intention (time/place)
- "Module 4 — Goals" — a habit/anti-habit tied to a goal (quitting smoking = a goal with a streak; lowering a limit = milestones)
- "Module 9 — Analytics" / "Module 6 — Daily Review" — trends, % completion, "when/how much"

### Aggregates
- ✅ Streak / % completion / metric sums are computed by the module itself (the aggregation principle)

### TODO / open questions
- A reference for the methodology: "Atomic Habits" — exactly which mechanics in the MVP
- The model for a "limit that changes over time" — a separate "stepped scale" entity (value+deadline+status), do NOT reuse a goal milestone blindly (different semantics: a constraint vs an achievement). ❓ whether a shared abstraction is needed at all, or two independent models

---

## Module 9 — Analytics

> In design (2026-06-07). A display layer: it gathers ready-made totals from the modules (see "Each module computes its own aggregates"), it does not duplicate the aggregation.
> The boundary with "Module 6 — Daily Review": the review = a single-day cross-section; analytics = trends over a period.

### The core — trends + correlations
- **Trends:** trend charts for metrics over time (weight/girths, calories/macros, working weights, streaks, sleep, the day score, etc.)
- **Correlations (the headline feature):** links between metrics — conclusions about effectiveness
  - examples: sleep → energy; nutrition → weight; workouts → working weights; well-being → discipline
  - the "result marker" source is the measurement log of "Module 0 — User Profile," well-being from "Module 6 — Daily Review"

### Periods and cross-sections
- Day / week / month + **an arbitrary date range**
- **Period comparison** (this month vs last)

### Conclusions — rules + LLM (the "layer" pattern)
- **Level 1 (rules, mandatory):** deterministic correlations/conclusions by rules (a threshold, a trend, a comparison with a target) — works without AI
- **Level 2 (LLM, opt.):** deep insights as living text via "Module 11 — AI Assistant (cross-cutting layer)"
- A link to the "Recommendation mechanism (adjustment)" — analytics provides the trends for the recommendations

### Export (important)
- Exporting data/reports: CSV / PDF (to show a doctor/trainer, for backup)
- ❓ formats and composition (per-module / consolidated / by period)

### Relationships with other modules
- All modules — sources of ready-made totals
- "Module 0 — User Profile" (body measurements), "Module 6 — Daily Review" (well-being/score), "Module 4 — Goals" (progress), "Module 11 — AI Assistant (cross-cutting layer)" (insights)

### TODO / open questions
- Exactly which correlations we compute by rules (a list of metric pairs)
- Performance over large periods (precomputation/caching of aggregates?)
- Export formats

---

## Module 10 — Finance

> In design (2026-06-13). Tracking income/expenses, multi-currency accounts, planned/actual budgeting, financial goals. A hub for monetary links: supplement restocks, purchases from ideas, recurring payments.
> 📐 **The ER schema of entities and relationships:** [Finance ER](finance-er.md)

### Decisions (recorded 2026-06-13)
- **Many accounts + transfers** — cash / cards / savings / currency. Each has its own balance. A transfer between accounts = a double transaction (a debit from one + a credit to another), neither income nor expense.
- **Multi-currency from the start** — accounts in different currencies (UAH/USD/EUR/…), storing exchange rates, conversion to the base currency for consolidated analytics.
- **Investments deferred** — an asset portfolio with quotes is NOT being built now. Savings are tracked as a regular Saving Fund account (account type "savings"). Assets/returns are a separate sub-stage later.
- **Two-level categories** — group → subcategory (e.g. "Food → Groceries / Cafe / Delivery"). Convenient for collapsing in analytics.
- **Budget — limits per category per month** — a monthly limit is set per category; the system shows "spent X of Y" and warns about approaching/exceeding it.

### The "Account" entity (account)
- Name, type (cash / card / savings / currency — extensible)
- **The account's currency** (one per account)
- The current balance (a derivative: starting balance + sum of transactions; **the module computes the total itself**, see "Each module computes its own aggregates")
- An "archived" flag (a closed account is not deleted — the transaction history must live on)
- ❓ starting balance: a separate field or the first "adjusting" transaction — to decide when designing the schema

### The "Transaction" entity (transaction)
- Type: **income / expense / transfer**
- Amount + currency (inherited from the account)
- Account (for a transfer — a source account and a destination account)
- Category (for income/expense; a transfer has no category)
- Date, note, (opt.) tag
- A **transfer between currencies** stores both amounts (debited 1000 UAH → credited 24 USD) + the effective rate of the operation
- (opt.) a reference to the source module (a supplement restock, a purchase from an idea) — see the relationships below

### Categories (two-level)
- **Group** (the top level) → **subcategory** (the bottom). A transaction is hung on a subcategory (or on a group, if without detail)
- A direction marker: an expense category vs an income category (we don't mix income and expense)
- User-defined + a set of defaults "out of the box"
- Archiving instead of deletion (to preserve the history per category)
- Examples of a two-level structure: "Food → Groceries / Cafe / Delivery," **"Medicine → Dentistry / Lab tests / Medications"**
  - The dentistry case: regular prevention (fixing teeth in time so as not to get hit with a major replacement) — this is a **regular planned expense** by subcategory + a reminder about a visit via "Module 5 — Planner" (a preventive visit as a recurring event). Analytics by subcategory shows the trend of dentistry spending
  - 📌 the pattern "cheap prevention now vs an expensive breakdown later" applies to other subcategories too (car maintenance, etc.) — a general idea, not a special-purpose mechanism

### Multi-currency and exchange rates
- **The user's base currency** (in "Module 0 — User Profile" / settings) — everything is consolidated into it
- Exchange rates: manual entry + (later) pulling from an external source (NBU/API). At the start — manual / the last known
- Conversion for **consolidated** analytics; within an account the amounts stay in its currency (we don't lose the original data)
- ⚠️ store the **historical rate as of the operation date** for transfers, not just the current one — otherwise the consolidated figures will "drift"

### Budget (planned vs actual)
- Per **category** (a group or a subcategory) — a monthly **limit**
- Actual = the sum of the category's transactions for the month (a ready-made total from the module)
- States: within / close to the limit (a threshold, e.g. 80%) / exceeded
- A consolidated monthly budget = the sum of the limits; "remaining until the end of the month"
- 📌 warnings about approaching/exceeding → the notification channel of "Module 5 — Planner"
- ❓ carrying the remaining limit over to the next month — yes/no (no by default; envelope mode — later, if desired)

### Debts and obligations (debt) — added 2026-06-13
> Cases: an installment plan on an item (N payments of X/month), "I owe a person — paying back X/month," "pay off a credit card of X thousand at bank Y." **A general debt mechanism with types** (the pattern as in "Module 4 — Goals": a single entity + a type/mode defining the specifics).

#### The "Debt" entity (common fields)
- Name / description (for what, to whom)
- **Direction:** I owe (a liability) / I am owed (an asset-claim) — both sides in one model
- **Counterparty:** to/from whom (a bank, a store, a person) — free text or a reference to a counterparty directory (later)
- **Original amount** + **balance** (the balance decreases with payments; the module computes the total itself)
- Currency (multi-currency, see "Multi-currency and exchange rates")
- Origination date, (opt.) a deadline for full repayment
- Status: active / repaid / overdue
- (opt.) a reference to the account **it is paid from** (for an auto payment transaction)

#### Schedule mode — two options (a flag on the debt)
- **Fixed schedule** (installment plan): amount + number of payments + frequency (X/month) → the system **expands the schedule** of concrete payments (the date + amount of each). Each payment: scheduled / paid / overdue
- **Free repayment** (a credit card, a debt to a person without a term): only the balance, payments are made whenever, in arbitrary amounts; the schedule is not fixed. Reminders are optional
- → reuses the common "Scheduling engine (reusable)" for the regular payments of a fixed schedule

#### Interest/overpayment — optional
- Can be left unspecified (a 0% installment plan, a debt to a person) — then the pure principal is tracked
- If specified: a rate OR the total overpayment amount → the system shows how much of a payment goes toward the **principal**, how much toward **interest**, and the total overpayment
- ⚠️ full amortization with interest accruing on the balance (annuity/declining) — NOT now, later if needed (see TODO)

#### Debt payment
- The fact of a payment = an expense transaction (for "I owe") / an income transaction (for "I am owed") with a reference to the debt → **decreases the balance**
- For a fixed schedule, a payment closes out a specific scheduled payment (paid)
- A link to transactions (see "The 'Transaction' entity (transaction)"): a debt payment is a regular transaction + a reference to the debt

#### Bindings
- An installment plan may reference a **purchase/item** (potentially from "Module 7 — Storage" — a wishlist/idea → bought on installments → a debt)
- A debt's **recurring payments** go through the same mechanism as "Recurring payments (recurring) — reusing the engine"
- Payment reminders → "Module 5 — Planner"
- "Pay off a credit card/debt" as a **goal** → linked to the debt (goal progress = the debt balance approaching zero), see below

#### Aggregates
- ✅ The balance per debt, the total "I owe" / "I am owed" debt, a repayment-date forecast (from the balance + the payment rate), the overpayment — computed by the module itself

### Saving Funds / saving toward a goal (sinking funds) — added 2026-06-13
> Cases: "save X for the garage renovation," "save X for a trip to the mountains." A custom saving toward a specific goal/category.

#### The "Saving Fund" entity
- Name (garage renovation, mountain trip), (opt.) a link to a **spending category** (what we're saving for)
- **Target amount** + **saved** (progress toward the goal), currency
- (opt.) a deadline → recompute of "how much to put aside/month to make it in time"
- Status: active / target reached / spent (closed)

#### Where the money sits — two options (the user's choice)
- **A virtual Saving Fund (envelope):** not a separate account, but "X set aside for the renovation" on top of the overall balance; the money is physically on any account. We don't spawn an account per goal
- **A link to a real account:** the Saving Fund's progress = the balance of a specific savings account (see the account type "savings")
- → a single model, a mode flag; a virtual amount vs a reference to an account

#### Top-up
- A Saving Fund top-up = a "set aside" operation (for a virtual one — an internal transfer into the envelope; for an account — a real transfer to the savings account)
- It can be **recurring** (X/month via "Recurring operations (recurring) — income AND expenses, reusing the engine") or a one-off by hand
- Reaching the goal → a notification; then the money is spent on the goal (an expense transaction in the linked category, the Saving Fund is closed)

### Emergency Fund (emergency fund) — added 2026-06-13
> A special, dedicated kind of saving: **mandatory** monthly buildup, **open-ended** (not a one-off goal of "saved it and closed it," but an ongoing discipline). That's why it's separate from regular Saving Funds.

#### The difference from a Saving Fund
- **Open-ended** and **mandatory** — topped up every month without fail, included in the month's mandatory expenses (like a debt payment), not in "if possible"
- Highlighted visually and in cash flow (this is "don't touch" money)

#### The top-up rule — three modes (the user's choice)
- **A fixed amount/month** (e.g. 2000 UAH)
- **A % of monthly income** (e.g. 10% — scales from the "planned income")
- **A target size = N months of expenses** (e.g. 6 months): the system takes average spending (a ready-made total from the module) → the target Emergency Fund size + how much to put aside/month until the goal
- → the top-up rule is configurable; upon reaching the target size (for the 3rd mode) — the status "Emergency Fund is full," after which it's maintenance

#### Behavior
- The mandatory top-up is expanded as a **recurring operation** (a priority mandatory "expense → into the Emergency Fund") via the scheduling engine
- A drawdown of the Emergency Fund (had to spend on a force majeure) → the Emergency Fund is "under-funded" again → the mandatory buildup resumes
- The Emergency Fund's progress/trend → "Module 9 — Analytics"; a top-up reminder → "Module 5 — Planner"
- 📌 conceptually the Emergency Fund is a special case of a Saving Fund with the flags "mandatory + open-ended"; in the model the Saving Fund entity can be reused + a type/flags (to decide at the schema stage)

### Financial goals (goal type "Finance") — a link to "Module 4 — Goals"
- Implements the TODO from "Module 4 — Goals" (type "Finance"): **save N**, **pay off a loan/debt**
- The **goal "save N for …"** is linked to a "Saving Fund" (renovation/travel); **progress = the amount saved in the Saving Fund** (the Saving Fund knows itself: virtual or on an account). That is, the Saving Fund = the saving mechanism, the finance goal = a wrapper with a deadline/milestones on top of it. ⚠️ progress is taken from the Saving Fund, NOT from the "account balance" directly (cf. "body" → body measurements, "training" → working weights, "save" → the Saving Fund)
- The **goal "pay off a loan/debt"** is linked to a specific **Debt** (see "Debts and obligations (debt) — added 2026-06-13"); progress = the debt balance decreasing toward zero
- **Milestones** are applicable: save 50k → 100k → 150k (the general checkpoint mechanism)
- Rate: target amount + deadline ↔ "set aside N/month" (mutual conversion, as in the "body" goal: target value ↔ rate)

### Relationships with other modules
- "Module 4 — Goals" — goal type "Finance": "save N" progress from the linked Saving Fund, "pay off a loan" from the Debt balance, milestones
- "Module 2a — Supplements & Vitamins" — the **stock → forecast → finance** link: factor the restock expense into the budget, the restock-date forecast → a planned expense. A restock spawns a **one-off planned expense** (from the supplement's remaining-stock forecast), NOT a recurring rule — see the distinction in "Recurring operations (recurring) — income AND expenses, reusing the engine". The expense transaction references the supplement via `TRANSACTION.source`
- "Module 7 — Storage" — the purchase wishlist and "idea → dependent purchases": purchase → an expense transaction/installment plan via **`TRANSACTION.source` (a polymorphic reference)**; the invariant "bought ⟺ there is a transaction". The FK is on the transaction side. The link is defined from both sides (see "Type 'Purchase' (purchase) — wishlist")
- "Module 5 — Planner" — **recurring operations** (expenses: subscriptions/utilities/loan/rent; income: salary 3×/month) as recurring events + reminders (a payment, salary arriving, an Emergency Fund top-up, a preventive dentist visit) via the "Scheduling engine (reusable)"; budget warnings as notifications
- "Module 9 — Analytics" — where the money goes (a breakdown by categories/groups, including subcategories like dentistry), monthly trends, period comparison, income vs expenses, **cash flow** (planned income − mandatory expenses), Emergency Fund/Saving Fund progress; export (CSV/PDF)

### Recurring operations (recurring) — income AND expenses, reusing the engine
> Symmetry: the recurrence engine works both ways — a planned expense (utilities) and planned income (salary). The cash flow forecast is built on this.

- **Recurring expenses:** subscriptions / utilities / loan / rent — a **recurring rule** from the common "Scheduling engine (reusable)" (the same one as supplement courses and planner events)
- **Recurring income:** salary, fees, etc. — the same rule, but with the direction "income"
  - Case: **salary 3 times/month in fixed amounts** (UAH for now) — three income rules with dates and amounts (advance / main / bonus, etc.), or one rule with several payouts in the period
  - **A receipts calendar:** you can see on a timeline when and how much will come in → an understanding of cash flow (whether there's enough money until the next paycheck)
- From a rule, **planned transactions** (income/expense) are expanded; the actual is marked when it occurs (received / paid / skipped), like a supplement intake
- **The month's planned income** = the sum of recurring income → the basis for planning the budget and the Saving Funds/Emergency Fund (what the limits and the "% of income" are based on)
- **The cash flow forecast:** planned income − mandatory expenses (recurring + debt payments + the mandatory Emergency Fund top-up) → the month's free money
- ❓ irregular/one-off expected income (a bonus, a refund) — as planned income without a rule (a one-off scheduled receipt)

### Aggregates (the module's principle)
- ✅ The balance of each account, the consolidated balance (in the base currency), the sum per category over a period, the budget actual, income/expenses/net for the day/week/month, the **month's planned income**, **cash flow** (planned income − mandatory expenses), the amount saved in the Saving Funds, the size/under-funding of the Emergency Fund, the debt balances — **computed by Module 10 itself**. "Module 9 — Analytics" takes the ready-made figures

### AI layer (opt., see "AI is an optional layer, not a foundation")
- **Level 1 (rules, mandatory):** deterministic spending analytics, budget warnings, a forecast of mandatory payments, computing cash flow and the Emergency Fund under-funding — works without an LLM
- **Level 2 (LLM, opt.):** insights as living text ("this month you spent twice as much as usual on delivery," "at the current rate you'll have saved for the garage renovation by March"), savings advice, a breakdown of the spending structure — via "Module 11 — AI Assistant (cross-cutting layer)"

### TODO / open questions
- Account starting balance: a field vs an adjusting transaction
- Storing exchange rates: the model (currency pair + date + rate), manual entry vs an external source (NBU/API)
- Carrying the remaining budget limit over to the next month (whether it's needed)
- The "transfer" model: a single record with two accounts vs two linked records
- The default "out of the box" categories — which starter set
- Where the "base currency" lives — the profile ("Module 0 — User Profile") or the finance settings
- Linking a supplement restock / a purchase from an idea to a transaction — a mandatory reference or a soft one (details when working on Modules 2a/7)
- **Debts:** a counterparty directory (whom you owe) — a separate entity or free text
- **Debts:** full amortization with interest on the balance (annuity/declining) — whether it's needed, or an optional principal+overpayment is enough
- **Debts:** how to compute "overdue" for free repayment (without a schedule there is a deadline, but no payments)
- **Saving Funds/Emergency Fund:** a common entity with flags (a regular Saving Fund / a mandatory-open-ended Emergency Fund) vs two separate ones — to decide at the schema stage
- **Saving Funds:** a virtual "envelope" — how to technically keep the amount separate from the account balance without double-counting the money (reserving part of the balance)
- **Emergency Fund:** for the "N months of expenses" mode — on which spending to base the average (all / mandatory only), over what period
- **Income:** irregular expected receipts (a bonus/refund) — a one-off planned income without a recurrence rule
- **Income:** multi-currency income (UAH for now), but lay out a currency field as everywhere

---

## Module 11 — AI Assistant (cross-cutting layer)

> Added 2026-06-07. NOT a feature of one module, but a **built-in helper for the whole system**.
> ⚠️ **An optional layer** — see "AI is an optional layer, not a foundation". The system works without it too.

### Purpose
- A cross-cutting AI helper available from all modules
- Sees the context of the whole system: profile/body measurements, nutrition, workouts, goals, habits, analytics
- Scenarios: adjustment recommendations (see "Recommendation mechanism (adjustment)"), workout breakdown, analyzing measurement trends, answering the user's questions, nutrition advice
- ✅ **Full per-module scenario catalog + the technical contract (context assembly / RAG, interaction types, backend tool-calls, privacy boundaries):** [LLM Layer](llm-layer.md). This module is the **infrastructure** (provider/BYOK/key security); the LLM Layer doc is the **scenarios**.

### Provider abstraction — at the user level (BYOK)
> **Bring Your Own Key.** The provider is configured NOT in the app's config, but by the user themselves through a form. The user connects THEIR OWN agent via API (their own paid Claude/OpenAI/other account). The user pays for the tokens.

- The system is **not tied to a single LLM** — the provider choice is made by the user
- LLM credentials = **the user's data** (a separate entity, tied to the user):
  - the provider (Claude/Anthropic, OpenAI, a custom endpoint, etc.)
  - the API key
  - the model + parameters
  - possibly several connections with a choice of the active one
- A form in the settings: add/edit/delete a connection, choose the active one, test the connection
- The pattern stays the same: a single **contract** (e.g. `LlmProvider`) + adapters for the providers
- But the choice of implementation is **at runtime from the user's data**, NOT from a static config (a factory by the user's provider)
- ⚠️ Support for a "custom agent/endpoint" — to think through: only known providers or an arbitrary OpenAI-compatible URL?
- Learning value: Strategy/Adapter + the Laravel Service Container, runtime resolution

### ⚠️ Security of users' API keys
- Other people's API keys in the DB are sensitive secrets, a high responsibility
- **Encrypt them in the DB** (Laravel encrypted cast / Crypt), do not store them in plaintext
- **Never return the key back to the frontend** in plaintext (only a mask `sk-...abcd`, the status "connected")
- Protection against leaks, access auditing, key revocation

### TODO
- The `LlmProvider` contract (methods: chat/complete, passing context, streaming?)
- Which system context and how it is assembled/passed (RAG over the user's data?)
- Token/cost management, limits
- The "user's LLM connection" entity: fields, key encryption, the active connection
- The agent connection form + a connection test; masking the key on the frontend
- Support for a custom OpenAI-compatible endpoint — yes/no?
- The default/reference provider for the documentation: Claude API (Anthropic) — cross-check against models/pricing via the claude-api reference
- Privacy: the user's data goes to an external LLM (their own) — a warning/consent
