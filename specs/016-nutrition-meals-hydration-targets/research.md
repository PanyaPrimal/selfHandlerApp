# Research: Nutrition, Meals, Hydration, and Targets

**Feature ID**: `016-nutrition-meals-hydration-targets` · **Date**: 2026-08-13

## R1 — What to port from `calorie-tracker`

The referenced public repository models `Food`, `Recipe`, `RecipeItem`, `IntakeLogEntry`, `Plan`, and
per-day totals. Its useful evidence is the distinction between atomic foods and composite recipes,
per-100 nutrition, quantity-scaled intake, and a compact daily progress surface:

- https://github.com/Podvodila/calorie-tracker/blob/master/src/db/types.ts
- https://github.com/Podvodila/calorie-tracker/blob/master/src/composables/useIntakeLog.ts
- https://github.com/Podvodila/calorie-tracker/blob/master/src/composables/useRecipes.ts

**Decision**: preserve those product concepts but not the Dexie database, fixed four meal groups,
mutable recalculation from current references, external food provider, image fields or default numeric
plan. Laravel/MySQL remains authoritative; categories/time are independent; every consumed entry
snapshots its computed facts so editing a reference cannot rewrite history. No source code or dataset is
copied.

## R2 — Relational shape and immutable history

Food and recipes are mutable references, while consumption is a fact. Recomputing an old intake from
today's edited food/recipe would change history without a correction. Preventing reference edits after
first use would make a catalogue unusable.

**Decision**: `meal_entries` keeps nullable reference IDs for navigation and exact snapshot columns for
label, basis, quantity, calories, protein, fat, carbohydrates, hydration and quality weights. Meal
correction atomically rebuilds snapshots from currently accepted references; ordinary catalogue edits
do not. Recipe nutrition remains derived from current ordered components and is snapshotted only when
consumed.

## R3 — Food and beverage basis

Canonical storage uses grams for mass and millilitres for volume. Treating 250 ml of cola as 250 g is
an implicit density assumption; keeping a separate water table would double-count caloric beverages.

**Decision**: each FoodItem declares `basis_unit = gram|millilitre`. A beverage is millilitre-based and
has an explicit hydration ratio `0..1`; a solid is gram-based and has ratio zero. Nutrition is per 100
matching units. Plain water is the only public seed: zero nutrition, ratio one, immutable. A recipe is
solid and its components are gram-based FoodItems only.

## R4 — BMR formulas and readiness

Profile already owns the user's formula and exact required fields. Mifflin et al. derived the
Mifflin-St Jeor resting energy equation from 498 healthy adults:
https://pubmed.ncbi.nlm.nih.gov/2305711/. Katch-McArdle uses lean body mass and therefore requires the
body-fat value already enforced by Profile.

**Decision**: Nutrition implements the selected formula in one deterministic calculator and relies on
`UserProfile::missingCalculationFields()` rather than inventing defaults. Mifflin uses age on the target
date, kg, cm and the sex constant; Katch uses `370 + 21.6 × lean_mass_kg`. Output is rounded once at the
daily target boundary. These are estimates, not measured metabolism.

## R5 — Baseline activity without workout double counting

Profile explicitly defines `baseline_activity` as non-sport activity. Standard activity multipliers
often include exercise frequency, so reusing them and then adding workouts would double count.

**Decision**: document product coefficients narrowly as non-sport estimates: sedentary `1.20`, light
`1.30`, moderate `1.40`, high `1.50`. Planned sport is a separate line and comes only from nullable
explicit `WorkoutProgram.planned_energy_kcal`. The target never guesses MET energy. Missing estimates
contribute zero and remain visible in the basis.

## R6 — Body-goal magnitude and the 7700 approximation

The canonical design requires goal magnitude and deadline to affect the target. The common 7700
kcal/kg rule is not a fixed physiological law; research documents that weight-change energy content is
dynamic and that the rule becomes inaccurate over time:
https://pmc.ncbi.nlm.nih.gov/articles/PMC3810417/.

**Decision**: use it only as a transparent planning approximation because a simple deterministic L1
feature is required. The signed adjustment is `(target-start kg × 7700) / remaining days`, consistent
with goal direction, clamped to `[-1000,+1000] kcal/day`; final calories never fall below the computed
BMR. The response stores the raw/clamped adjustment, goal identity and limitation code. A past/today
deadline, non-mass goal, inactive/archived/foreign goal or inconsistent direction contributes zero.
The existing SafePace warning remains authoritative and is not duplicated.

