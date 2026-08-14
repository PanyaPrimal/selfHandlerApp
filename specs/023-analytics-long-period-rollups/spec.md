# Feature Specification: Analytics and Long-Period Rollups

**Feature Branch**: existing user branch

**Created**: 2026-08-14

**Status**: Draft

**Input**: Roadmap feature 023 and the Analytics boundaries in `docs/design/vision.md`,
`docs/design/modules.md`, `docs/design/decisions.md`, and `docs/design/delivery-roadmap.md`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Read a Trustworthy Metric Trend (Priority: P1)

As a user, I can choose one supported metric, date range, and bucket size and see its values over time,
so I can understand change without opening every source module or waiting on repeated per-day scans.

**Why this priority**: A useful, trustworthy trend is the independently valuable core of Analytics.

**Independent Test**: Create owned facts across several dates for one metric, request daily, weekly, and
monthly views, and verify that every bucket and summary equals the owning module's aggregate semantics.

**Acceptance Scenarios**:

1. **Given** supported facts span the selected range, **When** the user opens Analytics, **Then** a chart,
   numeric table, first/last values, delta, slope, sample count, unit, and aggregation meaning are shown.
2. **Given** the same facts are viewed with daily, Monday-based weekly, and calendar-month buckets,
   **When** granularity changes, **Then** bucket boundaries and values remain deterministic and complete.
3. **Given** a source fact is corrected or deleted, **When** the trend is reloaded, **Then** the module's
   corrected aggregate is visible without an Analytics-owned stale copy.
4. **Given** a bucket has no eligible evidence, **When** it is displayed, **Then** it is explicitly missing
   rather than silently treated as zero, except where the metric definition makes an empty count or amount
   a real zero.

---

### User Story 2 - Compare Adjacent Periods (Priority: P1)

As a user, I can compare a selected period with the immediately preceding equal-length period, so I can
see exactly what changed without mentally aligning two charts.

**Why this priority**: Period-over-period comparison is a core Analytics outcome and remains useful even
without correlations.

**Independent Test**: Seed different values in adjacent equal-length windows and verify both labeled
series, aggregate values, absolute delta, percentage delta availability, and missing-evidence behavior.

**Acceptance Scenarios**:

1. **Given** both periods contain eligible evidence, **When** comparison is enabled, **Then** both exact
   ranges, both aggregate values, absolute delta, and percentage delta are shown.
2. **Given** the prior aggregate is zero or unavailable, **When** comparison is computed, **Then** percentage
   delta is unavailable with a machine-readable reason; the application never divides by zero or invents it.
3. **Given** the user disables comparison, **When** the workspace reloads, **Then** the primary trend remains
   independently usable and no comparison claim is shown.

---

### User Story 3 - Inspect Selected Deterministic Correlations (Priority: P1)

As a user, I can inspect a small, predefined set of cross-module associations with coefficient, direction,
strength, aligned sample size, and evidence limits, so patterns are visible without causal or AI claims.

**Why this priority**: Selected deterministic correlations are the headline cross-module capability in the
canonical design, but they must remain bounded and honest about evidence.

**Independent Test**: Supply aligned daily values for each fixed pair, verify the Pearson coefficient and
classification exactly, then verify insufficient, missing, and zero-variance states.

**Acceptance Scenarios**:

1. **Given** at least seven aligned days with variance on both sides, **When** correlations are requested,
   **Then** the coefficient, positive/negative direction, bounded strength, sample size, period, and metric
   labels are returned deterministically.
2. **Given** fewer than seven aligned days, **When** the pair is inspected, **Then** it is unavailable with
   the exact required and actual sample counts.
3. **Given** either side has zero variance, **When** the pair is inspected, **Then** it is unavailable and no
   coefficient, direction, or strength is claimed.
4. **Given** a correlation is available, **When** it is presented, **Then** the copy describes an association,
   never causation, diagnosis, recommendation, confidence interval, or statistical significance.

---

### User Story 4 - Explore Analytics Privately Across Clients (Priority: P2)

As a user, I can use the same accessible Analytics workspace in English, Russian, or Ukrainian on desktop,
exact-phone, and the synchronized Android shell without exposing raw sensitive records.

