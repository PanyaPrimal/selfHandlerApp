# Handoff: SelfHandler MVP — Today workspace, routines, review, goals + Appearance settings

## Overview

The first thin product slice of SelfHandler: a personal daily operating system. The user opens the
app directly into **Today**, marks routines done or skipped, sees completion rate and streaks, links
routines to goals, and closes the day with an **evening review**. A second deliverable, the
**Appearance settings** screen, introduces the theming mechanism the codebase does not have yet
(light / dark / system + accent, including a manual colour picker).

Scope is deliberately narrow. Anything not in the list below is explicitly deferred (see
*Deferred* at the end).

## About the design files

The files in `design/` are **design references created in HTML** — prototypes that show intended
look, spacing and behaviour. They are **not production code to copy**. The task is to **recreate
these designs inside the target codebase** (`apps/web`, Vue 3 + Vite, single stylesheet
`src/style.css`) using its existing patterns, tokens and UI components.

Concretely: the prototype expresses tokens as inline styles and CSS custom properties named
`--sh-*` purely so the mock can theme itself live. In the real app they are the **existing**
token names (`--paper`, `--surface`, `--accent`, …) defined in `src/style.css`. Do not introduce
`--sh-*` into the codebase.

- `design/SelfHandler-MVP-standalone.html` — **open this first.** Self-contained, works offline,
  fully interactive.
- `design/SelfHandler MVP.dc.html` + `design/support.js` — the authored source of the same document
  (must sit in the same folder to run).
- `source/ui-tokens.md` — the token reference extracted from the real `src/style.css`. **This is the
  authority for anything the prototype and the codebase disagree on.**
- `source/MVP.md`, `source/MVP_TECHNICAL_DESIGN.md` — product scope and the API/DB contract.

## Fidelity

**High-fidelity.** Final colours, typography, spacing, radii and interaction behaviour. Recreate the
UI faithfully using the codebase's existing tokens and `components/ui/` primitives. Two caveats:

- All numbers in the mock (percentages, `12d` streaks, the 7-day sparkline, dates like *Sat Jun 21*)
  are **sample data**. Derive real values from `routine_logs`.
- Device bezels, browser chrome, the dotted section backgrounds of the spec document itself, and the
  numbered section headers (`00`–`09`) are **presentation scaffolding for the spec**, not app UI.

## Design tokens

Authoritative list: `source/ui-tokens.md`. Summary of what the implementation needs.

### Colour — light (existing, unchanged)

| Token | Value | Role |
| --- | --- | --- |
| `--paper` | `#ece9e2` | App background |
| `--paper-soft` | `#faf8f4` | Recessed fills, chips, disabled fields |
| `--surface` | `#ffffff` | Panels, cards, field fills |
| `--surface-warm` | `#fffaf2` | Open/active control fill |
| `--ink` | `#232220` | Primary text |
| `--muted` | `#56524b` | Secondary text |
| `--subtle` | `#6b645b` | Labels, helper text |
| `--border` | `#e0dcd2` | Panel/card edges |
| `--border-strong` | `#c4beb2` | Hover edge |
| `--field-border` | `#cfcabf` | Resting input edge |
| `--accent` | `#3d6b4e` | Primary buttons, focus, active nav |
| `--accent-soft` | `#eef3ef` | Accent-tinted fill |
| `--accent-border` | `#cfe0d3` | Accent-tinted edge |
| `--blue-muted` | `#4f6d8a` | Secondary data accent |
| `--gold-muted` | `#c2843f` | Secondary data accent |
| `--error` | `#9a553f` | Error text/edges |
| `--error-soft` | `#faf2ef` | Error fill |
| `--shadow` | `0 18px 50px rgba(38,34,28,.08)` | Panels only |
| `--focus-halo` | `0 0 0 3px rgba(61,107,78,.22)` | Accent at 22% |

### Colour — dark (NEW, designed in this handoff)

