# Implementation Plan: Habits and Anti-Habits

**Feature ID**: `013-habits-anti-habits`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/openapi.yaml](contracts/openapi.yaml) · **Quickstart**: [quickstart.md](quickstart.md)

## Summary

Add a first-class Habits module that reuses the shared recurrence engine for planned opportunities,
stores one explicit mode-aware result per scheduled local date, computes streaks/progress/limits next
to those facts, and projects occurrences into Planner and the existing notification pipeline. Ordinary
habits support yes/no and numeric targets; anti-habits support explicit abstinence and a separate
ordered stepped-ceiling plan. A responsive EN/RU/UK Vue surface owns creation, check-ins, contextual
routine/goal links, statistics, and lifecycle actions.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12.61; TypeScript 6 / Vue 3.5.

**Primary Dependencies**: Existing Eloquent/UserOwned, Carbon, RecurringRule/PlannedOccurrence,
SchedulableSource/PlannerEntry, feature 011 notification services, existing owned Vue controls and i18n.

**Storage**: MySQL 8 production target; SQLite for portable automated tests. One additive migration
creates `habits`, `habit_logs`, and `habit_limit_steps`, adds `habit_log_id` to
`planned_occurrences`, and leaves all existing rows intact.

**Testing**: PHPUnit model/service/API/schema/OpenAPI tests, migration preservation, Pint, i18n static
guards, TypeScript typecheck, Vite build, affected and full Playwright desktop/mobile projects.

**Target Platform**: Responsive browser and the existing bundled Capacitor Android client; no new
native code is required.

**Performance Goals**: Habit index and statistics use bounded set queries rather than per-card
queries; one recurrence materialization remains a bounded read/upsert/delete transaction; a user's
ordinary habit list and 90-day planned window respond within existing interactive API expectations.

**Constraints**: UTC instants plus explicit user-local dates, exact user ownership, deterministic
aggregates, decimal precision to 3 places, complete EN/RU/UK, 390×844 with no horizontal overflow,
no deployment changes, no long-period rollup or AI dependency.

**Scale/Scope**: Hundreds of definitions and years of retained occurrences/logs per user; UI shows one
lifecycle segment and selected local day at a time. Long-period analytics remains feature 023.

## Localisation Plan

**Message ownership**: `apps/web/src/i18n/locales/en.ts` remains the canonical frontend key set with
matching RU/UK catalogs. `apps/api/lang/{en,ru,uk}/messages.php` owns validation/domain and notification
copy. User-entered names, units, descriptions, places, starters, routine names, and goal names are not
translated.

**Runtime locale**: Existing profile locale and prepaint reconciliation remain unchanged. The API
transport already sends `Accept-Language`; Laravel renders request errors and feature 011 renders
persisted reminder copy in the owner's current profile locale.

**Formatting**: Existing i18n helpers/Intl format user-local dates, decimal values, percentages, and
plural streak/opportunity units. Persisted decimals use dot notation in JSON and are formatted only at
the UI boundary. Monday-based weekly-period semantics do not change with presentation locale.

**Backend feedback**: Dedicated requests use catalog keys for unexpected fields, incompatible modes,
missing planned occurrences, locked identity fields, invalid limit ladders, and lifecycle/link errors.
The normal 422 `{message, errors}` contract and owner-scoped 404 boundary remain unchanged.

**Delivery gates**: Dictionary parity/blank/unknown/unused/hardcoded-copy checks, backend EN/RU/UK
message assertions, typecheck/build, and Playwright flows in all locales plus desktop and exact mobile.

## Project Structure

```text
apps/api/
├── app/Models/{Habit,HabitLog,HabitLimitStep}.php
├── app/Http/Controllers/{HabitController,HabitLogController,HabitStatisticsController}.php
├── app/Http/Requests/{StoreHabitRequest,UpdateHabitRequest,UpsertHabitLogRequest,ReplaceHabitLimitStepsRequest}.php
├── app/Http/Resources/HabitResource.php
├── app/Services/
│   ├── HabitRecurrence.php
│   ├── HabitLogService.php
│   ├── HabitStatisticsService.php
│   ├── HabitLimitService.php
│   └── Planner/HabitOccurrenceSource.php
├── database/migrations/*_create_habits.php
└── tests/{Feature/Habits,Unit/Habits}/

apps/web/
├── src/views/HabitsView.vue
├── src/api/{types,client}.ts
├── src/i18n/locales/{en,ru,uk}.ts
└── e2e/habits/habits-flow.spec.ts
```

Shared files change narrowly: `RecurringRule` gains owner constant/relation support;
`RecurrenceMaterializer` dispatches lifecycle by owner type and preserves either fact link;
`PlannedOccurrence` gains the habit fact relation; Planner/notification registries gain one consumer;
notification settings gain a backwards-compatible JSON category default.

## Architecture Gate Answers

1. **Owner**: Habits owns definitions, mode/lifecycle, explicit logs, limit steps, success semantics,
   streaks, percentages, numeric totals, and limit status. Recurrence owns planned instances and
   reschedule intent. Planner owns only aggregation/time blocks. Notifications owns delivery state.
   Routine and Goal own only their own records; `habits` stores optional outbound links.
2. **Inputs**: Profile remains the sole source for timezone and locale. No habit-level timezone,
   language, unit-system, or week preference is copied. A unit label is domain/user content for the
   particular numeric habit, not a Profile display-unit setting.
