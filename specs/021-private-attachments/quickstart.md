# Quickstart: Private Attachments with First Consumers

## Prerequisites

- Work only on the selected `master` branch and keep unrelated generated agent files unstaged.
- Keep `design_handoff_selfhandler_mvp/` untracked and byte-for-byte unchanged.
- Do not touch or execute feature 002, deployment, workflows, containers, APK assembly/install, or live data.
- PHP/Node dependencies and Chromium are installed; PHP GD, EXIF, and Fileinfo are available.

## Spec Kit Check

```powershell
.specify\scripts\powershell\check-prerequisites.ps1 -Json -RequireTasks -IncludeTasks
```

The selected directory must be `specs/021-private-attachments`; all checklist items and analysis findings
must be complete before implementation.

## RED-First Checkpoint

Add permanent 021 schema/model/storage/image/quota/API/client/native/browser tests before production code.
Expected failures are only the absent Attachment table/classes/routes/types/components/plugins. All pre-021
Body, Nutrition, owner, auth, filesystem, shared-client, and mobile tests remain green.

## Focused Backend

```powershell
Set-Location apps/api
php artisan test tests/Unit/Attachments tests/Feature/Attachments `
  tests/Feature/CoreDailyLoop/BodyMeasurementApiTest.php `
  tests/Feature/Nutrition
vendor\bin\pint --test
composer validate --strict
composer audit --locked
```

## Full Backend and Evolution

```powershell
Set-Location apps/api
php artisan test
```

Validate image fixture metadata/orientation, private disk set equality, query budgets, closed OpenAPI route
parity, MySQL-safe identifiers, and isolated 021 rollback/reapply preserving seeded 020 rows.

## Web and Browser

```powershell
Set-Location apps/web
npm run check:i18n
npm run typecheck
npm run test:unit
npm run build
npx playwright test e2e/attachments/attachments-flow.spec.ts `
  e2e/attachments/attachments-visual.spec.ts --project=desktop-chromium
npx playwright test --project=desktop-chromium
npx playwright test --project=mobile-chromium
```

Inspect final EN/RU/UK × light/dark × desktop/exact-390 Body and Nutrition photo screenshots. Confirm
private preview, localized feedback/alt/confirmation, keyboard focus, live regions, 44px controls, retry,
empty/error/loading states, safe areas, and zero overflow or path/token leakage.

## Mobile Shared Bundle

```powershell
Set-Location apps/mobile
npm test
npm audit
$env:SELFHANDLER_MOBILE_API_ORIGIN = 'https://selfhandler.example.test'
npm run sync:android
```

Record the shared-bundle fingerprint and Camera/Filesystem/File Transfer plus existing plugin discovery.
Do not assemble, sign, install, launch, or deploy an Android artifact.

## Safety and Commit

- Run `git diff --check`, secrets/private-path/logging, dependency, generated, large-file, protected-path,
  and handoff audits.
- Refresh GitNexus, analyze staged impact, and review every medium/high/critical direct consumer.
- Complete tasks, final analysis, roadmap/design/docs/changelog/memory, and status.
- Stage only 021 files, commit once without co-author trailers, push `master`, fetch, and prove local HEAD
  equals `origin/master` while unrelated untracked files remain unchanged.