Warm charcoal, not blue-black. Same token names, same roles.

| Token | Value |
| --- | --- |
| `--paper` | `#171613` |
| `--paper-soft` | `#1f1e1a` |
| `--surface` | `#24231f` |
| `--surface-warm` | `#2b2823` |
| `--ink` | `#f1eee7` |
| `--muted` | `#b9b3a7` |
| `--subtle` | `#968f83` |
| `--border` | `#35332d` |
| `--border-strong` | `#4b473f` |
| `--field-border` | `#433f38` |
| `--accent` | `#6fa982` |
| `--accent-soft` | `#22302a` |
| `--accent-border` | `#35503f` |
| `--error` | `#d08a72` |
| `--error-soft` | `#2e211d` |
| `--shadow` | `0 18px 50px rgba(0,0,0,.45)` |
| `--focus-halo` | `0 0 0 3px rgba(111,169,130,.28)` |
| texture dot colour | `rgba(255,255,255,.055)` |

### Accent presets (per scheme)

| Preset | Light accent / soft / border | Dark accent / soft / border |
| --- | --- | --- |
| Forest (default) | `#3d6b4e` / `#eef3ef` / `#cfe0d3` | `#6fa982` / `#22302a` / `#35503f` |
| Slate | `#4f6d8a` / `#eef1f5` / `#cfd9e4` | `#7f9fc0` / `#1f2733` / `#33465c` |
| Gold | `#b57a2f` / `#f7f1e6` / `#e6d9bf` | `#d8a45f` / `#2d2519` / `#4d3d23` |
| Brick | `#9a553f` / `#faf2ef` / `#ecdcd5` | `#c9836a` / `#2e211d` / `#4d332b` |

`--focus-halo` is always the accent at **22% (light) / 28% (dark)** — never a hard-coded literal.

### Non-colour (structural — must NOT vary by scheme)

- Type: `--font-body: "Hanken Grotesk", "Segoe UI", system-ui, sans-serif`;
  `--font-mono: "JetBrains Mono", "Cascadia Mono", Consolas, monospace`. Base `line-height: 1.5`, weight 400.
- Scale: `h1 clamp(2rem, 4vw, 3.35rem)/600/-0.02em/1.04`, `h2 1.04rem/650`, `h3 0.95rem/650`,
  `.eyebrow 0.78rem/700 mono uppercase 0.14em accent`, field label `0.92rem` `--subtle`,
  helper/error `0.82rem`, chip `0.78rem`, big metric `2rem`.
  Brand wordmark: mono `0.78rem`/700/`0.18em`, accent.
- Radii: `--radius: 14px` (panels, cards, notices), `--radius-sm: 8px` (buttons, fields, nav items),
  pills `999px`.
- Spacing: panel `padding: 20px`, internal `gap: 16px`; view stack gap `20px`; field grid gap `6px`.
- Control metrics: field `min-height: 42px` / `padding: 10px 12px` / `border: 1px`;
  textarea `min-height: 64px`; button `min-height: 40px` / `padding: 0 16px`;
  compact nav item `min-height: 44px`; chip `min-height: 26px` / `padding: 4px 8px`.
- Focus: a field keeps its geometry, turns its own edge `--accent`, adds `--focus-halo`, and carries
  `outline: 2px solid transparent` for Windows high-contrast. A button/link takes a real
  `2px` accent outline at `outline-offset: 2px`.
- Page background (must survive every scheme):
  ```css
  background:
    radial-gradient(circle at 1px 1px, <dot> 1px, transparent 0),
    var(--paper);
  background-size: 28px 28px;
  ```

### Data-accent dots (routine → goal colour)

Forest `#3d6b4e`, gold `#c2843f`, slate `#4f6d8a`, neutral `#9a948a`. 5–6px circles, `999px`.

## Layout

