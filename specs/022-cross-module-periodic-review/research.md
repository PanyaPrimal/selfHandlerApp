# Research: Cross-Module and Periodic Review

## Sources Reviewed

- `docs/design/modules.md`: Review is a single-day cross-section; source modules provide ready-made
  totals; only Review/Analytics may compose the day score.
- `docs/design/vision.md`: daily evening, Sunday weekly, and monthly reflection rhythms.
- `docs/design/decisions.md`: weekly/monthly Review is a missing entity; composite metrics are the
  deliberate exception to module-owned aggregates.
- `docs/design/llm-layer.md`: L1 score and module totals are deterministic; AI may narrate later but may
  never recompute the number.
- Existing services/controllers/types/tests for Today, DailyReview, Planner, Sleep, Workouts, Nutrition,
  Supplements, Habits, Finance, and Storage.
- GitNexus context/impact for `TodayController` and `DailyReviewController`: both have LOW graph risk and
  only direct route registration, while repository search identifies their frontend/API test consumers.

## Decision 1: Registered Read-Only Sources

**Decision**: Add a `ReviewAggregateSource` contract with stable `key`, `daily`, and `period` methods and
a deterministic registry. Source adapters call module-owned summary services; the registry/composer and
controllers never read raw source tables.

**Why**: It enforces the locked aggregation principle in code, keeps Review open to later module sources,
and makes contract completeness and duplicate keys testable.

**Alternatives rejected**:

- One large Review controller with joins across all tables: couples ownership and formulas to Review.
- Persisting a JSON snapshot: becomes stale after legitimate source corrections and complicates migrations.
- Letting frontend call every module endpoint: creates partial-load inconsistency and duplicates composition.

## Decision 2: Add Module-Owned Summary Services Where Missing

**Decision**: Reuse `SleepStatisticsService`, `WorkoutStatisticsService`, `NutritionSummaryService`,
`SupplementAdherenceService`, and `FinanceSummaryService`. Add bounded module services for Routine,
Habit, and Planner period status totals. The Planner service can read recurrence/status/task/block facts
because they belong to Planner's existing cross-source schedule boundary; Review only reads its result.

**Why**: Existing services already define authoritative formulas. The missing sources need a range-safe
projection once, and those projections become reusable inputs for 023 without adding rollup storage.

## Decision 3: Day Score Formula

**Decision**: Five candidate components, equal available-only weighting:

1. Nutrition: mean of available target evidence. Calories/fat/carbs use
   `clamp(100 - abs(percent - 100), 0, 100)`; protein/hydration/quality use `clamp(percent, 0, 100)`.
2. Workouts: `completed / planned * 100`; if planned is zero, any completed unplanned workout gives 100;
   otherwise unavailable.
3. Supplements: `done / (done + skipped + overdue + pending) * 100`.
4. Habits: `successful / scheduled * 100`, including unresolved scheduled items in the denominator.
5. Planner: `done / (done + skipped + pending) * 100` for actionable due/scheduled entries.

Final value is the two-decimal arithmetic mean of available values. Coverage `available/5`, component
values, applied equal weight, and stable reason codes are returned. No available values means null.

**Why**: The formula is deterministic, bounded, explainable, independently testable, and honest about
missing evidence. It follows the canonical five concepts without allowing self-rating or AI to change it.

**Alternatives rejected**:

- Missing equals zero: punishes users for modules they do not use.
- Hidden fixed weights: creates false precision and makes refinement unsafe.
- Configurable weights now: broadens UI/persistence without evidence of need.
- Save the score: corrections would leave stale authority.

## Decision 4: PeriodicReview Identity

**Decision**: One additive `periodic_reviews` table with `user_id`, `period_type` (`weekly|monthly`),
canonical `period_start`, derived `period_end`, optional manual fields, and first `completed_at`. Unique
`(user_id, period_type, period_start)`. Any anchor maps to the same canonical identity.

**Why**: It models the missing Vision entity directly, permits idempotent retry, and does not mix daily
and periodic field semantics in one table.

**Alternatives rejected**:

- Extend `daily_reviews` with a nullable type: weakens its one-date invariant and creates incompatible fields.
- Store week number/month string only: awkward cross-year validation and indexing.
- Store module aggregates/score columns: duplicates mutable source truth.

## Decision 5: Period Boundaries

**Decision**: Weekly is Monday-Sunday; monthly is calendar first-last day. Anchor parsing and boundaries
use `User::calendarTimezone()`. APIs accept strict `Y-m-d`, and responses expose canonical start/end.

**Why**: ISO-style week identity is deterministic across years, while Sunday remains the intended ritual
day at the end of the week. Calendar month semantics match Vision and Finance period language.

## Decision 6: API and Compatibility

**Decision**: Add authenticated daily-workspace and periodic-workspace GET plus periodic PUT operations.
Keep existing `/daily-reviews/{date}` GET/PUT unchanged. Add fields to `/today` without removing or
renaming the existing `module_summaries` members.

**Why**: Review needs one coherent read, while Today and existing tests/clients need additive evolution.

## Decision 7: Query Bounds

**Decision**: Module services aggregate complete ranges with grouped queries. No source is invoked per
calendar day, and no N+1 per record is allowed. Weekly/monthly reads are hard-bounded by period type.

**Why**: 023 will add long-period rollups, but 022 must remain fast without prematurely creating them.

## Decision 8: Shared UI and Android

**Decision**: Keep the current daily route and add explicit weekly/monthly routes to the shared Vue
workspace, with mode navigation and URL anchor identity. Use responsive cards/form controls and sync the
same built assets through Capacitor. There is no native review data store or offline queue.

## Security and Privacy Findings

- Aggregate payloads combine health, behavior, journal, and finance data and require authenticated owner
  scoping at every source.
- Journal text is never sent to an external provider in 022.
- Validation logs must not include request bodies or private reflection contents.
- Foreign records must influence neither visible totals nor score denominators, including error cases.

## Deferred to Later Features

- 023: long-period rollups, trend series, comparisons, correlations, and period/day-score analytics.
- 024: CSV/PDF reports, backup/export/restore.
- 025: calendar synchronization.
- 026: AI narrative, weekly meta-reflection, and proposed actions.
- Deployment, offline/native authority, automatic planning/goal writes, and notifications.
