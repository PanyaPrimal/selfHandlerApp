# Quickstart: Planner and Day Planning

**Feature ID**: `009-planner-day`

## Apply

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan migrate
```

Additive: one new table and one nullable column.

## Manual verification

1. Open **Planner**. It shows today in your profile time zone.
2. With a routine due today, a Storage task due today and a time block, all three appear in one list,
   in time order, each labelled with where it came from.
3. Add a block with a start and an end. It appears in time order among the rest. An end before its
   start is refused inline.
4. Reschedule a routine occurrence to tomorrow. It leaves today and appears tomorrow; clearing the
   reschedule brings it back.
5. Skip a routine occurrence. Open Today: the skip is there, exactly as if you had marked it on Today.
6. Complete a routine on Today, return to Planner and try to reschedule it. It is refused.
7. Move a dated Storage task to another day. Open Storage: its due date changed there, and nothing was
   duplicated.
8. Open a day far in the future. It says it is beyond the planned window rather than showing an empty day.
9. At 390×844 the screen has no horizontal overflow and every action is reachable by keyboard.

## The window

```powershell
docker compose --env-file _local-deploy\.env.funnel -f _local-deploy\compose.local.yaml `
  exec app php artisan schedule:list
```

`recurrence:materialize` runs daily; the `scheduler` service keeps `schedule:work` alive.

## Automated verification

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan test
vendor\bin\pint --test

cd C:\Code\PET\selfHandlerApp\apps\web
npm run typecheck
npm run build
npx playwright test
```

## What must not change

- Today, progress, streaks, routine logs.
- Storage behaviour beyond a due date moved through its own endpoint.
- Any existing endpoint or payload.
