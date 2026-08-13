# Feature Specification: Nutrition, Meals, Hydration, and Targets

**Feature ID**: `016-nutrition-meals-hydration-targets`

**Created**: 2026-08-13

**Status**: Complete

**Input**: Deliver the non-deployment Nutrition vertical slice from the canonical design: private
food and recipe references, flexible meals, beverage hydration, stable daily calorie/macro/water/
quality targets derived from Profile, one selected body goal and planned workout energy, bounded
module-owned summaries, and the shared EN/RU/UK clients.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Maintain Foods and Composite Dishes (Priority: P1)

The user creates private foods with nutrition per 100 grams or millilitres, marks beverages and their
hydration contribution, and builds a composite recipe from ordered solid-food components. They can
correct or archive their own references while the immutable built-in plain-water reference remains
available.

**Why this priority**: meals need a trustworthy reference before an intake fact can be recorded.

**Independent Test**: Create one solid food, one caloric beverage, and a two-component recipe; verify
per-100 totals, owner isolation, edit/archive/restore, and immutable plain water.

**Acceptance Scenarios**:

1. **Given** the catalogue, **When** the user creates a food, **Then** calories, protein, fat and
   carbohydrate use exact per-100 values and quality stays within the accepted scale.
2. **Given** a beverage, **When** it is created, **Then** its basis is millilitres and its hydration
   ratio is explicit; a solid uses grams and cannot carry hydration.
3. **Given** a recipe, **When** its ordered component set is saved, **Then** nutrition per 100 grams is
   derived from component facts and a missing, beverage, archived-new, or foreign component rejects
   the complete write.
4. **Given** a public plain-water row, **When** a user tries to mutate it, **Then** the request returns
   the same not-found boundary as foreign private data.

---

### User Story 2 - Log and Correct Flexible Meals (Priority: P1)

The user creates a named/category meal or a free-form timed meal on a Profile-local calendar day,
adds atomic foods or composite recipes with quantities, and can replace, correct, or delete it.

**Why this priority**: correctable intake facts are the smallest independently useful nutrition loop.

**Independent Test**: Record breakfast with an atomic food and recipe, correct quantities/category/
time, reload, and delete it; exact daily totals and history follow every change.

**Acceptance Scenarios**:

1. **Given** accessible references, **When** a meal is saved, **Then** its ordered entries are replaced
   atomically and each entry stores an immutable calculated nutrition/quality/hydration snapshot.
2. **Given** a later food or recipe edit, **When** an old day is read, **Then** the consumed fact does
   not drift; explicitly editing the meal creates a fresh snapshot from the accepted reference.
3. **Given** a meal with no preset category, **When** it has a name and local time, **Then** it remains
   valid and ordered chronologically with categorized meals.
4. **Given** another account's meal/reference, **When** it is addressed, **Then** no existence signal
   or partial write is exposed.

---

### User Story 3 - Track Beverages and Hydration (Priority: P1)

The user logs plain water or another beverage by millilitres. Beverage calories/macros count toward
nutrition and its explicit hydration ratio contributes to hydration without pretending that every
drink is calorie-free.

**Why this priority**: the canonical design treats hydration and caloric drinks as one fact, not two
competing trackers.

**Independent Test**: Record water and a caloric drink, correct both volumes, and verify independent
calorie and hydration totals against the same meal facts.

**Acceptance Scenarios**:

1. **Given** 500 ml water, **When** recorded, **Then** hydration increases by 500 ml and calories stay
   zero.
2. **Given** 250 ml of a 0.8-ratio caloric beverage, **When** recorded, **Then** nutrition uses 2.5
   times its per-100 values and hydration increases by exactly 200 ml.
3. **Given** a solid food, **When** the client attempts millilitre or hydration fields, **Then** strict
   validation rejects the complete request.

---

### User Story 4 - Use One Stable Daily Target (Priority: P1)

The user selects an active owned body-mass goal, chooses an adult macro distribution, and sees a daily
target snapshot calculated from the authoritative Profile, the selected goal and explicitly planned
workout energy. The reference target never drifts when meals or actual workouts change.

**Why this priority**: a moving or silently guessed target makes every progress signal misleading.

**Independent Test**: With controlled Profile/body goal/workout inputs, create a day target, then add
meals, change Profile and record actual workout energy; the reference target stays byte-for-byte stable
while a separately labelled end-of-day refinement reflects actual energy.

**Acceptance Scenarios**:

1. **Given** a calculation-ready Profile, **When** the first day read occurs, **Then** one immutable
   snapshot uses the selected Mifflin-St Jeor or Katch-McArdle formula, baseline non-sport activity,
   selected body-goal adjustment, planned workout energy, macro distribution and water estimate.
2. **Given** an incomplete Profile, **When** the day is read, **Then** target state lists exact missing
   Profile fields and never fabricates calorie/macro numbers; meal logging remains usable.
3. **Given** a body goal with magnitude and deadline, **When** the modifier is calculated, **Then** the
   conventional energy-density estimate, cap/floor and limitation label are explicit in the breakdown.
4. **Given** a target already created, **When** Profile, goal, planned work, meals or actual workouts
   change, **Then** its reference numbers never change or duplicate.
5. **Given** explicit actual workout energy, **When** end-of-day refinement is requested, **Then** it
   replaces only the planned-workout component in a derived comparison and is never persisted as the
   daytime target.

---

### User Story 5 - Review Nutrition Progress and History (Priority: P2)

The user sees selected-day calories, macros, hydration and food-quality against the stable target, plus
bounded previous-day/week/month-ready daily aggregates computed by Nutrition.

**Why this priority**: Nutrition must own trustworthy summaries before Review or Analytics consumes
them.

**Independent Test**: Create controlled entries across several dates, correct and delete facts, and
verify exact daily/range totals, target percentages, empty states and a fixed query budget.

**Acceptance Scenarios**:

1. **Given** solid-food entries, **When** a day is read, **Then** quality is the quantity-weighted mean
   of non-beverage food snapshots; beverages do not dominate food quality.
2. **Given** zero intake or a missing target, **When** progress is shown, **Then** absence is distinct
   from zero consumed and from zero percent.
3. **Given** a bounded range up to 366 dates, **When** summarized, **Then** output is ordered per local
   date and remains query-bounded as entries grow.

---

### User Story 6 - Use Nutrition Across Current Clients (Priority: P3)

The user reaches Nutrition from desktop and mobile, uses it in EN/RU/UK and either scheme, and sees the
same selected-day summary on Today and Review without copied ownership.

**Why this priority**: a module is complete only when its facts participate in the existing daily loop.

**Independent Test**: Follow a Nutrition deep link, record intake, and verify Nutrition/Today/Review
agreement, rollback, keyboard use, exact 390x844 layout, Android sync and all locale/theme states.

**Acceptance Scenarios**:

1. **Given** a saved day, **When** Today and Review load it, **Then** both present the exact
   Nutrition-owned summary DTO and neither persists a duplicate aggregate.
2. **Given** a rejected save, **When** the response arrives, **Then** the accepted state is restored,
   the draft remains recoverable and no false success is announced.
3. **Given** EN, RU or UK, **When** the route, feedback and ARIA copy render, **Then** all product text
   localizes while names/notes remain unchanged and the page has no mobile overflow.

## Edge Cases

- A recipe cannot contain itself, another recipe, a beverage, an archived-new or foreign food.
- A recipe must have positive total component mass and unique ordered food rows.
- Exactly one of `food_item_id` and `recipe_id` identifies a meal entry.
- Quantities are positive exact decimals; grams and millilitres never silently convert.
- Calories may differ from the energy implied by macros because the catalogue value is authoritative.
- A food/recipe edit never rewrites historical snapshots; a meal correction deliberately does.
- Only one selected active, nonarchived body-mass goal may inform settings; completing/archiving it
  leaves existing daily targets intact and future targets without that modifier until settings change.
- Multiple reads of one day cannot create duplicate targets under concurrent requests.
- Future dates may expose planned targets but cannot contain consumed meals.
- Planned energy is explicit optional input on WorkoutProgram; missing energy is zero and visible in
  the target breakdown, never guessed from METs.
- Actual refinement sums only explicit completed workout energy; missing actual energy is reported.
- Katch-McArdle cannot calculate without body-fat percentage; Mifflin cannot use unspecified sex.
- A goal deadline today/past, impossible direction, or non-mass goal contributes no modifier and an
  explanatory code rather than division by zero.
- Range reads over 366 dates, reversed ranges and dates outside accepted bounds reject atomically.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Food references MUST be either immutable public built-ins or private user-owned rows;
  visibility is exactly public-or-owned and mutations require ownership.
- **FR-002**: Food MUST store a gram or millilitre basis plus exact calories/protein/fat/carbohydrate
  per 100 units, an optional 0–100 food-quality score, beverage flag, and explicit 0–1 hydration ratio.
- **FR-003**: Solid food MUST use grams and zero hydration; beverage MUST use millilitres and may carry
  calories/macros and hydration. The built-in plain-water reference MUST be immutable and exact zero/
  zero/one.