## R7 — Macro distribution

The National Academies adult Acceptable Macronutrient Distribution Ranges are protein 10–35%, fat
20–35%, and carbohydrate 45–65% of energy:
https://www.nationalacademies.org/cdn/materials/9fb9fae6-337c-4b7c-9821-2c81d1f65ad0.

**Decision**: one NutritionSettings row stores percentages within those ranges and exactly totaling
100; defaults are 20/30/50. Grams derive using the conventional energy factors 4/9/4. Users can choose
another accepted adult distribution. This is a planning split, not individualized dietary advice.

## R8 — Hydration estimate

The National Academies explicitly states that water needs vary with environment and activity and no
single intake level ensures hydration for everyone. It reports adequate *total-water* intakes and says
beverages and food both contribute:
https://www.nationalacademies.org/read/10925/chapter/6.

The design nevertheless requires a weight/activity planning target. **Decision**: label this as a
product estimate, never guidance: `30 ml × profile kg`, clamped to 1500–4000 ml, plus `350 ml` per hour
of planned workout duration, with a final 5000 ml cap. A user may replace it with an explicit
1000–6000 ml override. Hydration progress includes only logged beverage contribution, not unrecorded
food moisture. The response exposes coefficient, additions, caps and limitation copy.

## R9 — Stable daytime target and end-of-day refinement

Recomputing after meals, a changed Profile or a completed workout creates a moving reference and makes
adherence impossible to interpret.

**Decision**: the first authenticated day read transactionally creates one
`nutrition_daily_targets(user_id,target_date)` snapshot. It is immutable. Daytime progress always uses
it. A separate derived `refinement` replaces the snapshot's planned workout energy with summed
explicit completed endurance energy and reports how many completed sessions lack energy. It writes no
target row and cannot change meal totals.

## R10 — Dynamic meals without recurrence

Meals in the canonical module are facts structured by user-selected category and/or time. The roadmap
does not yet require meal plans or reminders.

**Decision**: meals are plain local-date facts, not RecurringRule owners and not Planner sources.
Nutrition may read Workout planned occurrences but never writes them. Meal planning, grocery lists,
recurring meals and notifications remain explicit deferrals.

## R11 — Aggregates and shared daily surfaces

Nutrition owns daily/week/month totals; Analytics later consumes ready-made module results. Today and
Review already transport module-owned DTOs for sleep/workouts.

**Decision**: NutritionDailySummaryService returns selected-day and max-366-day rows using snapshot
facts and fixed-query eager aggregation. Today adds it under `module_summaries.nutrition`; Review
passes through the same Today response. No aggregate or daily-review copy is persisted.

## R12 — Ownership, privacy, and reference lifecycle

Nutrition and body data are sensitive. Public catalogues may be visible but private rows/facts must
not leak.

**Decision**: every private root and child carries `user_id` plus UserOwned owner-change guards;
children verify their parent/reference owner. Public rows have null owner and are immutable. Foreign
IDs resolve to the same 404 as missing IDs. Archival preserves facts and existing references but
blocks new selection.

## Architecture Gate Summary

| Gate | Decision |
|---|---|
| Ownership | Nutrition owns foods/recipes/meals/settings/targets/summaries; Profile/Goal/Workout remain authoritative inputs |
| Shared Profile inputs | Read formula, anthropometrics, non-sport activity and time zone; never copy current state |
| Time zone/date | Meals and targets use Profile-local `YYYY-MM-DD`; time remains a local wall time |
| Recurrence | Read effective Workout occurrences only; Nutrition is not a recurrence owner |
| Cross-module direction | Profile/Body Goal/Workout → Nutrition target; Nutrition summary → Today/Review/Analytics |
| Additive evolution | Seven new tables plus one nullable WorkoutProgram energy column; reversible migration |
| Contracts | 13 Sanctum operations, OpenAPI 3.1, strict requests, exact TS consumers |
| Aggregate ownership | Nutrition computes daily/range values; consumers present them |
| Privacy | UserOwned roots/children, global immutable water, 404 isolation, no providers |
| Deferrals | Photos/providers/medical advice/micronutrients/planning/reminders/charts/export/AI/deployment |
