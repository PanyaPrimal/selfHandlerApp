# Data Model: Nutrition, Meals, Hydration, and Targets

**Feature ID**: `016-nutrition-meals-hydration-targets`

One additive migration, `2026_08_13_220000_create_nutrition.php`, creates seven tables and adds one
nullable column to `workout_programs`. Existing migrations and data are not rewritten.

## `food_items`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| user_id | fk users nullable, cascade | null only for immutable public rows |
| system_key | string(64) nullable unique | `plain_water` seed only |
| name | string(160) | private name or stable built-in fallback |
| basis_unit | string(16) | `gram` or `millilitre` |
| is_beverage | boolean | must agree with basis |
| calories_per_100 | decimal(10,3) | non-negative |
| protein_per_100 | decimal(10,3) | grams |
| fat_per_100 | decimal(10,3) | grams |
| carbs_per_100 | decimal(10,3) | grams |
| quality_score | decimal(5,2) nullable | 0–100, solids only |
| hydration_ratio | decimal(5,4) | 0 for solid, 0–1 for beverage |
| is_archived / archived_at | boolean / timestamp nullable | private lifecycle |
| timestamps | | |

Private uniqueness `(user_id,name)` is application-enforced with archived rows retained. Index
`(user_id,is_archived,name)` serves catalogue reads. `plain_water` is exactly millilitre/true/zero
nutrients/null quality/one hydration and never mutates.

## `recipes`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| user_id | fk users, cascade | owner |
| name | string(160) | unique per owner among active rows |
| description | string(1000) nullable | |
| is_archived / archived_at | boolean / timestamp nullable | lifecycle |
| timestamps | | |

## `recipe_components`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| user_id | fk users, cascade | must equal recipe/private-food owner |
| recipe_id | fk recipes, cascade | |
| food_item_id | fk food_items, restrict | accessible solid food |
| sort_order | unsigned smallint | unique per recipe |
| quantity_grams | decimal(10,3) | positive |
| timestamps | | |

Unique `(recipe_id,sort_order)` and `(recipe_id,food_item_id)`.

## `meals`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| user_id | fk users, cascade | owner |
| consumed_on | date | Profile-local calendar day |
| name | string(160) | user content |
| category | string(24) nullable | breakfast/lunch/dinner/snack/custom |
| consumed_at_local | time nullable | wall time, no fake UTC instant |
| note | string(1000) nullable | |
| submission_key | uuid | unique `(user_id,submission_key)` retry identity |
| timestamps | | |

Index `(user_id,consumed_on,consumed_at_local,id)`.

## `meal_entries`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| user_id | fk users, cascade | must equal meal owner |
| meal_id | fk meals, cascade | |
| food_item_id | fk nullable, nullOnDelete | exactly xor recipe |
| recipe_id | fk nullable, nullOnDelete | exactly xor food |
| sort_order | unsigned smallint | unique per meal |
| reference_name | string(160) | immutable fact label snapshot |
| basis_unit | string(16) | gram/millilitre snapshot |
| quantity | decimal(10,3) | exact consumed quantity |
| calories | decimal(12,3) | calculated fact |
| protein_grams | decimal(12,3) | calculated fact |
| fat_grams | decimal(12,3) | calculated fact |
| carbs_grams | decimal(12,3) | calculated fact |
| hydration_ml | decimal(12,3) | calculated fact |
| quality_numerator | decimal(16,4) nullable | score × solid grams |
| quality_denominator | decimal(12,3) | solid grams or zero |
| timestamps | | |

Unique `(meal_id,sort_order)`. Service/model invariants enforce xor and matching basis. Snapshot
columns are source facts, not caches.

## `nutrition_settings`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| user_id | fk users unique, cascade | one per user |
| body_goal_id | fk goals nullable, nullOnDelete | active owned body-mass goal only |
| protein_percent | decimal(5,2) | default 20; 10–35 |
| fat_percent | decimal(5,2) | default 30; 20–35 |
| carbs_percent | decimal(5,2) | default 50; 45–65 |
| water_override_ml | unsigned smallint nullable | 1000–6000 |
| timestamps | | |

Percentages sum exactly 100 at the service/request/model boundaries.

## `nutrition_daily_targets`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| user_id | fk users, cascade | owner |
| target_date | date | unique with owner |
| status | string(16) | `ready` or `incomplete` |
| formula | string(32) | selected Profile formula snapshot |
| bmr_kcal | decimal(10,2) nullable | |
| baseline_kcal | decimal(10,2) nullable | BMR × non-sport coefficient |
| goal_adjustment_kcal | integer | raw/clamped details in basis |
| planned_workout_kcal | unsigned integer | explicit planned estimates once |
| calorie_target | unsigned integer nullable | floor at BMR |
| protein_target_grams | decimal(10,2) nullable | 4 kcal/g |
| fat_target_grams | decimal(10,2) nullable | 9 kcal/g |
| carbs_target_grams | decimal(10,2) nullable | 4 kcal/g |
| water_target_ml | unsigned smallint nullable | estimate or override |
| quality_target | decimal(5,2) | fixed 70 product threshold |
| calculation_basis | json | explanatory snapshot only; never filtered/aggregated |
| timestamps | | |

Unique `(user_id,target_date)`. Rows are immutable after insert. The JSON contains missing fields,
profile input values/updated-at, coefficients, settings/goal identity, raw/capped adjustments, planned
occurrence IDs, missing energy count, water rule and limitation codes.

## `workout_programs` additive column

`planned_energy_kcal` nullable unsigned integer after `planned_duration_seconds`, accepted range
1–100000. It is explicit user input and appears in Workout contracts/UI; it is not calculated by
Workouts or Nutrition.

## Derived Values

| Value | Owner | Rule |
|---|---|---|
| recipe per-100 | RecipeNutritionService | component totals ÷ total grams × 100 |
| meal entry snapshot | MealService | reference per-100 × quantity/100 |
| selected-day/range summary | NutritionSummaryService | SQL aggregate snapshot facts; quality numerator/denominator |
| stable target | NutritionTargetService | Profile BMR/activity + selected goal + effective planned energy; insert once |
| refinement | NutritionTargetService | reference target - planned energy + explicit completed energy; never stored |
| Today/Review DTO | NutritionSummaryService | same selected-day presentation payload |

## Ownership and Deletion

- All private tables use UserOwned and child-parent owner guards.
- Deleting the account cascades every private row; public water survives.
- Food/recipe lifecycle archives rather than deletes. FK `nullOnDelete` is a defensive historical
  fallback; entry snapshots remain complete.
- Meal deletion is intentional fact deletion and cascades its entries.
- Body goal deletion nulls settings only; daily target basis remains explanatory JSON.

## Rollback Order

Drop `nutrition_daily_targets`, `nutrition_settings`, `meal_entries`, `meals`,
`recipe_components`, `recipes`, `food_items`, then drop the nullable WorkoutProgram column. No prior
table or record is dropped.
