# Quickstart: Interface Foundation and User Changelog

**Feature ID**: `005-interface-foundation`

## Prerequisites

- Feature 004 complete (profile locale and time zone available on the session).
- `apps/web` dependencies installed, including `@floating-ui/vue`.

## Install

```powershell
cd C:\Code\PET\selfHandlerApp\apps\web
npm install
```

## Run locally

```powershell
# terminal 1 - API
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan serve --host=127.0.0.1 --port=8000

# terminal 2 - web
cd C:\Code\PET\selfHandlerApp\apps\web
npm run dev
```

## Manual verification

1. **Choice control** — open `/account`, open *Units*. The list must be an application surface: warm
   white, product radius, forest-green selected row. No operating-system dropdown.
2. **Searchable choice** — on the same screen open *Timezone* and type `kyi`. The list filters; typing
   nonsense shows an explicit "nothing found" state.
3. **Calendar** — open *Date of birth*. Month grid, weekday headers in the profile locale, selected day
   marked. Close with Escape: the value is unchanged. Reopen: the same day is still selected.
4. **Date stability** — set the operating-system time zone to something west of UTC (for example
   `America/New_York`), reload, open a saved date. The displayed day must not shift back by one.
5. **Time control** — open `/routines`, open *Preferred time*. Type `07:3` then `0`; blur normalises to
   `07:30`. Open the list and pick a slot with the keyboard.
6. **Switch and toggles** — on the same form toggle *Active in planning* with Space, and select
   weekdays with the toggle group.
7. **Keyboard only** — unplug the mouse. Create a routine end to end: Tab to each field, open the kind
   list with Enter, choose with arrows and Enter, set the start date from the calendar with arrows, and
   submit.
8. **Viewport** — resize to 390×844. Open the timezone combobox and the calendar. Neither may be
   clipped, overlap the bottom navigation, or make the page scroll sideways.
9. **Changelog** — select *Changelog* in the navigation, then reload the URL directly. Entries are
   newest first; each has a date, a plain-language description and a "how to test" line; links
   navigate.
10. **Navigation** — at 390px, confirm four primary tabs plus *More*; open *More* and reach Account and
    Changelog; confirm *More* shows an active state while on those routes; press Escape to close.
11. **Reduced motion** — enable the operating-system reduce-motion setting and confirm surfaces appear
    without a transition.

## Automated verification

```powershell
cd C:\Code\PET\selfHandlerApp\apps\web
npm run typecheck
npm run build

cd C:\Code\PET\selfHandlerApp
npm run test:e2e                      # both projects
npx playwright test --project=desktop
npx playwright test --project=mobile  # exact 390x844

cd C:\Code\PET\selfHandlerApp\apps\api
php artisan test                      # must stay green; no backend file changes
vendor\bin\pint --test
```

## What must not change

- Any API request or response body.
- Any validation message or field-error mapping.
- Any stored calendar date.
- The existing focus-recovery behaviour after a failed save.