- Desktop shell: `grid-template-columns: 248px minmax(0, 1fr)`; content `width: min(100%, 1180px)`,
  padding `40px 32px 64px`; most pages cap at `max-width: 880px`.
- `@media (max-width: 760px)` — sidebar becomes a bottom tab bar: **Today · Routines · Goals · More**
  (Settings and Review live under More).
- Mobile target viewport is exactly **390×844**; no page may scroll horizontally there.
- `@media (prefers-reduced-motion: reduce)` already honoured — keep it.

## Screens / views

### 1. Today — route `/` (default; the app opens here, never a landing page)

**Purpose:** run the day. Highest-frequency screen; every element must survive daily repetition.

**Mobile layout** (top → bottom): date eyebrow (mono, uppercase, `--subtle`) + `Today` title (24px/600)
+ 34px avatar circle → completion card → checklist → "Close your day" card → bottom tab bar.

**Completion card:** `--surface`, 1px `--border`, radius 16px, padding 16px. Row one: label
"Completion" (13px `--muted`) and "N/M handled" (mono 13px accent 600). Row two: big percentage
(mono 38px/600, `line-height: .9`) + "N of M done" (13px `--subtle`). Row three: 8px track
`--paper-soft`, radius 999px, accent fill, `transition: width .35s cubic-bezier(.2,.7,.2,1)`.

**Routine row** (the most-repeated control in the app):
- Whole row is the tap target — `padding: 11px 8px`, radius 12px, `gap: 12px`, cursor pointer.
- Left: 24px status circle. **To do** = 2px *dashed* `#c4beb2` on `--surface`, empty.
  **Done** = filled `--accent`, white `✓` 13px/700, border `--accent`. **Skipped** = 2px solid
  `--border-strong`, glyph `–` in `--muted`.
- Middle: name 14.5px/500 — `--ink` when to do, `#7a8f80` (muted accent) when done, `#a59d91` when
  skipped; `text-decoration: line-through` with `text-decoration-color: --border-strong` when done or
  skipped. Under it: mono 11px time, 5px goal dot, goal name 11px — or the literal `unlinked`.
- Right: mono 11px/600 streak `12d`, and a small `skip` affordance (10px, `--subtle`, radius 5px,
  hover `--paper-soft`).
- **Interaction:** tap row toggles done ⇄ to do. Long-press (mobile) / the `skip` control (desktop)
  toggles skipped ⇄ to do; `skip` must `stopPropagation` so it does not also fire the row toggle.
  Skipping is neutral, never a failure state, and is **excluded from the completion rate**.

**"Close your day" card:** `--accent-soft` on 1px `--accent-border`, radius 14px, title 14px/600 in
accent, sub-line "Evening review · not yet logged", trailing `→`. Navigates to `/review/:date`.

**Desktop layout:** 248px rail (wordmark, Today / Routines / Goals / Review, Settings below a divider,
user block pinned bottom) + main at `padding: 28px 32px`. Main: greeting header (date eyebrow +
"Good evening, Alex" 30px/600) with `‹ Yesterday` / `Today ›` buttons; a 3-up stat row; then
`grid-template-columns: 1.6fr 1fr`.

- **Stat cards** (`--surface`, `--border`, radius 13px, padding 18px): *Today's completion* (mono 32px
  + 6px bar), *Longest active streak* (`21d` + routine name), *Last 7 days* (`86%` + 7-bar sparkline,
  bars `--accent-border`, today `--accent`, radius 2px, 22px tall).
- **Checklist card** with a header row ("Today's routines" 15px/600 + mono "N/M handled"), the same
  rows in a denser one-line form (name · mono time · goal chip · streak · `skip`), row separators
  1px `--border`, hover `--paper-soft`, and a final `+ Quick-add a one-off for today` row.
- **Right column:** the "Close your day" card, then a *Goal context* card — per goal a name, mono
  `done/linked` count and a 5px progress bar tinted with that goal's dot colour.
- The name cell needs `min-width: 0` so it can never be crushed by the metadata beside it.

