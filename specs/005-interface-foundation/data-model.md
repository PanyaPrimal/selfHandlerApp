# Data Model: Interface Foundation and User Changelog

**Feature ID**: `005-interface-foundation`

This feature introduces **no database table, no column, no migration and no API resource**. The
"model" here is the typed frontend content shape plus the internal value shapes the control layer
operates on.

## 1. Persistence

None. `apps/api/database/migrations/` is unchanged. Live rows are untouched, so there is no
preservation or rollback concern beyond redeploying the previous web image.

## 2. Changelog content shape

Declared in `apps/web/src/content/changelog.ts`.

```ts
export interface ChangelogLink {
  readonly label: string          // shown as written, matches the current UI wording
  readonly to: string             // in-application route path, e.g. '/routines'
}

export interface ChangelogEntry {
  readonly id: string             // stable slug, unique, used as the list key and anchor
  readonly date: string           // YYYY-MM-DD, the day the change became usable
  readonly feature: string        // Spec Kit feature id or category, e.g. '005-interface-foundation'
  readonly title: string          // short plain-language headline
  readonly summary: string        // what changed, in plain language, no internal jargon
  readonly howToTest: string      // one concrete way to see it
  readonly links?: readonly ChangelogLink[]
  readonly limitations?: readonly string[]
}

export const changelogEntries: readonly ChangelogEntry[]
```

**Invariants**

- `id` is unique across the array.
- `date` is a calendar date string, never an instant.
- The exported array is sorted by `date` descending, then by `id` descending for same-day entries, so
  the order is total and deterministic. The sort is applied in code, not left to authoring order.
- `to` values must be routes that exist in `apps/web/src/router.ts`.
- `summary` and `howToTest` describe user-visible behaviour. Commit hashes, task identifiers and file
  paths do not appear in them.

**Content language**: Russian, as product copy for this installation's owner. Field names, identifiers
and every other repository artifact remain English.

## 3. Control value shapes

| Control | Model value | Empty value | Notes |
|---|---|---|---|
| `UiTextInput` | `string` | `''` | `type` prop restricted to `text \| email \| password \| search` |
| `UiTextarea` | `string` | `''` | |
| `UiNumberInput` | `number \| null` | `null` | Emits `null` for a cleared field, never `NaN` or `0` |
| `UiSelect` | `T \| null` | `null` | `T` is the option value type; `null` is a real, selectable option only when `nullable` |
| `UiCombobox` | `T \| null` | `null` | Filter text is internal state and is discarded on close |
| `UiDatePicker` | `string \| null` | `null` | `YYYY-MM-DD` only |
| `UiTimeField` | `string \| null` | `null` | `HH:MM` only |
| `UiCheckbox` | `boolean` | `false` | |
| `UiSwitch` | `boolean` | `false` | |
| `UiSegmented` | `T` | — | Always has a value; used where a choice is mandatory |
| `UiToggleGroup` | `T[]` | `[]` | Order follows the option order, not click order |

### CalendarDate

`apps/web/src/components/ui/calendar.ts` operates on:

```ts
interface CalendarDate { readonly year: number; readonly month: number; readonly day: number } // month 1-12
```

Parsed from and serialised to `YYYY-MM-DD`. No `Date` value crosses the module boundary. Internal
`Date` use is confined to `Date.UTC(...)` construction read back through `getUTCDay()` or through
`Intl.DateTimeFormat(..., { timeZone: 'UTC' })`.

## 4. Field descriptor

`useFieldIds(name)` produces the identifiers a control and its `UiField` share:

| Id | Purpose |
|---|---|
| `controlId` | `id` of the interactive element, target of the label's `for` |
| `helperId` | present only when helper text is rendered |
| `errorId` | present only when an error is rendered |

`aria-describedby` is the space-joined list of the ids that exist. `aria-invalid` is set when an error
is present. This assembly exists in exactly one place.

## 5. Component consumers

Constitution principle III requires a current consumer for every new abstraction.

| Component | Consumer in this feature | Consumer in 006/007 |
|---|---|---|
| `UiField` | every migrated field | recurrence editor, measurement form |
| `UiTextInput` | Login, Register, Routines, Goals, Account | measurement note |
| `UiTextarea` | Routines, Goals, Review | — |
| `UiNumberInput` | Routines (order), Account (height, weight, body fat) | measurement values, goal target value |
| `UiSelect` | Routines (kind), Account (locale, units, currency, tone, sex, activity, formula) | recurrence frequency, metric type, goal direction |
| `UiCombobox` | Account (time zone) | measurement metric picker |
| `UiDatePicker` | Today, Routines (starts/ends), Goals (target date), Account (birth date) | rule bounds, measurement date, milestone dates |
| `UiTimeField` | Routines (preferred time) | occurrence slot time |
| `UiCheckbox` | Goals (routine links) | milestone completion |
| `UiSwitch` | Routines (active in planning) | rule active/paused |
| `UiSegmented` | Routines (schedule type), archive filters | recurrence frequency selector |
| `UiToggleGroup` | Routines (weekdays) | rule weekdays |
| `UiPopoverSurface` | select, combobox, date, time, navigation More menu | same |

No component is introduced without an entry in this table.
