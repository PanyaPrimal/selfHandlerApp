# Tasks: Nutrition, Meals, Hydration, and Targets

**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/openapi.yaml](contracts/openapi.yaml),
[quickstart.md](quickstart.md)

**Tests**: Required at every boundary. Focused tests are written and observed failing before the
corresponding production implementation.

## Phase 1: Specification and Design

- [x] T001 Complete six prioritized stories, 35 functional requirements, localisation surface,
  assumptions/deferrals, 10 success criteria, and `checklists/requirements.md`
- [x] T002 Resolve reference/fact identity, per-100 scaling, recipe derivation, immutable snapshots,
  formula/activity/goal/macro/water policy, stable target, refinement, ownership and rollback in research
- [x] T003 Design the additive seven-table/one-nullable-column schema, relationships, derived values,
  uniqueness, deletion behavior, query ownership and ordered rollback in `data-model.md`
- [x] T004 Complete technical/localisation plan, ten Architecture Gates, implementation sequence,
  constitution re-check, closed 13-operation OpenAPI, and quickstart
- [x] T005 Run specification consistency analysis, record findings in `analysis.md`, and resolve every
  critical/high design issue before application code

**Checkpoint**: Approved internally consistent delivery contract exists before tests or application code.

---

## Phase 2: Failing Contract and Domain Evidence

- [x] T006 [P] Add additive schema, water seed, rollback, owner/FK/unique, MySQL identifier, preservation,
  and account-deletion tests in `NutritionSchemaTest.php`
- [x] T007 [P] Add Food/Recipe/component/Meal/entry/Settings/Target relationships, casts, same-owner,
  XOR, immutability, and lifecycle tests in `NutritionModelTest.php`
- [x] T008 [P] Add public/private visibility, immutable water, strict basis, duplicate, archive/restore,
  historical access, and bounded-query tests in `FoodCatalogueServiceTest.php`
- [x] T009 [P] Add ordered component replacement, exact per-100/quality, beverage/foreign/archive/self-
  rejection, transaction rollback, correction, and query-bound tests in `RecipeServiceTest.php`
- [x] T010 [P] Add atomic/recipe/beverage snapshot, local date, category/time, submission retry, update,
  deletion, edit-stability, future/owner/limit/rollback, and query tests in `MealServiceTest.php`
- [x] T011 [P] Add Mifflin/Katch/readiness/activity/goal magnitude/deadline/cap/floor/macro/water/planned-
  occurrence/concurrency/immutability/refinement tests in `NutritionTargetServiceTest.php`
- [x] T012 [P] Add selected-day/range totals, quality weighting, progress/null/zero/empty, max-366,
  correction/timezone/order, and fixed-query tests in `NutritionSummaryServiceTest.php`
- [x] T013 [P] Add Today/Review shared DTO, no-persistence, Profile/body-goal/Workout compatibility,
  account deletion, and client-boundary tests in `NutritionIntegrationTest.php`
- [x] T014 [P] Add strict lifecycle/ownership/readiness/date/range API evidence for all thirteen
  operations in `apps/api/tests/Feature/Nutrition/NutritionApiTest.php`
- [x] T015 [P] Add OpenAPI parse/ref/closed-schema/auth-operation/route parity plus changed Today and
  Workout contract tests in `NutritionOpenApiContractTest.php`
- [x] T016 [P] Add EN/RU/UK desktop/mobile catalogue/recipe/meal/hydration/target/history/Today/Review/
  rollback/accessibility/overflow journeys in `apps/web/e2e/nutrition/`
- [x] T017 [P] Add TypeScript response-shape and UI-state unit evidence for exact null/zero/incomplete
  semantics and queued accepted mutations
- [x] T018 Run focused backend and browser tests, correct test/fixture mistakes only, and record intended
  missing-schema/model/route/field/UI failures in `analysis.md`

**Checkpoint**: RED evidence fails only for intended missing feature behavior before production files exist.

---

## Phase 3: Additive Persistence and Shared Foundations

- [x] T019 Create reversible `2026_08_13_220000_create_nutrition.php` with seven feature tables,
  immutable exact plain-water seed, and nullable WorkoutProgram planned-energy column
- [x] T020 [P] Implement FoodItem/Recipe/RecipeComponent models with exact decimal casts, accessibility,
  lifecycle, relationships, public/private semantics, and same-owner guards
- [x] T021 [P] Implement Meal/MealEntry models with local date/time, submission identity, snapshot casts,
  XOR/reference/basis invariants, ordered children, and same-owner guards
