# Tasks: Analytics and Long-Period Rollups

**Input**: Complete design documents in this directory

**Tests**: Required by the constitution; permanent RED contracts precede production implementation

## Phase 1: Specification, Baseline, and Impact

- [x] T001 Read canonical Analytics, aggregate, Profile, privacy, localisation, mobile, and roadmap sources.
- [x] T002 Audit implemented source aggregates, API contracts, routes, client navigation, and prior specs.
- [x] T003 Capture branch/HEAD/remote/dirty-worktree and protected handoff/deployment baseline.
- [x] T004 Record 022 full-gate baseline as the 023 starting point without rerunning deployment checks.
- [x] T005 Create `spec.md` with prioritized stories, exact formulas, bounds, privacy, and exclusions.
- [x] T006 Complete the 023 specification-quality checklist with no material clarification outstanding.
- [x] T007 Create `research.md` and close metric, rollup, range, comparison, correlation, API, and UI decisions.
- [x] T008 Create `data-model.md`, `plan.md`, `quickstart.md`, and the closed OpenAPI contract.
- [x] T009 Run GitNexus context/impact for every existing service/controller/client/router symbol to be changed.
- [x] T010 Run pre-implementation cross-artifact analysis and resolve every critical/high finding.

## Phase 2: Permanent RED Contracts

- [x] T011 Add RED unit tests for strict daily/weekly/monthly bucket boundaries and clipping.
- [x] T012 Add RED unit tests for sum, mean, percentage, last, real-zero, missing, and incomplete rollups.
- [x] T013 Add RED unit tests for weighted numerator/denominator rates and one-time rounding.
- [x] T014 Add RED unit tests for empty/insufficient/ready trend states, delta, and OLS slope.
- [x] T015 Add RED unit tests for immediately preceding equal-day comparison range and deltas.
- [x] T016 Add RED unit tests for Pearson coefficients, rounding, direction, and strength thresholds.
- [x] T017 Add RED unit tests for insufficient-sample and zero-variance correlation states.
- [x] T018 Add RED registry tests for 17 unique definitions and requested-source call deduplication.
- [x] T019 Add RED module-source contract tests for every catalog metric and aggregation primitive.
- [x] T020 Add RED source correction/deletion/Profile/FX freshness tests with no Analytics cleanup.
- [x] T021 Add RED short-vs-maximum-range query-budget tests and per-day-call architecture guards.
- [x] T022 Add RED API tests for catalog/default workspace and all strict request bounds.
- [x] T023 Add RED API tests for every metric/operator, comparison toggle, and correlation endpoint.
- [x] T024 Add RED owner/foreign/anonymous and aggregate-only response privacy tests.
- [x] T025 Add RED localized validation tests for EN/RU/UK.
- [x] T026 Add RED OpenAPI parse/reference/route/auth/closed-schema contract tests.
- [x] T027 Add RED TypeScript contract/formatting unit tests.
- [x] T028 Add RED desktop/exact-mobile browser tests for the primary Analytics journey.

## Phase 3: Generic Analytics Core

- [x] T029 Add `AnalyticsMetricSource` with definitions and bounded daily primitive methods.
- [x] T030 Add immutable catalog constants for 17 metrics, operators, units, precision, zero, and sensitivity.
- [x] T031 Add three fixed correlation definitions and range-limit metadata.
- [x] T032 Add registry uniqueness/ownership validation and metric-to-source lookup.
- [x] T033 Add strict date/granularity/default request parsing in an Analytics request boundary.
- [x] T034 Add Profile-local daily/Monday-week/calendar-month bucket construction and clipping.
- [x] T035 Add previous equal-inclusive-day range construction across leap/year/DST boundaries.
- [x] T036 Add decimal-safe sum primitives and stable precision formatting.
- [x] T037 Implement sum rollup with real-zero and incomplete-evidence behavior.
- [x] T038 Implement weighted mean/percentage rollups from aggregate numerator/denominator evidence.
- [x] T039 Implement sparse last-observation rollup without interpolation or fill-forward.
- [x] T040 Implement ordered points, period aggregate, coverage, and reason normalization.
- [x] T041 Implement first/last/delta/OLS trend summary from available points only.
- [x] T042 Implement current/previous comparison and guarded percentage delta.
- [x] T043 Implement pairwise-complete Pearson findings and closed unavailable states.

