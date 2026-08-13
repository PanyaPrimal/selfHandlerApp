# Specification Analysis: Workouts and Training Goals

**Feature**: `015-workouts-training-goals`
**Analysis date**: 2026-08-13
**Artifacts**: spec, checklist, research, plan, data model, OpenAPI 3.1, quickstart, tasks

## Result

**PASS — zero unresolved critical or high findings.** The feature is fully implemented and verified.

The delivery contract contains 6 prioritized stories, 34 functional requirements, 10 measurable
outcomes, 15 new authenticated API operations across 10 paths, and 78 executable tasks. Every
requirement maps to an owner, story/cross-cutting gate, implementation task, and automated evidence.
The OpenAPI document parses as 3.1.0, all 228 local references resolve, and operation ids are unique.

## Findings and Resolutions

| ID | Severity | Finding | Resolution | Status |
|---|---|---|---|---|
| A001 | High | A Planner-side workout skip could create a second truth beside domain facts | Skipped planned work is one WorkoutSession fact with no subtype; occurrence links it exactly like completion | Resolved |
| A002 | High | Nullable catalogue ownership could expose or mutate another user's custom exercise | Catalogue visibility is exactly global-or-owned; only owned rows mutate, built-ins are immutable, every reference rechecks access | Resolved |
| A003 | High | Sparse subtype fields/JSON could allow one session to be both strength and running | Common wrapper plus mutually exclusive class-table detail; services/models/tests enforce exactly one matching detail for completion and none for skip | Resolved |
| A004 | High | A stored progression counter would drift after historical correction | Progression is a pure chronological fold over source facts and prescription inputs; no counter/next-target aggregate is persisted | Resolved |
| A005 | Medium | A schedule edit could rewrite historical prescriptions or delete evidence | Program edits affect future projections/suggestions; fact/reschedule occurrences and session child facts remain immutable unless explicitly corrected | Resolved |
| A006 | Medium | Manual and planned facts on the same date could collide or retries could duplicate | Planned PUT is unique by owner/program/effective date and occurrence; manual POST has null program and may coexist under SQL nullable semantics | Resolved |
| A007 | High | Extending Goal generically could break Body/general contracts or copy Workout progress | Training uses the proven typed-detail pattern; shared Goal owns lifecycle only and Workout derives current/progress | Resolved |
| A008 | Medium | Goal start could silently change when history is corrected or scope edited | Kind/scope and derived current-or-zero creation snapshot are immutable; target/common lifecycle may change; absent current history gives null progress | Resolved |
| A009 | Medium | A race deadline modelled as recurrence would create fake repeated events | Race is one TrainingGoalSource event on target date; program occurrences remain a separate repeating source | Resolved |
| A010 | High | Automatic energy expenditure could become unsafe or make feature 016 targets drift | Only explicit user-entered actual energy and stable program inputs are stored; MET/HR formulas and Nutrition policy are deferred to the owning feature | Resolved |
| A011 | Medium | Rescheduling onto an existing same-program day would make fact lookup ambiguous | Planner rejects another effective occurrence for the same rule/date before movement; planned write requires exactly one match | Resolved |
| A012 | Medium | Public catalogue seeds might be mistaken for deployment/user data | Six generic reference rows live only in the new table/migration; no ready-made program/user fact/provider/live data is inserted | Resolved |
| A013 | Low | “Best pace” without both distance and duration could display a fabricated zero | Pace/record is nullable and derived only from positive distance plus duration | Resolved |
| A014 | Low | Long-term analytics could tempt an unbounded history response | History/ranges cap at 366 dates with fixed query evidence; feature 023 retains rollup/chart ownership | Resolved |

## Constitution and Roadmap Gate Audit

| Gate | Evidence | Result |
|---|---|---|
| Specifications before implementation | T001–T005 complete before feature application files | Pass |
| Canonical Module 3 outcome | Catalogue, recurring program, four workout families, progression, records, training goals covered | Pass |
| Preserve shared owners | Profile/Goal/recurrence/Planner/Notifications/Today/Review remain authoritative | Pass |
| Thin vertical slice | One linear scheme and manual inputs; content/integrations/GPS/AI/charts explicitly deferred | Pass |
| Deterministic core | Pace, volume, records, progression, summaries, and goal progress are source-derived | Pass |
| User ownership/privacy | Private roots/children, global immutable catalogue, authenticated 404 boundary | Pass |
| Contracts and tests | Fifteen operations plus changed existing responses have backend/OpenAPI/TS/Vue evidence | Pass |
| Complete localisation | Full EN/RU/UK surface, formatting, static/browser/visual gates planned | Pass |
| Additive evolution | Twelve tables plus one nullable fact FK; reversible ordered rollback | Pass |
| Aggregate ownership | Workout set-query services; Today transports, Review/Planner present, no rollup | Pass |

## Requirement Traceability

| Requirements | Owner / story | Implementation | Automated evidence |
|---|---|---|---|
| FR-001–FR-007 | Catalogue/program / US1 | T019–T034 | T006–T009, T014, T016–T018, T025–T026, T034 |
| FR-008–FR-015 | Session facts / US2–US3 | T021–T024, T035–T045 | T007, T010, T012, T014–T018, T041, T045 |
| FR-016–FR-020 | Progression/statistics / US4 | T046–T050 | T011–T012, T014–T017, T050 |
| FR-021–FR-024 | Training goals / US5 | T022, T051–T058 | T007, T013–T017, T058 |
| FR-025–FR-028 | Shared integrations / US6 | T023, T038, T054, T059–T062 | T009–T010, T013, T015–T017, T065 |
| FR-029–FR-034 | Clients/cross-cutting | T024, T027–T078 | T014–T018, T025–T026, T067–T076 |

