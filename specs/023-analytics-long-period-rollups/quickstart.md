# Quickstart: Analytics and Long-Period Rollups

## Purpose

Verify feature 023 without deployment, live data, external providers, APK/device actions, or destructive
database commands. Run from `C:\Code\PET\selfHandlerApp` unless a section changes directory.

## Backend Focused Checks

```powershell
Set-Location apps/api
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test --testsuite=Unit --filter=Analytics
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test tests/Feature/Analytics
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test tests/Feature/Review tests/Feature/Body tests/Feature/Nutrition tests/Feature/Finance
```

Feature 023 has no migration. Verify that explicitly without rebuilding any database:

```powershell
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test tests/Feature/Analytics/AnalyticsArchitectureTest.php
```

## Backend Quality Gates

```powershell
Set-Location apps/api
& 'C:\OSPanel\modules\PHP-8.4\php.exe' vendor/bin/pint --test
& 'C:\OSPanel\modules\PHP-8.4\php.exe' 'C:\OSPanel\data\PHP-8.4\default\composer\composer.phar' validate --strict
& 'C:\OSPanel\modules\PHP-8.4\php.exe' 'C:\OSPanel\data\PHP-8.4\default\composer\composer.phar' audit --abandoned=fail
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test
```

## Frontend Checks

```powershell
Set-Location apps/web
npm run check:i18n
npm run test:unit
npm run typecheck
npm run build
npm audit --omit=dev
```

## Focused Browser Journeys

```powershell
Set-Location apps/web
npx playwright test e2e/analytics --project=desktop
npx playwright test e2e/analytics --project=mobile
```

The flow must cover trend, comparison, all correlation states, correction freshness, URL reload, errors,
keyboard semantics, aggregate-only responses, owner isolation, and horizontal-overflow checks.

## Android Shared-Bundle Check

```powershell
Set-Location ..\..
$env:SELFHANDLER_MOBILE_API_ORIGIN = 'https://selfhandler.example.test'
npm run sync:android
npm run validate:android
npm run test:android-shell
npm --prefix apps/mobile audit --omit=dev
```

Record the synchronized bundle fingerprint and seven-plugin inventory. Do not build/sign an APK, open an
emulator/device, introduce a native database/cache, or contact deployment infrastructure.

## Manual Acceptance Walkthrough

1. Sign in and open `/analytics`.
2. Select Sleep duration, a range with at least two recorded nights, and daily/weekly/monthly granularity.
3. Confirm chart and table expose the same values, units, bounds, coverage, delta, and slope.
4. Enable comparison and verify current/previous exact ranges and unavailable percentage when prior is zero.
5. Inspect all three correlation cards and the association-not-causation disclosure.
6. Correct one source fact, reload, and verify the point changes with no Analytics rebuild action.
7. Repeat locale switching in English/Russian/Ukrainian and light/dark schemes at desktop and 390×844.
8. Traverse every control/table/card by keyboard and verify visible focus, names, and no horizontal overflow.

## Full Final Gates

Run both configured Playwright projects and visually inspect the complete Analytics matrix: three locales ×
two schemes × desktop/mobile for trend-ready, empty/incomplete, comparison, and correlations. Then run staged
path, secret/raw-field, forbidden-scope, OpenAPI reference/route, GitNexus change-impact, protected deployment,
and preserved handoff checks before the atomic commit and push.
