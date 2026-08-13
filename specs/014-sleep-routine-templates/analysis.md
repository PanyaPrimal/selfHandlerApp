# Specification Analysis: Sleep and Rich Routine Templates

**Feature**: `014-sleep-routine-templates`
**Analysis date**: 2026-08-13
**Artifacts**: spec, checklist, research, plan, data model, OpenAPI 3.1, quickstart, tasks

## Result

**PASS — zero unresolved critical or high findings.** The feature is implemented and fully verified.

The delivery contract contains 6 prioritized stories, 32 functional requirements, 10 measurable
outcomes, 10 new authenticated API operations across 8 paths, and 72 executable tasks. Every
requirement maps to an owner, story/cross-cutting gate, implementation task, and automated evidence.
The OpenAPI document parses as 3.1.0, all local references resolve, and operation ids are unique.

## Findings and Resolutions

| ID | Severity | Finding | Resolution | Status |
|---|---|---|---|---|
| A001 | High | Independently resolved activities could leave the existing parent RoutineLog/occurrence/reminder in contradictory states | FR-014 and research define one derivation: pending child removes parent fact; all done = done; any skip after all resolve = skipped | Resolved |
| A002 | High | “No selection yet” and “explicitly choose no template” were indistinguishable if absence alone represented both | Nullable selection rows now distinguish explicit none from no row; deterministic default applies only to absence | Resolved |
| A003 | High | Keeping planned wake time only on the current SleepPlan would rewrite historical planned-vs-actual context after edits | Add one module-owned occurrence-detail snapshot; only unlinked future details update | Resolved |
| A004 | Medium | A portable unique index on `(user_id,is_active)` would wrongly allow only one paused plan as well as one active plan | Enforce one active non-archived plan transactionally; schema keeps an ordinary lookup index | Resolved |
| A005 | Medium | “Sleep date” and cross-midnight/DST input were underspecified and could yield different instants in browser/profile zones | Night date is planned-bedtime date; backend resolves explicit local fields in Profile timezone, rejects gaps/invalid durations, stores UTC | Resolved |
| A006 | Medium | Feeding aggregates into Review could be interpreted as copying them into DailyReview | Today transports owner DTOs; Review reads the same DTO and persists no sleep/activity field | Resolved |
| A007 | Medium | Filtering Planner but not Today/writes/notifications would allow unselected templates to remain actionable | FR-019 requires one routine-owned day projection service for all four consumers | Resolved |
| A008 | Low | Planner “skip sleep” has no factual meaning in a sleep record contract | Sleep Planner source exposes reschedule only; missing sleep remains unknown, never skipped | Resolved |
| A009 | Low | Activity-list edits after facts could retroactively change strict parent completion | Membership and progress totals lock after first fact; cosmetic edits only, with archive/duplicate as future workflow | Resolved |
| A010 | Medium | Routine-selection deletion was described as both `nullOnDelete` and cascade, producing different post-delete intent | The nullable FK now cascades its selection row on hard routine deletion; explicit-none rows have no FK and remain until replacement | Resolved |
| A011 | High | A plan-only detail hook would let global `recurrence:materialize` commit sleep occurrences without required wake snapshots | The shared materializer now owns bounded atomic detail insertion/update for every sleep-owner path; linked snapshots remain immutable | Resolved |
| A012 | Medium | The data model promised per-template counts and honest empty state while OpenAPI exposed only totals and numeric zero-style rate | Routine summaries now require per-template rows and nullable completion rates when no rich activity is scheduled | Resolved |

## Constitution and Roadmap Gate Audit

| Gate | Evidence | Result |
|---|---|---|
| Specifications before implementation | T001–T005 complete; no feature 014 application code exists | Pass |
| Canonical Module 1 outcome | Planned/actual sleep, quality, ordered activities, independent slot choice and daily aggregates are covered | Pass |
| Preserve shared owners | Routine source/rule/log remain; Sleep adds one owner; Planner/Review/Notifications copy nothing | Pass |
| Thin vertical slice | One nightly plan; naps/shifts/versioning/wearables/medical advice/rollups explicitly deferred | Pass |
| Deterministic core | Duration, parent state, defaults, summaries and lifecycle are algorithmic | Pass |
| User ownership/privacy | Every table user-owned; nested same-owner; authenticated 404 boundary; no external health transfer | Pass |
| Contracts and tests | Ten operations plus existing response changes have paired backend/OpenAPI/TS/Vue evidence | Pass |
| Complete localization | Full EN/RU/UK surface, formatting, static/browser/visual gates planned | Pass |
| Additive evolution | Six tables and two added columns (one nullable fact link), reversible ordered rollback | Pass |
| Query/aggregate ownership | Set-query owner services, Today transport, Review presentation, no rollup | Pass |

## Requirement Traceability

| Requirements | Owner / story | Implementation | Automated evidence |
|---|---|---|---|
| FR-001–FR-008 | Sleep / US1 | T018–T030 | T006–T010, T013–T017, T023–T031 |
| FR-009–FR-011 | Routine definition / US2 | T020, T032–T036 | T006–T007, T011, T013, T016, T037 |
| FR-012–FR-015 | Routine facts / US3 | T038–T042 | T011, T013–T016, T043 |
| FR-016–FR-019 | Routine selection / US4 | T044–T048 | T012–T016, T049 |
| FR-020–FR-021 | Today/Review / US5 | T050–T053 | T010, T012, T014, T016, T054 |
| FR-022–FR-025 | Planner/notifications/lifecycle / US6 | T021, T025, T039, T044, T055–T059 | T008–T009, T011–T016, T061 |
| FR-026–FR-032 | Cross-cutting | T018–T064 | T006–T017, T063–T070 |

