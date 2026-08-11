# Tasks: Interface Foundation and User Changelog

**Input**: Design documents from `specs/005-interface-foundation/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/ui-contracts.md`,
`quickstart.md`

**Tests**: Browser tests are mandatory because every requirement in this feature is about rendered
behaviour, keyboard operation and viewport fit. Type checking is the contract test for the component
props. The Laravel suite runs as a no-regression gate although no backend file changes.

**Organization**: Tasks are grouped by independently testable user story. Test tasks precede the
implementation they verify.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it changes a different file and has no incomplete dependency
- **[Story]**: Maps the task to one specification user story
- Every task names concrete repository paths and applicable requirement IDs

## Phase 1: Setup

**Purpose**: Bring in the positioning dependency and the shared test scaffolding before any component.

- [X] T001 Add `@floating-ui/vue@^2.0.1` to `apps/web/package.json` and update `apps/web/package-lock.json` (research R2, FR-010)
- [X] T002 [P] Add control-layer design tokens and shared control/surface styles to `apps/web/src/style.css` (FR-001, FR-014, FR-016, FR-017)
- [X] T003 [P] Add reusable browser helpers for opening surfaces, keyboard driving and viewport assertions in `apps/web/e2e/interface/support.ts` (SC-002, SC-003, SC-007)

---

## Phase 2: Foundational (Shared Primitives)

**Purpose**: Establish the identifier plumbing, the overlay primitive and the calendar arithmetic that
every control depends on.

**⚠️ CRITICAL**: No control is implemented before these pass.

- [X] T004 Implement `useFieldIds` id and `aria-describedby` assembly in `apps/web/src/components/ui/useFieldIds.ts` (FR-001, FR-015)
- [X] T005 Implement `YYYY-MM-DD` tuple arithmetic, month-grid construction and locale label helpers in `apps/web/src/components/ui/calendar.ts` with no local `Date` construction (FR-018-FR-021, research R3, R4)
- [X] T006 Implement `useAnchoredSurface` with flip/shift/size middleware, `autoUpdate`, outside-pointer and Escape dismissal, focus return, mobile-navigation inset and reduced-motion suppression in `apps/web/src/components/ui/useAnchoredSurface.ts` (FR-010, FR-012, FR-013, FR-016, SC-003)
- [X] T007 Implement the shared overlay shell in `apps/web/src/components/ui/UiPopoverSurface.vue`, applying the computed maximum height and internal scrolling (FR-010, SC-003)
- [X] T008 Implement the label/helper/error wrapper in `apps/web/src/components/ui/UiField.vue` (FR-001, FR-015)

**Checkpoint**: Identifiers, overlay behaviour and calendar arithmetic exist and are consumable.

---

## Phase 3: User Stories 1 and 2 - Consistent, Keyboard-Operable Control Set (Priority: P1) 🎯 MVP

**Goal**: A complete owned control set that renders in the product's visual language and implements its
ARIA patterns correctly.

**Independent Test**: Open every control type on a scratch form at desktop and 390×844, operate each by
keyboard only, and verify no operating-system surface appears.

### Tests for User Stories 1 and 2

- [X] T009 [P] [US1] Add failing surface-rendering, flip/shift, max-height and no-horizontal-overflow scenarios in `apps/web/e2e/interface/controls.spec.ts` (FR-003-FR-006, FR-010, SC-001, SC-003)
- [X] T010 [P] [US2] Add failing keyboard-only scenarios for listbox, combobox, calendar, time, switch and segmented patterns in `apps/web/e2e/interface/keyboard.spec.ts` (FR-011-FR-015, SC-002)
- [X] T011 [P] [US1] Add failing calendar-date stability scenarios under a west-of-UTC browser time zone in `apps/web/e2e/interface/calendar-date.spec.ts` (FR-018-FR-022, SC-005)

### Implementation for User Stories 1 and 2

