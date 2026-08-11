# Contracts: Interface Foundation and User Changelog

**Feature ID**: `005-interface-foundation`

## HTTP contracts

**Unchanged.** This feature adds, removes and modifies no endpoint, request body, response body,
status code or validation rule. `specs/004-profile-settings/contracts/openapi.yaml` and
`specs/001-core-daily-loop/contracts/openapi.yaml` remain accurate and are not edited.

The verification obligation is the inverse of the usual one: browser tests assert that the outgoing
request bodies for routine create/update, goal create/update, daily review upsert and profile update
are **identical** to the pre-migration bodies for the same user input (FR-025, SC-004).

## Component contracts

These are TypeScript contracts enforced by `vue-tsc`. `V` is the value type of the control.

### Shared field props

Every field-bearing control accepts:

| Prop | Type | Default | Meaning |
|---|---|---|---|
| `label` | `string` | required | Visible label text |
| `name` | `string` | required | Stable identifier used for generated ids and for `data-field` |
| `helper` | `string \| undefined` | `undefined` | Helper text below the control |
| `error` | `string \| undefined` | `undefined` | Field error; sets `aria-invalid` and renders the message |
| `disabled` | `boolean` | `false` | |
| `readonly` | `boolean` | `false` | Where the pattern supports it |
| `required` | `boolean` | `false` | Marks the field; does not add browser validation |

Every control emits `update:modelValue` and exposes a `focus()` method through `defineExpose`, so the
existing "focus the first invalid field" recovery keeps working unchanged.

### `UiField`

| Prop | Type |
|---|---|
| `label`, `name`, `helper`, `error`, `required` | as above |
| `for` | `string` — id of the control it labels |

Slot props: `{ controlId, describedBy, invalid }`. `UiField` is the only place `aria-describedby` is
assembled.

### `UiTextInput` / `UiTextarea`

| Prop | Type | Notes |
|---|---|---|
| `modelValue` | `string` | |
| `type` | `'text' \| 'email' \| 'password' \| 'search'` | `UiTextInput` only |
| `maxlength`, `placeholder`, `autocomplete` | pass-through | |
| `rows` | `number` | `UiTextarea` only |

### `UiNumberInput`

| Prop | Type |
|---|---|
| `modelValue` | `number \| null` |
| `min`, `max`, `step` | `number \| undefined` |

Emits `null` for an empty field. Never emits `NaN`.

### `UiSelect<V>`

| Prop | Type | Notes |
|---|---|---|
| `modelValue` | `V \| null` | |
| `options` | `readonly UiOption<V>[]` | `{ value: V; label: string; disabled?: boolean }` |
| `placeholder` | `string` | Shown when the value is `null` |
| `nullable` | `boolean` | Adds an explicit "not set" option |

Rendered contract: trigger is a `button` with `aria-haspopup="listbox"`, `aria-expanded`, and
`aria-controls`; the surface is `role="listbox"` with `aria-activedescendant`; options are
`role="option"` with `aria-selected`.

Keyboard: `Enter`/`Space`/`ArrowDown`/`ArrowUp` open; `ArrowDown`/`ArrowUp` move the active option;
`Home`/`End` jump; `Enter` commits; `Escape` closes without committing; `Tab` closes.

### `UiCombobox<V>`

Same value contract as `UiSelect`, plus internal filter text.

Rendered contract: an `input` with `role="combobox"`, `aria-expanded`, `aria-controls`,
`aria-autocomplete="list"`, `aria-activedescendant`; the popup is `role="listbox"`. When the filter
matches nothing, a non-option status element is rendered and announced.

Keyboard: typing filters and activates the first match; arrows/Home/End move; `Enter` commits;
`Escape` closes, restores the committed value and returns focus.

### `UiDatePicker`

| Prop | Type | Notes |
|---|---|---|
| `modelValue` | `string \| null` | `YYYY-MM-DD` |
| `locale` | `string` | Profile locale; required, never defaulted from the browser |
| `min`, `max` | `string \| undefined` | `YYYY-MM-DD` bounds |
| `today` | `string \| undefined` | Supplied by the screen from the profile time zone; used only to mark the current day |

Rendered contract: trigger button with `aria-haspopup="dialog"`; surface is `role="dialog"` with an
accessible name, containing a `role="grid"` month with `role="columnheader"` weekday cells and
`role="gridcell"` days; the selected day carries `aria-selected`, the focused day is the single
tabbable cell.

Keyboard: `ArrowLeft`/`ArrowRight` ±1 day, `ArrowUp`/`ArrowDown` ±7 days, `Home`/`End` week bounds,
`PageUp`/`PageDown` ±1 month, `Enter`/`Space` select, `Escape` closes without changing the value.

Invariants: never emits a value the user did not select; never converts through a UTC instant; opening
and closing without selecting is a no-op.

### `UiTimeField`

| Prop | Type | Notes |
|---|---|---|
| `modelValue` | `string \| null` | `HH:MM` |
| `step` | `number` | Minutes between offered slots, default 15 |

Text entry is accepted and normalised on blur; an unparseable entry is rejected without destroying the
user's text until blur. `ArrowUp`/`ArrowDown` on the closed field step by 5 minutes. The popup is a
listbox with the same keyboard model as `UiSelect`.

### `UiCheckbox` / `UiSwitch`

| Prop | Type |
|---|---|
| `modelValue` | `boolean` |

`UiCheckbox` renders a native `input[type="checkbox"]` that is visually replaced but remains the
accessibility and interaction source, so its semantics are not re-implemented. `UiSwitch` renders a
`button` with `role="switch"` and `aria-checked`, toggled by `Enter` and `Space`.

> `UiCheckbox` keeping a real `input[type="checkbox"]` under a custom mark is deliberate and is not the
> deviation described in FR-024: the element is never presented in its native appearance, and no
> operating-system surface is involved.

### `UiSegmented<V>` / `UiToggleGroup<V>`

| Prop | Type | Notes |
|---|---|---|
| `modelValue` | `V` / `V[]` | |
| `options` | `readonly UiOption<V>[]` | |

`UiSegmented` is a `role="radiogroup"` of `role="radio"` buttons with roving tabindex and arrow-key
movement. `UiToggleGroup` is a `role="group"` of `aria-pressed` toggle buttons, each individually
tabbable. `UiToggleGroup` emits values in option order.

### `UiPopoverSurface` / `useAnchoredSurface`

`useAnchoredSurface({ placement, offset, matchWidth })` returns `{ anchorRef, surfaceRef, open,
toggle, close, floatingStyles }`. It owns:

- `flip`, `shift` and `size` middleware so the surface stays inside the viewport and receives a
  `--ui-surface-max-height` custom property for internal scrolling;
- `autoUpdate` on scroll and resize;
- outside-pointer and `Escape` dismissal;
- focus return to the anchor on close;
- suppression of transitions under `prefers-reduced-motion: reduce`;
- a bottom inset so surfaces never sit under the fixed mobile navigation.

It contains no ARIA. Roles and states belong to the consuming control.

## Content contract

`apps/web/src/content/changelog.ts` exports `changelogEntries: readonly ChangelogEntry[]` with the
shape and invariants defined in [data-model.md](../data-model.md). Type checking is the enforcement
mechanism; sorting is applied in code.

## Repository contract

A check in the browser suite asserts that no file under `apps/web/src/views/` or
`apps/web/src/layouts/` contains `<select`, `type="date"`, `type="time"` or `type="checkbox"`. The
control layer under `apps/web/src/components/ui/` is exempt, because that is where the one intentional
native checkbox lives.
