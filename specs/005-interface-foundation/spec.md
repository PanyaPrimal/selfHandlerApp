# Feature Specification: Interface Foundation and User Changelog

**Feature ID**: `005-interface-foundation`

**Created**: 2026-08-12

**Status**: Ready for implementation

**Input**: Replace the remaining default browser form controls with one owned, accessible SelfHandler
control set, migrate every existing form onto it, and give the application owner a readable changelog
of what shipped and how to try it — without changing any API payload, validation rule, or stored value.

**Design sources**:
[Delivery Roadmap — 005](../../docs/design/delivery-roadmap.md#005--interface-foundation-and-user-changelog) ·
[Module 0 — User Profile](../../docs/design/modules.md#module-0--user-profile) ·
[Data Conventions](../../docs/design/data-conventions.md) ·
[Constitution](../../.specify/memory/constitution.md)

## Why This Feature Exists

The application already delivers a coherent warm-paper visual language for panels, cards, buttons and
state blocks. Its inputs do not follow it. Seven screens still render native `<select>`, `<input
type="date">`, `<input type="time">` and `<input type="checkbox">` elements. Styling those elements is
not sufficient: the expanded select list, the calendar popup and the time spinner are drawn by the
operating system and cannot be themed, so the product looks like two different applications depending
on whether a control is open or closed.

Feature 006 (recurrence editing) and feature 007 (dated body measurements) both add substantial new
forms. Building them on native controls would either ship more inconsistency or force a second
migration of the same screens later.

Separately, the owner of this installation currently has no way to learn what changed. Four features
have shipped with no user-facing record of them.

## Clarifications

### Session 2026-08-12

- Q: Should a third-party headless UI library provide the accessible primitives?
  A: No. `@floating-ui/vue` is adopted for anchor positioning only, because it explicitly does not
  manage ARIA or keyboard behaviour and solves the viewport-fit problem that is hardest to hand-roll.
  A full headless library (Reka UI) is rejected: its date picker requires `@internationalized/date`,
  introducing a second date model beside the `YYYY-MM-DD` calendar-date invariant this application
  already depends on. Recorded in [research.md](research.md).
- Q: Which control keeps a native element?
  A: `input[type="range"]` in Daily Review. It has no operating-system popup, is fully keyboard and
  screen-reader accessible natively, and is already styled to the product palette. Recorded as an
  accepted deviation in FR-024.
- Q: How does mobile navigation absorb the new destinations?
  A: Four primary tabs (Today, Routines, Goals, Review) plus a "More" tab that opens a sheet
  containing the remaining destinations (Account, Changelog, and later feature destinations). The
  desktop sidebar lists every destination directly.
- Q: Where does the changelog content live?
  A: A typed static module in `apps/web/src/content/changelog.ts`. No backend, no CMS, no database.
- Q: Which language is the changelog written in?
  A: The changelog is product copy for this installation's owner and is written in plain Russian, the
  language the owner reads. Repository documentation, code, identifiers and tests stay in English.
- Q: Does the date picker ever substitute today's date for an empty value?
  A: No. A nullable date field stays null until the user picks a day. Opening the calendar only moves
  the visible month; it never writes a value.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Use One Consistent Control Set (Priority: P1)

As a signed-in user, I fill in every SelfHandler form with controls that look and behave the same, so
that choosing a value, a day or a time never drops me into an operating-system widget that ignores the
application's design.

**Why this priority**: This is the feature. Every other story depends on the control set existing.

**Independent Test**: Open Routines, Goals, Review and Account, open each choice, date and time
control, and verify that the expanded surface is rendered by the application — warm surface, forest
accent, product radius — on both desktop and a 390×844 viewport, and that no native dropdown,
calendar or time popup appears.

**Acceptance Scenarios**:

1. **Given** a form with a choice field, **When** the user opens it, **Then** an application-rendered
   list appears anchored to the field, showing the current selection as selected.
2. **Given** an open list near the bottom of the viewport, **When** it cannot fit below the field,
   **Then** it flips above or shifts within the viewport instead of being clipped or forcing the page
   to scroll horizontally.
3. **Given** an open list with more options than fit on screen, **When** it is displayed, **Then** it
   is bounded by a maximum height and scrolls internally.
4. **Given** a date field, **When** the user opens it, **Then** an application-rendered month grid
   appears with weekday headers in the profile locale and the selected day marked.
5. **Given** a time field, **When** the user opens it, **Then** an application-rendered list of times
   appears, and the user can also type a time directly.
6. **Given** any control in an error state, **When** it is displayed, **Then** the same error
   treatment — border, message and programmatic association — is used on every screen.
7. **Given** a 390×844 viewport, **When** any control is opened, **Then** the open surface does not
   overlap the fixed bottom navigation and the page does not scroll horizontally.

---

### User Story 2 - Operate Every Control From The Keyboard (Priority: P1)

As a keyboard or screen-reader user, I reach, open, navigate, choose and dismiss every control without
a pointer, and focus always returns somewhere predictable.

**Why this priority**: A hand-owned control set that is not keyboard accessible is strictly worse than
the native controls it replaces.

**Independent Test**: Complete a full routine creation using only the keyboard: Tab between fields,
open the choice list with Enter, move with arrow keys, select with Enter, open the date field, move by
day and week with arrows, confirm with Enter, and submit the form.

**Acceptance Scenarios**:

1. **Given** a closed choice control with focus, **When** the user presses Enter, Space, Down or Up,
   **Then** the list opens with the current value active.
2. **Given** an open list, **When** the user presses Down, Up, Home or End, **Then** the active option
   moves accordingly without changing the committed value until confirmation.
3. **Given** an open list, **When** the user presses Escape, **Then** the list closes, the previous
   value is kept and focus returns to the control.
4. **Given** an open list, **When** the user presses Tab or clicks outside, **Then** the surface closes
   without trapping focus.
5. **Given** an open calendar, **When** the user presses Left, Right, Up, Down, Home, End, PageUp or
   PageDown, **Then** the focused day moves by day, week, week bounds or month respectively.
6. **Given** a searchable choice control, **When** the user types, **Then** the option list filters,
   the first match becomes active, and an empty result set is announced rather than silently blank.
7. **Given** any control, **When** it receives keyboard focus, **Then** a visible focus indicator is
   shown that meets the existing contrast treatment.
8. **Given** a user with reduced-motion preference, **When** a surface opens, **Then** no non-essential
   transition is played.

---

### User Story 3 - Preserve Existing Form Behaviour (Priority: P1)

As an existing user, my forms keep saving exactly what they saved before, keep rejecting exactly what
they rejected before, and keep my in-progress draft when a save fails.

**Why this priority**: A visual migration that silently changes a payload, a validation outcome or a
recovery path is a regression disguised as an improvement.

**Independent Test**: Save a routine, a goal, a review and a profile before and after the migration and
compare the request payloads, the validation responses and the post-error focus behaviour.

**Acceptance Scenarios**:

1. **Given** a migrated form, **When** it is submitted, **Then** the request body is byte-equivalent to
   the pre-migration body for the same user input.
2. **Given** a server validation failure, **When** the response returns, **Then** the same field-level
   messages are shown, the same field receives focus, and the user's draft is preserved.
3. **Given** a nullable date or choice field left empty, **When** the form is saved, **Then** `null` is
   sent, not today's date and not the first available option.
4. **Given** a calendar date stored as `YYYY-MM-DD`, **When** it is displayed, opened, reopened and
   saved without editing, **Then** the value is unchanged regardless of the browser time zone.
5. **Given** a form is already submitting, **When** the user activates submit again, **Then** no second
   request is issued.

---

### User Story 4 - Read What Changed (Priority: P2)

As the owner of this installation, I open a Changelog screen and understand, in plain language, what
appeared, when it appeared and how to try it.

**Why this priority**: Delivered work that the owner cannot find is indistinguishable from work that
was not delivered.

**Independent Test**: Navigate to `/changelog` from the navigation, reload the URL directly, and verify
that entries are listed newest first, each with a date, a title, a plain-language description, a "how
to test" instruction and working links into the application.

**Acceptance Scenarios**:

1. **Given** a signed-in user, **When** they select Changelog in the navigation, **Then** the changelog
   screen opens.
2. **Given** the changelog URL, **When** it is loaded directly or reloaded, **Then** the screen renders
   without requiring navigation through another route first.
3. **Given** a changelog entry that links to an application route, **When** the link is activated,
   **Then** the application navigates to that route.
4. **Given** a signed-out visitor, **When** they request the changelog URL, **Then** they are sent to
   sign-in and returned to the changelog after signing in.
5. **Given** a long entry list, **When** it is rendered at 390px, **Then** there is no horizontal
   overflow and every entry stays readable.

---

### User Story 5 - Reach Every Destination On A Phone (Priority: P2)

As a phone user, I reach all application destinations from a 390px-wide screen without a cramped or
truncated navigation bar.

**Why this priority**: The navigation was built for five destinations. Adding Changelog now and body
tracking in feature 007 breaks a five-column bar.

**Independent Test**: At 390×844, verify that the primary tabs are legible and tappable, that the
remaining destinations are reachable through the secondary menu, and that the current destination is
indicated in both cases.

**Acceptance Scenarios**:

1. **Given** a 390px viewport, **When** the application shell renders, **Then** the bottom navigation
   shows the primary destinations plus one entry to the remaining destinations.
2. **Given** the secondary menu, **When** it is opened, **Then** the remaining destinations are listed
   and can be activated by pointer and keyboard.
3. **Given** the user is on a destination inside the secondary menu, **When** the navigation renders,
   **Then** the secondary entry indicates the active state.
4. **Given** the secondary menu is open, **When** the user presses Escape or activates a destination,
   **Then** the menu closes and focus is handled predictably.
5. **Given** a desktop viewport, **When** the sidebar renders, **Then** every destination is a direct
   link with no secondary menu.

## Requirements *(mandatory)*

### Functional Requirements — Control Set

- **FR-001**: The application MUST provide a field wrapper that owns the label, optional helper text,
  optional error message, generated identifiers, and the `aria-describedby` / `aria-invalid`
  association for its control.
- **FR-002**: The application MUST provide text, password, email, number and multi-line text controls
  that share one visual and state treatment.
- **FR-003**: The application MUST provide a single-choice control that renders its option list itself,
  implementing the ARIA listbox pattern with `aria-expanded`, `aria-activedescendant`, and
  `aria-selected`.
- **FR-004**: The application MUST provide a searchable single-choice control for large option sets,
  implementing the ARIA combobox pattern with a filtered listbox popup and an explicit empty-result
  state.
- **FR-005**: The application MUST provide a calendar date control that reads and writes `YYYY-MM-DD`
  strings and renders a month grid with `grid`/`gridcell` semantics.
- **FR-006**: The application MUST provide a time control that reads and writes `HH:MM` strings,
  accepts direct typing, and offers an application-rendered list of selectable times.
- **FR-007**: The application MUST provide a checkbox control and a switch control with correct roles
  and checked state exposure.
- **FR-008**: The application MUST provide a segmented single-choice control and a multi-select toggle
  group for small option sets where a list would be heavier than the choice deserves.
- **FR-009**: Every control MUST support disabled and read-only presentation, and MUST expose a busy
  state where its owning form can be submitting.
- **FR-010**: Overlay surfaces MUST share one positioning primitive that flips, shifts and clamps the
  surface inside the viewport and applies a maximum height with internal scrolling.

### Functional Requirements — Accessibility

- **FR-011**: Every control MUST be reachable and operable with Tab, Shift+Tab, Enter, Space, Escape,
  arrow keys, and Home/End where the pattern defines them.
- **FR-012**: Closing an overlay by Escape, by outside click, or by selection MUST return focus to the
  control that opened it.
- **FR-013**: Overlay surfaces MUST close on outside pointer interaction without swallowing that
  interaction's intent to move focus away.
- **FR-014**: Every focusable element MUST show a visible focus indicator consistent with the existing
  focus treatment.
- **FR-015**: Controls in an invalid state MUST set `aria-invalid` and associate the error message
  through `aria-describedby`.
- **FR-016**: Overlay open/close transitions MUST be suppressed under `prefers-reduced-motion: reduce`.
- **FR-017**: Controls MUST be operable by pointer, touch and keyboard, with touch targets of at least
  40px in the smaller dimension.

### Functional Requirements — Calendar and Time Invariants

- **FR-018**: Calendar dates MUST be treated as `YYYY-MM-DD` days, never converted through a UTC
  instant, and MUST NOT shift when the browser time zone differs from the profile time zone.
- **FR-019**: Locale-dependent formatting (month names, weekday headers, first day of week) MUST use
  the profile locale, not the browser locale.
- **FR-020**: The user's IANA time zone from the profile remains the product time zone. The date and
  time controls MUST NOT read the browser time zone to decide what "today" is; the value is supplied by
  the calling screen.
- **FR-021**: Reopening a date control without editing MUST NOT change the stored value.
- **FR-022**: A nullable date or time field MUST remain null until the user explicitly selects a value.

### Functional Requirements — Screen Migration

- **FR-023**: Login, Register, Today, Routines, Goals, Review and Account MUST use the new control set
  for their text, choice, date, time and boolean inputs.
- **FR-024**: After migration no native `select`, `input[type="date"]`, `input[type="time"]` or
  `input[type="checkbox"]` element may remain in application screens. `input[type="range"]` in Daily
  Review is an accepted deviation, recorded with its rationale in the plan.
- **FR-025**: Migration MUST NOT change request payloads, validation behaviour, error messages, focus
  recovery, or draft preservation on any migrated screen.
- **FR-026**: The Account time zone field MUST be the searchable choice control rather than a long
  native list.
- **FR-027**: Forms MUST prevent a duplicate submission while a submission is in flight.

### Functional Requirements — Changelog

- **FR-028**: The application MUST serve an authenticated route at `/changelog` rendering the changelog
  screen, and MUST support direct entry and reload of that URL.
- **FR-029**: Changelog content MUST come from a typed static module in the web application; no backend
  endpoint, database table or content service is introduced.
- **FR-030**: Each entry MUST carry a date, a title, a feature identifier or category, a plain-language
  description, a "how to test" instruction, optional in-application route links, and optional
  limitations.
- **FR-031**: Entries MUST be presented newest first, ordered deterministically.
- **FR-032**: Entry text MUST describe user-visible behaviour in plain language and MUST NOT expose raw
  commit logs, task identifiers or internal implementation detail as the primary content.
- **FR-033**: The initial content MUST cover multi-user authentication, routines and Today, daily
  reviews, goals, seven-day progress and streaks, profile and settings, the new form interface, and the
  changelog itself. Entries for features 006 and 007 are added as those features complete.

### Functional Requirements — Navigation

- **FR-034**: Changelog MUST appear in the primary navigation.
- **FR-035**: The desktop sidebar MUST list every destination as a direct link.
- **FR-036**: At 390px the navigation MUST present primary destinations plus a secondary menu holding
  the rest, and MUST NOT compress all destinations into one row.
- **FR-037**: The secondary menu MUST indicate when the active destination is inside it, MUST close on
  Escape, outside interaction or selection, and MUST be keyboard operable.
- **FR-038**: The navigation MUST NOT introduce horizontal overflow at 390px.

### Key Entities

- **Changelog entry**: a static, versioned content record describing one user-visible change. Fields:
  `id`, `date` (`YYYY-MM-DD`), `title`, `feature` (identifier or category), `summary`, `howToTest`,
  optional `links` (label plus in-application route), optional `limitations`. It is content, not
  domain data: it has no owner, no persistence and no API.
- **Field descriptor**: the label/helper/error/identifier triple shared by every control, which is the
  only place `aria-describedby` and `aria-invalid` are assembled.

## Success Criteria *(mandatory)*

- **SC-001**: Zero native `select`, `date`, `time` or `checkbox` elements remain in application
  screens, verified by an automated repository check.
- **SC-002**: A complete routine can be created using only the keyboard, including choice, date and
  time fields, on both the desktop and 390×844 browser projects.
- **SC-003**: Every overlay surface opened at 390×844 stays inside the viewport, does not overlap the
  bottom navigation, and produces no horizontal page overflow.
- **SC-004**: For identical user input, migrated forms produce request payloads identical to the
  pre-migration behaviour, verified by request assertions in browser tests.
- **SC-005**: A calendar date opened and closed without editing is unchanged, verified with the browser
  time zone set west of UTC.
- **SC-006**: `/changelog` loads on direct entry and on reload, lists entries newest first, and its
  in-application links navigate correctly.
- **SC-007**: All application destinations are reachable at 390px, with the active destination
  indicated.
- **SC-008**: Vue type checking, the production build, the existing Laravel suite and the full
  Playwright suite pass with no regression in count.

## Scope Boundaries

### In Scope

- The owned control set, its shared positioning primitive and its styles.
- Migration of the seven existing screens.
- The changelog route, its typed content module and its initial entries.
- The navigation information architecture change.

### Out of Scope

- Any backend change: no migration, model, controller, route or OpenAPI change.
- A published or generally reusable design-system package.
- Theming, dark mode, animation frameworks, icon systems.
- Rich text, file upload, multi-select comboboxes, date ranges, or a colour picker.
- A backend-served, database-backed or CMS-backed changelog, and changelog notifications.
- Any control without a consumer in this feature or in features 006 and 007.

## Assumptions

- The profile locale and time zone from feature 004 are the authoritative formatting inputs and are
  already available on the authenticated session.
- Live user data is unaffected because no persistence changes.
- The existing warm-paper palette, radius and shadow tokens in `apps/web/src/style.css` remain the
  visual source of truth; the control set consumes them rather than introducing a parallel palette.

## Dependencies

- Feature 004 (profile locale, unit system, time zone).
- No new backend dependency. One new frontend dependency, `@floating-ui/vue`, evaluated in
  [research.md](research.md).
