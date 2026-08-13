# Implementation Plan: Workouts and Training Goals

**Feature ID**: `015-workouts-training-goals`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/openapi.yaml](contracts/openapi.yaml) · **Quickstart**: [quickstart.md](quickstart.md)

## Summary

Add a user-owned WorkoutProgram as the fourth recurring program owner and WorkoutSession as the fourth
mutually exclusive occurrence fact, using explicit class-table details for strength/endurance/timed
data. Deliver private exercise/program management, planned and manual session correction, deterministic
records/progression, typed training goals on the existing Goal aggregate, module-owned summaries, and
shared Planner/Notifications/Today/Review/web/Android integration. Existing Profile, recurrence,
Planner pull model, Goal lifecycle, notification pipeline, i18n, and mobile transport remain owners.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12.61; TypeScript 6 / Vue 3.5.

**Primary Dependencies**: Existing Eloquent/UserOwned, Carbon, Goal lifecycle, Profile timezone/units,
RecurringRule/PlannedOccurrence/materializer/reconcile, SchedulableSource/PlannerEntry, feature 011
notifications, Today/DailyReview module summaries, shared typed i18n/theme, Capacitor bundle.

**Storage**: MySQL 8 production target; SQLite test portability. One additive migration creates 12
feature tables and adds nullable unique `planned_occurrences.workout_session_id`.

**Testing**: PHPUnit schema/model/service/API/OpenAPI/ownership/compatibility/integration/query tests;
Pint; i18n guards; TypeScript/Vitest/build; Playwright desktop/mobile/locales/themes; mobile wrapper.

**Target Platform**: Responsive browser plus existing bundled Capacitor Android client. No native-only
Workout code or remote bundle is introduced.

**Performance Goals**: Program projection, selected-day summary, max-366-day statistics, progression,
records, and goal progress remain within fixed documented query budgets as row counts grow.

**Constraints**: Profile-local dates and optional wall times, UTC instants, decimal canonical kg,
integer metres/seconds, exact user ownership, closed subtype schemas, full EN/RU/UK, exact 390×844,
additive evolution, no medical advice/deployment/providers/live data.

**Scale/Scope**: Hundreds of programs/exercises, thousands of sessions, and tens of thousands of sets
per user. UI pages one bounded history/range at a time. Long rollups/charts wait for feature 023.

## Localisation Plan

**Message ownership**: `apps/web/src/i18n/locales/en.ts` remains canonical with simultaneous RU/UK.
`apps/api/lang/{en,ru,uk}` owns validation/domain/reminder copy. Stable built-in exercise keys map to
localized product labels; all custom names/notes remain user text.

**Runtime locale**: Existing Profile-authoritative locale, guest prepaint cache, English fallback, and
locale-reactive feedback/reminder delivery are reused without a Workout-specific store.

**Formatting**: Existing locale date/time/number/plural helpers plus shared unit preferences display
canonical kg/metres/seconds. Pace is formatted as minutes/seconds per kilometre. API stays canonical.

**Backend feedback**: Strict FormRequests/services use translated messages for subtype, schedule,
ownership-safe validation, progression, dates/DST, goals, and limits. Foreign IDs remain 404.

**Delivery gates**: Dictionary parity/blank/unknown/unused/hardcoded-copy; backend message assertions;
typecheck/build; EN/RU/UK desktop/mobile flows; light/dark visual inspection and overflow/console probes.

## Constitution and Architecture Gates

### Gate 1 — Specifications Before Implementation

- Spec, checklist, research, plan, data model, closed OpenAPI, quickstart, tasks, and analysis precede
  application files.
- Backend/browser tests are authored and observed failing on absent tables/routes/UI before production.

**Result**: Pass.

### Gate 2 — Ownership and Privacy

- All private roots/children repeat `user_id` and enforce same-owner relations.
- Public exercises are immutable reference rows; private custom entries never cross users.
- Every endpoint is Sanctum-authenticated, foreign IDs 404, account deletion cascades private rows.
- No provider, route, attachment, health export, deployment, or live data enters scope.

**Result**: Pass.

### Gate 3 — Shared Profile Inputs and Canonical Units

- Profile timezone resolves local dates/times; Profile units/locales format canonical API values.
- Kilograms/metres/seconds are stored exactly; energy/heart rate are explicit observations.
- Nutrition will consume stable planned/actual inputs later but cannot own or rewrite them.

**Result**: Pass.

### Gate 4 — Timezone and Date Handling

- Program rules expand in their captured Profile timezone; performed dates are local calendar dates.
- Optional wall times become UTC with DST-gap rejection and deterministic repeated-time behavior.
- Trailing consistency uses seven inclusive Profile-local dates; ranges are max 366 dates.

**Result**: Pass.

### Gate 5 — Recurrence and Planner Reuse

- WorkoutProgram adds one owner discriminator to existing recurrence; no new scheduler/table/window.
- Workout occurrence and race event sources implement Planner's pull contract; mutations route to
  Workout/Goal owners. Same-rule effective-date collisions are rejected.

**Result**: Pass.

### Gate 6 — Cross-Module Direction

- Workout owns facts, progression, records, summaries, and training-goal current values.
- Goal owns generic lifecycle only; Planner presents; Notifications delivers; Today transports;
  Review presents; Android bundles. No reverse writes or copied status/aggregate exist.