**Why this priority**: Analytics includes health, well-being, and financial aggregates and must inherit the
product's privacy, localisation, accessibility, and shared-client guarantees.

**Independent Test**: Verify owner/foreign/anonymous API matrices and navigate the complete workspace in all
three locales, both schemes, desktop, exact 390×844, keyboard, and the synchronized mobile bundle.

**Acceptance Scenarios**:

1. **Given** two users have overlapping dates, **When** either opens Analytics, **Then** only their own
   aggregate points and evidence counts are visible.
2. **Given** the user changes locale or scheme, **When** Analytics is open, **Then** every label, state,
   date, number, currency, unit, chart description, and accessibility name updates consistently.
3. **Given** the workspace is used by keyboard or assistive technology, **When** controls and charts are
   traversed, **Then** controls have visible focus, charts have equivalent tables/descriptions, touch targets
   are at least 44 px, and no horizontal overflow hides content.
4. **Given** an Analytics response is inspected, **When** sensitive sources contribute, **Then** it contains
   aggregates only: no notes, journal text, attachment paths/bytes, transaction details, counterparty names,
   provider secrets, or another user's identifiers.

### Edge Cases

- The inclusive date range crosses a DST transition, leap day, year boundary, or different-length months.
- A requested bucket contains only unavailable values, or the whole selected range is empty.
- An average/rate metric has a partial denominator; numerator, denominator, and sample coverage stay visible.
- A money metric requires historical FX that is missing for one contributing currency; the affected bucket
  and comparison remain incomplete rather than silently dropping that currency.
- A source contains a real numeric zero, which must remain distinct from missing evidence.
- The selected range is valid globally but invalid for the requested granularity's payload bound.
- Correlation inputs have gaps on different days, fewer than seven aligned pairs, or zero variance.
- A correction moves a fact from one date/bucket to another, or an owned source fact is deleted.
- Profile locale, timezone, unit system, or base currency changes between requests.
- Concurrent reads occur while a source correction commits; each completed response remains internally
  consistent and a subsequent read reflects the committed source truth.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST provide one authenticated Analytics workspace with metric, inclusive date
  range, granularity, and comparison controls.
- **FR-002**: The initial catalog MUST expose only metrics whose values are produced by their owning modules:
  Routine completion rate; Sleep duration and quality; Workout completed-session count and duration;
  Nutrition calorie-target adherence; Supplement adherence; Habit completion rate; Planner completion rate;
  Finance income, expense, and net in Profile base currency; Review energy, mood, stress, and day rating; and
  Body mass observations.
- **FR-003**: Every catalog entry MUST publish a stable key, owning module, label, unit, supported aggregation
  semantics, zero-vs-missing behavior, and sensitivity class.
- **FR-004**: Analytics MUST consume module-owned metric-series contracts and MUST NOT query source modules'
  raw tables or reimplement nutrition, workout, habit, finance, Review, or other domain formulas.
- **FR-005**: Rollups MUST be derived on read through bounded grouped source queries and MUST NOT persist a
  second mutable copy of source facts or aggregate values in this feature.
- **FR-006**: A source correction, deletion, restored archive state, Profile input change, or historical FX
  correction MUST be reflected on the next completed Analytics read.
- **FR-007**: The system MUST support daily buckets for at most 93 inclusive days, Monday-based weekly buckets
  for at most 730 inclusive days, and calendar-month buckets for at most 3,653 inclusive days.
- **FR-008**: Bucket boundaries MUST use the user's Profile calendar timezone, while persisted instants remain
  UTC and date-only facts retain their authoritative local calendar date.
- **FR-009**: Bucket values MUST follow the cataloged source semantic: sum, arithmetic mean, weighted
  numerator/denominator rate, or last dated observation; missing buckets MUST be explicit.
- **FR-010**: Trend responses MUST include ordered points plus first value, last value, absolute delta,
  least-squares slope per bucket, available-point count, total-bucket count, and explicit empty/insufficient/
  ready state.
- **FR-011**: Trend calculations MUST use only available numeric points, require at least two points for a
  slope, preserve real zeroes, and never interpolate missing facts.
