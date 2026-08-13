# Quickstart: Supplements, Courses, Intake, and Stock

## Preconditions

```powershell
Set-Location C:\Code\PET\selfHandlerApp
git branch --show-current   # existing master only
git status --short          # only the preserved handoff plus feature 017 work
```

Never deploy, touch feature 002/deployment/workflows/live data, create a branch/worktree, or stage
`design_handoff_selfhandler_mvp/`.

## Manual Product Verification

1. Sign in, open **Supplements**, and create gram/mg, millilitre, and piece references. Verify exact
   conversion, neutral copy, edit/archive/restore, filters, and another account's 404 isolation.
2. Create twice-daily, alternate-day, selected-weekday/2-week interval, and 7-on/7-off bounded courses.
   Verify local dates/times/contexts, DST behavior, course bounds, pause/resume/archive, and Planner.
3. Mark one slot taken and another skipped; correct outcome/dose/time/note, retry the PUT, then clear.
   Verify one/zero fact identity, occurrence status, reminders, Today/Review, and adherence throughout.
4. Add a positive restock and signed correction. Verify immutable history and exact remaining stock;
   deliberately record intake beyond stock and confirm an explicit negative discrepancy.
5. Use two overlapping courses for one reference. Verify exact run-out date and explanation, course-end
   and beyond-horizon states, one active proposal, dismissal persistence, and materially new reopening.
6. Deliver one intake reminder, three 30-minute repeats, and terminal transitions for taken, skipped,
   dismiss, disabled category, reschedule, pause, and archive. Verify proposal reminder never escalates.
7. Compare the same day/range in Supplements, Planner, Today, and Review. Done/skipped/overdue/pending/
   eligible totals and percentage must agree; Review persistence must contain no Supplement fields.
8. Repeat primary flows in EN/RU/UK, light/dark, desktop, and exact 390×844. Inspect neutral/safety copy,
   long category/unit/status text, focus/live regions, 44px controls, safe areas, console/page errors,
   and horizontal overflow.

## Focused Automated Gates

```powershell
Set-Location apps/api
php artisan test tests/Feature/Supplements tests/Unit/Supplements --compact
php artisan test tests/Feature/Recurrence tests/Feature/Planner tests/Feature/Notifications `
  tests/Feature/Habits tests/Feature/SleepRoutineTemplates tests/Feature/WorkoutsTrainingGoals `
  tests/Feature/Nutrition tests/Feature/CoreDailyLoop tests/Feature/Auth --compact
vendor\bin\pint --test
composer validate --strict

Set-Location ..\web
npm run check:i18n
npm run typecheck
npm run test:unit
npm run build
npx playwright test e2e/supplements --project=desktop
npx playwright test e2e/supplements --project=mobile

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
composer validate --strict
composer audit --locked --no-interaction

Set-Location ..\web
npm run check:i18n
npm run typecheck
npm run test:unit
npm run build
npm audit --omit=dev
npx playwright test --project=desktop
npx playwright test --project=mobile

Set-Location ..\mobile
npm audit --omit=dev
npm test

Set-Location ..\..
.\.specify\scripts\powershell\check-prerequisites.ps1 -Json -RequireTasks -IncludeTasks
git diff --check
git status --short
```

Inspect EN/RU/UK × light/dark × desktop/exact-390 screenshots for Supplements plus affected Today,
Review, Planner, Notifications, and Settings. Run broad secret/protected-path/handoff audits and
GitNexus `detect_changes(all/staged)` before the one feature commit. Stage only feature 017 files;
verify every task plus local/remote HEAD after push.
