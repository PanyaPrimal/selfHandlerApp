# Research: Interface Foundation and User Changelog

**Feature ID**: `005-interface-foundation`

**Date**: 2026-08-12

## R1 — Native controls cannot be styled into the product

**Question**: Can the current inconsistency be fixed with CSS alone?

**Findings**: The closed state of `<select>`, `<input type="date">`, `<input type="time">` and
`<input type="checkbox">` can be styled, and partially already is. Their *open* state cannot:

- the expanded `<select>` popup is rendered by the browser/OS outside the page's styling context;
- the `<input type="date">` calendar popup is a Chromium-internal widget with no styling hooks
  beyond the `::-webkit-calendar-picker-indicator` shadow part;
- the `<input type="time">` spinner and its list are equally internal;
- `appearance: none` on a checkbox removes the native mark but leaves the author to redraw the state
  anyway, so there is no saving versus an owned control.

The CSS Working Group's `appearance: base-select` work would eventually address the select case, but
it is not available across the browsers this application must run on, and it would not cover date or
time.

**Decision**: The control set must own the popup surface. Confirmed by direct inspection of the current
screens: `AccountView` alone renders eight native selects, one native date input and five native number
inputs.

## R2 — Third-party headless UI library

**Question**: Should a headless component library supply the accessible primitives instead of
hand-written ARIA?

**Candidates evaluated**

| Candidate | Verified against | Outcome |
|---|---|---|
| Reka UI | https://reka-ui.com/docs/components/combobox, https://reka-ui.com/docs/components/date-picker | Rejected |
| `@floating-ui/vue` | https://floating-ui.com/docs/vue | Adopted, positioning only |
| Hand-written, no dependency | WAI-ARIA Authoring Practices patterns | Adopted for behaviour |

**Reka UI** — Vue 3, TypeScript-first, unstyled, and its combobox documents adherence to the WAI-ARIA
combobox pattern with full keyboard support. It was nonetheless rejected:

1. **Date model conflict.** Its date picker documentation states outright that "the component depends
   on the `@internationalized/date` package". This application's calendar-date invariant is a plain
   `YYYY-MM-DD` string with the product time zone owned by the profile, established by feature 001's
   `CalendarDateContractTest` and feature 004's timezone boundary work. Introducing `CalendarDate`
   objects would create a second date model at exactly the boundary where this application has already
   been bitten by UTC drift.
2. **Styling cost is not avoided.** Unstyled primitives still require the entire warm-paper treatment
   to be written by hand, so the library saves behaviour code but not appearance code.
3. **Surface size.** The application's whole runtime dependency set today is `vue` and `vue-router`. A
   component library plus a date library would be the largest dependency in the project, for a private
   single-installation application.

**`@floating-ui/vue` 2.0.1** — adopted. Its documentation is explicit that it "provides Vue bindings for
`@floating-ui/dom` … for anchor positioning" and that it does **not** manage ARIA attributes, keyboard
navigation or interaction patterns. That boundary is exactly right here: positioning with `flip`,
`shift` and `size` middleware plus `autoUpdate` on scroll and resize is the part that is genuinely hard
to hand-roll correctly, and the part the specification is strictest about (FR-010, SC-003). Verified:
requires Vue 3.3+, ships types, installs as 4 packages, `npm audit` reports 0 vulnerabilities,
`node_modules/@floating-ui` is 605 KB on disk of which only the tree-shaken positioning core reaches
the bundle.

**Accessibility behaviour** is therefore hand-written against the WAI-ARIA Authoring Practices listbox,
combobox, date-picker-dialog, switch and checkbox patterns, and is verified by keyboard-only Playwright
scenarios on both browser projects rather than by trusting a dependency (US2, SC-002).

**Lockfile**: `apps/web/package-lock.json` is updated in the same change.

## R3 — Calendar date arithmetic without UTC drift

**Question**: How does an owned calendar avoid the date-shift class of bug?

**Findings**: The failure mode is `new Date('2026-08-16')`, which JavaScript parses as a UTC instant, so
any user west of Greenwich renders 15 August. Feature 001 already fixed this in `formatCalendarDate` by
splitting the string and constructing a local date.

**Decision**: The calendar operates on a `CalendarDate = { year, month, day }` tuple parsed from the
string with `split('-')`, and all arithmetic (add days, add months, month length, weekday index, grid
construction) is integer arithmetic on that tuple. A `Date` object is constructed only:

- with `Date.UTC(...)` for weekday derivation, read back with `getUTCDay()`;
- with `Date.UTC(...)` for `Intl.DateTimeFormat` label rendering, always with `timeZone: 'UTC'`.

