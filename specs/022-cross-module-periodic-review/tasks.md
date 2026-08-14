# Tasks: Cross-Module and Periodic Review

**Input**: Complete design documents in this directory
**Tests**: Required by the constitution; permanent RED tests precede production implementation
**Organization**: Tasks are grouped by vertical phase and user story.

## Phase 1: Specification and Baseline

- [x] T001 Activate `specs/022-cross-module-periodic-review` in `.specify/feature.json`.
- [x] T002 Complete specification, checklist, research, data model, plan, quickstart, OpenAPI, and analysis.
- [x] T003 Map current Review/Today/module services with repository search and GitNexus context/impact.
- [x] T004 Record protected deployment/handoff exclusions and the clean 021 baseline.
- [x] T005 Run focused existing DailyReview/Today/API/client tests as the green baseline.
- [x] T006 Parse the 022 OpenAPI document and resolve every local reference.
- [x] T007 Re-run cross-artifact analysis after the contract/tasks are final.
- [x] T008 Confirm no unresolved clarification changes persistence, privacy, period, or score semantics.

## Phase 2: Permanent RED Contracts

- [x] T009 Add daily workspace/Today backward-compatibility API tests.
- [x] T010 Add owner/foreign/anonymous aggregate and reflection matrices.
- [x] T011 Add exact Nutrition score normalization/bounds tests.
- [x] T012 Add Workout planned/unplanned/unavailable score tests.
- [x] T013 Add Supplement pending/skipped/overdue denominator tests.
- [x] T014 Add Habit scheduled/successful/pending denominator tests.
- [x] T015 Add Planner done/skipped/pending/time-block denominator tests.
- [x] T016 Add composite equal-weight, rounding, partial, and null score tests.
- [x] T017 Add weekly/monthly/DST/leap/year-boundary period factory tests.
- [x] T018 Add PeriodicReview canonical invariant and first-completion tests.
- [x] T019 Add aggregate registry order, uniqueness, completeness, and contract tests.
- [x] T020 Add OpenAPI parse/ref/auth/closed-schema/operation tests.
- [x] T021 Add additive `periodic_reviews` schema/index/FK/identifier tests.
- [x] T022 Add TypeScript exports/client-route contract tests.
- [x] T023 Add EN/RU/UK parity/used-key/hardcoded-copy expectations.
- [x] T024 Add desktop/mobile periodic navigation/save/retry E2E skeletons.

## Phase 3: Additive Persistence and Review Core

- [x] T025 Create reversible owner-scoped `periodic_reviews` migration.
- [x] T026 Implement `PeriodicReview` constants, casts, ownership, fillable fields, and invariants.
- [x] T027 Add the User `periodicReviews` relationship.
- [x] T028 Implement strict timezone-aware ReviewPeriod value/factory.
- [x] T029 Implement request normalization: trim empty text to null and require one supplied field.
- [x] T030 Implement first-completion-preserving canonical upsert with retry-safe unique identity.
- [x] T031 Implement Review-owned bounded daily well-being summary.
- [x] T032 Pass schema/model/period/well-being focused tests.

## Phase 4: Module-Owned Aggregate Sources

- [x] T033 Add `ReviewAggregateSource` contract and deterministic registry.
- [x] T034 Implement module-owned Routine daily/period status summary.
- [x] T035 Implement module-owned Habit daily/period status summary.
- [x] T036 Implement module-owned Planner daily/period status/due/block summary.
- [x] T037 Wrap existing Sleep/Workout/Nutrition/Supplement/Finance module services without raw Review queries.
- [x] T038 Preserve rich Routine activity detail as an additive daily compatibility projection.
- [x] T039 Implement source daily/period adapters with stable keys and explicit empty/incomplete states.
- [x] T040 Add correction matrices proving every source is recomputed on read.
- [x] T041 Add weekly/monthly fixed query-budget tests and remove N+1/per-day loops.
- [x] T042 Pass focused source/registry/ownership/query tests.

## Phase 5: User Stories 1–2 — Daily Workspace and Day Score (P1)

- [x] T043 Implement `DayScoreService` with five stable ordered components.
- [x] T044 Implement Nutrition closeness/attainment contribution and reason evidence.
- [x] T045 Implement Workout planned/unplanned contribution and reason evidence.
- [x] T046 Implement Supplement, Habit, and Planner eligible-denominator contributions.
- [x] T047 Implement equal available-only weights, coverage, rounding, and null result.
- [x] T048 Implement daily Review workspace composer/response.
- [x] T049 Add authenticated daily workspace route/controller with no source model imports.
- [x] T050 Refactor Today to delegate module composition while retaining all existing response keys.
- [x] T051 Pass score, daily workspace, correction, ownership, and compatibility tests.
- [x] T052 Update 022 OpenAPI examples from verified response fixtures.

## Phase 6: User Stories 3–4 — Weekly and Monthly Review (P1)

