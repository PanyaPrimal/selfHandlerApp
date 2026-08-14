# Research: Analytics and Long-Period Rollups

## Sources Reviewed

- `docs/design/vision.md` — charts, period trends, and correlations.
- `docs/design/modules.md` — Module 9 boundary and every implemented module's aggregate ownership.
- `docs/design/decisions.md` — Analytics is a display layer; Review is a day slice; AI is optional.
- `docs/design/data-conventions.md` — ownership, time, exact money, and derived-value conventions.
- `docs/design/llm-layer.md` — deterministic findings precede any later narrative and must not become causal.
- `docs/design/delivery-roadmap.md` — 023 outcome, prerequisites, and Architecture Gates.
- Specs 004, 007, 010, 013–022 and their implemented source services/contracts.
- Current API routes, Profile settings, Review aggregate registry, client navigation, i18n guards, browser
  suites, and Android shared-bundle workflow.

## Decision 1: Derived Grouped Series, Not Persisted Analytics Copies

**Decision**: Feature 023 stores no analytics table. Each owning module exposes a bounded daily metric-series
method that performs a fixed number of grouped queries for the requested range. Analytics buckets those
daily aggregate primitives into day/week/month points and derives trend/comparison/correlation results.

**Rationale**:

- Corrections, deletes, archive restoration, timezone/Profile changes, and historical FX fixes appear on the
  next read without a cross-module invalidation protocol.
- It preserves the locked rule that each module owns calculations while Analytics remains a display layer.
- Query count is independent of day/bucket count; payload and computation have explicit range bounds.
- A materialized cache would introduce copied sensitive data, invalidation events across many old features,
  backfill/repair commands, and stale-result semantics without measured production evidence.

**Rejected**:

- One Analytics controller querying every raw table: violates module direction and duplicates formulas.
- One row per day/metric cache: creates mutable copied facts and an unproven invalidation subsystem.
- Calling existing one-day summaries in a loop: query count grows with range length.

## Decision 2: One Small Metric Contract

**Decision**: `AnalyticsMetricSource` publishes immutable definitions and daily aggregate primitives for a
requested subset of its keys. A primitive is date + numerator + optional denominator + complete/reasons.
The definition supplies operator, precision, unit, empty behavior, and sensitivity.

Operators are deliberately closed:

| Operator | Bucket value |
|---|---|
| `sum` | sum of complete numerators; absent day is zero only when definition says so |
| `mean` | sum(numerator) / sum(denominator) |
| `percentage` | sum(numerator) / sum(denominator) × 100 |
| `last` | latest dated complete numerator in the bucket |

**Rationale**: Numerator/denominator preserves weighted rate semantics and avoids averaging daily percentages.
One generic rollup engine can remain deterministic without knowing Nutrition, Habit, or Finance formulas.

**Rejected**: Free-form callbacks/formulas in Analytics, arbitrary JSON shapes, or untyped custom metrics.

## Decision 3: Initial Metric Catalog

| Key | Owner | Primitive and operator | Precision/unit | Empty behavior |
|---|---|---|---|---|
| `routines.completion_rate` | Routine | done / scheduled, percentage | 2 / percent | missing |
| `sleep.duration_minutes` | Sleep | duration minutes / recorded logs, mean | 2 / minutes | missing |
| `sleep.quality` | Sleep | quality / recorded logs, mean | 2 / rating_5 | missing |
| `workouts.completed_sessions` | Workout | completed facts, sum | 0 / count | zero |
| `workouts.duration_minutes` | Workout | completed duration minutes, sum | 2 / minutes | zero |
| `nutrition.calorie_target_adherence` | Nutrition | daily bounded closeness to target / eligible days, mean | 2 / percent | missing |
| `supplements.adherence` | Supplement | taken / eligible opportunities, percentage | 2 / percent | missing |
| `habits.completion_rate` | Habit | successful / scheduled, percentage | 2 / percent | missing |
| `planner.completion_rate` | Planner | done / scheduled, percentage | 2 / percent | missing |
| `finance.income` | Finance | historical-FX converted actual income, sum | 4 / currency | zero unless incomplete |
| `finance.expense` | Finance | historical-FX converted actual expense magnitude, sum | 4 / currency | zero unless incomplete |
| `finance.net` | Finance | converted income minus expense, sum | 4 / currency | zero unless incomplete |
| `review.energy` | Review | rating / completed fields, mean | 2 / rating_10 | missing |
| `review.mood` | Review | rating / completed fields, mean | 2 / rating_10 | missing |
| `review.stress` | Review | rating / completed fields, mean | 2 / rating_10 | missing |
| `review.day_rating` | Review | rating / completed fields, mean | 2 / rating_10 | missing |
| `body.body_mass` | Body | latest dated measurement, last | 4 / kilograms | missing |