## Phase 4: Module-Owned Metric Sources

- [x] T044 Add Routine daily completion primitives beside `RoutinePeriodSummaryService`.
- [x] T045 Verify Routine moved/skipped/pending occurrence semantics and owner isolation.
- [x] T046 Add Sleep daily duration/quality numerator and sample primitives beside its statistics service.
- [x] T047 Verify Sleep UTC-to-Profile-date, correction, deletion, and sparse-day semantics.
- [x] T048 Add Workout completed-session count/duration daily primitives beside its statistics service.
- [x] T049 Verify planned/unplanned completed facts, correction, and zero-day semantics.
- [x] T050 Add Nutrition daily calorie-target-closeness primitives beside `NutritionSummaryService`.
- [x] T051 Verify persisted-target eligibility, over/under target bounds, correction, and missing-target state.
- [x] T052 Add Supplement daily taken/eligible primitives without a one-day service loop.
- [x] T053 Verify recurrence expansion/durable correction and maximum-range query bounds.
- [x] T054 Add Habit daily successful/scheduled primitives beside its period summary.
- [x] T055 Verify archived, skipped, unsuccessful, rescheduled, and owner semantics.
- [x] T056 Add Planner daily done/scheduled primitives beside its period summary.
- [x] T057 Verify occurrence/item/reschedule/drop and no-duplicate semantics.
- [x] T058 Add Finance historical-FX daily income/expense/net primitives beside its summary service.
- [x] T059 Verify reversal, base-currency, exact decimal, missing-FX whole-day, and correction semantics.
- [x] T060 Add Review-owned daily energy/mood/stress/day-rating primitive service.
- [x] T061 Add Body-owned sparse body-mass primitive service with canonical kilograms.
- [x] T062 Add ten thin Analytics source adapters with no raw model imports.
- [x] T063 Verify the registry calls each required module source once across trend/comparison/correlations.

## Phase 5: API, Contracts, and Privacy

- [x] T064 Implement catalog presentation with stable order and no translated strings.
- [x] T065 Implement selected workspace orchestration over one combined current/previous source range.
- [x] T066 Implement correlation orchestration over three unique sources and a 366-day limit.
- [x] T067 Add the read-only `AnalyticsController` without source-model imports.
- [x] T068 Add three authenticated `/api/analytics/*` routes with no write route.
- [x] T069 Add EN/RU/UK backend validation/domain messages.
- [x] T070 Normalize missing-FX reasons to sorted bounded currency evidence.
- [x] T071 Prove response payloads exclude raw IDs/notes/journals/attachments/transactions/secrets.
- [x] T072 Make the checked-in OpenAPI contract match final controller shapes and validation.
- [x] T073 Run focused unit/module/API/OpenAPI/Review compatibility suites to GREEN.

## Phase 6: Shared Browser Client

