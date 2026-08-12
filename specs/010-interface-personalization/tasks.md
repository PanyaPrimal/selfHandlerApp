# Tasks: Interface Personalisation and Complete Localisation

**Input**: Design documents from `/specs/010-interface-personalization/`

**Prerequisites**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/openapi.yaml`

**Tests**: Required by the feature and Constitution VI/VII. Failing focused checks precede the
implementation they protect; the complete gate closes the feature.

## Phase 1: Governance and Setup

- [X] T001 Update `docs/design/delivery-roadmap.md` and affected completed-spec references so this
  feature is 010 and the previously provisional queue becomes 011-026.
- [X] T002 Finalize the constitution/templates and `docs/design/localization.md` three-locale delivery
  rule (FR-025).
- [X] T003 Point `.specify/feature.json` at `specs/010-interface-personalization` and validate all
  feature artifacts/checklists.
- [X] T004 Add `apps/web/scripts/check-i18n.mjs` and package scripts with failing fixtures/check modes
  for parity, unknown/unused static keys and hardcoded product copy (FR-024, SC-006).

## Phase 2: Failing Contract and Browser Coverage

- [X] T005 [P] Add failing preference PATCH, ownership, unknown-key, atomicity, locale and legacy-theme
  tests in `apps/api/tests/Feature/Profile/ProfilePreferenceApiTest.php` (FR-008-FR-014, SC-007).
- [X] T006 [P] Extend authentication/domain tests to request EN/RU/UK validation and warning/refusal
  feedback in the active request/profile locale (FR-012-FR-013).
- [X] T007 [P] Add failing guest/auth first-paint, reconciliation, rapid-change and rollback journeys
  in `apps/web/e2e/preferences/global-preferences.spec.ts` (FR-006-FR-012, FR-021-FR-023).
- [X] T008 [P] Add failing background preset/custom/invalid/contrast/persistence journeys in
  `apps/web/e2e/preferences/background.spec.ts` (FR-014-FR-020, SC-004).
- [X] T009 [P] Add failing all-route EN/RU/UK copy/format/accessibility coverage in
  `apps/web/e2e/localization/current-ui.spec.ts` (FR-001-FR-005, FR-028, SC-001).
- [X] T010 [P] Add failing exact 390x844 global-control, longest-copy and overflow coverage in
  `apps/web/e2e/localization/mobile.spec.ts` (FR-006, FR-028, SC-005).

## Phase 3: Localisation Foundation

- [X] T011 Define canonical keys and complete `apps/web/src/i18n/locales/{en,ru,uk}.ts` catalogs for
  the entire current UI and static changelog (FR-001-FR-004).
- [X] T012 Implement reactive locale/cache/interpolation/plural/Intl behavior in
  `apps/web/src/i18n/index.ts` and update `apps/web/src/lib/format.ts` (FR-005, FR-007-FR-008).
- [X] T013 Update `apps/web/index.html` and `apps/web/src/main.ts` for compatible validated
  locale/theme prehydration with safe storage fallback (FR-007, FR-022-FR-023).
- [X] T014 Send the active locale on API and CSRF requests in `apps/web/src/api/http.ts` (FR-012).
- [X] T015 Implement API request/profile locale selection in
  `apps/api/app/Http/Middleware/UseRequestLocale.php` and register it in `apps/api/bootstrap/app.php`
  (FR-012).
- [X] T016 Add Laravel EN/RU/UK validation/auth/domain catalogs and replace hardcoded user messages
  across `apps/api/app` with translation keys (FR-013).

## Phase 4: Partial Preferences and Global Controls

- [X] T017 Extend frontend theme/background types and the strict partial preference payload in
  `apps/web/src/api/types.ts` and `apps/web/src/api/client.ts` (FR-009, FR-014-FR-015).
- [X] T018 Replace the theme-only request with strict atomic locale/theme validation in
  `apps/api/app/Http/Requests/UpdatePreferencesRequest.php`, `ProfileController.php`, routes and
  resources (FR-009-FR-014).
- [X] T019 Extend `UserProfile::themePreferences()` with backward-compatible background defaults and
  pass T005 contract coverage (FR-014, SC-007).
- [X] T020 Implement presets, deterministic custom palette derivation, contrast calculation and full
  token application in `apps/web/src/theme.ts` and `apps/web/src/style.css` (FR-015-FR-018).
- [X] T021 Implement sequence-safe locale/theme profile reconciliation and optimistic rollback in
  `apps/web/src/auth/session.ts` plus a shared preference coordinator (FR-008-FR-011, FR-021).
- [X] T022 Build accessible `apps/web/src/components/GlobalPreferences.vue`, mount it outside routed
  content in `App.vue`, and style desktop/390x844 placement (FR-006, FR-021, FR-028).
- [X] T023 Remove locale ownership from the Account draft while injecting accepted session locale in
  its PUT; prove global switching preserves the unsaved draft (FR-009, SC-003).

## Phase 5: Complete Current Interface Localisation

- [X] T024 [P] Localize App startup/unavailable, Login, Register and shared error mapping in
  `App.vue`, `LoginView.vue`, `RegisterView.vue` and API helpers (FR-003, FR-013).
- [X] T025 [P] Localize navigation/global accessible names in `AppShell.vue` and shared defaults in
  `components/AsyncState.vue`, `ProgressSummary.vue` and `components/ui/*.vue` (FR-003).
- [X] T026 Localize Today and Routines including enum labels, dates, counts, plurals and dynamic ARIA
  text in `TodayView.vue` and `RoutinesView.vue` (FR-003-FR-005).
- [X] T027 Localize Goals and Review including lifecycle, validation, linked-state, ratings and dynamic
  ARIA text in `GoalsView.vue` and `ReviewView.vue` (FR-003-FR-005).
- [X] T028 Localize Planner and Storage including source/status/type labels, counts, domain refusals
  and dynamic ARIA text in `PlannerView.vue` and `StorageView.vue` (FR-003-FR-005, FR-013).
- [X] T029 Localize Body and Account including metric/direction/baseline enum labels, units, warnings,
  readiness and all profile feedback in `BodyView.vue` and `AccountView.vue` (FR-003-FR-005, FR-013).
- [X] T030 Complete Appearance with localized background controls, live preview, invalid-input safety,
  contrast feedback and atomic rollback in `AppearanceSettingsView.vue` (FR-014-FR-020).
- [X] T031 Restructure `content/changelog.ts` to keyed content, remove the duplicate entry identifier,
  and localize `ChangelogView.vue` plus every current entry (FR-003-FR-004).

## Phase 6: Verification and Closure

- [X] T032 Run and pass `npm run check:i18n`; remove only narrow documented false-positive exceptions
  and prove negative fixtures still fail (FR-024, SC-006).
- [X] T033 Pass focused API preference/localisation tests and focused Playwright preference/localisation
  suites in both browser projects (SC-001-SC-007).
- [X] T034 Reconcile OpenAPI, feature artifacts, design docs, README/changelog and implementation;
  mark tasks only with evidence.
- [X] T035 Run the full gate: Laravel tests, Pint, i18n gate, Vue typecheck/build, all Playwright
  projects, OpenAPI parse, `git diff --check` and protected-path audit (SC-008).
- [X] T036 Update `C:\Code\memory\projects\selfhandler\overview.md`, make one atomic feature commit
  without the preserved untracked design handoff, push `master`, and verify local HEAD equals
  `origin/master`.

## Dependencies and Execution Order

- T001-T004 close governance/setup before implementation.
- T005-T010 define failing behavior and can be authored independently.
- T011-T016 establish the locale boundary before current screens migrate.
- T017-T023 establish persistence, background and global controls before Appearance/current UI closes.
- T024-T031 may proceed by disjoint screen groups after T011-T16 and T21.
- T032-T036 require all implementation tasks.

## Traceability

| Requirement area | Tasks |
|---|---|
| Complete EN/RU/UK and formatting (FR-001-FR-005) | T009, T011-T016, T024-T031, T032-T035 |
| Global controls/cache/profile (FR-006-FR-013) | T005-T007, T012-T18, T021-T25 |
| Background safety/appearance (FR-014-FR-023) | T008, T013, T017-T22, T030 |
| Governance/regression/responsive (FR-024-FR-028) | T001-T004, T009-T10, T022, T032-T36 |
