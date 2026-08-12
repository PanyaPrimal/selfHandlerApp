# Quickstart: Storage Inbox and Quick Capture

**Feature ID**: `008-storage-inbox`

## Apply

```powershell
cd C:\Code\PET\selfHandlerApp\apps\api
php artisan migrate
```

Additive: four new tables, nothing reshaped.

## Manual verification

1. Open **Storage**. The empty inbox explains itself rather than showing a blank frame.
2. Type a single line and press Enter. The item appears in the inbox; the field clears and keeps focus.
3. Capture two more. They are newest first, and the unsorted count matches.
4. Triage one: set it to an idea, give it a project and two tags, and save. It leaves the inbox and
   appears under the project.
5. Attach two children to it. Mark one as a blocker.
6. Complete the parent. It is refused, naming the blocking child.
7. Complete the blocking child, then the parent. It succeeds.
8. Try to attach a child to a child. It is refused: nesting is one level.
9. Delete the project. Its items are still there, now without a project.
10. At 390×844 the screen has no horizontal overflow, and capture and triage work from the keyboard.

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

- Any existing endpoint, payload or behaviour.
- Anything about routines, goals, reviews, recurrence or body measurements.
