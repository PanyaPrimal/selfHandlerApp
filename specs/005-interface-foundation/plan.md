# Implementation Plan: Interface Foundation and User Changelog

**Feature ID**: `005-interface-foundation`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) · **Contracts**: [contracts/ui-contracts.md](contracts/ui-contracts.md) ·
**Quickstart**: [quickstart.md](quickstart.md)

## Summary

Introduce one owned form-control layer in `apps/web/src/components/ui/`, migrate all seven existing
screens onto it, add an authenticated `/changelog` route backed by a typed static content module, and
rework the shell navigation so more than five destinations fit a 390px viewport. No backend change.

## Technical Context

- **Language/runtime**: TypeScript 6, Vue 3.5 with `<script setup>`, Vite 8.
- **New dependency**: `@floating-ui/vue@^2.0.1` — anchor positioning only. See research R2.
- **Unchanged**: Laravel API, all endpoints, all payloads, all validation, the database.
- **Testing**: `vue-tsc` type checking, `vite build`, Playwright on the existing `desktop`
  (1366×900) and `mobile` (exact 390×844) projects. The Laravel suite must stay green as a
  no-regression check even though no backend file changes.

## Architecture

```
apps/web/src/
  components/ui/
    UiField.vue            label + helper + error + id/aria plumbing
    UiTextInput.vue        text | email | password | search
    UiTextarea.vue         multi-line
    UiNumberInput.vue      numeric with min/max/step, nullable
    UiSelect.vue           ARIA listbox, owned popup
    UiCombobox.vue         ARIA combobox, filtered listbox popup
    UiDatePicker.vue       YYYY-MM-DD, owned month grid
    UiTimeField.vue        HH:MM text + owned time list
    UiCheckbox.vue         checkbox
    UiSwitch.vue           role=switch
    UiSegmented.vue        single choice, small sets
    UiToggleGroup.vue      multi choice, small sets (weekdays)
    UiPopoverSurface.vue   shared overlay shell
    useAnchoredSurface.ts  floating-ui wiring, dismissal, focus return
    useFieldIds.ts         id/aria-describedby assembly
    calendar.ts            YYYY-MM-DD arithmetic and locale labels
    index.ts               public barrel
  content/changelog.ts     typed static entries
  views/ChangelogView.vue  changelog screen
  layouts/AppShell.vue     navigation IA (desktop sidebar / mobile tabs + More)
```

**Boundaries**

- `components/ui/` may not import from `views/`, `api/` or `auth/`. Controls receive values and locale
  through props; they never fetch, never read global state, and never decide what "today" is.
- Screens own form state, submission, validation display and focus recovery, exactly as today. The
  migration replaces the *element*, not the surrounding logic.
- `calendar.ts` is pure and has no `Date`-based public surface.

## Architecture Gate Answers (delivery roadmap §Architecture Gates)

1. **Owner**: no new domain fact. The control layer owns presentation and interaction only; changelog
   content is owned by the web application as static content.
2. **Inputs**: locale and unit system are read from the existing profile-backed session and passed
   down as props. No control invents its own copy.
3. **Time**: no persisted instant. Calendar dates stay `YYYY-MM-DD`; the product time zone stays the
   profile time zone and is never re-derived in the browser.
4. **Scheduling**: not applicable; this feature adds no recurring behaviour. It supplies the controls
   feature 006 will use.
5. **Cross-module links**: none.
6. **Evolution**: no schema change, so no migration and no rollback concern. The rollback path is the
   previous frontend image.
7. **Contracts**: no OpenAPI change. Component contracts are typed props/emits, recorded in
   `contracts/ui-contracts.md` and enforced by `vue-tsc`.
8. **Aggregates**: none.
9. **Privacy**: no user data added, transmitted or stored. Changelog content is global.
10. **Deferral**: multi-select combobox, date ranges, rich text, file inputs, theming and a published
    design-system package are deferred until a feature needs them.

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I Specifications before implementation | Pass | Full contract authored before code. |
| II Distinct sources of truth | Pass | Roadmap renumbered in the same change; no design doc contradicted. |
| III Thin slices, deliberate simplicity | Pass | Each component has a named consumer in this feature or in 006/007 (see data-model.md). |
| IV Deterministic core, optional AI | Pass | No AI. |
| V User-owned data, privacy | Pass | No persistence, no user data. |
| VI Contracts and tests move together | Pass | Typed contracts plus keyboard/viewport browser coverage in the same change. |

**Complexity tracking / accepted deviations**

- **AD-1 — `input[type="range"]` retained in Daily Review.** Rationale: it has no operating-system
  popup, is natively keyboard and screen-reader accessible, is already styled to the palette, and a
  hand-written slider would be a net accessibility risk for zero visual gain. Recorded in FR-024.
- **AD-2 — ARIA behaviour is hand-written rather than delegated.** Rationale and evidence in research
  R2; mitigated by keyboard-only browser scenarios on both projects, which a dependency would not have
  given us for free either.

## Phased Approach

| Phase | Content |
|---|---|
| 1 Setup | Dependency, barrel, shared styles, browser test helpers. |
| 2 Foundational | `useFieldIds`, `useAnchoredSurface`, `UiPopoverSurface`, `calendar.ts`, `UiField`. Nothing else can be built first. |
| 3 US1+US2 | The control set itself, with its keyboard behaviour, in one phase because the interaction model and the visual model are the same artifact. |
| 4 US3 | Screen migration, one screen at a time, each with its payload/validation/focus regression check. |
| 5 US4 | Changelog content module, route, screen. |
| 6 US5 | Navigation information architecture. |
| 7 Polish | Repository check for native controls, contrast/overflow review, full gate. |

## Risks

| Risk | Mitigation |
|---|---|
| Hand-written ARIA is subtly wrong | Keyboard-only Playwright journeys per pattern on both projects; roles and states asserted explicitly, not implied. |
| A migrated form silently changes its payload | Browser tests assert the outgoing request body for routine, goal, review and profile saves. |
| Popover clipped or overlapping the mobile tab bar | `size` middleware plus an explicit bottom-navigation offset, asserted with bounding-box checks at 390×844. |
| Date drift reappears | Browser project runs a west-of-UTC time zone for the date-stability scenario; `calendar.ts` never constructs a local `Date`. |
| Scope creep into a design system | Component list is fixed by the consumer table in data-model.md; anything else is out of scope. |
