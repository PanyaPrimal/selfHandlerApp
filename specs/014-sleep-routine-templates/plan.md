# Implementation Plan: Sleep and Rich Routine Templates

**Feature ID**: `014-sleep-routine-templates`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/openapi.yaml](contracts/openapi.yaml) · **Quickstart**: [quickstart.md](quickstart.md)

## Summary

Extend the current Routine aggregate into ordered morning/evening/anytime templates with independent
activity facts and one deterministic parent RoutineLog, add one recurring nightly SleepPlan with
actual UTC sleep facts and planned wake snapshots, then expose module-owned summaries consistently in
Today, Review, Planner, notifications, web, and the bundled Android client. Existing simple routines,
the routine occurrence source, recurrence engine, Planner pull model, and notification pipeline remain
the authoritative shared boundaries.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12.61; TypeScript 6 / Vue 3.5.

**Primary Dependencies**: Existing Eloquent/UserOwned, Carbon, Routine/RoutineLog,
RecurringRule/PlannedOccurrence/materializer/reconcile, `SchedulableSource`/PlannerEntry, feature 011
notifications, Today/DailyReview, owned Vue controls/i18n, Capacitor shared bundle.

**Storage**: MySQL 8 production target; SQLite test portability. One additive migration creates six
tables, adds `routines.day_period`, and adds nullable unique `planned_occurrences.sleep_log_id`.

**Testing**: PHPUnit schema/model/service/API/OpenAPI/compatibility/integration/query tests; Pint;
i18n static guards; TypeScript/Vitest/build; Playwright desktop/mobile/locales/themes; mobile wrapper.

**Target Platform**: Responsive browser plus existing bundled Capacitor Android client. No native code
change is expected beyond synchronizing the final shared bundle.

**Performance Goals**: Selected-day and 366-day summary reads have fixed query budgets as plan,
template, activity, and fact counts grow. Recurrence materialization remains bounded and idempotent.

**Constraints**: UTC actual instants plus Profile-local day/time inputs, exact ownership, one active
sleep plan, deterministic parent derivation, decimal activity progress to 3 places, full EN/RU/UK,
exact 390×844 without overflow, additive evolution, no deployment or medical inference.

**Scale/Scope**: Hundreds of templates/activities and years of retained facts per user. UI shows one
date and lifecycle segment at a time; range endpoint is bounded to 366 days. Rollups remain feature 023.

## Localisation Plan

**Message ownership**: `apps/web/src/i18n/locales/en.ts` stays canonical with simultaneous RU/UK.
`apps/api/lang/{en,ru,uk}` owns strict validation/domain and persisted reminder copy. User-authored
plan/template/activity names and notes are never translated.

**Runtime locale/timezone**: Existing profile values and `Accept-Language` remain authoritative. Local
sleep wall fields are resolved only by backend Profile timezone; returned UTC/local values are formatted
by shared frontend helpers. Monday week semantics and recurrence weekdays remain unchanged.

**Formatting**: Date/time, duration, quality/averages, percentages, counts, and decimal progress use
existing Intl/plural helpers. Unknown sleep is shown as not recorded, never numeric zero.

**Delivery gates**: Key parity/blank/unknown/unused/hardcoded checks; backend message assertions;
typecheck/build; locale browser flows; 12-view EN/RU/UK × light/dark × desktop/mobile visual matrix.

## Project Structure

```text
apps/api/
├── app/Models/{SleepPlan,SleepOccurrenceDetail,SleepLog,RoutineActivity,RoutineActivityLog,RoutineDaySelection}.php
├── app/Http/Controllers/{SleepController,SleepPlanController,SleepLogController,SleepStatisticsController,
│   RoutineActivityController,RoutineActivityLogController,RoutineDaySelectionController}.php
├── app/Http/Requests/{StoreSleepPlanRequest,UpdateSleepPlanRequest,UpsertSleepLogRequest,
│   ReplaceRoutineActivitiesRequest,UpsertRoutineActivityLogRequest,ReplaceRoutineDaySelectionsRequest}.php
├── app/Http/Resources/{SleepPlanResource,RoutineActivityResource}.php
├── app/Services/
│   ├── SleepPlanRecurrence.php
│   ├── SleepLogService.php
│   ├── SleepStatisticsService.php
│   ├── RoutineActivityService.php
│   ├── RoutineActivityLogService.php
│   ├── RoutineDayProjectionService.php
│   ├── RoutineActivitySummaryService.php
│   └── Planner/SleepOccurrenceSource.php
├── database/migrations/2026_08_13_180000_create_sleep_and_routine_templates.php
└── tests/{Feature,Unit}/SleepRoutineTemplates/

apps/web/
├── src/views/{RoutinesView,TodayView,ReviewView,PlannerView,NotificationsView}.vue
├── src/api/{types,client}.ts
├── src/i18n/locales/{en,ru,uk}.ts
└── e2e/sleep-routines/sleep-routines-flow.spec.ts

specs/014-sleep-routine-templates/
├── spec.md · research.md · data-model.md · plan.md · quickstart.md · tasks.md · analysis.md
├── checklists/requirements.md
└── contracts/openapi.yaml
```

