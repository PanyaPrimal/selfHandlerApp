# UI Tokens — SelfHandler

Handoff reference for designing an **Appearance settings page with selectable colour schemes**.
Everything below is extracted from `apps/web/src/style.css` (the single stylesheet) as of `07585b2`.

## The one thing to know first

There is **no theming mechanism yet**. All colours are literal hex values defined once on a bare
`:root`, with no `[data-theme]` attribute, no `prefers-color-scheme` block, and no dark palette.
Building the settings page means introducing that mechanism, not just adding a screen.

Two constraints that follow from the existing code:

- Only the **colour** tokens should become themeable. Radii, fonts, spacing and control metrics are
  structural — a scheme that changes them stops being a colour scheme.
- `--focus-halo` is an `rgba()` literal of the accent, so any new scheme must redefine it alongside
  `--accent` or focus rings will keep the old green tint.

Where a preference would live: the profile already carries `preferences` (locale, unit system,
currency, tone, BMR formula). A theme choice belongs there, not in `localStorage` alone.

## Colour tokens (current: single warm-paper light palette)

| Token | Value | Role |
| --- | --- | --- |
| `--paper` | `#ece9e2` | App background (warm paper) |
| `--paper-soft` | `#faf8f4` | Recessed fills, chips, disabled fields |
| `--surface` | `#ffffff` | Panels, cards, field fills |
| `--surface-warm` | `#fffaf2` | Open/active control fill |
| `--ink` | `#232220` | Primary text |
| `--muted` | `#56524b` | Secondary text |
| `--subtle` | `#6b645b` | Labels, helper text |
| `--border` | `#e0dcd2` | Panel and card edges |
| `--border-strong` | `#c4beb2` | Hover edge |
| `--field-border` | `#cfcabf` | Resting edge of any input/select/date |
| `--accent` | `#3d6b4e` | Forest green: primary buttons, focus, active nav |
| `--accent-soft` | `#eef3ef` | Accent-tinted fill |
| `--accent-border` | `#cfe0d3` | Accent-tinted edge |
| `--blue-muted` | `#4f6d8a` | Secondary data accent |
| `--gold-muted` | `#c2843f` | Secondary data accent |
| `--error` | `#9a553f` | Error text and edges (warm brick, not pure red) |
| `--error-soft` | `#faf2ef` | Error fill |

Non-colour visual tokens:

- `--shadow: 0 18px 50px rgba(38, 34, 28, 0.08)` — panels only
- `--focus-halo: 0 0 0 3px rgba(61, 107, 78, 0.22)` — accent at 22%
- `--focus-ring-width: 2px`

The page background is `--paper` plus a dotted texture that must survive any scheme:

```css
background:
  radial-gradient(circle at 1px 1px, rgba(30, 28, 24, 0.05) 1px, transparent 0),
  var(--paper);
background-size: 28px 28px;
```

## Type

- `--font-body: "Hanken Grotesk", "Segoe UI", system-ui, sans-serif`
- `--font-mono: "JetBrains Mono", "Cascadia Mono", Consolas, monospace`
- Base `line-height: 1.5`, weight `400`

| Element | Size | Weight | Notes |
| --- | --- | --- | --- |
| `h1` | `clamp(2rem, 4vw, 3.35rem)` | 600 | `letter-spacing: -0.02em`, `line-height: 1.04`, `max-width: 760px` |
| `h2` | `1.04rem` | 650 | |
| `h3` | `0.95rem` | 650 | |
| `.eyebrow` | `0.78rem` | 700 | mono, uppercase, `letter-spacing: 0.14em`, accent-coloured |
| Field label | `0.92rem` | — | `--subtle` |
| Helper / error | `0.82rem` | — | |
| Chip | `0.78rem` | — | |
| Big metric | `2rem` | — | |

Brand wordmark: mono, `0.78rem`, weight 700, `letter-spacing: 0.18em`, accent.

## Shape and spacing

- `--radius: 14px` — panels, cards, notices
- `--radius-sm: 8px` — buttons, fields, nav items
- Pills (chips, dots): `999px`
- Panel: `padding: 20px`, internal `gap: 16px`
- View stack gap: `20px`; field grid gap: `6px`
- Auth card: `width: min(100%, 420px)`, radius `calc(var(--radius) + 4px)`

## Control metrics (do not vary by scheme)

| Control | Metric |
| --- | --- |
| Field (`.ui-control`) | `min-height: 42px`, `padding: 10px 12px`, `border: 1px` |
| Textarea | `min-height: 64px` |
| Button | `min-height: 40px`, `padding: 0 16px` |
| Nav item (compact) | `min-height: 44px` |
| Chip | `min-height: 26px`, `padding: 4px 8px` |

Button variants: default (accent fill, white text, no border); `.secondary` (paper-soft fill,
`--muted` text, `--border` edge); `.ghost` (transparent, accent text, `--accent-border` edge).

## Focus (deliberate, keep the behaviour when restyling)

A field **keeps its exact geometry** and only turns its own edge accent, plus `--focus-halo`. It also
carries `outline: 2px solid transparent` so Windows high-contrast mode still paints a ring. A button
or link has no edge to recolour and takes a real `2px` accent outline at `outline-offset: 2px`.

Any new scheme must keep enough contrast for both: the accent must read against `--surface` (field
edges) and the outline must read against `--paper` (buttons on background).

## Layout and breakpoints

- Desktop shell: `grid-template-columns: 248px minmax(0, 1fr)`; content `width: min(100%, 1180px)`,
  padding `40px 32px 64px`
- Most pages cap at `max-width: 880px`
- `@media (max-width: 760px)` — sidebar becomes a bottom tab bar (4 primary tabs + More)
- `@media (max-width: 480px)` and `(max-width: 360px)` — further compaction
- `@media (prefers-reduced-motion: reduce)` is already honoured
- Mobile target viewport is exactly **390×844**; no page may scroll horizontally there

## What the settings page needs to cover

1. Scheme choice (at minimum: current light, a dark counterpart, and "follow system").
2. Live preview — the tokens are global, so a preview must show a panel, a field at rest, a focused
   field, a primary button and a chip together.
3. Contrast is a correctness requirement, not a nicety: text on surface, accent on surface, and the
   focus ring on both `--surface` and `--paper`.
4. Existing controls to reuse: `UiSelect`, `UiSegmented`, `UiToggleGroup`, `UiSwitch`, `UiCheckbox`,
   `UiField` (see `apps/web/src/components/ui/`).
