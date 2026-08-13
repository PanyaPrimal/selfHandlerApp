# Specification Analysis: Habits and Anti-Habits

**Feature**: `013-habits-anti-habits`
**Analysis date**: 2026-08-13
**Artifacts**: spec, requirements checklist, research, plan, data model, OpenAPI 3.1, quickstart, tasks

## Result

**PASS — zero unresolved critical or high findings.** The feature is implemented and all required
non-deployment verification gates are green.

The analyzed contract contains 6 prioritized user stories, 26 functional requirements, 9 measurable
outcomes, 7 authenticated API operations, and 57 executable tasks. Every requirement maps to a story,
domain/cross-cutting decision, implementation task, and automated evidence task. The OpenAPI document
parses as 3.1.0 and contains no duplicate operation ids. The first analysis and red run preceded feature
013 application code; this final analysis was repeated against the completed implementation.

## Findings and Resolutions

| ID | Severity | Finding | Resolution | Status |
|---|---|---|---|---|
| A001 | High | “strictly decreasing ceiling” contradicted the canonical `1/day → 5/week` example if raw values were compared | Spec/research/model now compare normalized daily rate (`day=value`, `week=value/7`) | Resolved |
| A002 | High | A recurrence rule is polymorphic in schema, but the existing materializer enables routines by bare id; Habit id collisions would silently activate/disable the wrong owner | Plan/tasks require lifecycle dispatch by `(owner_type, owner_id)` plus collision/idempotency tests before API work | Resolved |
| A003 | Medium | OpenAPI `LimitStep` originally extended an `additionalProperties:false` input through `allOf`, which can reject response-only `id/status` under JSON Schema 2020-12 | Response schema is now a complete explicit closed object | Resolved |
| A004 | Medium | Changing a numeric target after facts would retroactively change derived success, but the user-visible rule was only in the data model | FR-003 now explicitly makes kind/mode immutable and locks numeric target/unit after the first fact | Resolved |
| A005 | Medium | Current `planned_occurrences` has only `routine_log_id`; status alone cannot be a numeric/relapse fact and a polymorphic rewrite would be destructive | Add one nullable unique `habit_log_id`, enforce exactly one fact link, and preserve either link during materialization/reconcile | Resolved |
| A006 | Low | “N times/week” could be misread as a floating weekly quota unsupported by the locked recurrence pattern | Assumption clarifies that N chosen weekdays is the documented `N times/week on given days`; no new floating semantic is inferred | Resolved |
| A007 | Low | The generated template label “Feature Branch” conflicted with the user's explicit no-branch workflow | Header changed to “Feature ID”; all work remains on current `master` | Resolved |

## Constitution and Roadmap Gate Audit

| Gate | Evidence | Result |
|---|---|---|
| Specifications before implementation | T001–T005 and all artifacts exist before product code | Pass |
| Canonical design ownership | Habit ≠ Routine/Goal; stepped limit ≠ milestone; recurrence/Planner/notifications remain owners | Pass |
| Thin vertical slice | One complete module loop; rollups, Review, AI, templates, push, offline are explicit future owners | Pass |
| Deterministic core | Mode success, streaks, percentages, step selection, periods, and limits are rule-based | Pass |
| User ownership/privacy | Immutable user ids, owned nested lookup, model guards, null/cascade FK rules, 404 boundary | Pass |
| Contracts and tests | Schema/domain/API/OpenAPI/TS/Vue/Planner/notifications/E2E tasks move together | Pass |
| Complete localization | Frontend/backend/reminder/a11y/changelog EN/RU/UK plus static/browser gates | Pass |
| Time/Profile inputs | UTC instants, explicit local dates, Profile timezone/locale, DST tests | Pass |
| Additive evolution | Three new tables + one nullable FK; ordered reversible rollback and preservation tests | Pass |
| Aggregate ownership | Habits services compute; Review/Analytics do not recompute or persist in 013 | Pass |

## Requirement Traceability

| Requirements | Story / owner | Implementation | Automated evidence |
|---|---|---|---|
| FR-001–FR-006 | US1 / Habit + Recurrence | T016–T026 | T006–T008, T011, T014, T021, T027 |
| FR-007–FR-009 | US1 / HabitLog | T018–T023 | T007–T009, T011, T027 |
| FR-010–FR-011 | US2 / Habit statistics | T028–T030 | T009, T031 |
| FR-012–FR-014 | US4 / Habit limit plan | T035–T037 | T010–T011, T038 |
| FR-015–FR-016 | US5 / outbound Habit links | T039, T042 | T007, T011–T012, T043 |
| FR-017 | US5 / Planner projection | T040, T042 | T008, T012, T014, T043 |
| FR-018 | US5 / Notifications delivery | T041 | T012, T014, T043 |
| FR-019 | US6 / Habit lifecycle | T018, T020, T044–T045 | T008, T011–T012, T014, T047 |
| FR-020–FR-022 | Cross-cutting | T016–T024, T048 | T006–T013, T050–T051 |
| FR-023–FR-025 | US6 / shared Vue+i18n | T025–T026, T030, T033, T037, T042, T045–T046 | T014, T047, T052–T053 |
| FR-026 | Closure / all owners | T048–T055 | T050–T054 |

All 26 requirements are covered; no orphan implementation task or unowned persisted fact was found.

## Contract Consistency

- Operations: list/create/update, log upsert/clear, statistics, and limit-plan replace are present in
  spec, plan, tasks, OpenAPI, and quickstart.
