# SelfHandler — LLM Layer (cross-cutting)

> The **optional** AI layer that sits on top of every module. The app is fully functional **without it** — see [AI is an optional layer, not a foundation](modules.md). This doc is the systematic pass promised by the standing TODO: for each module, what AI (Level 2) adds on top of the deterministic Level 1 baseline, what context the agent sees, and where the safety/privacy boundaries are.
>
> Infrastructure (provider abstraction, BYOK, key security) lives in [Module 11 — AI Assistant](modules.md). This doc is about **scenarios and contracts**, not plumbing.

---

## Core principle — Level 1 vs Level 2

Every smart feature has two tiers:

- **Level 1 — deterministic, mandatory.** Rules, formulas, aggregates on the backend. Works with no LLM, no key, no network. This is the product.
- **Level 2 — LLM, optional.** An amplifier on top: living text, natural-language input, vision, agentic actions. Requires the user's own provider key (BYOK).

**Hard rule:** if there is no key, the provider is unreachable, or the user declines — Level 1 stands alone and nothing breaks. AI never sits in a critical path.

---

## How the AI layer works (technical contract)

Grounded in the Claude API (reference provider; BYOK means the user can swap in any provider). Verify model/pricing facts against the `claude-api` reference, never from memory.

- **Reference model:** `claude-opus-4-8` (1M context window). The large context comfortably fits a day's — or week's — worth of cross-module aggregates as context. BYOK = the user's own key/account pays for tokens.
- **Context assembly (RAG over the user's own data):** the backend selects relevant **ready-made aggregates** from modules (each module already computes its own totals — see [Each module computes its own aggregates](modules.md)) and feeds them as context. The LLM reads aggregates; it does **not** recompute them, and for most scenarios it never sees raw rows.
- **Interaction types** (per scenario):
  - **chat** — conversational Q&A over supplied context.
  - **one-shot generation** — living-text narrative on top of computed figures (summaries, insights).
  - **structured-output (JSON)** — machine-readable result validated against an existing schema before any write (parsing, classification, plan generation). Uses the API's structured-output format.
  - **tool-calling** — the agent emits tool calls; **the SelfHandler backend executes them** against the domain modules / recurrence engine / notifications, then returns results. This is how the AI "takes actions" (plan a day, triage inbox, draft a transaction).
  - **vision** — image input (food photo, progress photo, receipt) → structured draft.
- **Write discipline:** the LLM **never auto-commits**. Every recognition, plan, classification, or draft round-trips through user confirmation. Tool-calls are executed by the backend, which enforces domain invariants (e.g. blocker rules, "bought ⟺ transaction exists", recurrence materialization stays engine-owned).
- **Prompt caching:** the (large, stable) system prompt and tool definitions are cacheable; volatile per-request context goes last. Keeps BYOK token cost down.
- **Cost/limits:** token budget and limits are the user's (BYOK). The backend should keep context lean (aggregates, not raw logs) — also the privacy default.

---

## Cross-cutting safety & privacy rules

1. **Consent per scope.** Sending the user's data to an external LLM (even their own) is opt-in, per category. The UI states what leaves.
2. **Aggregates over raw rows.** Default to sending computed totals, not raw records — privacy *and* cost. Raw data only where the scenario inherently needs it (vision, transaction categorization).
3. **Finance & body data are the sharpest.** Financial aggregates, body measurements, progress photos, and journal text are the most sensitive payloads — strongest consent gates.
4. **AI narrates, never overrides.** Where Level 1 produced a verdict/score/correlation, the LLM rephrases it — it must not contradict the deterministic direction or invent findings absent from the supplied data (anti-fabrication guard, esp. Analytics).
5. **Supplements stay a neutral monitor.** For supplements/cycled substances the AI reports taken/skipped/remaining only — **no protocols, dosages, usage advice, ever** (the system prompt forbids it even if asked). See Module 2a below.
6. **No medical/clinical claims.** Coaching and observation only (habits, nutrition, training, body) — never diagnosis or medical-grade advice.
7. **Tone is a user setting.** The recommendation tone (harsh / friendly / neutral, from [Profile](modules.md)) gates LLM phrasing — a relapse or stall isn't met with unwanted snark.

---

## Scenarios by module

> Format per scenario: **L1** = deterministic baseline · **AI adds** = Level-2 value · **Context** = what the agent sees · **Type** = interaction · **Tools** = backend-executed actions (agentic only) · **Risk** = privacy/safety note.

### Module 0 — User Profile

**Living adjustment recommendations** — *one-shot / structured*
- **L1:** `if`-rules match measurement trend + goal type + nutrition actuals → canned advice, respecting tone.
- **AI adds:** rewrites the rule verdict as personalized living text in the chosen tone; weaves in cross-signals (sleep, volume, stalls) flat rules can't phrase.
- **Context:** last ~30 body measurements + active goal (type/target/rate) + 7–14d nutrition actuals + tone; the deterministic verdict is passed in as a seed.
- **Risk:** body/weight history to external LLM (sensitive); must not contradict the rule's direction.

**Measurement-trend insight summary** — *one-shot*
- **L1:** Analytics computes trend slopes + plateau/spike flags.
- **AI adds:** narrative reading ("waist −2cm while arm girth held — recomposition"), tied to the goal loop.
- **Context:** per-metric trend series (aggregated) + active goal + deterministic flags.
- **Risk:** anthropometrics to external LLM; descriptive only, no diagnosis.

**Progress-photo analysis** — *vision*
- **L1:** none — only stores + shows photos side by side.
- **AI adds:** before/after visual read on two progress photos, tied to numeric deltas. Opt-in per upload.
- **Context:** two progress images + weight/girth deltas between dates + goal.
- **Risk:** ⚠️ progress photos are the most sensitive payload in the app — explicit per-upload consent, never automatic; observational only, no body-shaming even in "harsh" tone.

### Module 1 — Daily Routine & Sleep

> Light AI surface. Day-template/routine generation overlaps with Planner ("Plan my tomorrow") and Habits (habit-stacking onto routine steps). No standalone scenarios beyond those; routine steps are the anchor data those scenarios read.

### Module 2 — Nutrition

**Food-photo recognition** — *vision → structured*
- **L1:** manual meal logging (whole dish + weight, or by components) against the food-item reference.
- **AI adds:** from a dish photo, identifies components + estimated weights → machine-readable rows the app turns into a meal; estimates quality. User confirms before save.
- **Context:** dish image + food-item reference (candidate subset) + meal category/time. **Backend computes macros** from component + grams; the LLM returns components + grams, not macro numbers.
- **Tools:** optional `lookup_food_item(name)`.
- **Risk:** meal photos to external LLM; estimate-only, must round-trip confirmation, never auto-commit.

**Free-text meal parsing** — *structured*
- **L1:** manual item-by-item entry.
- **AI adds:** parses "200g chicken, rice, a bit of olive oil" → structured components + weights mapped to reference items.
- **Context:** free-text + food-item reference. Backend computes macros.
- **Tools:** `lookup_food_item(name)`; app creates the meal on confirm.
- **Risk:** diet text to external LLM (low sensitivity); confirm-before-write.

**Diet assessment narrative** — *one-shot*
- **L1:** module computes period aggregates (kcal/macro vs target, avg quality) + rule-based score.
- **AI adds:** readable assessment ("protein consistently 30g short on training days") + 1–2 neutral suggestions.
- **Context:** period aggregates + active body goal + planned vs actual training load (dynamic-TDEE). Totals only.
- **Risk:** diet + goal to external LLM; food/portion guidance only, no clinical-diet claims.

### Module 2a — Supplements & Vitamins ⚠️ NEUTRAL-MONITOR ONLY

> **Hard boundary.** The AI is a neutral monitor: it reports taken / skipped / remaining only. **No protocols, dosages, cycle schemes, timing, combinations, or usage advice — for ANY category.** The system prompt must forbid generating usage guidance even if explicitly asked.

**Adherence summary (neutral)** — *one-shot*
- **L1:** module computes course adherence (% of planned intakes done/skipped) from marked occurrences.
- **AI adds:** factual readback ("creatine: 18 of 21 intakes logged; 3 missed, all evenings last week").
- **Context:** per-course adherence stats (planned/done/skipped + timing). **No purpose/dosage reasoning fed in.**
- **Tools:** none.
- **Risk:** ⚠️ supplement names (incl. cycled substances) to external LLM; report-only, never advice.

**Restock / run-out readback (neutral)** — *one-shot*
- **L1:** from remaining stock + dose + frequency → run-out forecast + one-off planned expense + restock reminder; optional cheaper-bulk hint.
- **AI adds:** friendly phrasing of the already-computed forecast ("gainer runs out in ~4 days; 6kg pack ~15% cheaper/kg").
- **Context:** computed run-out date + remaining qty + restock-cost figure + price/gram comparison. LLM does not compute the forecast.
- **Tools:** none — the planned expense is created deterministically by the stock-forecast mechanism.
- **Risk:** ⚠️ supplement + spend data to external LLM; logistics/budget only, never whether/how much to take.

### Module 3 — Workouts

**Smart progression advisor (strength)** — *one-shot / structured*
- **L1:** rule-based progression (+2.5kg when target reps hit N sessions running); auto PRs; next session → Planner.
- **AI adds:** adjusts next-session prescription to real history (stalls, missed reps, deload, swap a stuck lift) beyond the flat rule.
- **Context:** per-exercise set/weight/rep history + PRs + deterministic suggestion (seed) + linked training goal + adherence.
- **Tools:** `create_planned_occurrence(workout, date)` — on confirm.
- **Risk:** training history to external LLM (low sensitivity); respects the deterministic safety floor; no injury/medical advice.

**Race training-plan generation/adjustment (running)** — *structured / tool-calling*
- **L1:** plan from distance + race date + level using templates (buildup→peak→taper), or manual.
- **AI adds:** personalizes/repairs the template to the runner's reality (missed weeks, actual pace/volume, constraints), re-flows to the race date.
- **Context:** target race + runner level + recent run history (distance/pace/HR/type/volume) + planned-vs-done + template baseline.
- **Tools:** `create_planned_occurrence(run_session, date)` (batch), `reschedule_occurrence(id, date)` — on confirm; the backend creates the `RecurringRule`.
- **Risk:** run/HR data to external LLM; draft reviewed before populating Planner; sane volume ramp, no medical-clearance claims.

**Workout expenditure → nutrition narrative** — *one-shot* (usually inside Daily Review)
- **L1:** module computes per-workout expenditure (MET/type/duration) and adds it to dynamic-TDEE.
- **AI adds:** explains the training↔nutrition loop ("today's long run added ~600 kcal — your target is higher; you came in 300 short").
- **Context:** today's computed expenditure + planned vs actual target + nutrition actuals + body goal. Figures only.
- **Risk:** training + diet to external LLM; descriptive; respects "target doesn't drift retroactively" — explains the end-of-day reconciliation, doesn't redefine the target.

### Module 4 — Goals

**Idea → goal decomposition** — *tool-calling*
- **L1:** manual Goal creation (type/deadline/target) + hand-added milestones.
- **AI adds:** from a vague ambition ("strong enough for a half-marathon by autumn") → proposes a typed Goal + milestones + sensible rate; persists on confirm.
- **Context:** free-text intent + Goal schema + existing active goals + relevant baseline (anthropometrics / PRs / saving fund).
- **Tools:** `create_goal`, `create_milestone`, `link_goal_to_owner` (Saving Fund / Debt / workout discipline).
- **Risk:** goal text + baseline metrics to external LLM; propose-then-confirm.

**Milestone laddering / rate sanity-check** — *structured*
- **L1:** target+deadline ↔ rate/week conversion + unhealthy-rate threshold warning.
- **AI adds:** explains *why* a rate is unrealistic + proposes a re-laddered milestone sequence from actual history.
- **Context:** goal (target/deadline/progress) + deterministic rate verdict + historical progress source.
- **Risk:** health-rate framing stays motivational, not medical; the deterministic warning remains source of truth.

**Stalled-goal nudge (cross-module)** — *one-shot*
- **L1:** goal shows progress %, status, milestone flags.
- **AI adds:** when progress lags the planned curve, a short diagnostic + one concrete adjustment.
- **Context:** goal + milestones + progress timeline + owner-entity actuals (top-up cadence / payment history / workout frequency) + tone.
- **Risk:** aggregated progress only; respects tone setting.

### Module 5 — Planner

**"Plan my tomorrow" (NL day planning)** — *tool-calling*
- **L1:** manual time blocks + routine templates; engine surfaces scheduled occurrences for the day.
- **AI adds:** from a free-text brief builds a coherent timed schedule around fixed anchors and creates blocks/tasks.
- **Context:** target date + existing `PlannedOccurrence`s (read-only anchors) + routine templates + quiet/working hours + free-text intent + open schedulable tasks.
- **Tools:** `create_time_block`, `create_task`, `schedule_planned_occurrence`, `schedule_reminder`, `apply_routine_template`.
- **Risk:** engine-owned occurrences are read-only anchors (schedule around, never silently move a series); confirm before write.

**Recurring-rule from natural language** — *structured*
- **L1:** user fills the `RecurringRule` form by hand.
- **AI adds:** parses "every other day at 8am, plus Monday evenings" → correct structured rule fields (or `rrule` fallback).
- **Context:** NL phrase + `RecurringRule` schema + owner entity + timezone.
- **Tools:** `create_recurring_rule` — after confirm; validated against the engine schema, engine still owns materialization.
- **Risk:** bad parse can only propose, not corrupt scheduling.

**Reschedule / triage missed items** — *tool-calling*
- **L1:** each missed occurrence → manual skip or reschedule.
- **AI adds:** proposes where to reschedule a backlog into upcoming free slots in one pass (respecting anchors + quiet hours).
- **Context:** overdue/planned occurrences + upcoming free/busy map + constraints + priority/owner.
- **Tools:** `reschedule_occurrence`, `mark_occurrence_skipped` — per occurrence (editing one occurrence ≠ editing the rule).
- **Risk:** surfaces the plan before applying.

### Module 6 — Daily Review

**Living-text day summary (tone-aware)** — *one-shot*
- **L1:** computes the day score from completion + shows raw per-module totals.
- **AI adds:** human "how today went" narrative on top of the computed score, in the chosen tone, with 1–2 takeaways. The number stays deterministic.
- **Context:** computed day score + components + each module's ready-made daily totals + well-being inputs (energy/stress/mood, self-rating, journal) + tone.
- **Risk:** daily figures + optional journal text to external LLM; score never recomputed by AI, only narrated.

**Evening-ritual → tomorrow handoff** — *tool-calling*
- **L1:** review triggers Module 5's "plan tomorrow"; user plans manually.
- **AI adds:** reads today's misses/wins and pre-seeds tomorrow (carry an undone priority, suggest a light day after a hard workout).
- **Context:** today's completion summary + tomorrow's anchors + routine templates + goals near deadline + tone.
- **Tools:** delegates to Planner tools (`create_time_block`, `create_task`, `reschedule_occurrence`, `schedule_reminder`).
- **Risk:** proposals confirmed in the Planner, not auto-committed.

**Weekly meta-reflection** — *one-shot*
- **L1:** per-day scores + well-being metrics listed (trend lines are Analytics' job).
- **AI adds:** qualitative weekly recap with soft links ("scores dipped the two nights you slept <6h") — observation, not advice.
- **Context:** last 7 days of scores + well-being + key totals + any deterministic correlations Analytics flagged.
- **Risk:** ⚠️ multi-day mood/sleep/journal — most sensitive payload here; "noticed", never causal/medical claims.

### Module 7 — Storage

**Inbox triage** — *structured / tool-calling*
- **L1:** manual move-out-of-inbox; set type/project/tags/priority by hand.
- **AI adds:** reads captured text → proposes type (task/idea/purchase/list item) + project + tags + priority + optional deadline. Confirm or edit.
- **Context:** inbox item title + note + existing Projects/tags/Lists + active Goals (for idea→goal hints). Inbox items only.
- **Tools:** `triage_inbox_item(item_id, type, project_id?, tags[], priority?, scheduled_for?)` — reversible.
- **Risk:** note text (personal plans) to external LLM; flag in consent.

**Idea → project breakdown** — *tool-calling*
- **L1:** manual Project + hand-added child tasks/purchases via `parent_id`; blocker flags by hand.
- **AI adds:** expands a freeform idea into ordered child tasks + dependent purchases, marking blockers. "Chaos into action" in one step.
- **Context:** idea title + description + existing children (avoid dupes) + currency + linked Goal + parent→children convention.
- **Tools:** `create_project(name, from_idea_id)`, `create_task(parent_id, ...)`, `create_purchase(parent_id, ..., is_blocker)`. Backend enforces the blocker invariant.
- **Risk:** idea/plan text (personal/business) to external LLM.

**Project summary / next-action** — *one-shot* (+ optional tool)
- **L1:** module shows status counts, open blockers, completed-over-period.
- **AI adds:** narrative of project state + a suggested next action, grounded only in ready-made aggregates.
- **Context:** ready-made project aggregates + task/idea titles. Reads, doesn't recompute.
- **Tools:** optional `schedule_task(task_id, date)` → Planner, if user accepts.
- **Risk:** project contents to external LLM; flag for work/business context.

### Module 8 — Habits & Anti-habits

**Habit-stack & implementation-intention builder** — *structured*
- **L1:** manual habit + frequency rule + hand-typed "After X I do Y" anchor + time/place.
- **AI adds:** Atomic-Habits coaching — suggests a concrete stack onto a real routine step, an implementation intention, a 2-minute starter.
- **Context:** desired habit + target metric + existing routine templates (ordered steps = anchor candidates) + current habits (avoid conflicts) + existing time blocks.
- **Tools:** `create_habit`, `create_recurring_rule`, `link_habit_to_routine_step`, `schedule_reminder` — on confirm.
- **Risk:** coaching only, explicitly NOT therapy/medical; grounded in the user's real routines.

**Streak-recovery coaching** — *one-shot* (+ optional tool)
- **L1:** tracks current/best streak + % completion; break resets the counter.
- **AI adds:** on a break/wobble, a short get-back-on-track message in tone + an easier 2-minute version.
- **Context:** habit + streak history + completion % + current metric/target + tone.
- **Tools:** optional `update_habit` (lower to the 2-minute version) on confirm.
- **Risk:** encouragement only; harsh tone gated by setting.

**Stepped-limit reduction plan (anti-habit)** — *structured*
- **L1:** manual stepped ceiling array (1/day → 5/week → 3/week) + "must not exceed" enforcement.
- **AI adds:** proposes a realistic reduction ladder (step sizes + timing + unit change) from actual consumption logs; explains the taper.
- **Context:** anti-habit + mode + recent consumption history + current step + end state + tone.
- **Tools:** `set_stepped_limit_schedule` (writes the (value, deadline, status) array) on confirm.
- **Risk:** lifestyle coaching, not withdrawal/medical; for any substance the framing stays neutral-tracker; deterministic ceiling enforcement stays independent of AI.

### Module 9 — Analytics

> AI is a **view/insight layer**: it narrates correlations/trends the deterministic Level-1 layer already found; it does **not** recompute and must **not** invent links absent from the supplied data.

**Narrate deterministic correlations (headline insight)** — *structured → one-shot*
- **L1:** rule-based engine computes metric-pair correlations (sleep→energy, nutrition→weight, etc.) with strength + direction.
- **AI adds:** turns the computed correlation set into plain-language insight in tone.
- **Context:** the machine-readable correlation findings (pair, coefficient, direction, period, sample size) + referenced trend aggregates + tone + goals. **Not raw records.**
- **Risk:** aggregated health/well-being metrics to external LLM. **Anti-fabrication guard:** model explains ONLY pairs present in the supplied findings; strength/direction passed in, never inferred.

**Period comparison narrative** — *one-shot*
- **L1:** module computes period-over-period deltas per metric.
- **AI adds:** "what changed and what it likely means" grounded in the supplied deltas.
- **Context:** two periods' aggregates + computed deltas + goal context + tone. No raw rows.
- **Risk:** cross-module aggregates to external LLM (flag if finance included).

**Natural-language analytics Q&A** — *chat*
- **L1:** user reads charts/tables for the chosen period.
- **AI adds:** "why did my day-score drop last week?" answered from supplied aggregates, citing the figures used.
- **Context:** pre-fetched relevant aggregates (backend selects — RAG over ready-made totals).
- **Tools:** optional read-only `fetch_aggregate(metric, period)`.
- **Risk:** whatever metrics the question touches (possibly finance/health) leave to external LLM; user scopes what's shared.

### Module 10 — Finance ⚠️ SHARPEST PRIVACY

> Raw financial data is highly sensitive. Default: send **aggregates, not raw transactions**, wherever the insight allows. Receipt parsing is the one scenario that must send a raw image. All finance scenarios go to the **user's own** external LLM under explicit consent.

**Spending insights** — *one-shot*
- **L1:** deterministic spend analytics by category, budget warnings, period comparison, cash-flow figure.
- **AI adds:** living insight ("Food→Delivery is 2.1× last month and 40% over its limit; Groceries fell, so this is substitution") in tone.
- **Context:** **aggregates only** — per-category monthly totals, budget limits + actuals, deltas, cash-flow number. **Not individual transactions.**
- **Risk:** ⚠️ category-level spending leaves to external LLM; aggregates not rows, but reveals behavior; explicit per-scope consent.

**Receipt photo → transaction** — *vision → structured*
- **L1:** manual expense entry; optional receipt photo as proof.
- **AI adds:** parses a receipt image → draft transaction (total, currency, date, merchant, suggested category, optional line items); closes the purchase→expense link if it matches a wishlist purchase.
- **Context:** receipt image (base64 / Files-API `file_id` from the Attachment) + category tree + accounts + base currency.
- **Tools:** `create_transaction_draft(...)` — backend creates a draft; on confirm enforces "bought ⟺ transaction exists" for a linked purchase.
- **Risk:** ⚠️ **most exposing scenario** — raw receipt (merchant, exact amount, items, sometimes card tail/address) to external LLM. Strongest consent gate; per-receipt action only, never bulk.

**Savings & goal advice** — *one-shot / chat*
- **L1:** computes Saving Fund progress + required rate, Emergency Fund shortfall, debt repayment-date forecast.
- **AI adds:** narrates + advises ("on track for the garage fund by March; +1,500/mo hits the original deadline; Emergency Fund is 2 months short — prioritize that first").
- **Context:** **aggregates only** — fund targets/saved + rate, emergency shortfall + top-up rule, debt balances + forecasts, month's planned income + free cash flow. No raw transactions.
- **Tools:** optional `set_saving_fund_topup(fund_id, amount, recurring?)` on confirm; read-only by default.
- **Risk:** ⚠️ savings/debt aggregates (how much you have/owe/can save) to external LLM; explicit consent.

**Categorize / reconcile transactions** — *structured / tool-calling*
- **L1:** manual category assignment; defaults out of the box; uncategorized count is a module figure.
- **AI adds:** suggests a category for uncategorized transactions from merchant/note; flags likely miscategorizations — batch-assist, confirm.
- **Context:** per uncategorized transaction: **merchant/note + amount only** (no balances/history) + category tree.
- **Tools:** `categorize_transaction(transaction_id, category_id)` on confirm; reversible.
- **Risk:** ⚠️ per-transaction merchant + amount = **raw transaction data** (highest-sensitivity path after receipts); minimize fields, run only on the uncategorized subset, require consent.

---

## Open questions

1. Context-assembly service: how the backend selects which aggregates to feed per scenario (a `ContextBuilder` per module?).
2. Tool-call registry: the full set of backend-executed tools, their schemas, and which require confirmation vs auto-apply (default: all writes confirm).
3. Per-scenario consent model: global AI consent vs per-category vs per-action (receipts/photos likely per-action).
4. System-prompt guardrails: the neutral-monitor constraint (2a) and anti-fabrication constraint (Analytics) as enforced prompt sections.
5. Cost controls: token-budget surfacing to the user (BYOK), prompt-cache strategy for the stable system prompt.
6. Provider portability: scenarios are written against Claude API capabilities (vision, tool use, structured outputs) — confirm the BYOK abstraction degrades gracefully if a user's provider lacks one (e.g. no vision → photo scenarios disabled for that provider).
