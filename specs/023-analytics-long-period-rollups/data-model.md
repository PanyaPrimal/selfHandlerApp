# Data Model: Analytics and Long-Period Rollups

Feature 023 adds no database table. Every object below is an immutable code definition or a request-scoped
derived value assembled from owner-scoped module aggregates.

## MetricDefinition

| Field | Type | Constraints |
|---|---|---|
| `key` | enum string | one of the 17 catalog keys; globally unique |
| `module` | enum string | routines/sleep/workouts/nutrition/supplements/habits/planner/finance/review/body |
| `unit` | enum string | percent/minutes/count/currency/rating_5/rating_10/kilograms |
| `operator` | enum string | sum/mean/percentage/last |
| `precision` | integer | 0, 2, or 4 |
| `empty_is_zero` | boolean | true only for completed-session/duration and Finance amount sums |
| `sensitivity` | enum string | standard/well_being/health/finance |

Labels and descriptions are client localisation keys derived from stable keys; translated strings are not sent
as data. Finance unit resolves to the authenticated user's Profile base currency in the workspace response.

## DailyMetricPrimitive

Internal module-to-Analytics contract; never persisted or exposed verbatim.

| Field | Type | Constraints |
|---|---|---|
| `date` | date string | authoritative Profile-local date inside requested range |
| `numerator` | decimal string or null | finite; semantic owned by definition/module |
| `denominator` | decimal string or null | positive when required; absent for sum/last |
| `complete` | boolean | false invalidates the affected bucket |
| `reasons` | unique enum list | bounded; currently `missing_fx:<ISO code>` only internally, normalized in API |

Sources return only requested metric keys. A registry rejects unknown/duplicate keys and calls each required
source at most once for the combined current/comparison range.

## MetricPoint

| Field | Type | Constraints |
|---|---|---|
| `bucket_start` | date string | inclusive; clipped to selected range |
| `bucket_end` | date string | inclusive; `>= bucket_start` |
| `state` | enum | ready/empty/incomplete |
| `value` | decimal string or null | rounded once to definition precision |
| `sample_count` | integer | contributing complete primitive dates/observations |
| `numerator` | decimal string or null | aggregate evidence for mean/percentage; otherwise null |
| `denominator` | decimal string or null | aggregate evidence for mean/percentage; otherwise null |
| `reasons` | enum/list | missing evidence or missing FX currency codes; bounded and sorted |

### Operator Transitions

- `sum`: ready when evidence exists or `empty_is_zero`; any incomplete contributor makes it incomplete.
- `mean`: ready only when aggregate denominator is positive.
- `percentage`: ready only when aggregate denominator is positive; result is numerator/denominator×100.
- `last`: ready when an observation exists; select maximum date, never fill forward across buckets.

## TrendSummary

| Field | Type | Constraints |
|---|---|---|
| `state` | enum | empty/insufficient/ready |
| `available_points` | integer | ready points used in calculations |
| `total_buckets` | integer | all emitted buckets |
| `first` / `last` | decimal string or null | first/last available values |
| `delta` | decimal string or null | last minus first; two points required |
| `slope_per_bucket` | decimal string or null | OLS slope over available bucket ordinal; two points required |

No interpolation, trend adjective, target judgement, confidence, or persisted state exists.

## PeriodAggregate and PeriodComparison

`PeriodAggregate` has exact `from`/`to`, `state`, `value`, `sample_count`, numerator/denominator, and reasons,
using the same operator as points over unbucketed daily primitives.

`PeriodComparison` contains `current`, `previous`, `absolute_delta`, `percentage_delta`, and
`percentage_delta_reason` (`available`, `missing_value`, or `previous_zero`). Previous is the immediately
preceding equal inclusive-day range and never overlaps current.

## CorrelationDefinition

| Field | Type | Constraints |
|---|---|---|
| `key` | enum | sleep_energy/sleep_quality_mood/habit_completion_day_rating |
| `left_metric` / `right_metric` | metric key | fixed catalog members |
| `minimum_samples` | integer | exactly 7 |

## CorrelationFinding

| Field | Type | Constraints |
|---|---|---|
| `key`, metric keys | enums | copied from definition |
| `from` / `to` | date strings | exact inclusive requested range; at most 366 days |
| `state` | enum | ready/unavailable |
| `coefficient` | decimal string or null | Pearson r, [-1,1], four decimals |
| `direction` | enum or null | positive/negative/none only when ready |
| `strength` | enum or null | none/weak/moderate/strong only when ready |
| `sample_count` | integer | pairwise-complete aligned daily values |
| `minimum_samples` | integer | 7 |
| `reason` | enum or null | insufficient_samples/zero_variance |

## Ownership, Privacy, and Lifecycle

- There is no Analytics lifecycle or deletion path because no Analytics row exists.
- Sources receive the authenticated `User`; every query uses `ownedBy` or an equivalent explicit user filter.
- Values are re-derived per request, so source correction/deletion and Profile input changes are immediately
  authoritative.
- Only aggregates and reason codes leave the API. Raw identifiers/content and external processing are absent.
- User deletion behavior remains wholly owned by the source modules.
