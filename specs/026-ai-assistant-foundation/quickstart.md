# Quickstart: AI Assistant Foundation Verification

All commands run from `C:\Code\PET\selfHandlerApp`. Never enter a real provider key in fixtures, screenshots,
tracked environment files or browser recordings. Automated tests must intercept every provider request.

## 1. Baseline and protected paths

```powershell
git branch --show-current
git rev-parse HEAD
git rev-parse origin/master
git status --short
npx gitnexus analyze
```

Expected preserved untracked paths are `AGENTS.md`, `CLAUDE.md`, and `design_handoff_selfhandler_mvp/` only.

## 2. Backend focused RED/green suites

```powershell
Set-Location apps/api
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test --testsuite=Unit --filter=Ai
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test --testsuite=Feature --filter=Ai
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test tests/Feature/Storage/StorageApiTest.php
& 'C:\OSPanel\modules\PHP-8.4\php.exe' artisan test tests/Feature/Portability
```

Evidence must cover encryption/masking/rotation, two provider fixtures, fixed endpoints, safe errors, ownership,
active readiness, consent, outbound minimization, strict tool validation, no-write draft, expiry/replay/stale/race,
one confirmed Storage mutation, audit redaction and portability exclusion.

## 3. Contracts and frontend focused gates

```powershell
Set-Location apps/web
npm run test:unit -- ai-assistant-contracts
npm run check:i18n
npm run typecheck
npm run build
npx playwright test e2e/ai --project=desktop --project=mobile
Set-Location ../..
```

Playwright routes use deterministic fake SelfHandler API/provider results; browser requests must never reach an
LLM host.

## 4. Manual fixture acceptance

1. Add Anthropic and OpenAI connections with obvious fake keys and verify keys disappear after save.
2. Exercise fixture test success and each closed failure; activate only a ready connection.
3. Read the `storage_inbox` disclosure, grant consent, then request one Inbox proposal.
4. Confirm the source Item is unchanged while the proposal is visible; dismiss once and verify no change.
5. Regenerate and confirm; verify one Item becomes active with the reviewed values and existing Storage UI updates.
6. Replay, expire, change the source, revoke consent and attempt foreign-owner access; verify zero additional write.
7. Repeat EN/RU/UK, light/dark, desktop and exact 390x844; inspect screenshots using the image viewer.
8. Verify keyboard order, focus/status/error semantics, 44px targets, wrapping and no horizontal overflow.

Live provider acceptance is external evidence: an operator supplies their own Anthropic/OpenAI key, an available
strict-tool-capable model, accepts a potentially billable context-free test, grants `storage_inbox`, and records one
draft/confirmation journey. Its absence does not authorize fabricated success or a committed credential.

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

Also verify MySQL checks/identifier lengths/migration preservation, OpenAPI route/ref closure, provider network
isolation, Android bundle fingerprint and only documented conditional E2E skips.

## 6. Security and delivery audit

```powershell
git diff --check
git grep -n -I -E 'sk-ant-|sk-proj-|Bearer [A-Za-z0-9_-]{20,}' -- ':!specs/026-ai-assistant-foundation/quickstart.md'
git status --short
git diff --name-only --cached
git diff --name-only --cached | Select-String -Pattern '^(deployment/|_local-deploy/|deploy\.ps1|specs/002-homelab-deployment/|\.github/workflows/|design_handoff_selfhandler_mvp/|AGENTS\.md|CLAUDE\.md)'
```

Run GitNexus staged change detection, inspect every affected high-risk symbol/flow, commit one feature, push only
current `master`, prove `HEAD == origin/master`, then refresh the index.
