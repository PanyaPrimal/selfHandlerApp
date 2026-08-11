# Quickstart: Unified Recurrence with Routine Migration

**Feature ID**: `006-unified-recurrence`

## Apply

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan migrate
```

The migration backfills one rule per existing routine and then drops the old schedule shape. It is
reversible: `php artisan migrate:rollback --step=1` restores the columns and the weekday table from the
rules.

## Manual verification

1. **Nothing moved** — open Today and Routines. The same routines, the same schedules, the same logs and
   the same streak numbers as before.
2. **Recurrence editor** — on `/routines`, switch Schedule between *Daily* and *By weekdays*. Weekday
   selection appears only for the weekly choice, and saving without a weekday is rejected inline.
3. **Locked schedule** — mark a routine done on Today, then try to change its schedule. The save is
   rejected with the "archive and create a replacement" message.
4. **Window** — run `php artisan recurrence:materialize` and inspect `planned_occurrences`: exactly the
   days the rule expands, up to 90 days ahead, none after the end date.
5. **Idempotency** — run it again. The row count does not change.
6. **Fact linkage** — mark a routine done for today, then check the occurrence for that day: `status` is
   `done` and `routine_log_id` points at the log. Clear the log and it returns to `planned`.
7. **Two zones** — set two accounts to zones on opposite local days and confirm each sees only their own
   scheduled day.
8. **390px** — at 390×844, the recurrence editor has no horizontal overflow and every control is
   reachable by keyboard.

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

- Any routine, log, goal link or review row.
- Any routine, Today or review API shape or value.
- Seven-day progress or streak numbers for the same data.
- The schedule lock after history, its fields or its messages.