**Result**: Pass.

### Gate 7 — Additive Data Evolution

- Twelve new tables plus one nullable fact FK; existing rows/columns are never rewritten/dropped.
- Explicit rollback and identifier tests cover MySQL/SQLite, FK order, preservation, and account delete.
- Class-table complexity is required by canonical divergent fields and has current consumers.

**Result**: Pass.

### Gate 8 — Contracts and Verification

- Fifteen exact authenticated operations move through Laravel, OpenAPI 3.1, TypeScript, Vue, and tests.
- Closed requests and conditional domain evidence prevent subtype drift.
- Focused affected tests precede full backend/web/mobile gates.

**Result**: Pass.

### Gate 9 — Aggregate Ownership and Deterministic Core

- Set-query services calculate pace/volume/records/progression/goal progress/day/range summaries.
- No rollup, cached counter, inferred energy, LLM, or client-side authoritative calculation.
- Null means absent observation; values recalculate after correction/clear.

**Result**: Pass.

### Gate 10 — Localisation, Accessibility, Evidence, and Scope

- Every new string ships EN/RU/UK; user content is never translated.
- One responsive Vue implementation supports both schemes, keyboard/live/focus semantics, 44px targets,
  safe areas, exact 390×844, and Capacitor transport.
- Visual matrix, diff/secret/protected/handoff audit, GitNexus impact review, atomic commit/push required.

**Result**: Pass.

## Project Structure

### Documentation

```text
specs/015-workouts-training-goals/
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
├── database/migrations/2026_08_13_200000_create_workouts_and_training_goals.php
├── app/Models/{Exercise,WorkoutProgram,WorkoutProgramExercise,...,TrainingGoalDetail}.php
├── app/Http/Requests/*Workout*.php
├── app/Http/Controllers/{Exercise,WorkoutProgram,WorkoutSession,WorkoutStatistics,TrainingGoal}Controller.php
├── app/Http/Resources/{Exercise,WorkoutProgram,WorkoutSession,TrainingGoal}Resource.php
├── app/Services/{WorkoutProgramRecurrence,WorkoutSessionService,WorkoutProgressionService,
│   WorkoutStatisticsService,TrainingGoalProgressService}.php
├── app/Services/Planner/{WorkoutOccurrenceSource,TrainingGoalSource}.php
├── app/{Models,Services}/ existing recurrence/goal/notification/Today integration files
├── routes/api.php
└── tests/{Feature,Unit}/WorkoutsTrainingGoals/
```

### Web and bundled mobile

```text
apps/web/
├── src/views/{WorkoutsView,TodayView,ReviewView,PlannerView,NotificationsView}.vue
├── src/components/workouts/{ExerciseCatalogue,ProgramEditor,SessionEditor,TrainingGoalEditor}.vue
├── src/api/{types,client}.ts
├── src/{router.ts,style.css}
├── src/i18n/locales/{en,ru,uk}.ts
└── e2e/workouts-training-goals/workouts-training-goals-flow.spec.ts

apps/mobile/
└── existing shared web build + Android sync/validation only
```

**Structure Decision**: Extend the existing monorepo and owner services. No package, native module,
generic repository, scheduler, or second state store is added.

## Implementation Sequence

1. Complete Spec Kit artifacts; resolve analysis findings.
2. Author schema/model/domain/API/OpenAPI/integration/query and permanent Playwright tests; record red.
3. Add migration, models, same-owner guards, recurrence owner/fact dispatch, and catalogue/program roots.
4. Implement planned/manual session writes and exact subtype/child replacement.
5. Implement records, progression, statistics, typed training goals, and race events.
6. Integrate Planner actions, Notifications, Today/Review, routes/contracts/types.
7. Build accessible EN/RU/UK `/workouts` workspace and navigation/deep links.
8. Run focused/full/mobile/visual/audit gates, docs/changelog, Spec Kit re-analysis, memory, commit/push.

## Constitution Re-check After Design

| Principle | Result |
|---|---|
| Specifications before code | Pass — complete delivery artifacts and red-first tasks |
| Thin vertical slice | Pass — manual facts/program/progression/goal only; integrations/content/charts deferred |
| Deterministic core | Pass — pure folds and source queries; no AI/inferred energy |
| User ownership/privacy | Pass — private roots/children, immutable public catalogue, 404 boundary |
| Contracts/tests together | Pass — fifteen operations and all consumers have paired evidence |
| Complete localisation | Pass — full EN/RU/UK surface and automatic/browser gates |

## Complexity Tracking

| Deliberate complexity | Why needed | Simpler alternative rejected because |
|---|---|---|
| 12 feature tables | Canonical Workouts fields genuinely diverge; program/fact/children/goals each need exact ownership and constraints | Sparse table/JSON would lose conditional integrity, stable queries, and contract clarity |
| Nullable-owner Exercise | Durable public catalogue plus private custom entries are both current requirements | Frontend constants cannot own FKs/history; duplicating public rows per user wastes data and complicates updates |
| Two Planner sources | Repeating program occurrence and one-off race deadline have different owners/actions | Fake recurrence for races or copied Planner rows would duplicate truth |