All 32 requirements are covered; no orphan task, unowned fact, undocumented operation, destructive
migration, or deployment activity was found.

## Contract Consistency

- Ten operations are identical across spec/plan/tasks/OpenAPI/quickstart: sleep list/create/update/log
  upsert/log clear/statistics; activity replace/log upsert/log clear; day-selection replace.
- Schedule vocabulary reuses `daily`, `weekdays`, `MO`…`SU`; day period is exactly
  `morning|evening|anytime`; outcomes are exactly `done|skipped`.
- Sleep input/output separates local wall fields from UTC instants and derived duration.
- Every mutation schema is closed; conditional parent/mode/owner/DST rules live in Laravel evidence.
- Existing Routine/Today/Planner/Notification responses are explicitly updated in their originating
  contracts rather than silently overridden by 014.
- No deployment, provider, attachment, AI, alarm, wearable, medical, finance, or external integration
  enters the contract.

## Pre-Implementation Test Order

T006–T016 are authored first. Their initial focused run must fail on absent migration/models/services/
routes/fields/UI while schema-independent OpenAPI parsing and fixture boot continue to pass. Production
files begin only after that red evidence is recorded below.

### Initial failing-run evidence

T017 completed before any feature 014 production file existed.

- `php artisan test tests/Feature/SleepRoutineTemplates tests/Unit/SleepRoutineTemplates`:
  **53 tests total; 4 passed and 49 failed**. The passing evidence was schema-independent OpenAPI
  parse/ref/closed-schema plus the pre-existing-index length check. Failures were the intended absent
  sleep/activity/selection tables, models, constants, services, relationships, routes, response fields,
  recurrence owner, Planner/notification integration, and Today summaries. One test-only destructuring
  warning found on the first run was corrected before this recorded run.
- `npx playwright test e2e/sleep-routines --project=desktop`: **4 failed**. The failures were the
  intended absent Routines & Sleep heading/form, day-period control, activity workspace, and RU/UK
  sleep copy. Playwright discovery separately listed all **8** desktop/mobile cases without a compile
  error.

This is accepted red evidence, not a runtime regression: feature 014 application implementation begins
only after these missing-boundary failures were observed.

### Final verification evidence

T063–T070 completed against the final working tree on 2026-08-13.

- **Focused backend**: the Sleep/Routine and affected auth, CoreDailyLoop, recurrence, Planner,
  notification, and ownership suites passed **108 tests / 1,506 assertions**. The final full Laravel
  run passed **357 tests / 2,894 assertions**; `vendor/bin/pint --test` passed.
- **Contracts**: OpenAPI 3.1 parse/reference/closed-schema/auth-operation/route-parity tests passed for
  all ten feature operations and the changed feature 001/009/011 contracts. Strict request tests
  cover unknown keys, nested activities, ownership, dates, DST, and lifecycle transitions.
- **Web static/unit**: the i18n guard passed **900 keys × EN/RU/UK across 71 source files**;
  TypeScript passed; Vitest passed **5 files / 27 tests**; production build transformed **135 modules**.
- **Browser**: permanent focused Sleep/Routine journeys passed **4/4 desktop and 4/4 mobile**. The
  full desktop project passed **78 with 8 conditional skips**; the final post-CSS mobile project
  passed **85 with 1 desktop-only skip**. There were no failures.
- **Visual/accessibility**: **36** seeded screenshots covered Routines & Sleep, Today, and Review in
  EN/RU/UK, light/dark, desktop and exact 390×844. Every image was inspected; overflow, console, and
  page-error probes passed. Mobile activity and management actions were corrected to wrap without
  clipping or vertical word breaks, then the focused/visual probes were rerun green.
- **Bundled Android client**: Node tests passed **15/15**; Capacitor synchronized four existing
  plugins and validation passed with final bundle fingerprint `3e2f9cd8d3b2`. No native domain,
  signing, deployment, or live-device action was added.
- **Scope/security audit**: `git diff --check` passed; **114** feature files were scanned with zero
  private-key/provider-token patterns; explicit git status/diff checks found **0** protected
  deployment/feature-002/workflow changes. The **7** untracked handoff files remain untouched and
  excluded. OpenAPI route coverage is also enforced by the full Laravel run.
- **Impact review**: GitNexus mapped the intentionally cross-cutting recurrence/Today/Planner/
  notification change to 54 existing flows and raised a broad critical-size signal. Targeted API
  impact for Today, Planner day, and Routine routes was **LOW** with no indexed direct consumers.
  The broad signal is accepted because the shared owners are intentionally extended, not replaced,
  and focused plus full backend/desktop/mobile regression suites are green.
- **Spec Kit re-analysis**: the prerequisite script resolves the canonical feature directory and all
  required artifacts; all 32 functional requirements and 10 success criteria remain mapped across
  72 tasks, the checklist is complete, and no clarification/TODO/TBD, orphan requirement, conflicting
  owner, destructive migration, or unresolved critical/high finding remains.
