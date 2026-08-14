# Kick-off prompt for the IDE agent

Copy the folder `design_handoff_selfhandler_mvp/` into your repo root, then paste the prompt below
into Claude Code / Cursor. Do it in **two** turns — plan first, then build one screen at a time.

---

## Turn 1 — orient and plan (no code yet)

```
Read design_handoff_selfhandler_mvp/README.md in full, plus
design_handoff_selfhandler_mvp/source/ui-tokens.md and source/MVP_TECHNICAL_DESIGN.md.
Open design_handoff_selfhandler_mvp/design/SelfHandler-MVP-standalone.html in a browser to see the
intended result — it is interactive; click the Today rows and the Appearance controls.

Then read the actual code: apps/web/src/style.css, apps/web/src/components/ui/, the router, and the
existing stores.

The HTML is a DESIGN REFERENCE, not code to copy. Recreate it in this Vue 3 codebase using our
existing tokens and ui/ primitives. The prototype's --sh-* custom properties are a mock artifact —
our real token names are --paper, --surface, --accent, etc. Never introduce --sh-* here.

Give me, before writing any code:
1. A file-by-file plan (new files vs. edits) for: token split into [data-theme], useTheme(),
   AppShell, TodayView, RoutinesView, ReviewView, GoalsView, AppearanceSettings.
2. Every place the README's spec conflicts with what's already in the codebase, and your
   recommendation for each.
3. Which existing ui/ components cover the spec's component list, and what genuinely needs to be new.

Do not write code until I approve the plan.
```

## Turn 2 — build, in this order

Feed these one at a time; review each before moving on.

```
1. Theming foundation only. Split the colour tokens from :root into :root[data-theme="light"] and
   ["dark"] using the exact dark values in the README. Keep radii/fonts/spacing/control metrics on
   :root. Add [data-accent] for the four presets, each redefining --accent, --accent-soft,
   --accent-border AND --focus-halo together. Add useTheme() writing both attributes on
   documentElement, with the matchMedia subscription for 'system', localStorage as pre-hydration
   cache only, and the inline anti-flash script in index.html. No new screens yet — prove the
   existing app renders correctly in both schemes first.

2. AppShell + TodayView. The rail/tab-bar swap at 760px, then the Today screen exactly as specced:
   completion card, RoutineRow (tap = done, long-press/skip control = skipped with stopPropagation,
   skipped excluded from the rate), stat row and goal-context panel on desktop, "Close your day"
   card. Optimistic PUT to /api/routines/{id}/logs/{date} with rollback. Completion % and streaks
   computed from routine_logs.

3. ReviewView — all four sliders plus the text fields on one screen, upsert to
   PUT /api/daily-reviews/{date}.

4. RoutinesView + RoutineForm, then GoalsView with the routine-link pivot.

5. AppearanceSettings — scheme toggle group, accent UiSwatchGroup, the custom-colour picker with the
   documented derivation formulas and the live contrast pill, the two switches, motion control, and
   the live preview panel. Persist via PATCH /api/profile → preferences.theme.

After each step: verify at 390×844 (no horizontal scroll) and at desktop width, in BOTH schemes, and
confirm focus rings read against --surface and --paper.
```

## Guardrails worth pasting alongside

```
- Only colour tokens are themeable. If a change would alter radii, type, spacing or control metrics
  per scheme, stop and ask.
- --focus-halo is always derived from --accent (22% light / 28% dark). Never a hard-coded rgba.
- All numbers in the mock are sample data. Nothing may be hard-coded — derive from routine_logs.
- Skipping a routine is neutral, never a failure, and never counts against the completion rate.
- Absence of a log means "not handled yet", never "failed".
- No spinner may block marking a routine done. Optimistic write, background reconcile, inline retry.
- Do not build anything in the README's "Deferred" list, and do not pre-build the recurrence engine.
- Device bezels, browser chrome and the numbered section headers in the HTML are spec scaffolding,
  not app UI.
```