- **FR-004**: Private food lifecycle MUST support create, edit, archive and restore; archived rows stay
  readable through existing facts/recipes but cannot enter a new recipe/meal.
- **FR-005**: A recipe MUST be private, mass-based, and contain an atomically replaceable ordered set
  of unique accessible solid-food components with positive gram quantities.
- **FR-006**: Recipe per-100 nutrition and quality MUST be derived deterministically from current
  components at read/new-fact time and MUST NOT be stored as an independent editable truth.
- **FR-007**: Recipe lifecycle MUST support create, edit, archive and restore while referenced history
  remains readable.
- **FR-008**: A meal MUST be user-owned and carry one Profile-local `YYYY-MM-DD` consumed date, a name,
  optional preset category and optional local time; category and time MUST remain independent.
- **FR-009**: Meal entries MUST atomically replace as an ordered set and identify exactly one accessible
  food or recipe with a positive quantity in its matching canonical unit.
- **FR-010**: Each accepted meal entry MUST snapshot its calculated calories, protein, fat,
  carbohydrate, hydration, quality numerator/denominator, reference label and basis so history cannot
  drift when catalogue/recipe rows change.
- **FR-011**: Meal create/update/delete MUST be transactional, correctable, idempotent against duplicate
  submission tokens, and owner-scoped with no partial child facts.
- **FR-012**: Future meal dates MUST reject; correcting/deleting a fact MUST immediately change every
  derived Nutrition summary and no other module fact.
- **FR-013**: Nutrition settings MUST be one row per user and MAY select one active owned body-mass
  goal, configure protein/fat/carbohydrate percentages and an optional explicit water override.
- **FR-014**: Macro percentages MUST total exactly 100 and remain inside the adult AMDR boundaries:
  protein 10–35%, fat 20–35%, carbohydrate 45–65%.
- **FR-015**: Target calculation MUST read Profile inputs rather than copy them and use Profile's BMR
  formula; incomplete inputs MUST return exact missing fields with nullable calorie/macro targets.
- **FR-016**: Baseline energy MUST apply documented non-sport activity coefficients to BMR; sport MUST
  not be included in those coefficients or counted twice.
- **FR-017**: WorkoutProgram MAY add one nullable explicit `planned_energy_kcal`; the selected day's
  materialized effective planned occurrences contribute those values once to the target.
- **FR-018**: A valid selected body-mass goal MUST contribute a signed magnitude/deadline adjustment
  using the documented conventional 7700-kcal/kg planning approximation; the response MUST label its
  limitations and expose all caps/floors.
- **FR-019**: Macro gram targets MUST be derived once from the accepted calorie target and percentages
  using 4 kcal/g protein, 4 kcal/g carbohydrate and 9 kcal/g fat.
- **FR-020**: The default water estimate MUST use Profile weight with a documented product coefficient,
  bound and planned-duration addition; an explicit settings override MUST take precedence.
- **FR-021**: The first read of a user/date MUST create at most one immutable daily target snapshot;
  later Profile/goal/settings/planned/actual/intake changes MUST NOT alter it.
- **FR-022**: Daily target creation MUST be concurrency-safe and snapshot enough calculation basis to
  explain the result without copying authoritative Profile/Goal/Workout lifecycle state.
- **FR-023**: End-of-day refinement MUST be a separately labelled derived comparison using explicit
  completed workout energy; it MUST NOT update the reference target and MUST report missing energy.
- **FR-024**: Nutrition MUST compute selected-day calories, macros, hydration, non-beverage
  quantity-weighted quality, and progress against available targets exclusively from meal snapshots.
- **FR-025**: Nutrition MUST expose bounded ordered per-day aggregates for up to 366 dates and own all
  aggregation/query logic consumed by later Analytics.
- **FR-026**: Empty intake, incomplete target, zero consumed, unavailable quality and unavailable
  refinement MUST be distinct machine-readable/presented states.
- **FR-027**: Today `module_summaries` MUST add one backward-compatible Nutrition DTO and Review MUST
  present that DTO without persisting or recomputing it.
- **FR-028**: Nutrition MUST NOT become a recurrence/Planner source; it may read planned workout
  occurrences but MUST NOT create or mutate them.
- **FR-029**: API operations MUST use Sanctum, strict closed request keys, eager/bounded loading,
  authenticated owner-derived IDs, consistent 404 isolation, and exact OpenAPI 3.1/TypeScript parity.