- [x] T022 [P] Implement NutritionSettings/NutritionDailyTarget models and extend User/Goal/
  WorkoutProgram relationships and exact nullable planned-energy serialization
- [x] T023 Implement strict-key, list limit/order/uniqueness, owner-safe reference, date/range, basis,
  AMDR sum, settings, and lifecycle FormRequests with translated feedback
- [x] T024 Add common exact decimal scaling/rounding helpers and immutable-target insertion retry without
  generic repository or mutable rollup state
- [x] T025 Make T006–T008 and affected Profile/Body/Workout/schema/ownership regressions green; run Pint

**Checkpoint**: Portable private roots, relationships, seed, and shared input column exist safely.

---

## Phase 4: User Story 1 — Foods and Composite Recipes (P1)

**Goal**: Maintain exact private foods and ordered solid recipes beside immutable plain water.

**Independent Test**: Create solid/beverage foods and a two-component recipe; verify exact derived values,
owner isolation, correction, archive/restore, immutable water, and rejection of invalid components.

- [x] T026 [US1] Implement FoodCatalogueService public-or-owned reads, private create/update/archive/
  restore, strict solid/beverage invariants, duplicate handling, and historical-reference access
- [x] T027 [US1] Add food list/create/update controller, requests, resource, routes, active/archive filters,
  localized water key, owner-derived IDs, and 404 mutation boundary
- [x] T028 [US1] Implement RecipeNutritionService exact component totals/per-100/solid-weighted quality
  with deterministic rounding and null quality semantics
- [x] T029 [US1] Implement transactional RecipeService ordered atomic replacement, access/lifecycle/
  uniqueness/positive-mass checks, create/update/archive/restore, and rollback
- [x] T030 [US1] Add recipe list/create/update controller, requests, resource, routes, derived values,
  eager loading, filters, and strict same-owner 404 behavior
- [x] T031 [P] [US1] Add exact Food/Recipe/component TypeScript contracts and API functions
- [x] T032 [US1] Add accessible localized food catalogue and recipe editor/lifecycle UI; make T008–T009,
  T014, and focused browser catalogue/recipe journeys green

**Checkpoint**: Trustworthy exact reference data is independently usable through every current client.

---

## Phase 5: User Story 2 — Flexible Correctable Meals (P1)

**Goal**: Log and correct atomic/composite intake with immutable accepted snapshots.

**Independent Test**: Record/correct/reload/delete a categorized or timed meal and prove exact totals,
retry identity, owner isolation, catalogue-edit stability, and transactional child replacement.

- [x] T033 [US2] Implement MealService reference resolution and exact atomic/recipe snapshot creation for
  calories, macros, hydration, quality numerator/denominator, labels and basis
- [x] T034 [US2] Implement transactional meal create retry, owner/date validation, ordered replacement,
  update/rebuild/delete, future rejection, limits, and rollback with no partial facts
- [x] T035 [US2] Add day meal loading plus create/update/delete controllers, requests, resources, routes,
  Profile-local ordering, foreign 404s, and exact accepted-state responses
- [x] T036 [P] [US2] Add Meal/MealEntry/snapshot TypeScript contracts and exact API functions
- [x] T037 [US2] Add accessible flexible meal editor with independent category/time, mixed references,
  ordered quantities, note, recoverable draft, queued accepted mutations, rollback, and confirmations
- [x] T038 [US2] Add localized EN/RU/UK meal/reference/basis/category/time/note/validation/feedback/ARIA copy
- [x] T039 [US2] Expose exact day meals on `/nutrition` with correction/delete/focus recovery and honest
  empty/loading/error states
- [x] T040 [US2] Make T010/T014/T017 plus desktop/mobile meal identity/snapshot/correction journeys green

**Checkpoint**: Correctable daily intake facts are stable, exact, private, and retry-safe.

---

## Phase 6: User Story 3 — Beverage Hydration (P1)

**Goal**: One beverage fact contributes its explicit calories/macros and hydration without duplication.

**Independent Test**: Record/correct plain water and a caloric 0.8-ratio drink; verify exact ml scaling,
independent nutrition/hydration totals, strict solid rejection, and shared UI progress.

- [x] T041 [US3] Complete beverage snapshot and aggregate paths for millilitre quantities, fractional
  hydration, caloric macros, plain-water exactness, and solid zero-hydration enforcement
