# Quickstart: Nutrition, Meals, Hydration, and Targets

## Preconditions

```powershell
Set-Location C:\Code\PET\selfHandlerApp
git branch --show-current   # existing master only
git status --short          # only the preserved handoff may be unrelated/untracked
```

Never deploy, touch feature 002/deployment/workflows/live data, create a branch/worktree, or stage
`design_handoff_selfhandler_mvp/`.

## Manual Product Verification

1. Sign in, open **Nutrition**, and confirm localized immutable plain water plus private food create,
   edit, archive, restore, and active/archived filters.
2. Create a gram-based food and an ml-based caloric beverage; verify strict basis/hydration rules and
   exact per-100 round trips.
3. Create and correct a two-food recipe. Verify ordered components, derived per-100 totals, quality,
   archive/restore, and rejection of beverages/foreign/archived-new components.
4. Record a Profile-local meal with atomic food, recipe, water, custom category/time, and note. Correct
   quantities, reload, edit catalogue references, and verify old snapshots remain stable until the meal
   itself is corrected. Delete the meal and verify all summaries update.
5. Configure macro percentages, optional water override, and an active body-mass goal. Read a controlled
   day and inspect formula, readiness, coefficients, goal approximation/caps, planned Workout energy,
   macro conversion, water rule, and limitation labels.
6. Change Profile, selected goal, Workout plan, intake, and actual energy after the target exists. Verify
   the reference target is unchanged and only the separately labelled refinement reflects actual energy.
7. Exercise incomplete Profile, Katch without body fat, missing planned/actual energy, no intake, zero
   intake, unavailable quality, future target, rejected future meal, and max-366 range states.
8. Compare one selected-day DTO on Nutrition, Today, and Review; Review persistence must contain no
   Nutrition aggregate fields.
9. Repeat main flows in EN/RU/UK, light/dark, desktop, and exact 390×844. Inspect focus, live feedback,
   44px controls, safe areas, long copy, console/page errors, and horizontal overflow.

## Focused Automated Gates

```powershell
Set-Location apps/api
php artisan test tests/Feature/Nutrition tests/Unit/Nutrition --compact
php artisan test tests/Feature/CoreDailyLoop tests/Feature/Body `
  tests/Feature/WorkoutsTrainingGoals tests/Feature/Profile --compact
vendor\bin\pint --test

Set-Location ..\web
npm run check:i18n
npm run typecheck
npm run test:unit
npm run build
npx playwright test e2e/nutrition --project=desktop
npx playwright test e2e/nutrition --project=mobile

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

Inspect EN/RU/UK × light/dark × desktop/exact-390 screenshots for Nutrition, Today, and Review. Run
broad secret/protected-path/handoff audits and GitNexus `detect_changes(all/staged)` before the single
feature commit. Stage only feature 016 files; verify tasks and local/remote HEAD after push.
