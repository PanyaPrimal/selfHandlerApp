# Quickstart: Sleep and Rich Routine Templates

**Feature ID**: `014-sleep-routine-templates`

## Apply locally

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan migrate

cd C:\Code\PET\selfHandlerApp\apps\web
npm run dev
```

No deployment, provider, live-data, or Android signing action belongs to this feature.

## Manual product verification

1. Sign in, open **Routines**, and verify the combined Routines & Sleep workspace loads existing simple
   routines unchanged. Create one daily sleep plan with bedtime 23:00 and wake time 07:00.
2. Choose a scheduled date and record actual bedtime on the night date, wake on the following date,
   quality 1–10, and a note. Reload, correct wake/quality, then clear. Verify duration and range averages
   update and no duplicate fact appears.
3. Try equal planned times, a DST-gap wall time, wake before bed, >24-hour duration, unknown keys, a
   foreign plan, and an unscheduled date. Every request must fail atomically with localized feedback.
4. Create a morning routine and replace its activity list with three unique ordered items: one untimed,
   one timed, and one with numeric progress total. Reorder/edit before facts and verify reload.
5. On Today, complete activities independently. The parent stays pending until all resolve; all done
   yields parent done, any skip yields parent skipped. Correct/clear one child and verify parent and
   occurrence reopen/reclose deterministically. Direct parent done must be rejected.
6. After a fact exists, attempt to add/remove an activity or change its progress total. Verify the
   accepted structure remains unchanged. Existing simple routines still support direct done/skip/clear.
7. Create two scheduled morning and two evening candidates. With no explicit choice, verify deterministic
   defaults. Select alternatives, then explicit none for one slot. Today and Planner must match; anytime
   routines remain. Reject wrong-period, foreign, unscheduled, moved-away, or fact-hiding selections.
8. Open Today and the same Daily Review date. Verify identical read-only sleep and activity summaries;
   save Review, correct module facts, and confirm only the displayed context changes.
9. Open Planner. Verify selected routines use the existing routine source, sleep has one separate row
   with wake context, reschedule works before a fact, and fact-bearing occurrences cannot move.
10. Enable routine/sleep notifications, process due sources, and verify selected timed routine and
    bedtime reminders are localized, deduplicated, quiet-hours aware, and closed by facts/lifecycle.
    Unselected/explicit-none/untimed sources must not create pending reminders.
11. Pause/archive/restore plans/templates and verify facts/history remain while future occurrences and
    reminders stop/resume idempotently.
12. Repeat core flows in EN/RU/UK, light/dark, desktop, and exact 390×844. Verify keyboard focus, live
    regions, 44px targets, safe-area navigation, Android transport, and no horizontal overflow.

## Focused automated gates

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan test tests/Feature/SleepRoutineTemplates tests/Unit/SleepRoutineTemplates
php artisan test tests/Feature/Recurrence tests/Feature/Planner tests/Feature/Notifications tests/Feature/CoreDailyLoop tests/Feature/Mobile
vendor\bin\pint --test

cd C:\Code\PET\selfHandlerApp\apps\web
npm run check:i18n
npm run typecheck
npm run test:unit
npm run build
npx playwright test e2e/sleep-routines

cd C:\Code\PET\selfHandlerApp\apps\mobile
npm test
$env:SELFHANDLER_MOBILE_API_ORIGIN='https://selfhandler.example.test'
npm run sync:android
```

## Full closure gates

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan test
vendor\bin\pint --test

cd C:\Code\PET\selfHandlerApp\apps\web
npm run check:i18n
npm run typecheck
npm run test:unit
npm run build
npx playwright test --project=desktop
npx playwright test --project=mobile

cd C:\Code\PET\selfHandlerApp
git diff --check
git status --short
```

Also parse OpenAPI 3.1, compare ten authenticated operations with Laravel, verify migration
preservation/rollback and MySQL identifiers, run fixed query-budget/ownership/secret scans, inspect all
12 locale/theme/viewport screenshots, and prove protected deployment paths plus
`design_handoff_selfhandler_mvp/` are untouched.