### 2. Routines — route `/routines`

Create and edit repeatable actions. Mobile: title + 32px accent `+` FAB-style button; sections
`ACTIVE · N` and `HIDDEN · N` (mono 10px, `letter-spacing: .06em`, `--subtle`); rows show name 14px/500
and mono meta `DAILY · 07:00` + kind; a 34×20 switch (accent when on, `--border-strong` when off,
20px white knob) toggles active. Hidden rows render at `opacity: .7` on `--paper-soft`.

Desktop: `1.5fr 1fr` — a 4-column table (`NAME`, `SCHEDULE`, `KIND`, `ACTIVE`; header row on
`--paper-soft`, mono 10px labels) beside an **Edit routine** panel: name field, kind as a 3-up
segmented control (`routine` / `habit` / `sleep`), schedule segmented (`Daily` / `Weekdays`), a
Mo–Fr day picker of 28px squares (selected = accent fill, white mono 11px), preferred-time field
(mono), and a full-width primary **Save changes**.

### 3. Evening review — route `/review/:date?`

One review per day; **all fields on one calm screen** (no wizard). Header: back `←`, date eyebrow,
"Evening review" 22px/600.

- Slider card: four `1–10` sliders — **Mood, Energy, Stress, Day rating**. Each: label 13px `--muted`
  + mono 13px/600 accent `N/10`, then a 4px track `--paper-soft` radius 3px with a 16px accent thumb
  ringed by a 3px white border and `0 1px 3px rgba(30,28,24,.3)`.
- Text card: **What went well** and **Improve tomorrow** — borderless textareas (`min-height: 64px`
  in the real control), 12px `--subtle` labels, hairline `--border` divider between, placeholders
  "One good thing…" / "One change…". A third optional **Notes** field follows the same pattern.
- Primary **Save review**: full width, 14px padding, radius 13px, accent, white 15px/600,
  `box-shadow: 0 6px 16px -8px rgba(61,107,78,.7)`.

### 4. Goals — route `/goals`

Active goals and the routines linked to each (many-to-many pivot). Card per goal: title 15px/600, an
`ACTIVE` pill (mono 10px on `--accent-soft` / `--accent-border`), mono `TARGET · AUG 31`, a hairline
divider, then "Linked routines · N" and a wrapping row of routine chips (dot + name on `--paper-soft`,
radius 7px) plus a dashed `+ link` chip in accent that opens the picker.

### 5. Settings → Appearance — route `/settings/appearance` (NEW mechanism)

**Purpose:** let the user theme the app. **Only colour tokens change** — radii, type, spacing and
control metrics stay fixed, so a scheme can never become a layout change.

Desktop: the same 248px rail with **Settings** active; content capped at 880px with an eyebrow
"SETTINGS", `Appearance` (32px/600), a one-line explainer, a sub-tab row
(**Appearance** · Profile · Preferences · Data), then `grid-template-columns: minmax(0,1fr) minmax(280px,320px)`
— controls left, sticky **Live preview** right.

**Colour scheme** (`UiToggleGroup`, 3-up): **Light** "Warm paper" · **Dark** "Warm charcoal" ·
**System** "Follow device". Selected option = `--accent-soft` fill, `--accent` border, accent text 600;
unselected = `--paper-soft` / `--border` / `--muted`. Helper line states the resolution, e.g.
"Device reports dark — showing Dark" or "Fixed to Light, ignores the device". (The mock's
`simulate device: dark` chip is a prototype affordance — do not build it; use `matchMedia`.)

**Accent:** a 4-up swatch grid (`repeat(4, minmax(0,1fr))`) of 30px round swatches with label and mono
hex; the selected card carries `box-shadow: 0 0 0 2px <accent>`. New control — call it
`UiSwatchGroup`, radio semantics, 44px targets.

