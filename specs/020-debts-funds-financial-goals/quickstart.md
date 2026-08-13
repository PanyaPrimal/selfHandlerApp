# Quickstart: Debts, Funds, Financial Goals, and Purchase Links

## Prerequisites

- Work only on the already selected `master` branch.
- Keep `design_handoff_selfhandler_mvp/` untracked.
- Do not touch or execute feature 002, deployment, workflows, containers, or live data.
- PHP dependencies, Node dependencies, and Chromium are already installed.

## Spec Kit Check

```powershell
.specify\scripts\powershell\check-prerequisites.ps1 -Json -RequireTasks -IncludeTasks
```

The returned feature directory must be `specs/020-debts-funds-financial-goals`; all requirement checklists
must be complete before implementation.

## RED-First Checkpoint

Add permanent 020 schema/domain/API/shared/client/browser tests before production code and record focused
failures. Expected failures must be only absent 020 tables/types/services/routes/surfaces or missing additive
members; legacy Finance, Goal, Storage, Supplement, recurrence, Planner, and Notifications tests stay green.

## Focused Backend

```powershell
Set-Location apps/api
php artisan test tests/Unit/Finance tests/Feature/Finance `
  tests/Feature/CoreDailyLoop/GoalApiTest.php `
  tests/Feature/Storage tests/Feature/Supplements `
  tests/Feature/Planner tests/Feature/Notifications `
  tests/Feature/Recurrence
vendor\bin\pint --test
composer validate --strict
composer audit --locked
```

## Full Backend

```powershell
Set-Location apps/api
php artisan test
```

Validate closed OpenAPI schemas/references/route parity and MySQL-safe identifiers in the permanent
contract/schema suites. Prove isolated rollback removes only 020 additions, preserves seeded existing
users/currencies/019 tables, and reapplies cleanly.

## Web and Browser

```powershell
Set-Location apps/web
npm run check:i18n
npm run typecheck
npm run test:unit
npm run build
npx playwright test e2e/finance/finance-commitments-flow.spec.ts `
  e2e/finance/finance-commitments-visual.spec.ts --project=desktop-chromium
npx playwright test --project=desktop-chromium
npx playwright test --project=mobile-chromium
```

Inspect every final EN/RU/UK, light/dark, desktop/exact-390 Debts/Funds/Goals/source screenshot. Confirm
localized copy, exact money/date evidence, keyboard/focus/live regions, 44px controls, source deep links,
draft recovery, loading/error/empty states, safe areas, and no horizontal overflow.

## Mobile Shared Bundle

```powershell
Set-Location apps/mobile
npm test
npm audit
$env:SELFHANDLER_MOBILE_API_ORIGIN = 'https://selfhandler.example.test'
npm run sync:android
```

Record the final shared-bundle fingerprint and expected four Capacitor plugins. Do not assemble/sign/install
or deploy as part of this feature.

## Safety and Commit

- Run `git diff --check`, secret/dependency/large-file/protected-path/handoff audits.
- Refresh GitNexus, analyze staged impact, and review every medium/high/critical direct consumer.
- Complete all tasks, final analysis, roadmap/docs/changelog/memory, and spec status.
- Stage only feature files, create one non-coauthored atomic commit, push `master`, fetch, and prove local
  HEAD equals `origin/master` while handoff remains the only unrelated untracked path.