Both are closed loops: a UTC-constructed value read in UTC cannot drift. The string is the only value
ever emitted. "Today" is never derived inside the control — the calling screen supplies it, because the
authoritative today comes from the profile time zone through the API (FR-020).

Rejected: `Temporal` (not available across the target browsers without a polyfill), `date-fns`/`dayjs`
(a dependency for arithmetic simple enough to write in 60 lines and to unit-test through the browser
suite).

## R4 — Locale-aware calendar labels

**Question**: Which locale drives month names, weekday headers and the first day of the week?

**Findings**: The profile locale (`en-GB`, `uk-UA`, `ru-UA`) is already exposed on the authenticated
session as `user.preferences.locale` (feature 004). All three of those locales start the week on
Monday. `Intl.Locale.prototype.getWeekInfo` is not yet available in every target browser.

**Decision**: `Intl.DateTimeFormat(locale, { timeZone: 'UTC', … })` renders month and weekday labels.
The first day of the week is resolved through `Intl.Locale(...).getWeekInfo?.().firstDay` when the
browser supports it, falling back to Monday, which is correct for every currently supported profile
locale. The browser locale is never consulted.

## R5 — Time control shape

**Question**: What replaces `input[type="time"]`?

**Options**: (a) segmented hour/minute spin buttons; (b) a text field with an anchored list of times;
(c) two choice controls.

**Decision**: (b). The only current consumer is the routine "preferred time", where a user usually wants
a round time and occasionally an exact one. A text field constrained to `HH:MM` keeps typing fast,
supports paste, and keeps the empty state genuinely empty; the anchored list of 15-minute slots covers
the common case with the same listbox behaviour as the choice control, so there is one keyboard model to
learn and one to test. Arrow keys on the closed field step the value by five minutes, matching the
native affordance being replaced.

## R6 — Mobile navigation information architecture

**Question**: How does a five-column bottom bar absorb Changelog now and body tracking in feature 007?

**Findings**: The existing bar is `repeat(5, minmax(0, 1fr))` at 390px, giving 74px per tab after
padding. Seven equal columns would give roughly 52px, below the 40px target once padding and a label are
subtracted, and would truncate every label. A horizontally scrollable bar hides destinations behind a
gesture with no affordance. A hamburger replacing the bar loses the one-tap access the daily loop relies
on.

**Decision**: Four primary destinations (Today, Routines, Goals, Review — the daily loop) stay as tabs;
a fifth "More" tab opens an anchored sheet listing the remaining destinations. The sheet reuses the
overlay primitive, so its dismissal, focus return and reduced-motion behaviour are already specified and
tested. The More tab carries the active indication when the current route is inside it. The desktop
sidebar is unchanged in kind: every destination is a direct link.

## R7 — Changelog storage

**Question**: Where does changelog content live?

**Decision**: A typed static module, `apps/web/src/content/changelog.ts`, exporting a
`readonly ChangelogEntry[]` sorted newest first. Rationale: the content changes only when the
application changes, so it belongs to the same deployable artifact; a table would need a migration, an
owner column that has no meaning for global content, an API, and a way to edit it that does not exist;
and a static module is type-checked, so a malformed entry fails the build rather than the page. The
constitution's "no abstraction without a current consumer" rule points the same way.

Ordering is asserted in code from the `date` field rather than trusted to authoring discipline.

## R8 — Duplicate submit protection

**Findings**: The existing screens already guard with a `isSubmitting` ref and a `:disabled` binding.
Two paths remain open: pressing Enter inside a field submits the form before the disabled state applies
in some browsers, and a double click can land two events in the same tick.

**Decision**: The guard stays in the screens (it is form state, not control state), but every migrated
submit handler returns early when its in-flight flag is set, so the protection does not depend on the
disabled attribute alone (FR-027).

## Constitution Check

| Principle | Assessment |
|---|---|
| I — Specifications before implementation | This feature has a full Spec Kit contract before any component is written. |
| II — Distinct sources of truth | No conflict with `docs/design/`: the roadmap was renumbered in the same change to place this feature at 005. |
| III — Thin slices, deliberate simplicity | Every component has a named current consumer, listed in [data-model.md](data-model.md). Components with no consumer (multi-select combobox, date range, colour input) are excluded. |
| IV — Deterministic core, optional AI | No AI. All behaviour is deterministic. |
| V — User-owned data and privacy | No persistence change; the changelog is global content with no user data. |
| VI — Contracts and tests move together | No HTTP contract changes; the component contract is documented in [contracts/ui-contracts.md](contracts/ui-contracts.md) and verified by typed usage plus browser tests. |
