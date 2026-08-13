# Quickstart: Budget and Recurring Cash Flow

## Scope

Feature 019 adds monthly category budgets, recurring income/expense plans, explicit occurrence outcomes,
planned cash flow, Planner entries, Finance reminders/warnings, and the shared localized client. Do not
add debts, funds, Finance goals, purchase/restock links, one-off plans, integrations, AI, or deployment.

## Local Verification

```powershell
Set-Location C:\Code\PET\selfHandlerApp\apps\api
php artisan test tests/Feature/Finance tests/Unit/Finance
php artisan test tests/Feature/Recurrence tests/Feature/Planner tests/Feature/Notifications
vendor\bin\pint --test
composer validate --strict --no-interaction
composer audit --locked --no-interaction

Set-Location C:\Code\PET\selfHandlerApp\apps\web
npm run check:i18n
npm run typecheck
npm run test:unit
npm run build
npx playwright test e2e/finance --project=desktop
npx playwright test e2e/finance --project=mobile

Set-Location C:\Code\PET\selfHandlerApp\apps\mobile
npm test
npm audit --audit-level=high
$env:SELFHANDLER_MOBILE_API_ORIGIN = 'https://selfhandler.example.test'
npm run sync:android
```

Before closure run full Laravel and split full Playwright projects, inspect every generated visual,
prove isolated one-step rollback/reapply, and complete protected-path/handoff/GitNexus audits.

## Manual Acceptance Path

1. Open `/finance?tab=budgets`, choose the current month and create one expense-category limit.
2. Post same- and foreign-currency expenses; verify exact actual/remaining/state and missing-rate honesty.
3. Create salary on days 5/15/25 and a mandatory monthly bill with optional reminder times.
4. Inspect the selected month's planned income, mandatory/discretionary expense and free cash flow.
5. Open Planner on an occurrence date, move one plan, skip another, and deep-link one back to Finance.
6. Realize one income and one expense; retry and verify one ledger group/balance change each.
7. Clear only a skipped outcome; reverse an actual through immutable ledger history.
8. Cross budget 80% and 100%; process Notifications and verify one localized warning per level.
9. Pause/archive/restore a plan and verify only future unfactored projections change.
10. Repeat locale/theme/reload/desktop/exact-390/Android checks and inspect focus/errors/overflow.

## External Blockers

None for the shared API/web/Capacitor-sync slice. Native compilation/device checks remain subject to
the documented local Android SDK availability; provider/bank credentials are outside feature 019.