## Architecture Gates

### Gate 1 — Existing Routine Ownership

- `Routine` remains the template, rule owner, Planner source, goal-link owner, and parent fact owner.
- No generated routine/template copies or activity recurrence rules.
- Existing zero-activity routines remain byte/API/behavior compatible.

### Gate 2 — Deterministic Activity Facts

- Child facts are unique, explicit, correctable, and same-owner.
- One transactional service derives/clears parent RoutineLog and occurrence mirror.
- Structure/total lock prevents historical aggregate drift.

### Gate 3 — One Day Projection

- Explicit-null versus absent/default selections are distinct.
- One projection service supplies Today, Planner, writes, and notifications.
- Selections filter scheduled occurrences; they never manufacture or own them.

### Gate 4 — Sleep Recurrence and Facts

- SleepPlan is a new owner type using the current materializer.
- One occurrence carries bedtime; one module detail snapshots wake time; one SleepLog is the fact.
- Plan-specific and global materialization write occurrence/detail atomically; edit/reconcile preserve
  linked history and handle numeric id collisions by owner type.

### Gate 5 — Time Correctness

- Night-date and cross-midnight rules are explicit.
- Backend resolves Profile-local wall times, rejects DST gaps and invalid durations, stores UTC.
- Tests cover spring/fall transitions and device/profile timezone disagreement.

### Gate 6 — Module Summary Ownership

- Sleep and routine services compute selected/range DTOs from owned facts.
- Today transports once; Review displays the same DTO and stores nothing new.
- Fixed query budgets block N+1 and no rollup/AI enters 014.

### Gate 7 — Planner and Notification Reuse

- Existing routine source stays registered; sleep adds a distinct source.
- Planner actions route to module ownership and refuse fact-bearing moves/collisions.
- Feature 011 category/type/default/request/UI changes remain backwards compatible.

### Gate 8 — Additive Data and Privacy

- One reversible migration, portable FKs/uniques, MySQL-safe identifiers, preservation test.
- Immutable user ids and same-owner links at model/request/service layers.
- No health data leaves local authenticated APIs or enters logs/telemetry.

### Gate 9 — Contracts, Locales, and Shared Client

- Ten exact new authenticated operations; changed existing contracts/types/consumers move together.
- Every new string ships EN/RU/UK; user text remains untranslated.
- One responsive Vue implementation works in web and bundled Android without native duplication.

### Gate 10 — Evidence and Scope

- Red schema/domain/API/browser tests precede production files.
- Focused then full backend/web/mobile gates and visual inspection are recorded exactly.
- Deployment, feature 002, handoff, live data, alarms/wearables/medical advice remain untouched.

## Implementation Sequence

1. Complete spec/research/model/OpenAPI/quickstart/tasks/analyze and resolve every critical/high issue.
2. Write schema/model/recurrence/activity/sleep/selection/summary/API/integration/OpenAPI/E2E tests and
   capture expected red failures before production code.
3. Add the migration and owner-safe models; extend generic occurrence fact/owner dispatch.
4. Implement sleep recurrence/details/log validation/statistics, then sleep API.
5. Implement routine activities, fact derivation, structure lock, and compatibility.
6. Implement day selection/projection and integrate Today/Planner/activity validation.
7. Integrate module summaries into Today and Review without persistence.
8. Extend notifications and Planner sleep source; close lifecycle/fact/reminder races.
9. Implement typed API, combined Routines & Sleep UI, Today/Review/Planner states and localization.
10. Close docs/contracts, run all gates/visual/audits, update workspace memory, atomic commit/push.

## Constitution Re-check

| Principle | Result |
|---|---|
| Specifications before implementation | Pass — application changes wait for completed artifacts/analyze/red run |
| Canonical design ownership | Pass — Module 1 plus roadmap boundaries are implemented explicitly |
| Thin slice/simplicity | Pass — one nightly plan and rich templates; deferred shift/naps/versioning remain out |
| Deterministic core | Pass — duration, derivation, selection, and summaries are rule-based |
| User ownership/privacy | Pass — every row user-owned; sleep data stays authenticated/local |
| Contracts/tests move together | Pass — backend/OpenAPI/TS/Vue/integrations have paired evidence tasks |
| Complete localization | Pass — full EN/RU/UK surface and static/browser gates planned |

## Complexity Tracking

| Deliberate complexity | Why required | Simpler option rejected |
|---|---|---|
| `sleep_occurrence_details` | A night has two planned wall times but one shared occurrence/fact; wake must be snapshotted without polluting the generic table | Current plan wake time would rewrite history; two rules create two facts |
| Derived parent RoutineLog | Existing recurrence/Planner/notification closure requires one parent fact while activities remain independent | New partial parent state widens every consumer and duplicates child truth |
| Explicit nullable selection rows | “None” and “use default” are different user intents | Missing row alone cannot represent both |
| Shared day projection service | Four consumers must agree on the selected occurrence | Consumer-local filtering creates visible/reminder drift |

Each item has a current acceptance consumer and no speculative generalization.