- [X] T012 [P] [US1] Implement `apps/web/src/components/ui/UiTextInput.vue` and `apps/web/src/components/ui/UiTextarea.vue` (FR-002, FR-009)
- [X] T013 [P] [US1] Implement nullable numeric handling in `apps/web/src/components/ui/UiNumberInput.vue` (FR-002, FR-009)
- [X] T014 [US1] [US2] Implement the listbox trigger, surface, roles, states and keyboard model in `apps/web/src/components/ui/UiSelect.vue` (FR-003, FR-011-FR-015)
- [X] T015 [US1] [US2] Implement filtering, empty-result announcement and combobox keyboard model in `apps/web/src/components/ui/UiCombobox.vue` (FR-004, FR-011-FR-015)
- [X] T016 [US1] [US2] Implement the month grid, grid semantics, day navigation and no-implicit-value behaviour in `apps/web/src/components/ui/UiDatePicker.vue` (FR-005, FR-011-FR-015, FR-018-FR-022)
- [X] T017 [US1] [US2] Implement typed entry, normalisation, stepping and the slot list in `apps/web/src/components/ui/UiTimeField.vue` (FR-006, FR-011-FR-015)
- [X] T018 [P] [US1] Implement `apps/web/src/components/ui/UiCheckbox.vue` and `apps/web/src/components/ui/UiSwitch.vue` (FR-007, FR-011, FR-014)
- [X] T019 [P] [US1] Implement `apps/web/src/components/ui/UiSegmented.vue` with radiogroup roving tabindex and `apps/web/src/components/ui/UiToggleGroup.vue` with pressed toggles (FR-008, FR-011)
- [X] T020 [US1] Export the public control surface from `apps/web/src/components/ui/index.ts` and add shared option/value types (contracts)
- [X] T021 [US1] Apply the warm-paper visual treatment for hover/open/selected/focus/error/disabled across all control styles in `apps/web/src/style.css` (FR-009, FR-014, FR-017)
- [X] T022 [US2] Verify and fix reduced-motion, touch-target and visible-focus behaviour across the control set (FR-014, FR-016, FR-017)

**Checkpoint**: The control set is complete, keyboard operable and visually consistent.

---

## Phase 4: User Story 3 - Preserve Existing Form Behaviour (Priority: P1)

**Goal**: Every existing screen uses the new controls with byte-identical request behaviour.

**Independent Test**: Submit each migrated form and compare the outgoing request body, the validation
rendering and the post-error focus target against the pre-migration behaviour.

### Tests for User Story 3

- [X] T023 [P] [US3] Add failing payload-equivalence and validation/focus-recovery scenarios for routine, goal, review and profile saves in `apps/web/e2e/interface/payload-parity.spec.ts` (FR-025, FR-027, SC-004)

### Implementation for User Story 3

- [X] T024 [P] [US3] Migrate `apps/web/src/views/LoginView.vue` to the control set, preserving payload and focus recovery (FR-023, FR-025, FR-027)
- [X] T025 [P] [US3] Migrate `apps/web/src/views/RegisterView.vue` (FR-023, FR-025, FR-027)
- [X] T026 [US3] Migrate the date selector in `apps/web/src/views/TodayView.vue` without changing the profile-timezone default day (FR-023, FR-020, FR-025)
- [X] T027 [US3] Migrate `apps/web/src/views/RoutinesView.vue`: kind select, schedule segmented control, weekday toggle group, time field, date fields, order number, active switch (FR-023, FR-025, FR-026)
- [X] T028 [US3] Migrate `apps/web/src/views/GoalsView.vue`: name, description, target date, routine-link checkboxes (FR-023, FR-025)
- [X] T029 [US3] Migrate the text areas in `apps/web/src/views/ReviewView.vue` and keep `input[type="range"]` as accepted deviation AD-1 (FR-023, FR-024)
- [X] T030 [US3] Migrate `apps/web/src/views/AccountView.vue`: eight choice fields, birth date, numeric anthropometrics, with the time zone field as a searchable combobox (FR-023, FR-025, FR-026)
- [X] T031 [US3] Confirm every migrated submit handler returns early while a submission is in flight (FR-027)
- [X] T032 [US3] Update existing browser suites that selected native controls, without weakening their assertions, in `apps/web/e2e/core-daily-loop/` and `apps/web/e2e/profile/` (FR-025, SC-008)