All 34 requirements are covered; no orphan task, unowned fact, undocumented operation, destructive
migration, speculative provider, deployment activity, or unresolved critical/high issue was found.

## Contract Consistency

- Fifteen operations are identical across spec/plan/tasks/OpenAPI/quickstart: exercise list/create/
  update; program list/create/update/exercise replacement; planned session upsert; workout history/
  manual create/update/delete; training-goal list/create/update.
- Schedule vocabulary reuses `daily|weekdays` and `MO`…`SU`; outcomes are `completed|skipped`;
  workout types are `strength|cardio|flexibility|sport`.
- Canonical API units are kg/metres/seconds; local input separates date/time from stored UTC instant.
- Every mutation and nested object schema is closed; conditional subtype/owner/schedule/DST rules have
  paired Laravel evidence tasks rather than loose OpenAPI guesses.
- Existing Goal/Today/Planner/Notification responses will be updated in their originating contracts.
- No deployment, provider, attachment, GPS, wearable, medical advice, licensed content, finance,
  Nutrition calculation, long rollup, or AI enters the contract.

## Pre-Implementation Test Order

T006–T017 are authored first. Their initial focused run must fail on absent migration/models/services/
routes/fields/UI while schema-independent OpenAPI parsing and fixture boot continue to pass. Production
files begin only after that red evidence is recorded below.

### Initial failing-run evidence

Recorded before any feature application file existed:

- `php artisan test tests/Feature/WorkoutsTrainingGoals tests/Unit/WorkoutsTrainingGoals --compact`
  produced **41 failed, 4 passed (957 assertions)**. The four schema-independent OpenAPI/index-name
  checks passed; failures were the intended absent feature migration/tables, models, services, routes,
  recurrence/fact owner, shared-contract fields, and integrations.
- `npx playwright test e2e/workouts-training-goals --project=desktop` produced **4 failed**. Three
  journeys proved `/workouts` was not registered (post-registration fallback to `/`), and the locale
  journey proved the Workouts heading/form did not exist.
- One pre-evidence fixture defect was corrected: the collision test now creates an existing yes/no
  Habit with its required `kind` and `mode`. Contract-path expectations were also aligned with the
  already-shipped `TodayResponse`, `PlannerSource`, and nested `NotificationSettings` schemas. A
  focused rerun then failed only at the intended missing `WorkoutProgram` boundary.

### Final verification evidence

- Focused Workout backend: **46 passed (1334 assertions)**, including all fifteen authenticated
  operations, OpenAPI 3.1/reference/closed-schema/route parity, additive rollback, ownership,
  recurrence, fact identity, statistics, progression, Planner, notifications, Today, and Review.
- Full backend regression: **403 passed (4234 assertions)**; Pint passed. The migration remains
  additive/reversible and every new identifier remains SQLite/MySQL-safe.
- Web static/client gates: i18n guard **1063 keys across EN/RU/UK and 72 source files**, TypeScript
  passed, Vitest **27/27**, and production build passed with **137 modules**. The existing >500 kB
  bundle advisory is non-blocking and remains owned by later performance work.
- Focused permanent Workout Playwright: **8/8** across desktop/mobile. A rapid goal lifecycle race
  found by the first full run was fixed by serializing accepted mutations and then passed **3/3**
  mobile stress repetitions. A separate late Today date-load focus race found by the next full run
  was fixed by relying on the date surface's immediate focus restoration and passed **5/5** mobile
  stress repetitions.
- Final full Playwright after both fixes: **171 passed, 9 project-conditional skipped, 0 failed**
  across all 180 desktop/mobile cases. No assertion, timeout, or retry weakening was used.
- Visual audit: **48/48** EN/RU/UK light/dark desktop and 390x844 screenshots for Workouts, Today,
  Review, and Planner were inspected with no clipping, overlap, contrast, or horizontal-overflow
  defect.
- Mobile: Node tests **15/15**; final HTTPS-origin Capacitor sync/validation passed with bundle
  fingerprint **`d8f49ad85061`** and all four expected plugins.
- Repository audit: `git diff --check` passed; 109 changed/new feature files produced zero
  high-confidence secret or conflict-marker matches; protected deployment paths have empty status
  and diff; `design_handoff_selfhandler_mvp/` remains unrelated and untracked.
- GitNexus `detect_changes(scope=staged)` reports 109 feature files, 139 changed symbols, and 77
  affected flows at critical breadth. That breadth is expected for the deliberately shared
  recurrence, fact, Goal, Planner, Today, Review, notification, API-client, and control integrations
  and is covered by the full backend/browser regressions above. Protected deployment and unrelated
  handoff files are absent from the staged scope. No unresolved actionable critical/high finding
  remains.

## Final Analyze Disposition

- All 34 functional requirements and 10 success criteria retain an implementation owner and
  executable evidence; all 78 tasks are complete.
- The delivered UI exposes the complete custom-exercise, program, session, correction, record, and
  training-goal lifecycle rather than API-only capabilities.
- Medium/low design findings A005, A006, A008, A009, A011, A013, and A014 are closed by explicit
  identity, immutable-history, single-event, collision, nullable-record, and bounded-range tests.
- Deployment, providers, GPS/wearables, medical inference, licensed templates, automatic calorie
  estimation, long-term chart rollups, and AI remain intentionally deferred to their owning features.
