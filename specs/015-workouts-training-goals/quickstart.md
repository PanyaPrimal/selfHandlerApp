# Quickstart: Workouts and Training Goals

## Preconditions

```powershell
Set-Location C:\Code\PET\selfHandlerApp
git branch --show-current   # existing master only
git status --short          # only the preserved handoff may be unrelated/untracked
```

Never deploy, touch feature 002/deployment/workflows/live data, create a branch/worktree, or stage
`design_handoff_selfhandler_mvp/`.

## Manual Product Verification

1. Sign in, open **Workouts**, and confirm the six localized built-in exercises plus custom catalogue
   create/edit/archive behavior and lifecycle filters.
2. Create a M/W/F timed strength program with two ordered exercises and progression targets. Reload;
   verify schedule, order, targets, and Planner entries.
3. Record a planned detailed strength session, correct one set/note, verify volume/PR/progression and
   the same session/occurrence identity, then clear it and see pending restored.
4. Record a simple manual strength session; verify it coexists on the same date without claiming a
   program occurrence.
5. Skip a planned session in Planner, verify one skipped Workout fact and reminder closure, then clear/
   complete it through Workouts.
6. Record/correct a run (distance, duration, run type, heart rate/energy), flexibility session, and
   sport session. Verify exact canonical round trips and localized pace/unit display.
7. Create strength, distance/race, and consistency goals. Add/correct matching/nonmatching sessions;
   verify null/derived current values and progress, immutable scope/start, lifecycle, and race Planner event.
8. Compare the same date in Workouts, Planner, Today, and Review. Planned/completed/skipped/manual,
   duration/distance/volume must agree; Review persistence must contain no Workout fields.
9. Enable/disable Workout notifications, exercise quiet hours/dedupe/reschedule/fact/pause/archive
   closure, and verify safe `/workouts` deep links.
10. Repeat primary flows in EN/RU/UK, light/dark, desktop, and exact 390×844. Inspect long copy, focus,
    live regions, 44px controls, safe areas, keyboard flow, console/page errors, and horizontal overflow.

## Focused Automated Gates

```powershell
Set-Location apps/api
php artisan test tests/Feature/WorkoutsTrainingGoals tests/Unit/WorkoutsTrainingGoals --compact
php artisan test tests/Feature/Recurrence tests/Feature/Planner tests/Feature/Notifications `
  tests/Feature/CoreDailyLoop tests/Feature/Body tests/Feature/Auth --compact
vendor\bin\pint --test

Set-Location ..\web
npm run check:i18n
npm run typecheck
npm run test:unit
npm run build
npx playwright test e2e/workouts-training-goals --project=desktop
npx playwright test e2e/workouts-training-goals --project=mobile

Set-Location ..\mobile
npm test
$env:SELFHANDLER_MOBILE_API_ORIGIN='https://selfhandler.example.test'
npm run sync:android
```

## Full Closure Gates

```powershell
Set-Location C:\Code\PET\selfHandlerApp\apps\api
php artisan test --compact
vendor\bin\pint --test

Set-Location ..\web
npm run check:i18n
npm run typecheck
npm run test:unit
npm run build
npx playwright test --project=desktop
npx playwright test --project=mobile

Set-Location ..\..
.\.specify\scripts\powershell\check-prerequisites.ps1 -Json -RequireTasks -IncludeTasks
git diff --check
git status --short
```

Inspect EN/RU/UK × light/dark × desktop/exact-390 screenshots for Workouts, Today, Review, and Planner.
Run broad secret/protected-path/handoff audits and GitNexus `detect_changes(all/staged)` before the one
feature commit. Stage only feature 015 files; verify all tasks and local/remote HEAD after push.