**Checkpoint**: All seven screens are migrated with unchanged behaviour.

---

## Phase 5: User Story 4 - Read What Changed (Priority: P2)

**Goal**: An authenticated changelog screen with plain-language entries.

**Independent Test**: Load `/changelog` directly, confirm ordering, links and 390px layout.

### Tests for User Story 4

- [X] T033 [P] [US4] Add failing direct-entry, reload, ordering, link-navigation, guest-redirect and 390px overflow scenarios in `apps/web/e2e/interface/changelog.spec.ts` (FR-028-FR-032, SC-006)

### Implementation for User Story 4

- [X] T034 [US4] Implement the typed content shape, the deterministic sort and the initial entries in `apps/web/src/content/changelog.ts` (FR-029-FR-033)
- [X] T035 [US4] Implement `apps/web/src/views/ChangelogView.vue` with entry cards, route links and long-content handling (FR-028, FR-030-FR-032, SC-006)
- [X] T036 [US4] Register the authenticated `/changelog` route in `apps/web/src/router.ts` (FR-028)
- [X] T037 [US4] Add changelog entry styles to `apps/web/src/style.css` with no horizontal overflow at 390px (SC-006)

**Checkpoint**: The changelog is reachable, readable and correct.

---

## Phase 6: User Story 5 - Reach Every Destination On A Phone (Priority: P2)

**Goal**: Navigation that scales past five destinations at 390px.

**Independent Test**: At 390×844, reach every destination and confirm active indication.

### Tests for User Story 5

- [X] T038 [P] [US5] Add failing primary-tab, secondary-menu, active-indication, dismissal and overflow scenarios in `apps/web/e2e/interface/navigation.spec.ts` (FR-034-FR-038, SC-007)

### Implementation for User Story 5

- [X] T039 [US5] Implement the destination model, desktop sidebar links and mobile primary tabs plus More menu in `apps/web/src/layouts/AppShell.vue` (FR-034-FR-037)
- [X] T040 [US5] Implement the More menu on the shared overlay primitive with Escape, outside-dismissal, keyboard operation and focus return (FR-037, FR-012, FR-013)
- [X] T041 [US5] Update navigation styles for four tabs plus More at 390px with no horizontal overflow in `apps/web/src/style.css` (FR-036, FR-038, SC-007)

**Checkpoint**: All destinations reachable at 390px.

---

## Phase 7: Polish and Completion Gate

- [X] T042 [P] Add the repository check asserting no native `select`/`date`/`time`/`checkbox` remains in `apps/web/src/views/` or `apps/web/src/layouts/` in `apps/web/e2e/interface/native-controls.spec.ts` (FR-024, SC-001)
- [X] T043 Review contrast, long-value handling and 390px layout across every migrated screen and fix regressions (SC-003, SC-007)
- [X] T044 Reconcile `spec.md`, `plan.md`, `contracts/ui-contracts.md` and `data-model.md` against the implementation and record accepted deviations (Constitution VI)
- [X] T045 Run the full gate: `npm run typecheck`, `npm run build`, both Playwright projects, `php artisan test`, `vendor/bin/pint --test`, `git diff --check` (SC-008)
- [X] T046 Add the implementation-evidence section to this file and mark the roadmap entry complete (Constitution 6)

---

## Dependencies

- T001 blocks T006.
- T004-T008 block every task in Phase 3.
- T005 blocks T011 and T016.
- T020 blocks Phase 4.
- Phase 4 blocks T042.
- T034 blocks T035, which blocks T036.
- T039 blocks T040 and T041.