**Custom colour** (inside the accent card): a 44×44 swatch that hosts a visually-hidden
`<input type="color">`, a mono **Hex** text field (both directions bound, invalid input keeps the last
valid colour), and a **Use** button. From that single hex derive:
- `--accent-soft` = accent mixed toward `--surface` at **92% (light) / 82% (dark)**
- `--accent-border` = accent mixed toward `--surface` at **72% (light) / 62% (dark)**
- `--focus-halo` = `rgba(r,g,b, .22 light / .28 dark)`

Show all four derived tokens as small labelled chips. Show a live **contrast pill**: the *worse* of
the WCAG ratios accent-vs-`--surface` and accent-vs-`--paper`; `>= 3:1` passes (accent-soft fill,
accent-border edge), below that the pill flips to `--error-soft` / `--error` with scheme-aware advice
("pick a **lighter** tone" on dark, "**deeper**" on light). Flag before save — do not silently block.

**Switches** (`UiSwitch`, 44×26, 20px knob): **Dotted page texture** (the 28px dot grid; retints per
scheme) and **Monospace numerals** (metrics/times/streaks in `--font-mono` so columns don't jitter).
**Motion** (`UiToggleGroup`): *Follow system* / *Always reduce*.

**Live preview** must show, together, in the currently selected theme: a panel on textured `--paper`,
a percentage metric + progress bar, a **field at rest**, a **focused field** (accent edge + halo),
**primary / secondary / ghost** buttons, two chips, and an error notice. Footer: **Save appearance** +
**Reset**, and the mono caption `PATCH /api/profile · preferences.theme`.

Mobile (390×844): same content stacked — scheme as a vertical list of 44px rows, a 4-up swatch row
with a compact custom-colour row beneath it (36px swatch, hex + ratio, **Use**), the two switches, a
condensed preview, and the bottom tab bar with **More** active.

## Interactions & behaviour

- **Optimistic writes everywhere.** Done/skip and review edits apply instantly; the `PUT` upsert
  reconciles in the background; a rejection rolls that row back and shows an inline retry. No spinner
  ever blocks the daily tap.
- Progress bar: `width` transition `.35s cubic-bezier(.2,.7,.2,1)`. Option/switch state changes:
  `.18s`–`.2s ease`. Nothing else animates.
- The date in the route is the single source of truth for Today and Review.
- Theme applies on tap (before the request resolves).
- All hit targets ≥ 44px on mobile.

## State management

Pinia stores; **Today is the only store fetched on load.**

- `useToday` — `date`, `routines[]` (each with `status: 'todo' | 'done' | 'skipped'`, `time`, `kind`,
  `streak`, linked goal), derived `completionPct`, `handledCount`, `doneCount`. Completion % and
  streaks are **computed from `routine_logs`** — there is no `daily_metrics` table yet.
- `useRoutines` — CRUD + active/hidden.
- `useGoals` — goals and the routine pivot.
- `useReview` — one record per date, upserted.
- `useTheme` — `scheme: 'light' | 'dark' | 'system'`, `accent` preset key, `accentHex`,
  `texture`, `monoNumerals`, `motion`.

## API contract

| Action | Endpoint |
| --- | --- |
| Today payload | `GET /api/today?date=` |
| Routines CRUD | `GET` · `POST` · `PATCH /api/routines[/{id}]` |
| Mark done/skipped | `PUT /api/routines/{id}/logs/{date}` (idempotent; unique per routine+date) |
| Evening review | `PUT /api/daily-reviews/{date}` (upsert) |
| Link routine to goal | `POST /api/goals/{goal}/routines/{routine}` |
| Save appearance | `PATCH /api/profile` → `preferences.theme`, `preferences.theme.accentHex` |

Auth is deferred but decided: temporary `CurrentUser` resolver now, Sanctum SPA auth later;
`user_id` on every table from day one.

## Empty / loading / error / saved states

- **Empty** — 40px dashed rounded square, title ("No routines yet"), one calming line
  ("Add your first routine and it'll show up here every day."), primary CTA.
- **Loading** — skeletons that match the real row rhythm: 22px circle + text bars on `--paper-soft`.
  Never a centred spinner on Today.
- **Error** — `--error-soft` header, `!` badge, "Couldn't load today", reassurance that local changes
  are kept, **Retry** in `--error` on `--error-soft`.
- **Saved** — filled accent circle with `✓`, "Review saved", one mono confirmation line.
- Absence of a log means *not handled yet*, never *failed*.

## Component list

Shared primitives: `RoutineRow`, `CompletionBar` / `RingStat`, `StreakBadge`, `GoalChip`,
`ScoreSlider`, `ScheduleControl`, `UiSwitch`, `UiSegmented` / `UiToggleGroup`, `UiSelect`,
`UiCheckbox`, `UiField`, `StateBlock` (empty / loading / error / saved), **new** `UiSwatchGroup`.

Screen/layout: `AppShell` (rail ⇄ tab bar at 760px), `TodayView`, `RoutinesView` + `RoutineForm`,
`ReviewView`, `GoalsView` + `GoalForm`, `SettingsView` + `AppearanceSettings`, `DateNavigator`,
`StatCard`, `GoalContextPanel`.

Reuse what exists in `apps/web/src/components/ui/` before adding anything.

## Implementing the theming mechanism

1. Move the **colour** tokens off bare `:root` into `:root[data-theme="light"]` and
   `:root[data-theme="dark"]`. Radii, fonts, spacing and control metrics stay on `:root`, untouched.
2. Accent is a second, independent axis: `:root[data-accent="…"]` redefines `--accent`,
   `--accent-soft`, `--accent-border` **and** `--focus-halo` together — never one without the others.
   A custom accent sets those four as inline custom properties on `documentElement`.
3. `useTheme()` writes both attributes on `document.documentElement`; `system` subscribes to
   `matchMedia('(prefers-color-scheme: dark)')`.
4. Persist to `preferences.theme` on the profile (beside locale, units, currency, tone, BMR formula).
   Mirror to `localStorage` **only** as a pre-hydration cache.
5. Set the attributes from a tiny inline script in `index.html` before the app mounts — otherwise the
   first paint flashes light before hydration.
6. Validate contrast for every scheme: ink-on-surface, accent-on-surface (field edges) and the focus
   ring against **both** `--surface` and `--paper`.

## Build order

1 migrations → 2 models → 3 controllers → 4 feature tests → 5 API client
→ 6 **Today** → 7 Routines → 8 Review → 9 Goals linking → 10 Appearance settings.

## Deferred — do not build

Finance & wishlists · meal/nutrition planning · AI & integrations · notifications & reminders ·
recurrence engine and `planned_occurrences` · `daily_metrics` rollups · weekly/monthly reviews ·
deep analytics & trends · multi-user & collaboration · offline sync engine.

The MVP is online-only and single-user. The data model leaves a clean path forward (e.g. a Routine can
later own a RecurringRule) — don't pre-build it.

## Assets

None. No images, icon fonts or SVG illustrations. Every glyph in the design is a text character
(`✓ – → ← ‹ › + !`) or a CSS shape. Fonts are Google Fonts: **Hanken Grotesk** (400/500/600/700) and
**JetBrains Mono** (400/500/600).

## Files

```
design/SelfHandler-MVP-standalone.html   ← open this; offline, interactive
design/SelfHandler MVP.dc.html           ← authored source (needs support.js beside it)
design/support.js
source/ui-tokens.md                      ← authority on tokens
source/MVP.md
source/MVP_TECHNICAL_DESIGN.md
```

Spec sections in the document: `00` visual system · `01` screen map · `02` core flows ·
`03` mobile screens · `04` desktop screens · `05` component list · `06` states ·
`07` Vue notes · `08` deferred · `09` **Settings → Appearance**.