Nutrition closeness is `max(0, 100 - abs(actual/target×100 - 100))` per eligible day. Finance preserves the
018 rule: any missing contributing historical FX makes the complete bucket unavailable. Body does not fill
forward sparse measurements because doing so would invent observations.

## Decision 4: Calendar Buckets and Range Bounds

**Decision**: Inputs are strict inclusive Profile-local dates. Daily permits 93 days, weekly 730 days, and
monthly 3,653 days. Weeks start Monday; months use their actual first/last calendar dates. Edge buckets are
clipped to the selected range and publish both exact bounds. UTC instants are assigned to the owning local
date before the module emits a primitive.

**Rationale**: The bounds keep JSON/SVG/table payloads usable while supporting roughly 13 weeks, 104 weeks,
or ten years. Calendar semantics match Review and Profile rather than fixed-duration approximations.

## Decision 5: Trend and Comparison Mathematics

**Decision**: Trend summary uses available bucket values only. With two or more values it reports ordinary
least-squares slope where x is the zero-based bucket ordinal; it also reports first, last, and last-first.
No interpolation or directional judgement is added. Comparison uses the immediately preceding equal-day
window and rolls both windows with the same operator. Percentage delta exists only when both aggregates are
available and the previous value is nonzero.

**Rationale**: This is explainable across irregular calendar bucket widths and keeps zero separate from missing.

## Decision 6: Exactly Three Descriptive Correlations

**Decision**: Correlations are daily pairwise-complete Pearson coefficients for:

1. Sleep duration ↔ Review energy.
2. Sleep quality ↔ Review mood.
3. Habit completion rate ↔ Review day rating.

At least seven aligned days and variance on both axes are required. Coefficient is rounded once to four
decimals. Absolute strength: `<0.10 none`, `<0.30 weak`, `<0.60 moderate`, otherwise strong. Direction is
positive/negative/none from the rounded coefficient. Unavailable reasons are `insufficient_samples` or
`zero_variance`; pair definitions and required/actual samples remain visible.

**Rationale**: These pairs are named by the design, use already owned descriptive values, and do not embed one
side into the other. Seven is a modest evidence floor, not a statistical-significance claim.

**Rejected**: Exhaustive pair mining, lag search, p-values, causal arrows, anomaly detection, advice, or user
formulas. Those would encourage false discovery and materially expand risk.

## Decision 7: Three Closed Read Endpoints

**Decision**:

- `GET /api/analytics/catalog` — static metric/pair definitions and range limits.
- `GET /api/analytics/workspace` — one selected trend plus optional previous-period comparison.
- `GET /api/analytics/correlations` — all three fixed findings for a strict range of at most 366 days.

All require Sanctum. Defaults use Profile today, `sleep.duration_minutes`, the last 30 inclusive days, daily
granularity, and comparison enabled. Unknown values are validation errors at the API; the browser normalizes
invalid URL state to safe defaults before calling it.

**Rationale**: Catalog is cacheable code metadata; one selected series bounds response size; correlations have
their own evidence and limit. No writes, jobs, or persistence are needed.

## Decision 8: Dependency-Free Accessible Chart

**Decision**: Render a compact SVG trend with semantic description and an always-available HTML table. Use the
existing date picker, UI controls, tokens, locale formatters, and responsive panels. URL query state contains
metric/from/to/granularity/compare. A chart library is unnecessary for one or two bounded series.

**Rationale**: Avoids a new runtime dependency/bundle expansion and makes exact values accessible by default.

## Decision 9: Aggregate-Only Privacy

Analytics responses contain definitions, exact date bounds, aggregate values, coverage, and reason codes only.
No raw record IDs, notes, journal fields, attachments, transaction rows, counterparties, or credentials cross
the boundary. Finance/body/well-being definitions are marked sensitive so UI descriptions can be explicit;
feature 026 must obtain separate consent before any external use.

## Deferred

Persisted caches/precomputation, configurable metrics/pairs, drill-down, forecasting, alerts, anomaly or causal
claims, statistical inference, reports/exports/restore, calendar/integrations, AI narratives/tool calls, offline
data authority, and deployment remain outside 023.
