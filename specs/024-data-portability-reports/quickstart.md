# Quickstart: Data Portability and Reports

## Reports

1. Open `/analytics` and apply metric, range, grouping, and optional comparison.
2. Download CSV or PDF from the report actions.
3. Confirm the filename, selected range, summary, exact interval rows, and evidence limitations.

## Backup and validation

1. Open `/settings/data` and download the machine backup ZIP.
2. Inspect `manifest.json`; JSON data and attachment bytes are separate members.
3. In a different empty account, select that ZIP and run validation.
4. Review schema, date, record/attachment/byte counts, exclusions, eligibility, and any issues.
5. Enter the exact localized confirmation shown by the UI and restore before the token expires.
6. Confirm module data and private images are present while the target login email/password remain unchanged.

## Focused verification

```powershell
cd apps/api
C:\OSPanel\modules\PHP-8.4\php.exe artisan test --testsuite=Unit --filter='Report|Portability'
C:\OSPanel\modules\PHP-8.4\php.exe artisan test --testsuite=Feature --filter='Report|Portability'

cd ../web
npm run test:unit -- portability-contracts
npm run typecheck
npm run build
npx playwright test e2e/portability
```

Large archive transport may require an operator to set ordinary PHP/web-server upload limits above the archive
size. That is environment configuration, not feature 024 deployment work.

## Delivery evidence

- Laravel: 796 passed / 10,643 assertions on PHP 8.4 with full GD; Pint, strict Composer validation, and
  Composer audit passed with zero advisories.
- Web: 1,874 locale keys across 117 scanned files, 53 Vitest tests, TypeScript typecheck, production build,
  and npm audit passed with zero advisories.
- Playwright: 239 passed and 11 documented project-conditional skips across desktop and exact-390 projects;
  the eight report/Data cases passed in the same full run.
- Visual acceptance: all 12 EN/RU/UK light/dark desktop/mobile Data screenshots were inspected after the
  final file-picker styling change; no horizontal overflow or unreadable state remained.
- Android shared client: Capacitor sync completed with seven existing plugins, 19/19 mobile tests passed,
  npm audit reported zero advisories, and the synchronized bundle fingerprint was `83408ae90897`.
- No migration, deployment, production data, feature 002, native database, APK, device, or workflow action
  was performed.