- [x] T042 [US3] Add hydration and beverage calorie progress/formatting controls to meal/day UI with
  canonical ml input, localized display, null/zero semantics, and no separate duplicate tracker
- [x] T043 [US3] Make T008/T010/T012/T014 and focused water/caloric-beverage browser journeys green

**Checkpoint**: Hydration is an exact projection of beverage meal facts, not a competing fact store.

---

## Phase 7: User Story 4 — Stable Daily Target and Refinement (P1)

**Goal**: Create one transparent immutable daily estimate and a separately labelled actual comparison.

**Independent Test**: Hand-calculate Mifflin/Katch targets, verify readiness/caps/planned energy, then
change every input and prove the target stable while explicit actual energy changes refinement only.

- [x] T044 [US4] Implement NutritionSettingsService/read/update with one active owned mass goal, AMDR
  percentages summing 100, optional water override, defaults, lifecycle validation, and owner guards
- [x] T045 [US4] Implement Profile readiness and exact Mifflin/Katch BMR plus documented non-sport
  coefficient selection, input/basis capture, and transparent missing-field codes
- [x] T046 [US4] Implement selected body-goal planning adjustment with direction/deadline validation,
  7700 approximation, raw/clamped ±1000 result, BMR floor, and limitation codes
- [x] T047 [US4] Read effective planned Workout occurrences and explicit program energy once; report
  missing estimates and avoid recurrence ownership or MET inference
- [x] T048 [US4] Implement 4/9/4 macro targets from the final calories and validated distribution with
  documented rounding; implement bounded weight/planned-duration water estimate and override
- [x] T049 [US4] Implement concurrency-safe first-read immutable target materialization and byte-stable
  subsequent reads for ready/incomplete states with complete explanatory basis
- [x] T050 [US4] Implement nonpersisted end-of-day refinement that replaces planned energy with explicit
  completed energy, reports missing facts, and never mutates the target
- [x] T051 [US4] Add settings read/update and selected-day target/refinement controllers/resources/routes,
  strict dates, owner scoping, exact status/null semantics, and eager/bounded loading
- [x] T052 [P] [US4] Add Settings/Target/breakdown/refinement TypeScript contracts and exact API functions;
  update WorkoutProgram contracts/UI for nullable planned energy
- [x] T053 [US4] Add accessible settings and target cards with formula/readiness/input/breakdown/cap/floor/
  limitation/refinement states, localized estimate copy, rollback, and focus/live feedback
- [x] T054 [US4] Make T011/T013–T015 and Mifflin/Katch/incomplete/immutable/refinement browser journeys green

**Checkpoint**: One explainable target stays stable through daytime change and actual comparison is honest.

---

## Phase 8: User Story 5 — Daily and Range Progress (P2)

**Goal**: Nutrition owns correction-safe day and bounded range aggregates.

**Independent Test**: Controlled multi-date meal snapshots produce exact totals/quality/progress before
and after corrections/deletions, with distinct empty/incomplete states and fixed query counts.

- [x] T055 [US5] Implement NutritionSummaryService set-query selected-day totals, non-beverage weighted
  quality, target progress, empty/zero/null distinctions, meal ordering, and fixed query plan
- [x] T056 [US5] Implement inclusive max-366 Nutrition-owned ordered per-day aggregate range with strict
  reversed/out-of-bounds validation and no persisted rollup
- [x] T057 [US5] Add day/range resources and GET summary route with exact decimals/statuses, bounded
  eager loading, target/refinement reuse, and authenticated owner scope
- [x] T058 [P] [US5] Add DailySummary/range/progress TypeScript contracts and exact API functions
- [x] T059 [US5] Add selected-day progress and bounded recent-history UI for calories/macros/hydration/
  quality with locale formatting and honest absent/zero/empty/incomplete states
- [x] T060 [US5] Make T012/T014/T017 plus correction/range/query/browser summary journeys green

**Checkpoint**: Daily and range progress has one deterministic module owner ready for consumers.

---

## Phase 9: User Story 6 — Shared Surfaces, Locales, and Clients (P3)

**Goal**: Nutrition, Today, Review, and Android agree without copied aggregate ownership.

**Independent Test**: One fact lifecycle produces the exact same DTO across all surfaces and locales/
themes/viewports, including rejected-write rollback and Android bundle validation.

- [x] T061 [US6] Inject selected-day Nutrition DTO into Today `module_summaries`; update feature 001
  contract/types/tests while preserving legacy summary and query ownership
