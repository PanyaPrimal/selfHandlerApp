# Quickstart: Habits and Anti-Habits

**Feature ID**: `013-habits-anti-habits`

## Apply locally

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan migrate

cd ..\web
npm install
```

The migration is additive. It does not alter deployment configuration or production data.

## Manual product verification

1. Sign in, open **Habits**, and create a daily yes/no habit. Set a time, place, two-minute starter,
   owned routine anchor, and owned goal. Reload and verify every field remains.
2. On today's card, mark it done with the current time. Correct it to not done, then clear it. Planner
   and statistics must follow each change without duplicate facts.
3. Create a weekday numeric habit with target `20` and unit `pages`. Record `25`, verify success/total,
   then correct to `10` and verify the chain breaks.
4. Create an abstinence anti-habit. Record protected days around one relapse. Verify exact current/best
   streak and retained relapse time; an omitted past day must not count as protected.
5. Create a stepped-limit anti-habit with `1/day`, then `5/week`, then `3/week`. Verify the active step,
   Monday–Sunday period, consumed/remaining amount, and exceeded state at the boundary.
6. Try duplicate dates, a higher normalized ceiling, a negative log, incompatible outcomes, unknown
   fields, and a foreign routine/goal id. Each write must fail atomically with localized feedback.
7. Open Planner for a scheduled habit day. Verify one source entry, time/order, reschedule behavior, and
   navigation to the Habits check-in. A completed fact cannot be moved.
8. Enable habit notifications, generate a due timed reminder, and verify one localized inbox record;
   complete the habit and verify the notification family closes. Untimed habits create no direct reminder.
9. Pause/archive/restore the habit. History remains, while future actionable occurrences/reminders stop
   and resume idempotently.
10. Repeat primary flows in EN/RU/UK and at 390×844. Use keyboard only, inspect ARIA names/live feedback,
    confirm ≥44px actions and no horizontal overflow.

## Focused automated verification

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan test --filter=Habit
php artisan test --filter=HabitsOpenApiContractTest
vendor\bin\pint --test

cd ..\web
npm run check:i18n
npm run typecheck
npm run build
npx playwright test e2e/habits
```

## Full feature gate

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan test
vendor\bin\pint --test

cd ..\web
npm run check:i18n
npm run typecheck
npm run build

cd C:\Code\PET\selfHandlerApp
npm run test:e2e
```

Also parse the OpenAPI document, compare its authenticated operations with Laravel routes, inspect
desktop/mobile screenshots, run `git diff --check`, and verify protected deployment paths and
`design_handoff_selfhandler_mvp/` are untouched.

## Must remain unchanged

- Existing Routine/Today progress semantics and historical routine logs.
- Goal milestones; stepped limits never read or write them.
- Planner ownership: only time blocks and occurrence reschedule intent remain Planner facts.
- Browser/native authentication and Android credential/local-presentation boundaries.
- `specs/002-homelab-deployment`, deployment folders/scripts/workflows, live rollout, and user handoff.
