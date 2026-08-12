# Quickstart: Interface Personalisation and Complete Localisation

## Isolated Environment

Use the existing Playwright environment only. It starts the API on `18110`, Vite on `15183`, and a
temporary SQLite database. Do not point tests at the live application or live database.

## Focused Verification

```powershell
Set-Location C:\Code\PET\selfHandlerApp\apps\api
php artisan test --filter=ProfilePreference
php artisan test --filter=Locale
vendor\bin\pint --test

Set-Location C:\Code\PET\selfHandlerApp\apps\web
npm run check:i18n
npm run typecheck
npm run build

Set-Location C:\Code\PET\selfHandlerApp
npx playwright test apps/web/e2e/preferences apps/web/e2e/localization
```

## Manual Acceptance Walkthrough

1. Clear only the two SelfHandler preference cache keys in a disposable browser context.
2. Open `/login`; verify English first paint, then choose Russian and reload without an English flash.
3. Choose Ukrainian, register/sign in, and verify the returned profile locale replaces guest state.
4. Visit every current route in all three languages. Exercise an empty state, validation error,
   domain refusal and API-unavailable feedback. Confirm product copy and ARIA names use one language.
5. In Account, change several fields without saving. Use the global language selector. Confirm the
   draft remains byte-for-byte equivalent and locale changes independently.
6. In Appearance, try all background presets and custom `#6D5AC4` in light and dark. Confirm the
   displayed minimum contrast is at least 4.5:1. Enter invalid `#123`; the last valid palette remains.
7. Save appearance, reload and use a fresh signed-in context. Confirm scheme/accent/background arrive
   on first paint. Use the global quick toggle on desktop and at 390x844.
8. Simulate a failed preference PATCH. Confirm localized feedback and rollback to the last accepted
   locale/theme.

## Full Gate

```powershell
Set-Location C:\Code\PET\selfHandlerApp\apps\api
php artisan test
vendor\bin\pint --test

Set-Location C:\Code\PET\selfHandlerApp\apps\web
npm run check:i18n
npm run typecheck
npm run build

Set-Location C:\Code\PET\selfHandlerApp
npm run test:e2e
git diff --check
git status --short
```

Expected repository dirtiness before the feature commit is limited to feature files. The preserved
untracked `design_handoff_selfhandler_mvp/` directory remains unmodified and uncommitted.
