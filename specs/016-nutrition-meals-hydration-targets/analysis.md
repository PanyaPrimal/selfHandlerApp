# Specification Analysis: Nutrition, Meals, Hydration, and Targets

**Feature**: `016-nutrition-meals-hydration-targets`
**Analysis date**: 2026-08-13
**Artifacts**: spec, checklist, research, plan, data model, OpenAPI 3.1, quickstart, tasks

## Result

**PASS COMPLETE — zero unresolved critical or high design or implementation findings.**

The delivery contract contains 6 prioritized stories, 35 functional requirements, 10 measurable
outcomes, 13 authenticated API operations across 9 paths, and executable implementation/verification
tasks. Each requirement maps to an owner, story/cross-cutting gate, implementation task, and observed
automated evidence. All application and closure tasks are complete.

## Findings and Resolutions

| ID | Severity | Finding | Resolution | Status |
|---|---|---|---|---|
| A001 | High | Catalogue edits could silently rewrite consumed history | MealEntry stores a complete accepted reference/nutrition/hydration/quality snapshot; only explicit meal correction rebuilds it | Resolved |
| A002 | High | A recalculated target could move as Profile, goal, plan, intake, or actual work changes | First read inserts one immutable user/date snapshot; later reads return it byte-stably; refinement is derived separately | Resolved |
| A003 | High | Activity could be counted in both the Profile coefficient and planned Workouts | Documented coefficients are non-sport only; effective occurrences add only explicit optional WorkoutProgram energy once | Resolved |
| A004 | High | Recipe/reference ownership could expose another account or create mixed-owner children | Public-or-owned read, owned-only mutation, same-owner child guards, and 404 isolation apply at every relation boundary | Resolved |
| A005 | High | Formula/goal heuristics could be presented as medical truth | Inputs, missing fields, coefficients, conventional 7700 limitation, raw/capped/floored values, and water heuristic are machine-readable and labelled estimates | Resolved |
| A006 | Medium | Calories inferred from macros could conflict with catalogue energy | Catalogue calories remain authoritative; macros scale independently and no equality invariant is invented | Resolved |
| A007 | Medium | Recipe quality or beverages could distort food-quality progress | Recipe quality is solid-gram weighted; meal snapshots retain numerator/denominator; beverages contribute zero denominator | Resolved |
| A008 | Medium | Concurrent GET materialization could duplicate targets | Unique `(user_id,target_date)` plus transactional insert/retry returns the winning immutable row | Resolved |
| A009 | Medium | End-of-day actual energy might overwrite the stable reference or hide missing evidence | Refinement is never stored, replaces only planned energy in comparison, and reports explicit-energy missing counts | Resolved |
| A010 | Medium | Nutrition could become a second recurrence/Planner owner | It reads existing effective planned occurrences only and exposes no schedule, recurrence, or Planner mutation | Resolved |
| A011 | Medium | Archival could destroy historical references or allow stale new facts | Archive preserves rows/snapshots; new recipes/meals reject archived references; existing facts remain readable | Resolved |
| A012 | Low | Empty, zero, incomplete, and unavailable might collapse in UI | Closed DTOs carry explicit target/refinement status and nullable quality/target progress separately from numeric consumption | Resolved |
| A013 | Low | An unbounded history response could leak memory/query growth | Summary validates inclusive max 366 dates and uses owner/date aggregate queries with a fixed budget | Resolved |

## Constitution and Roadmap Gate Audit

| Gate | Evidence | Result |
|---|---|---|
| Specifications before implementation | T001–T005 complete before application files | Pass |
| Canonical Nutrition outcome | Food/recipe/meal/beverage/target/progress slice covered | Pass |
| Preserve shared owners | Profile/Goal/Workout input and Today/Review consumer boundaries explicit | Pass |
| Thin vertical slice | Private facts/references and transparent estimates; providers/content/AI deferred | Pass |
| Deterministic core | Snapshots, target calculation, refinement, and aggregates are source-derived | Pass |
| User ownership/privacy | Private roots/children, immutable water, authenticated 404 boundary | Pass |
| Contracts/tests | Thirteen operations and changed shared responses have paired evidence tasks | Pass |
| Complete localisation | EN/RU/UK formatting, static/browser/visual gates planned | Pass |
| Additive evolution | Seven tables plus one nullable Workout column; reversible order | Pass |
| Aggregate ownership | Nutrition computes; Today transports; Review presents; no rollup | Pass |

## Requirement Traceability

| Requirements | Owner / story | Implementation | Automated evidence |
|---|---|---|---|
| FR-001–FR-007 | Catalogue/recipes / US1 | T019–T032 | T006–T009, T014, T016–T018, T025, T032 |
| FR-008–FR-012 | Meal facts / US2 | T021–T024, T033–T040 | T007, T010, T014–T018, T040 |
| FR-013–FR-023 | Targets/refinement / US4 | T022–T024, T045–T054 | T011–T014, T016–T018, T054 |
| FR-024–FR-026 | Aggregation/history / US5 | T042–T044, T055–T060 | T012–T017, T060 |
| FR-027–FR-028 | Shared integrations / US6 | T061–T067 | T013–T017, T067 |
| FR-029–FR-035 | Clients/cross-cutting | T023–T078 | T014–T018, T025, T068–T077 |

All 35 requirements are covered. No orphan task, mutable duplicate aggregate, undocumented operation,
destructive migration, provider, deployment activity, or unresolved critical/high issue is present.

## Contract Consistency

- Thirteen operations are identical across spec/plan/tasks/OpenAPI/quickstart: food list/create/update;
  recipe list/create/update; settings read/update; day read; range summary; meal create/update/delete.
