# Quickstart: Calendar Integration Verification

All commands run from `C:\Code\PET\selfHandlerApp`. Never add live credentials to tracked files and never run
provider contract tests in passthrough mode.

## 1. Baseline and protected paths

```powershell
git branch --show-current
git rev-parse HEAD
git rev-parse origin/master
git status --short
```

Expected preserved untracked paths are `AGENTS.md`, `CLAUDE.md`, and `design_handoff_selfhandler_mvp/` only.

## 2. Backend focused RED/green suites

```powershell
Set-Location apps/api
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test --testsuite=Unit --filter=Calendar
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test --testsuite=Feature --filter=Calendar
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test tests/Feature/Planner/PlannerDayTest.php
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test tests/Feature/Portability/PortabilityCatalogCoverageTest.php
```

Evidence must cover owner guards, encrypted/hidden fields, Google HTTP fixtures, Apple discovery/ICS fixtures,
OAuth state, pagination/cursors, window/timezones, idempotency, conflict authority, errors, locking and disconnect.

## 3. Contracts and frontend focused gates

```powershell
Set-Location apps/web
npm run test:unit -- calendar-integration-contracts
npm run check:i18n
npm run typecheck
npm run build
npx playwright test e2e/integrations --project=desktop --project=mobile
Set-Location ../..
```

The Playwright provider routes are deterministic mocks; no request may reach Google or Apple.

## 4. Manual acceptance without live credentials

1. Start the local API/web stack with the normal repository test environment.
2. Use the provider mock mode available only under automated test configuration.
3. Verify Google pending/calendar selection and Apple app-password form success/failure.
4. Verify default no-export, busy-only imported Planner entry, title opt-in, category warnings, repeated sync counts,
   transient/auth errors, retry, reload, and confirmed disconnect.
5. Repeat in EN/RU/UK, light/dark, desktop and exact 390x844; inspect screenshots with the image viewer.
6. Confirm keyboard order, focus, status announcements, 44 px targets, wrapping and no horizontal overflow.

Live provider acceptance is external evidence: an operator must supply Google OAuth client configuration or an
Apple account/app-specific password. Its absence does not authorize fabricated success or committed secrets.

## 5. Full gates

```powershell
Set-Location apps/api
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test
& 'C:\OSPanel\modules\PHP-8.4\php.exe' vendor/bin/pint --test
& 'C:\OSPanel\modules\PHP-8.4\php.exe' C:\ProgramData\ComposerSetup\bin\composer.phar validate --strict
& 'C:\OSPanel\modules\PHP-8.4\php.exe' C:\ProgramData\ComposerSetup\bin\composer.phar audit
Set-Location ../web
npm run check:i18n
npm run test:unit
npm run typecheck
npm run build
npm audit --audit-level=high
Set-Location ../..
npm run test:e2e
Set-Location apps/mobile
$env:SELFHANDLER_MOBILE_API_ORIGIN='https://selfhandler.example.test'
npm run sync:android
npm test
npm run validate
npx cap ls android
npm audit --audit-level=high
```

Also verify MySQL-specific checks/identifier lengths/migration preservation, OpenAPI route/ref closure, provider
network isolation, Android plugin/bundle fingerprint, and only documented conditional E2E skips.

## 6. Delivery audit

```powershell
git diff --check
git status --short
git diff --name-only --cached
git diff --name-only --cached | Select-String -Pattern '^(deployment/|_local-deploy/|deploy\.ps1|specs/002-homelab-deployment/|\.github/workflows/|design_handoff_selfhandler_mvp/|AGENTS\.md|CLAUDE\.md)'
npx gitnexus analyze
```

Run GitNexus staged change detection, inspect every affected high-risk symbol/flow, then make one feature commit,
push only current `master`, and prove `HEAD == origin/master`.

## 7. Recorded feature evidence (2026-08-14)

- Laravel: `831 passed` with `10,834 assertions`; focused Calendar/provider/Planner/Portability coverage
  is included. Pint check, Composer strict validation, Composer audit, schema identifiers and migration
  preservation pass.
- Web: i18n guard `1960` keys across EN/RU/UK and `119` source files; Vitest `15/15` files and
  `55/55` tests; TypeScript, production build and audits pass with `0 vulnerabilities`.
- Calendar browser acceptance: `6/6` deterministic desktop/mobile integration journeys pass. All 12
  EN/RU/UK × light/dark × desktop/mobile screenshots were opened and visually inspected.
- Full Playwright matrix: the initial run recorded `244 passed`, `11` documented project/viewport
  skips and one reproducible fixed-surface positioning regression exposed by the new navigation item.
  The fix was then proven by `16/16` desktop/mobile Sleep/Routines and shared-control regression tests,
  followed by the green `6/6` final Calendar matrix.
- Android shared bundle: sync/validation fingerprint `08661352c828`, `19/19` mobile tests, seven
  expected Capacitor plugins and `0 vulnerabilities`.
- No live provider request or credential was used. Live acceptance remains blocked on operator-supplied
  Google OAuth client configuration or an Apple account/app-specific password; this is external
  deployment evidence, not a repository defect.