- [x] T053 Implement periodic workspace GET for weekly/monthly anchors.
- [x] T054 Return canonical period, saved reflection/null, module aggregates, and well-being summary.
- [x] T055 Implement authenticated periodic upsert and exact validation limits.
- [x] T056 Preserve first completion and canonical alias identity across edits/retries.
- [x] T057 Add owner/foreign/anonymous route behavior without existence disclosure.
- [x] T058 Add duplicate/concurrent retry coverage for the unique period identity.
- [x] T059 Prove source correction changes aggregate but not saved reflection.
- [x] T060 Prove Planner/Goals links are navigation only and Review performs no foreign writes.
- [x] T061 Pass weekly/monthly API/model/ownership/concurrency/query tests.
- [x] T062 Rollback/reapply the feature migration and pass schema tests.

## Phase 7: Shared Browser Client (P1/P2)

- [x] T063 Add closed TypeScript types for periods, modules, score, well-being, and PeriodicReview.
- [x] T064 Add daily/periodic read and periodic save API client functions.
- [x] T065 Add Review mode navigation and URL-persisted daily/weekly/monthly anchors.
- [x] T066 Migrate Daily Review reads to the coherent daily workspace without changing its form semantics.
- [x] T067 Add day score card with value/null, coverage, components, values, and reason states.
- [x] T068 Add responsive eight-source module summary grid with explicit empty/incomplete states.
- [x] T069 Add weekly/monthly period header and well-being evidence.
- [x] T070 Add periodic reflection form with rating and five text fields.
- [x] T071 Add Planner/Goals follow-up links without direct mutations.
- [x] T072 Implement loading/empty/read-error/validation/unsaved/saving/saved/retry states.
- [x] T073 Preserve unsaved-form safety when refreshing aggregates or changing mode/date.
- [x] T074 Add semantic labels, live regions, visible focus, and keyboard flow.
- [x] T075 Pass client unit/typecheck/build tests.

## Phase 8: Localization, E2E, Mobile, and Docs

- [x] T076 Add canonical English Review/score/period/validation/changelog copy.
- [x] T077 Add complete Russian translations with locale-appropriate date/number formatting.
- [x] T078 Add complete Ukrainian translations with locale-appropriate date/number formatting.
- [x] T079 Pass key parity, used-key, hardcoded-copy, and source scan checks.
- [x] T080 Implement deterministic Playwright fixtures for daily/all-source/full-score state.
- [x] T081 Cover weekly canonical alias save/edit/reload desktop journey.
- [x] T082 Cover monthly leap/boundary save/edit/reload desktop journey.
- [x] T083 Cover null/partial score, empty aggregates, validation, and retry states.
- [x] T084 Cover exact 390x844 navigation/cards/forms with no horizontal overflow.
- [x] T085 Capture EN/RU/UK × light/dark/system review screenshots.
- [x] T086 Build contact sheets and visually inspect every screenshot.
- [x] T087 Build the shared web bundle and synchronize Capacitor Android assets.
- [x] T088 Update mobile source/fingerprint tests and verify no native authority/offline queue.
- [x] T089 Update changelog, design module/decisions/roadmap delivery notes, and feature quickstart evidence.

## Phase 9: Final Quality Gates and Atomic Delivery

- [x] T090 Run focused Laravel Review/compatibility suites.
- [x] T091 Run full Laravel suite with zero unexpected failure/skip.
- [x] T092 Run Pint, Composer strict validation, and Composer advisory/abandonment audit.
- [x] T093 Run frontend i18n, unit, typecheck, build, and npm audit gates.
- [x] T094 Run full desktop Playwright suite and classify only documented conditional skips.
- [x] T095 Run full exact-mobile Playwright suite and classify only documented conditional skips.
- [x] T096 Run mobile tests/plugin inventory/fingerprint/audit without APK/device/deploy.
- [x] T097 Run migration rollback/reapply and final OpenAPI/schema/identifier checks.
- [x] T098 Run forbidden scope, secret, public-path, raw Review query, and unapproved-copy scans.
- [x] T099 Refresh GitNexus and review staged changed symbols, routes, and affected flows.
- [x] T100 Confirm 021 regressions and preserved handoff identity are unchanged.
- [x] T101 Complete every task/checklist and final spec-to-code traceability matrix.
- [x] T102 Stage only 022 in-scope files and verify no deployment/generated/handoff path.
- [x] T103 Create one atomic feature commit and push current branch.
- [x] T104 Verify local HEAD equals `origin/master` and worktree contains only preserved excluded files.

## Dependencies and Parallel Notes

Tests in Phase 2 must be RED before their production targets. Persistence precedes periodic API; module
sources precede composition; API contracts/types precede UI; localization accompanies user-visible copy.
No task authorizes deployment, workflow changes, branch/worktree operations, APK/device actions, live data,
or modification of the preserved handoff/generated instruction files.

**Final checkpoint**: Feature 022 is complete. All 104 tasks, 17 specification-quality checks, permanent
contracts, implementation work, regression gates, and delivery safeguards have passed.