- Canonical bases are `gram|millilitre`; meal categories are `breakfast|lunch|dinner|snack|custom`.
- Every mutation schema is closed; owner, archived-new, XOR, AMDR sum, readiness, date, and concurrency
  invariants have paired Laravel evidence rather than loose OpenAPI claims.
- Existing WorkoutProgram and Today response contracts are updated at their originating feature docs.
- No deployment, provider, photo/attachment, medical inference, planning, reminder, long-period chart,
  export, or AI enters the contract.

## Pre-Implementation Test Order

T006–T017 are authored first. Their initial focused runs must fail on absent migration/models/services/
routes/fields/UI while schema-independent OpenAPI parsing continues to pass. Production files begin only
after intended RED evidence is recorded below.

### Initial failing-run evidence

- Before any feature application file existed,
  `php artisan test tests/Feature/Nutrition tests/Unit/Nutrition --compact` produced **41 failed,
  4 passed (683 assertions)**. The four schema-independent OpenAPI parse/reference/closed-schema and
  existing-index-name checks passed. Failures were the intended absent tables/models/services/routes,
  WorkoutProgram field, Today/Workout contract additions, and shared integrations.
- `npm run test:unit -- --run src/nutrition/nutrition-state.test.ts` produced **1 failed suite** at the
  intended missing `nutrition-state` module boundary; no test executed against a production stub.
- `npx playwright test e2e/nutrition --project=desktop` produced **4 failed**. Three journeys proved the
  unregistered `/nutrition` post-registration fallback to `/`; the locale journey proved the localized
  heading/form did not exist. The existing application registered and migrated normally.
- All eleven new PHP test files passed `php -l`; no fixture or contract mistake was found before the
  evidence run. Production implementation begins only after these recorded failures.

### Final verification evidence

- Focused final Nutrition backend: **49 passed (997 assertions)**. The wider affected Auth, Profile,
  Core Daily Loop, Body, Workout, and Mobile backend selection passed **206 tests (2,504 assertions)**.
  Pint passed.
- Final full Laravel regression: **452 passed (5,235 assertions)**. Schema preservation, account
  ownership/cascade, immutable public water/accepted entries/targets, MySQL-safe identifiers, route
  parity, closed OpenAPI schemas, and existing feature compatibility all passed.
- The final registered route audit reports exactly **13 authenticated Nutrition operations** across
  nine paths. The OpenAPI 3.1 contract parses, all **160 local references** resolve, and the changed
  feature 001 Today and feature 015 WorkoutProgram contracts pass their contract tests.
- Frontend gates passed with **1,181 keys** identical across EN/RU/UK and 75 source files checked,
  clean TypeScript, **31/31 Vitest tests**, and a 140-module production build. The only build message is
  the existing non-blocking Vite shared-chunk size warning.
- Focused final Nutrition browser journeys passed **8/8** across desktop and 390×844 mobile. A shared
  date-picker regression discovered by the wide run was resolved with a null-preserving current-date
  navigation cursor and localized previous/next-year controls; its focused cross-client gate passed
  **25 tests with one intentional desktop-only skip** and GitNexus rated the symbol change LOW risk.
- Final full Playwright regression passed **181 tests with 9 intentional project-condition skips and
  zero failures** across 190 desktop/mobile cases. Every feature 016 flow and runtime assertion passed.
- The permanent visual test generated and runtime-checked **36 images**: Nutrition, Today, and Review
  for EN/RU/UK, light/dark, desktop/mobile. All were inspected for hierarchy, contrast, clipping,
  horizontal overflow, fixed-navigation/safe-area behavior, and localized copy; no defect remained.
  The final run regenerated 18 desktop and 18 Pixel 7 device-scale images from logical 1366×900 and
  exact 390×844 viewports.
- Android Node tests passed **15/15**. The shared web build, Capacitor sync, four-plugin validation,
  HTTPS-only origin validation, native-vault/manifest/resource checks, and bundle validation passed
  with fingerprint **`87635a3df2fc`** using the non-live validation origin.
- Food, recipe, meal, settings, immutable target, hydration, range summary, Today/Review transport,
  nullable planned Workout energy, EN/RU/UK UI, rollback, archived/history, and account-isolation
  behaviors are implemented without a second recurrence, Planner, Review, or persisted-rollup owner.
- Composer and both web/mobile production npm audits report zero advisories. Final staged scope is 90
  files with clean `diff --check`, zero secret/credential signatures, zero deployment/workflow paths,
  and zero handoff files; the seven handoff files remain unrelated and untracked.
- GitNexus staged detection reports CRITICAL breadth because shared `User.profile`, Today, Workout,
  API-client, and date-control symbols fan into 39 indexed processes. Its stale index also falsely
  labels unchanged deployment and handoff documentation as touched and cannot classify every new file;
  authoritative Git staged-path checks are clean. The risk is accepted only because exact contract
  tests, 452-test backend regression, 181-test full browser regression, focused shared-date-control
  regression, Android validation, and owner-isolation/security evidence all pass without weakening.

## Final Disposition

- Critical/high: **0 open**.
- Medium/low: **0 open**. A006–A013 are resolved by exact persisted snapshots, explicit nullable states,
  immutable target identity, bounded aggregation, and tested ownership/lifecycle behavior.
- GitNexus breadth: **accepted with full regression evidence**; no untested direct dependency or
  protected-scope change remains.
- Deferrals remain deliberate and unchanged: provider imports, photos/attachments, medical claims,
  nutrient catalogue content, meal planning, reminders, long-period charting, export, AI, offline
  native ownership, and deployment.