- **FR-012**: Comparison MUST use the immediately preceding non-overlapping period with the same inclusive day
  count and the same metric semantics, and MUST label both exact ranges.
- **FR-013**: Comparison MUST expose current and previous aggregate values, sample coverage, absolute delta,
  and percentage delta only when both values exist and the previous value is nonzero.
- **FR-014**: The first correlation catalog MUST contain exactly three daily pairs: Sleep duration with Review
  energy, Sleep quality with Review mood, and Habit completion rate with Review day rating.
- **FR-015**: Correlations MUST use pairwise-complete owned daily points and the Pearson product-moment
  coefficient rounded once to four decimal places.
- **FR-016**: A correlation MUST require at least seven aligned samples and nonzero variance on both axes;
  otherwise it MUST return a closed unavailable reason and no coefficient.
- **FR-017**: Available correlations MUST classify absolute coefficient as none below 0.10, weak below 0.30,
  moderate below 0.60, and strong at or above 0.60, with positive/negative/none direction derived only from
  the rounded coefficient.
- **FR-018**: Correlation responses and UI MUST state that association does not establish causation and MUST
  NOT claim medical meaning, statistical significance, a confidence interval, or a recommendation.
- **FR-019**: Finance points MUST use the user's Profile base currency and historical exchange-rate evidence;
  any missing contributing FX MUST make the whole affected value unavailable with currency reasons.
- **FR-020**: Rate metrics MUST retain numerator and denominator evidence so bucket aggregation is weighted and
  never an average of percentages.
- **FR-021**: Analytics query counts MUST remain bounded by source count rather than day or bucket count at
  every supported maximum range; per-day source calls are forbidden.
- **FR-022**: All reads MUST be owner-scoped and protected by existing authentication; anonymous requests MUST
  be rejected and matching facts owned by another user MUST never affect values, coverage, or reasons.
- **FR-023**: API responses MUST expose only aggregate metric definitions, points, coverage, comparison, and
  correlation evidence; raw notes, journal text, attachments, transactions, counterparties, secrets, and
  source-row identifiers MUST remain absent.
- **FR-024**: The API contract MUST be closed and bounded, with strict dates, enumerated metric/granularity/
  state/reason values, finite numbers, maximum ranges, and stable validation errors.
- **FR-025**: The web workspace MUST provide an accessible chart and an equivalent numeric table, readable
  empty/error/retry states, range and granularity controls, comparison summary, and correlation cards.
- **FR-026**: Metric/granularity choices MUST survive URL navigation and reload without becoming a second
  authoritative user preference; invalid query values MUST fall back to a safe documented default.
- **FR-027**: Every new or changed user-visible string, validation message, metric/unit label, formatter,
  chart description, and accessibility label MUST ship together in EN/RU/UK with English fallback.
- **FR-028**: Dates, decimals, percentages, durations, counts, and base-currency amounts MUST use the active
  locale and existing unit/currency conventions without translating user content or identifiers.
- **FR-029**: The same online-only Vue feature MUST be synchronized into the existing Android Capacitor shell;
  no native database, offline analytics cache, APK, device, signing, or deployment work is authorized.
- **FR-030**: Feature 023 MUST NOT add CSV/PDF/backup/restore, calendar or fitness/bank integration, alerts,
  provider calls, LLM narratives, causal recommendations, forecasts, anomaly diagnosis, configurable custom
  formulas/pairs, persisted analytics facts, native offline authority, or deployment behavior.

### Localisation Surface *(mandatory)*

- **Locales**: English (`en-GB`), Russian (`ru-UA`), and Ukrainian (`uk-UA`).
- **New user text**: Analytics navigation, headings, metric and module labels, range/granularity controls,
  comparison and correlation explanations, units, coverage, empty/loading/error/retry states, validation
  feedback, table/chart descriptions, direction/strength labels, disclaimers, buttons, and ARIA text.
- **Formatting**: Profile-local calendar dates; locale-aware decimals, percentages, counts, durations, slopes,
  and Finance base-currency values; pluralized sample/day/bucket counts.
