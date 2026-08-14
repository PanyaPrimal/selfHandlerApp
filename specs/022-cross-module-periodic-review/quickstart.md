# Quickstart: Cross-Module and Periodic Review

## Purpose

Verify feature 022 locally without deployment. Commands run only against the repository test environment.

## Backend Focused Checks

```powershell
Set-Location C:\Code\PET\selfHandlerApp\apps\api
php artisan test --testsuite=Unit --filter=Review
php artisan test tests/Feature/Review
php artisan test tests/Feature/CoreDailyLoop/DailyReviewApiTest.php
```

Expected: period/score/registry tests, schema/OpenAPI/API/ownership/query-budget feature tests, and the
existing DailyReview compatibility suite pass.

## Migration Reversibility

Use the isolated Laravel test harness; do not run `migrate:fresh` against the developer `.env` database:

```powershell
Set-Location C:\Code\PET\selfHandlerApp\apps\api
php artisan test tests/Feature/Review/ReviewSchemaTest.php
```

The schema test invokes the 022 migration's `down()` and `up()` methods inside its isolated database and
confirms only `periodic_reviews` is removed/recreated.

## Frontend Checks

```powershell
Set-Location C:\Code\PET\selfHandlerApp\apps\web
npm run check:i18n
npm run test:unit
npm run typecheck
npm run build
```

## Focused Browser Journeys

```powershell
Set-Location C:\Code\PET\selfHandlerApp
npm --prefix apps/web run test:e2e -- e2e/review --project=desktop
npm --prefix apps/web run test:e2e -- e2e/review --project=mobile
```

Inspect daily/weekly/monthly screenshots for EN/RU/UK, light/dark/system schemes, desktop, and exact
390x844 viewports. Generated evidence stays in ignored test output.

## Android Shared-Bundle Check

```powershell
Set-Location C:\Code\PET\selfHandlerApp
$env:SELFHANDLER_MOBILE_API_ORIGIN = 'https://selfhandler.example.test'
npm run sync:android
npm --prefix apps/mobile test
```

No APK, emulator/device, signing, workflow, or deployment action is part of feature 022.

## Manual Acceptance Walkthrough

1. Sign in and create or log at least one habit, workout, meal/target, supplement intake, planned item,
   sleep fact, routine, and finance transaction for a selected day.
2. Open Daily Review and confirm eight module cards, score value/coverage/components, and the unchanged
   daily reflection form.
3. Correct a source fact in its owner module, return/reload, and confirm the aggregate and score update
   while saved reflection text remains.
4. Switch to Weekly, choose a mid-week date, save rating/what worked/what did not/lesson/next focus/notes,
   then choose Sunday of the same week and confirm the same Monday-Sunday review reopens.
5. Switch to Monthly, verify the first/last day, save/edit/reload, and follow Planner/Goals links without
   Review changing either module itself.
6. Repeat at 390px and in each locale; force a failed read/write and verify explicit retry feedback.

## Full Final Gates

```powershell
Set-Location C:\Code\PET\selfHandlerApp\apps\api
php artisan test
vendor\bin\pint --test
composer validate --strict
composer audit --format=json

Set-Location C:\Code\PET\selfHandlerApp\apps\web
npm run check:i18n
npm run test:unit
npm run build
npm audit --audit-level=high

Set-Location C:\Code\PET\selfHandlerApp
npm run test:e2e
npm run test:e2e:mobile
```

Also run GitNexus change detection, inspect affected flows, confirm no protected deployment/handoff path is
staged, verify feature tasks/checklists are complete, then create and push one atomic feature commit.