- [x] T074 Add closed Analytics TypeScript enums/interfaces matching OpenAPI decimal strings and nullable states.
- [x] T075 Add catalog/workspace/correlation API client functions with URL-safe query encoding.
- [x] T076 Add Analytics contract and metric-aware formatting Vitest coverage.
- [x] T077 Add `/analytics` authenticated router entry and lazy-free deterministic import.
- [x] T078 Add Analytics to desktop navigation with existing owned navigation conventions.
- [x] T079 Add mobile-safe Analytics navigation access without overflowing the fixed bottom bar.
- [x] T080 Build metric/range/granularity/comparison controls with 44 px targets and URL persistence.
- [x] T081 Normalize invalid/missing URL query state to safe documented defaults.
- [x] T082 Build an accessible dependency-free SVG metric trend with locale description.
- [x] T083 Build the equivalent numeric trend table with exact bounds, value, evidence, and state.
- [x] T084 Build trend summary and comparison cards with guarded delta semantics.
- [x] T085 Build three correlation cards with coefficient/evidence/state and non-causality disclosure.
- [x] T086 Implement loading, empty, incomplete, error, and retry states without stale cross-request data.
- [x] T087 Implement Finance currency, duration, percentage, rating, count, kg, slope, and date formatting.
- [x] T088 Add complete canonical English Analytics messages and accessibility text.
- [x] T089 Add structurally identical natural Russian Analytics translations.
- [x] T090 Add structurally identical natural Ukrainian Analytics translations.
- [x] T091 Add responsive light/dark styles with no hardcoded scheme-breaking colours or horizontal overflow.
- [x] T092 Add the Analytics changelog entry in all three locales.

## Phase 7: Browser, Android, and Documentation

- [x] T093 Complete deterministic seeded E2E trend/bucket/table assertions.
- [x] T094 Complete period comparison, previous-zero, missing, and toggle E2E assertions.
- [x] T095 Complete ready/insufficient/zero-variance correlation E2E assertions.
- [x] T096 Complete source correction/reload and URL reload/new-context assertions.
- [x] T097 Complete API failure/retry/no-stale-state and aggregate-only interception assertions.
- [x] T098 Complete keyboard, ARIA, touch-target, and horizontal-overflow assertions.
- [x] T099 Add three-locale × two-scheme × desktop/mobile Analytics visual matrix.
- [x] T100 Synchronize the shared web bundle into Android and verify no native authority was added.
- [x] T101 Update README and canonical modules/decisions/roadmap/technical-design documents.
- [x] T102 Re-run Spec Kit traceability and update `analysis.md` with implementation and evidence.

## Phase 8: Final Quality Gates and Atomic Delivery

- [x] T103 Run focused Analytics plus affected source/Review/Profile compatibility suites.
- [x] T104 Run full Laravel with zero unexpected failure or skip.
- [x] T105 Run global Pint, strict Composer validation, and advisory/abandonment audit.
- [x] T106 Run i18n parity/used-key/hardcoded-copy, Vitest, typecheck, build, and web audit.
- [x] T107 Run full desktop Playwright and classify only documented conditional skips.
- [x] T108 Run full exact-390 Playwright and classify only documented conditional skips.
- [x] T109 Generate and visually inspect every Analytics locale/scheme/viewport/state screenshot.
- [x] T110 Run mobile sync/validation/tests/plugin inventory/fingerprint/audit without APK/device/deploy.
- [x] T111 Run query-budget, formula, OpenAPI/schema, strict-date, and route-identity final checks.
- [x] T112 Run raw-model/import, secret, sensitive-field, forbidden-scope, and unapproved-copy scans.
- [x] T113 Confirm 022 regressions and preserved seven-file handoff identity remain unchanged.
- [x] T114 Run `git diff --check` and verify zero deployment/workflow/generated/handoff changes.
- [x] T115 Refresh GitNexus and review staged changed symbols, direct dependents, and affected flows.
- [x] T116 Confirm all 115 preceding tasks and every specification checklist item are complete.
- [x] T117 Stage only 023 in-scope files and supporting regression fixes required by its gates.
- [x] T118 Create one atomic feature commit without attribution and push the current branch.
- [x] T119 Verify local HEAD equals `origin/master` and only preserved excluded files remain.
- [x] T120 Refresh the post-commit GitNexus index and durable SelfHandler memory, then continue to 024.

## Dependencies and Parallel Notes

RED contracts precede production targets. Generic math precedes adapters; owner-side primitives precede the
registry; API contracts/types precede UI; localization accompanies visible copy. No task authorizes a branch,
worktree, merge, deployment, workflow, feature 002, live data, destructive database command, APK/device action,
external provider, native analytics store, or modification of handoff/generated instruction files.
