# Quickstart: Finance Ledger Foundation

## Scope

Feature 018 delivers actual Finance ledger facts only: accounts, categories, manual rates, income,
expense, transfer, reversal, reconciliation, and derived balances/totals. Do not add budgets,
recurrence, notifications, debts/funds/goals, purchases/restocks, integrations, AI, or deployment.

## Local verification

```powershell
Set-Location C:\Code\PET\selfHandlerApp\apps\api
php artisan test tests/Feature/Finance tests/Unit/Finance
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
npm run sync:android
```

Before closure also run full Laravel and both full Playwright projects, inspect every generated
EN/RU/UK × light/dark desktop/exact-390 screenshot, and perform isolated migration rollback plus
protected-path/handoff/GitNexus audits.

## Manual acceptance path

1. Set Profile base currency to UAH and open `/finance`.
2. Verify the localized starter category tree appears once.
3. Create UAH cash and USD savings accounts with exact opening values.
4. Record a UAH income and expense; retry one request and verify one history group.
5. Transfer UAH to USD using two explicit amounts; verify both balances and effective rate.
6. Add a dated USD→UAH rate and verify the consolidated value becomes complete.
7. Reverse an expense and transfer; verify immutable original/reversal groups and exact balances.
8. Reconcile one account to an observed value; verify only the delta is appended.
9. Archive a zero account/category, restore them, and confirm history always remains visible.
10. Repeat locale/theme/reload/mobile checks and inspect focus, live errors, and overflow.

## Expected response principles

- Money and rates are JSON strings, never numbers.
- Foreign/unknown owned references return 404-equivalent responses.
- Missing FX returns a nullable incomplete consolidated total, not a partial claim.
- Transaction list items are action groups with one or two entry facts.
- Reversal never erases or edits its original group.

## External blockers

None are required for the shared web/API slice. Android compile/emulator checks remain subject to the
locally documented SDK availability; provider/bank credentials are outside feature 018.