- Mode vocabulary is identical across artifacts: `yes_no`, `numeric`, `abstinence`, `stepped_limit`.
- Outcome vocabulary is identical: `done`, `not_done`, `recorded`, `protected`, `relapse`, `skipped`.
- Schedule vocabulary reuses feature 006: `daily`, `weekdays`, `MO`…`SU`, bounds, and preferred time.
- Lifecycle vocabulary is represented by active, paused (`is_active=false`), and archived.
- Unknown mutation fields are rejected; conditional cross-field rules deliberately live in Laravel
  tests because OpenAPI cannot express parent-record-mode-dependent validation safely.
- No deployment route, provider, credential, attachment, AI, finance, or external integration enters
  the contract.

## Pre-Implementation Test Order

T006–T014 are authored first. Their first focused run must fail because tables/classes/routes/view do
not yet exist, while fixtures and the OpenAPI parser themselves must load. Only then may T016 begin.
The failure summary will be appended below rather than marking production behavior complete early.

### Initial failing-run evidence

- Backend focused run after correcting one test-helper naming collision: **38 failed, 3 passed,
  27 assertions; exit 2**. Failures are the intended missing `Habit` models/services/migration/routes,
  absent schema column, and an empty registered operation set; OpenAPI parsing/vocabulary self-checks
  already pass.
- Desktop browser run: **1 expected failure, 3 not run due to `--max-failures=1`; exit 1**. Registration
  succeeds, then navigation cannot find the not-yet-implemented Habits destination. There are no fixture,
  compilation, or global-setup failures.
- No production file was introduced before these red results.

### Final verification evidence

#### Backend and contracts

- Final affected-suite run across Habits, Recurrence, Planner, Notifications, and Mobile:
  **126 passed, 720 assertions; exit 0**. This includes the additive migration rollback/preservation,
  MySQL identifier limits, owner boundaries, DST, fixed query budgets, Planner projection/reschedule,
  notification compatibility/dedupe/closure, mobile API, and all seven Habit OpenAPI operations.
- Full Laravel regression: **304 passed, 1,946 assertions; exit 0**.
- `vendor/bin/pint --test`: **pass** on the final PHP tree.
- Laravel route inspection reports exactly **7/7** Habit operations and every operation expands to
  `Illuminate\\Auth\\Middleware\\Authenticate:sanctum`. The OpenAPI parity test independently parses
  OpenAPI 3.1 and proves exact registered/documented operation equality and closed mutation schemas.

#### Web, browser, and Android client

- i18n guard: **790 keys** with EN/RU/UK parity across **69 source files**, with no blank, unknown,
  unused, or hardcoded product copy. TypeScript `vue-tsc --noEmit`: **pass**.
- Vitest: **5 files, 27 tests passed**. Vite production build: **131 modules transformed; pass**.
- Permanent focused Habit browser suite: **8 passed** (4 scenarios in each of desktop and exact
  390×844 mobile), including create/correct/clear, abstinence/limits, lifecycle rollback, keyboard,
  overflow, and live EN→RU→EN success-feedback localization.
- Full Playwright desktop project: **74 passed, 8 skipped; exit 0**. Full mobile project:
  **81 passed, 1 skipped; exit 0**. The only failures seen during closure were four stale mobile
  navigation expectations after Habits became a primary tab; the shared viewport-aware navigation
  helper and complete destination matrix were corrected, then the focused and both full projects passed.
- A temporary visual probe passed in both projects and generated **12 inspected screenshots**:
  EN/RU/UK × light/dark × desktop/390×844. Inspection confirmed readable contrast, wrapping without
  clipping, stable safe-area navigation, and no horizontal overflow. It also exposed a locale-switch
  success message that retained its old translation; Habits now stores a typed message key, the
  permanent E2E asserts reactive feedback, and the regenerated screenshots are localized. The probe
  itself was removed after inspection.
- Native wrapper tests: **15 passed**. Final production web bundle was synchronized through Capacitor
  and mobile structural validation passed with fingerprint `b0c7db145630`; generated bundle files are
  ignored and no tracked Android file changed.

#### Repository and scope audit

- `git diff --check`: **pass**. Credential-signature scan: **clean across 86 changed feature files**.
- Protected `specs/002-homelab-deployment`, `deployment/`, `_local-deploy/`, `deploy.ps1`, and workflow
  paths have no tracked or untracked change. No live deploy, provider, credential, or production-data
  action was performed.
- `design_handoff_selfhandler_mvp/` remains unrelated and untracked (**7 files**) and is excluded from
  staging. Temporary GitNexus-generated root `AGENTS.md`/`CLAUDE.md` files are absent; its local ignored
  index is not a deliverable.

### Final re-analysis and disposition

The completed spec, plan, research, data model, quickstart, OpenAPI, implementation, tests, and tasks
were cross-read again. Counts and vocabularies remain aligned: 6 stories, 26 requirements, 9 outcomes,
7 authenticated operations, 4 modes, 6 outcomes, 3 lifecycle views, and 57 tasks. No orphan requirement,
undocumented operation, unowned fact, destructive migration, or deployment work was found.

| ID | Severity | Final finding | Disposition | Status |
|---|---|---|---|---|
| F001 | Medium | Adding Habits to the three direct mobile destinations moved Goals behind More while four legacy E2E paths assumed a direct link | Reused `gotoDestination`, expanded the complete navigation matrix, then passed focused and full mobile/desktop suites | Resolved |
| F002 | Low | A success message stored rendered English and did not react when the user switched locale before it disappeared | Store a typed message key and translate at render time; permanent browser assertion plus regenerated visual matrix pass | Resolved |

**Unresolved critical/high/medium/low findings: none.** Feature 013 is complete and ready for its atomic
commit; deployment remains explicitly deferred and feature 014 is next.