- [x] T062 [US6] Make Review present the same Today Nutrition DTO without persisting/recomputing it and
  preserve all DailyReview draft/save/retry behavior
- [x] T063 [US6] Complete `/nutrition` route, desktop/More navigation, Today/Review deep links, selected-
  date hydration, locale-reactive feedback, and three-language changelog
- [x] T064 [US6] Complete simultaneous exact EN/RU/UK catalogue/recipe/meal/target/progress/history/
  validation/empty/error/ARIA dictionaries and hardcoded-copy cleanup
- [x] T065 [US6] Complete desktop/exact-390 CSS, 44px targets, safe areas, keyboard/focus/live regions,
  long localized copy, light/dark contrast, queued mutations, and no horizontal overflow
- [x] T066 [US6] Make T013/T016–T017 Today/Review/locale/accessibility/rollback/full-browser cases green
- [x] T067 [US6] Synchronize and validate final shared bundle in Capacitor with no native/offline owner

**Checkpoint**: The full Nutrition loop agrees across current daily surfaces and clients.

---

## Phase 10: Contracts, Documentation, and Full Closure

- [x] T068 Make T015 parse/ref/closed-schema/auth-operation/route parity green for all 13 operations;
  verify changed feature 001/015 OpenAPI and every TypeScript consumer uses exact fields
- [x] T069 [P] Add feature 016 changelog and update README, ARCHITECTURE, modules, decisions, roadmap,
  data conventions, Today/Review, and Workout docs with implementation/estimates/deferrals
- [x] T070 Run focused Nutrition plus affected Auth/Profile/CoreDailyLoop/Body/Workout/Mobile Laravel
  tests and Pint; fix failures without weakening assertions
- [x] T071 Run full Laravel tests, migration preservation/MySQL identifier/ownership/security gates,
  and record exact counts in `analysis.md`
- [x] T072 Run i18n guard, typecheck, Vitest, production build, and focused Playwright both projects;
  record exact pass counts
- [x] T073 Run full desktop and full mobile Playwright after final CSS/code; record pass/skip counts
- [x] T074 Capture/inspect EN/RU/UK light/dark desktop and 390×844 Nutrition/Today/Review images; fix
  contrast, clipping, focus, console/page errors, and overflow, then rerun probes
- [x] T075 Run mobile Node tests and final HTTPS-origin Capacitor sync/validation; record fingerprint
- [x] T076 Run diff check, broad secret scan, OpenAPI/route scan, protected deployment-path audit,
  handoff/untracked audit, and GitNexus pre-commit impact/detect-changes review
- [x] T077 Re-run specification analysis, resolve all critical/high and document medium/low disposition;
  set spec Complete and mark tasks only against recorded evidence
- [x] T078 Update canonical workspace memory, stage only feature 016 files, verify 78/78 and protected/
  handoff scope, create one atomic commit without co-author, push master, update memory SHA, and verify
  HEAD equals `origin/master`

---

## Dependencies and Execution Order

- T001–T005 block tests/code. T006–T017 are authored before T018 records RED evidence.
- T019–T025 establish portable references/facts/settings/target foundations and block every story.
- US1 establishes references; US2 consumes them; US3 completes beverage behavior; US4 consumes shared
  Profile/Goal/Workout inputs; US5 aggregates facts/targets; US6 integrates finished owners.
- T068–T078 run only after every story checkpoint is green. `[P]` marks file independence, not
  permission for a branch, worktree, sub-agent, deployment, or parallel feature.

## Traceability

| Story | Requirements | Primary tasks |
|---|---|---|
| US1 catalogue/recipes | FR-001–FR-007 | T006–T009, T019–T032 |
| US2 meal facts | FR-008–FR-012 | T007, T010, T021–T024, T033–T040 |
| US3 hydration | FR-002–FR-003, FR-009–FR-012, FR-024 | T008, T010, T041–T043 |
| US4 targets/refinement | FR-013–FR-023 | T011, T013–T015, T044–T054 |
| US5 summaries/history | FR-024–FR-026 | T012, T014, T055–T060 |
| US6 integrations/clients | FR-027–FR-034 | T013–T017, T061–T067 |
| Cross-cutting closure | FR-029–FR-035, SC-001–SC-010 | T068–T078 |

## Notes

- Deployment, feature 002, live providers/data, and `design_handoff_selfhandler_mvp/` are excluded.
- Do not check an application task before its concrete file/behavior/evidence exists.
- Repository docs remain English; every product string ships EN/RU/UK together.
