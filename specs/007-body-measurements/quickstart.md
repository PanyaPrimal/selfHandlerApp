# Quickstart: Body Measurements and Body Goals

**Feature ID**: `007-body-measurements`

## Apply

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan migrate
```

Additive only: three new tables, nothing reshaped.

## Manual verification

1. Open **Body** in the navigation. With no history it explains the empty state rather than showing zeros.
2. Record a weight for today, then for two earlier dates entered out of order. The history lists them by
   date.
3. Save the same metric and date again with a different value. The history still has one row for that
   day, with the new value.
4. With one observation, the trend says there is not enough data. Add a second and it reports change per
   week.
5. Delete an observation. The trend and any goal progress follow immediately.
6. Create a body goal: metric, direction, starting value, target value and a target date. Progress
   appears against your latest measurement.
7. Set a target and date implying more than 2 lb per week of loss. A warning appears, the goal still
   saves, and the target you typed is unchanged.
8. Switch the profile to imperial. The same observations are shown in pounds and inches; switch back and
   the numbers are exactly what you entered.
9. At 390×844 the screen has no horizontal overflow and every control is reachable by keyboard.

## Automated verification

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan test
vendor\bin\pint --test

cd C:\Code\PET\selfHandlerApp\apps\web
npm run typecheck
npm run build

cd C:\Code\PET\selfHandlerApp
npx playwright test
```

## What must not change

- The Profile baseline. Recording a measurement never rewrites it.
- Existing goal behaviour, lifecycle or ownership.
- Any existing API shape.