- **Non-translatable content**: User-authored content is not displayed; stable metric keys, currency codes,
  ISO dates in API payloads, and product name remain unchanged.
- **Verification**: Dictionary parity, used-key and hardcoded-copy guards, backend validation localisation,
  desktop/exact-phone journeys, and visual inspection cover all three locales and both schemes.

### Key Entities

- **Metric Definition**: Immutable catalog metadata for one module-owned numeric series, including key,
  module, unit, aggregation, zero semantics, and sensitivity; it is code-owned, not user data.
- **Metric Point**: A derived aggregate for one exact bucket with value or unavailable state plus sample,
  numerator/denominator, completeness, and bounded reason evidence; it is not persisted by Analytics.
- **Trend Summary**: Derived first/last/delta/slope and coverage for one metric series.
- **Period Comparison**: Two exact adjacent ranges with source-owned aggregates and derived deltas.
- **Correlation Definition/Finding**: One fixed pair plus a derived Pearson coefficient or closed unavailable
  reason, aligned sample count, direction, strength, range, and non-causality disclaimer.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Every listed metric returns daily/weekly/monthly points matching its owning module for supported
  ranges, including weighted-rate and last-observation semantics.
- **SC-002**: Changing a source fact changes the next Analytics response with no Analytics cleanup or rebuild.
- **SC-003**: Query count for each source and complete workspace is identical for a representative short and
  maximum-length range of the same granularity, excluding authentication/Profile bootstrap queries.
- **SC-004**: Trend, comparison, and all three correlation formula matrices match independently calculated
  fixtures exactly, including missing, real-zero, insufficient, zero-variance, and rounding cases.
- **SC-005**: Owner, foreign-owner, and anonymous tests prove that no response value, count, reason, or metadata
  leaks another user's raw or aggregate data.
- **SC-006**: API contract tests prove every Analytics route is authenticated, unique, bounded, fully resolved,
  and consumed by matching TypeScript types.
- **SC-007**: A user can select a metric and range, enable comparison, inspect a correlation, and recover from
  an error in both desktop and exact 390×844 browser projects with no horizontal overflow.
- **SC-008**: All Analytics states render in EN/RU/UK and both schemes, with chart-equivalent tables, keyboard
  access, visible focus, descriptive names, and visually inspected screenshots.
- **SC-009**: Full backend, frontend, browser, localisation, Android shell, formatting, dependency-audit, and
  protected-path gates pass with no unexpected failure or skip.
- **SC-010**: No new table stores Analytics aggregates, no response contains prohibited raw/sensitive fields,
  and no deployment, workflow, handoff, native authority, external provider, export, calendar, or AI file is
  changed by the feature commit.

## Assumptions

- Analytics is descriptive. Positive or negative direction is not value judgement, benefit, harm, or cause.
- The first useful slice favors a fixed, reviewable metric/pair catalog over user-authored formulas.
- Query-time grouped rollups are preferable to cache invalidation until measured production evidence shows
  that a persisted rollup is necessary; supported bounds and query-budget contracts protect this decision.
- Date-only module facts already use the Profile calendar date defined by their owning features.
- Existing EN/RU/UK, Appearance, session authentication, Profile timezone/unit/base-currency, and shared
  browser/Android foundations remain authoritative.

## Dependencies and Explicit Exclusions

- **Depends on**: 004 Profile, 007 Body, 010 localisation/theme, 012 Android shell, implemented source modules
  013–020, and 022 cross-module Review boundaries.
- **Delegates to**: owning modules for every metric point; Profile for locale/timezone/units/base currency;
  existing browser auth and mobile transport for access.
- **Defers to 024**: CSV/PDF reports, backup manifests, restore, and portability schemas.
- **Defers to 025**: calendar and all other external integrations/synchronization.
- **Defers to 026**: provider credentials, consent for external processing, AI narratives, and tool calls.
- **Also excluded**: forecasts, causal/medical claims, alerts/notifications, arbitrary formulas or correlation
  pairs, persisted analytics copies, raw-record drill-down, background jobs, offline writes, deployment, and
  any modification of the preserved design handoff or generated agent instructions.
