# Implementation Plan: Nutrition, Meals, Hydration, and Targets

**Feature ID**: `016-nutrition-meals-hydration-targets`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/openapi.yaml](contracts/openapi.yaml) · **Quickstart**: [quickstart.md](quickstart.md)

## Summary

Deliver a private food/recipe catalogue, correctable flexible meal facts, caloric beverage hydration,
and immutable per-day target snapshots derived from Profile, one selected body-mass goal, and explicit
planned Workout energy. Nutrition owns all daily/range aggregation; Today and Review only transport or
present its DTO. The slice uses thirteen authenticated operations and the shared EN/RU/UK web bundle.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12.61; TypeScript 6 / Vue 3.5.

**Primary Dependencies**: Eloquent/UserOwned, Profile BMR/activity/timezone, typed Body Goal detail,
WorkoutProgram/PlannedOccurrence/WorkoutSession, Today/DailyReview, shared i18n/theme, Capacitor bundle.

**Storage**: MySQL 8 production target; SQLite test portability. One additive migration creates seven
feature tables and adds nullable `workout_programs.planned_energy_kcal`.

**Testing**: PHPUnit schema/model/service/API/OpenAPI/ownership/compatibility/integration/query tests;
Pint; i18n/typecheck/Vitest/build; Playwright desktop/mobile/locales/themes; mobile wrapper validation.

**Target Platform**: Responsive browser and the existing bundled Capacitor Android client. No native
Nutrition store, provider, background worker, or remote bundle.

**Performance Goals**: Catalogue, selected-day meals/target/summary, and maximum 366-day aggregates use
a fixed documented query budget as meal-entry counts grow.

**Constraints**: Profile-local calendar dates, exact decimal nutrient snapshots, canonical grams/ml,
owner isolation, immutable target/history semantics, closed contracts, full EN/RU/UK, exact 390×844,
additive evolution, no medical advice/provider/deployment/live data.

**Scale/Scope**: Hundreds of private foods/recipes and tens of thousands of meal entries per user;
bounded day/range reads only. Long-term charts remain feature 022/023 work.

## Localisation Plan

**Message ownership**: `apps/web/src/i18n/locales/en.ts` remains canonical with simultaneous RU/UK;
`apps/api/lang/{en,ru,uk}` owns validation/domain feedback. The water system key maps to localized
product copy; private names/notes are never translated.

**Runtime locale**: Reuse Profile-authoritative locale, English fallback, and locale-reactive feedback.

**Formatting**: Existing locale date/number helpers format canonical API grams, millilitres, kcal, and
percentages. Input remains explicit; no locale-sensitive string becomes an authoritative number.

**Backend feedback**: Strict FormRequests/services translate closed-key, ownership-safe, date, AMDR,
catalogue, quantity, target-readiness, and range failures. Foreign IDs retain the 404 boundary.

**Delivery gates**: Dictionary parity/blank/unknown/unused/hardcoded-copy checks; backend localized
message assertions; EN/RU/UK desktop/mobile flows; both schemes; overflow, focus, console, and page-error
probes.

## Constitution and Architecture Gates

### Gate 1 — Specifications Before Implementation

- Complete spec, checklist, research, plan, model, closed OpenAPI, quickstart, tasks, and analysis first.
- Author permanent backend/browser tests and record intended missing-feature failures before production.

**Result**: Pass.

### Gate 2 — Ownership and Privacy

- Private roots and children repeat `user_id`; relation services enforce the same owner.
- Public plain water is immutable; private catalogue rows never cross accounts; every endpoint uses
  Sanctum and foreign IDs return 404.
- Account deletion cascades private rows while the global reference survives.

**Result**: Pass.

### Gate 3 — Immutable Facts and Targets

- Meal entries snapshot accepted nutrition, hydration, quality, label, and basis.
- Catalogue edits never rewrite history; an explicit meal correction intentionally rebuilds snapshots.
- A daily target is inserted once per user/date and is never updated; refinement remains derived.

**Result**: Pass.

### Gate 4 — Shared Profile and Body Goal Inputs

- Nutrition reads current Profile formula, anthropometrics, non-sport activity, timezone, and weight.
- Settings may select one active owned body-mass goal; Goal remains lifecycle owner.
- Missing inputs produce explicit readiness codes and nullable targets, not guessed values.

**Result**: Pass.

### Gate 5 — Workout Boundary and Double-count Prevention

- WorkoutProgram owns optional explicit planned energy; Nutrition reads effective occurrences once.
- Non-sport coefficients exclude training. No MET/heart-rate inference is introduced.
- End-of-day refinement swaps the planned component for explicit completed energy without persistence.

**Result**: Pass.

### Gate 6 — Canonical Units and Transparent Estimates

- Solids use grams; beverages use millilitres and an explicit hydration ratio.
- Mifflin/Katch, activity coefficients, the limited 7700-kcal/kg planning approximation, 4/9/4 macro
  conversion, caps/floors, and water heuristic are exposed in the target basis.