3. **Time**: `occurred_at` is persisted in UTC; `log_date`, rule bounds, step effective dates, and
   selected statistic periods are calendar dates interpreted in `User::calendarTimezone()`. DST parsing
   occurs at the request boundary. An open current local day is not an ended missed opportunity.
4. **Scheduling**: Every habit owns one existing `RecurringRule` with owner type `habit`; existing
   weekdays and `PlannedOccurrence` materialization are reused. No local frequency, due-date projection,
   or duplicate occurrence status is introduced.
5. **Cross-module links**: `habits.routine_id` and `habits.goal_id` are the only authoritative link
   directions, nullable on target deletion and validated as active + owned on write. Planner consumes
   `HabitOccurrenceSource`; notifications consume the same occurrence id. All reads are projections and
   all retries are idempotent.
6. **Evolution**: One additive migration creates new tables and one nullable FK/index on an existing
   table. Existing occurrence rows receive null automatically. Rollback removes only the new FK/column
   and new tables. Migration/schema tests preserve existing user/routine/goal/profile/notification rows,
   check MySQL-safe identifiers, cascades, null-on-delete links, and unique keys.
7. **Contracts**: Laravel routes/controllers/request/resource tests, standalone OpenAPI 3.1, TypeScript
   types/client functions, Habits/Planner/Notification consumers, changelog, and design docs move in the
   same commit. A route/OpenAPI test rejects drift.
8. **Aggregates**: `HabitStatisticsService` derives success, current/best streak, completion percentage,
   and metric sum from occurrences/logs; `HabitLimitService` derives active step/period consumption.
   Review and Analytics receive no copied calculation in 013 and will consume these module results later.
9. **Privacy**: All three new tables carry immutable `user_id`; parent/target/model guards reject mixed
   ownership, nested controllers start from the authenticated owner's habit, and failures use 404 for
   foreign ids. No external provider, attachment, secret, medical inference, or cross-user surface exists.
10. **Deferral**: Floating N-times/week recurrence, configurable week start, routine-step stacking,
    templates/tags, Review score, daily rollups/correlations, offline queues, push/FCM, AI coaching, and
    medical/withdrawal advice remain out of scope. Extraction is triggered only by its named future
    feature or a second implemented consumer requiring a genuinely shared abstraction.

## Implementation Sequence

### Phase A — Failing contracts

Add migration/model invariant tests, recurrence/materializer/reconcile/aggregate/limit unit tests,
owner-scoped API/OpenAPI/locale tests, and Playwright journeys. Run focused suites and record the
expected pre-implementation failures.

### Phase B — Domain and recurrence

Create additive schema/models, dedicated validators and transactional services. Extend recurrence's
owner dispatch and generic fact preservation, implement mode-aware log synchronization and aggregate
calculation, then make all focused domain/schema tests pass.

### Phase C — API and cross-module adapters

Add index/create/update/log/clear/statistics/limit-plan routes and explicit resources. Register the
Habit Planner source, enable generic occurrence rescheduling, and add the backwards-compatible habit
notification category/type/copy/disposition. Make route/OpenAPI and integration tests pass.

### Phase D — Responsive product surface

Add typed client contracts, route/navigation, Habits selected-day/check-in/statistics/create-edit/
lifecycle UI, reusable owned controls, error rollback, and complete EN/RU/UK messages. Update Planner
source labels/actions and notification settings copy.

### Phase E — Reconciliation and closure

Run focused then full Laravel/Pint, i18n/typecheck/build, affected and full Playwright desktop/mobile,
OpenAPI-route parity, migration/identifier checks, secret/protected-path/status audit, and visual review.
Update changelog, architecture/design/roadmap, README, tasks/analysis, durable memory; stage only 013,
commit atomically, push current `master`, verify `HEAD == origin/master`, and retain the handoff folder.

## Constitution Check

| Principle | Evidence | Result |
|---|---|---|
| I | Spec/checklist/research/model/contract/plan/tasks/analyze precede product code | Pass |
| II | Canonical module/recurrence/notification/roadmap decisions are linked and not redefined | Pass |
| III | One complete Habits vertical slice; analytics/AI/templates/shared quota remain deferred | Pass |
| IV | Success, streaks, limits, and lifecycle are deterministic without AI | Pass |
| V | Immutable ownership on definitions/logs/steps and non-disclosing nested boundaries | Pass |
| VI | Schema/domain/API/OpenAPI/TS/Vue/Playwright evidence moves together | Pass |
| VII | Frontend, backend, reminders, accessibility, and changelog ship in EN/RU/UK | Pass |

Post-design re-check: the data model and contract preserve every answer above. No constitution
exception is requested.

## Complexity Tracking

| Deliberate complexity | Why required | Simpler option rejected |
|---|---|---|
| Separate `habit_limit_steps` table | Ordered ceilings are constraints with period/effective-date semantics and must validate atomically | Goal milestones mean achieved checkpoints and would corrupt both domain meanings |
| Nullable `habit_log_id` occurrence link | The recurrence contract requires a durable fact reference while current implementation has a routine-specific FK | Treating occurrence status as the fact loses numeric/relapse data; a polymorphic rewrite would be risky and non-additive |
| Owner-type lifecycle dispatch in materializer | Habits are the second real recurrence owner and ids collide across tables | Querying routines only silently disables habits; a parallel habit scheduler violates the canonical engine |

No item is speculative: each has a direct acceptance consumer in 013.