## Parallel Opportunities

- T002 and T003 are independent of each other and of T001.
- T009-T011 are three independent failing browser surfaces.
- T012, T013, T018 and T019 touch separate component files.
- T024 and T025 are independent screens.
- T033 and T038 are independent browser surfaces.

## Implementation Strategy

1. Land the primitives (Phase 2) and prove the overlay behaves at 390×844 before writing controls.
2. Build the control set and its keyboard model together (Phase 3); they are one artifact.
3. Migrate screens one at a time (Phase 4), running the payload-parity suite after each.
4. Add changelog and navigation (Phases 5-6), which are independent of each other.
5. Close with the repository check and the full gate.

## Notes

- No backend file may change in this feature.
- No API payload, validation rule or stored value may change.
- Do not introduce a component that is absent from the consumer table in `data-model.md`.
- Do not derive "today" or the time zone inside a control.
- Mark a task `[X]` only after its described behaviour and verification are complete.

---

## Implementation Evidence

**Completed**: 2026-08-12 — 46/46 tasks.

### Delivered

- **Control layer** in `apps/web/src/components/ui/`: `UiField`, `UiTextInput`, `UiTextarea`,
  `UiNumberInput`, `UiSelect`, `UiCombobox`, `UiDatePicker`, `UiTimeField`, `UiCheckbox`, `UiSwitch`,
  `UiSegmented`, `UiToggleGroup`, `UiPopoverSurface`, plus `useAnchoredSurface`, `useFieldIds` and the
  pure `calendar.ts`. One new dependency: `@floating-ui/vue@2.0.1` (positioning only).
- **Screens migrated**: `AccountView` lost eight native selects and one native date input;
  `RoutinesView` lost two selects, a native time input, two native date inputs and a native checkbox;
  `GoalsView`, `TodayView`, `LoginView`, `RegisterView` and `ReviewView` migrated their remaining
  text, date and checkbox inputs.
- **Changelog**: `/changelog` route, `ChangelogView`, typed `content/changelog.ts` with eight initial
  entries sorted newest-first in code.
- **Navigation**: desktop sidebar lists all six destinations; at 390px four primary tabs plus a More
  menu built on the shared overlay primitive.

### Defects found and fixed during implementation

- `UiField` originally wrapped the control and its helper/error text inside the `<label>`, which folded
  that text into the field's accessible name. The label element now contains only the label text, and
  required-ness is announced with `aria-required` on the control instead of a rendered marker.
- `UiCombobox` appended keystrokes to the committed label instead of starting a fresh filter. The
  field now selects its text on focus.
- The hidden checkbox input was not the hit target for its row, so the drawn mark intercepted pointer
  events. The input now covers the row, which also enlarges the touch target.

### Accepted deviations

- **AD-1**: `input[type="range"]` retained in `ReviewView`. No operating-system surface, natively
  accessible, already styled. Enforced by `e2e/interface/native-controls.spec.ts`, which asserts that
  `ReviewView.vue` is the only screen using it.
- **AD-2**: ARIA behaviour hand-written rather than delegated to a headless library; rationale in
  `research.md` R2, coverage in `e2e/interface/keyboard.spec.ts`.
- **AD-3**: `UiCheckbox` keeps a real native `input[type="checkbox"]` beneath a custom mark, so the
  native-control check scopes to `views/` and `layouts/`.

### Gate results (2026-08-12)

| Gate | Result |
|---|---|
| `php artisan test` | 120 passed, 918 assertions |
| `vendor/bin/pint --test` | passed |
| `npm run typecheck` | passed |
| `npm run build` | passed |
| Playwright, both projects | 63 passed, 3 skipped (project-specific) |
| `git diff --check` | clean |

The three skips are deliberate: the desktop-sidebar scenario skips on the mobile project and the two
compact-navigation scenarios skip on the desktop project.