- Product copy labels estimates and avoids medical claims.

**Result**: Pass.

### Gate 7 — Cross-Module Direction

- Nutrition owns catalogue, recipes, meals, settings, targets, refinements, and summaries.
- Profile/Goal/Workout are read-only inputs; Today transports and Review presents the Nutrition DTO.
- Nutrition does not become a recurrence, Planner, notification, or Analytics owner.

**Result**: Pass.

### Gate 8 — Additive Evolution and Portability

- Seven new tables and one nullable Workout column are reversible in dependency order.
- Existing rows and columns are never rewritten/dropped; identifiers and decimal/json behavior are
  tested on SQLite and against MySQL naming limits.

**Result**: Pass.

### Gate 9 — Contracts and Deterministic Aggregation

- Thirteen exact authenticated operations align Laravel, OpenAPI 3.1, TypeScript, Vue, and tests.
- Requests are closed; outputs distinguish absent, zero, incomplete, and unavailable states.
- SQL/service aggregation is correction-safe, bounded, and has no stored mutable rollup.

**Result**: Pass.

### Gate 10 — Complete Clients, Evidence, and Scope

- One accessible responsive implementation covers full lifecycle, EN/RU/UK, light/dark, keyboard,
  live/focus semantics, 44px controls, safe areas, exact 390×844, and Android transport.
- Full regressions, visual evidence, protected-path/handoff audit, GitNexus review, atomic commit, and
  push close the feature. Providers/photos/medical advice/planning/AI/deployment stay deferred.

**Result**: Pass.

## Project Structure

### Documentation

```text
specs/016-nutrition-meals-hydration-targets/
├── spec.md
├── checklists/requirements.md
├── research.md
├── plan.md
├── data-model.md
├── contracts/openapi.yaml
├── quickstart.md
├── tasks.md
└── analysis.md
```

### API and persistence

```text
apps/api/
├── database/migrations/2026_08_13_220000_create_nutrition.php
├── app/Models/{FoodItem,Recipe,RecipeComponent,Meal,MealEntry,
│   NutritionSettings,NutritionDailyTarget}.php
├── app/Http/Requests/*Nutrition*.php
├── app/Http/Controllers/{FoodItem,Recipe,NutritionSettings,NutritionDay,Meal}Controller.php
├── app/Http/Resources/{FoodItem,Recipe,Meal,NutritionDay}Resource.php
├── app/Services/{FoodCatalogueService,RecipeNutritionService,RecipeService,MealService,
│   NutritionTargetService,NutritionSummaryService}.php
├── app/{Models,Services}/ existing Workout/Today integration files
├── routes/api.php
└── tests/{Feature,Unit}/Nutrition/
```

### Web and bundled mobile

```text
apps/web/
├── src/views/{NutritionView,TodayView,ReviewView}.vue
├── src/components/nutrition/{FoodEditor,RecipeEditor,MealEditor,TargetSettings}.vue
├── src/api/{types,client}.ts
├── src/{router.ts,style.css}
├── src/i18n/locales/{en,ru,uk}.ts
└── e2e/nutrition/nutrition-flow.spec.ts

apps/mobile/
└── existing shared web build + Android sync/validation only
```

**Structure Decision**: Extend existing owners and shared clients. No generic repository, new store,
native domain module, scheduler, provider adapter, or background aggregate is added.

## Implementation Sequence

1. Complete Spec Kit artifacts, closed schemas, and traceability; resolve analysis findings.
2. Author permanent schema/model/domain/API/OpenAPI/integration/query/browser tests and record RED.
3. Add migration, models, owner invariants, immutable water, and nullable Workout planned energy.
4. Implement catalogue and recipe derivation/lifecycle.
5. Implement transactional meal snapshots and deterministic daily/range aggregation.
6. Implement settings, target readiness/materialization, transparent breakdown, and refinement.
7. Integrate Today/Review/contracts/types and build full accessible `/nutrition` control surface.
8. Run focused/full/mobile/visual/audit gates, docs/changelog, analysis, memory, atomic commit/push.

## Constitution Re-check After Design

| Principle | Result |
|---|---|
| Specifications before code | Pass — design artifacts and explicit RED-first order |
| Thin vertical slice | Pass — input/reference/fact/target/summary only; broad content deferred |
| Deterministic core | Pass — source snapshots and pure/service calculations; no AI/inference |
| User ownership/privacy | Pass — private roots/children, immutable water, 404 boundary |
| Contracts/tests together | Pass — thirteen operations plus changed consumers have paired evidence |
| Complete localisation | Pass — full EN/RU/UK surface and automated/browser gates |
| Additive data model | Pass — seven tables and one nullable compatible column |
| Explicit ownership | Pass — Nutrition aggregates; shared modules consume without copying |