- **FR-030**: `/nutrition` MUST expose full food/recipe/meal/settings lifecycle, daily target breakdown,
  progress and recent summaries using the owned control layer.
- **FR-031**: All product copy, validation, feedback, empty/error/loading states and ARIA text MUST ship
  together in EN/RU/UK with locale-aware dates/numbers/units and untranslated user content.
- **FR-032**: Desktop and exact 390x844 layouts MUST work in light/dark schemes, keyboard and screen
  reader use, 44px touch targets, safe areas and no horizontal overflow.
- **FR-033**: The shared web implementation MUST synchronize into the existing Android Capacitor shell
  without native Nutrition ownership or offline divergence.
- **FR-034**: The feature MUST update contracts, changelog, roadmap/design docs and module ownership
  documentation while leaving deployment and the user handoff untouched.
- **FR-035**: Photo/receipt recognition, barcode/OpenFoodFacts/provider import, attachment uploads,
  meal planning/recurrence, notifications, micronutrients, medical advice and AI MUST remain deferred.

### Key Entities

- **FoodItem**: immutable public or private reference with exact nutrients per 100 grams/millilitres.
- **Recipe / RecipeComponent**: private composite solid dish and ordered food quantities; derived
  nutrition is not an editable stored truth.
- **Meal / MealEntry**: correctable daily intake root and immutable per-entry nutrition snapshot.
- **NutritionSettings**: selected body-goal link, macro distribution and optional water override.
- **NutritionDailyTarget**: immutable user/date calculation snapshot and explanatory basis.
- **NutritionDailySummary**: non-persisted module-owned aggregate from MealEntry snapshots.

## Success Criteria *(mandatory)*

- **SC-001**: Two accounts expose zero foods/recipes/meals/settings/targets belonging to each other.
- **SC-002**: Known atomic, recipe and beverage fixtures produce exact calories/macros/hydration and
  quality before and after correction, catalogue edits, reload and deletion.
- **SC-003**: Concurrent first reads produce exactly one byte-stable daily target snapshot.
- **SC-004**: Controlled Mifflin and Katch fixtures match hand calculations at the documented rounding
  boundary; incomplete Profile fixtures never receive invented target values.
- **SC-005**: A target remains unchanged after intake, Profile, goal, planned and actual workout
  changes; the separate refinement changes only when explicit actual workout energy changes.
- **SC-006**: Daily and 366-day aggregate query counts remain bounded as meals/entries grow.
- **SC-007**: Nutrition, Today and Review return the same selected-day aggregate for every tested fact
  lifecycle transition.
- **SC-008**: All 13 authenticated operations match registered routes, closed OpenAPI schemas and
  TypeScript consumers with zero undocumented field.
- **SC-009**: EN/RU/UK light/dark desktop/mobile screenshots, keyboard, ARIA and overflow probes reveal
  no untranslated product copy, clipping, collision or runtime error.
- **SC-010**: Focused and full Laravel, Pint, i18n, typecheck, Vitest, build, Playwright, mobile Node and
  Capacitor validation gates pass with exact evidence recorded before commit.

## Assumptions

- Users are adults; the macro distribution validation is not a paediatric, pregnancy or clinical
  prescription and the interface labels all computed targets as estimates.
- Profile baseline weight is the calculation input; measurement history does not silently replace it.
- Calories supplied for a food are authoritative even if they differ from macro-derived energy.
- Planned workout energy is optional explicit user input, avoiding unverifiable automatic MET guesses.
- The conventional goal adjustment is an explainable planning approximation, not a weight prediction.
- Plain water is the only seeded reference; users provide facts for other foods and beverages.

## Dependencies

- `003-multi-user-auth` ownership and Sanctum boundary.
- `004-profile-settings` formula/anthropometric/non-sport inputs and local date/time.
- `005-interface-foundation` controls and responsive shell.
- `007-body-measurements` typed body-mass goals.
- `010-interface-personalization` EN/RU/UK and theme foundation.
- `012-android-capacitor-shell` shared bundle.
- `015-workouts-training-goals` planned occurrences and explicit actual endurance energy.

## Explicit Deferrals

- Deployment and every feature-002/live operation.
- Food/meal photos, attachments, OCR, barcode/provider/OpenFoodFacts import and photo recognition.
- Medical or therapeutic calorie/macro/hydration advice and paediatric/pregnancy/condition formulas.
- Micronutrients, allergens, branded/commercial food datasets and licensed meal plans.
- Meal planning, grocery lists, recurrence and nutrition reminders.
- Long-period charts/correlations (022), export/report files (023), and AI (026).
