# Quickstart: In-App Notifications

## Preconditions

- API dependencies installed in `apps/api`.
- Web dependencies installed in `apps/web`.
- Test database is disposable; no deployment or live data is involved.

## Focused backend verification

```powershell
cd apps/api
php artisan test --filter=Notification
php artisan test --filter=QuietHours
php artisan route:list --path=notifications
php artisan schedule:list
```

Expected:

- all notification settings/API/source/digest/escalation/ownership tests pass;
- only authenticated notification endpoints are listed;
- `notifications:process` is registered every minute without overlap.

## Manual processor check

With a disposable local user and due fixture:

```powershell
cd apps/api
php artisan notifications:process --user=1 --sync
php artisan notifications:process --user=1 --sync
```

The second run must not add a duplicate `(source_type, source_id, escalation_count)` row. `--sync` is
a development/test aid; the scheduled path enqueues unique per-user jobs.

## Focused frontend verification

```powershell
cd apps/web
npm run check:i18n
npm run typecheck
npm run build
npx playwright test e2e/notifications/notifications-inbox.spec.ts
npx playwright test e2e/notifications/notifications-settings.spec.ts
```

Verify in English, Russian, and Ukrainian:

1. the shell badge and notification destination are visible;
2. unread/read filtering, action, dismiss, and snooze update immediately;
3. quiet hours, digest time, and both category settings save and reload;
4. the page remains keyboard-operable and has no horizontal overflow at 390×844.

## Full feature gate

```powershell
cd apps/api
php artisan test
vendor/bin/pint --test

cd ../web
npm run check:i18n
npm run typecheck
npm run build

cd ../..
npm run test:e2e
git diff --check
```

Also parse `contracts/openapi.yaml`, verify its paths against `route:list`, and audit that feature 011
did not change `specs/002-homelab-deployment`, `deployment`, `_local-deploy`, `deploy.ps1`, workflows,
or the preserved untracked `design_handoff_selfhandler_mvp` directory.
