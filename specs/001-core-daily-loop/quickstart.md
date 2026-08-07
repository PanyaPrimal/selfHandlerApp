# Quickstart Validation: Core Daily Loop

This guide describes how to validate feature `001-core-daily-loop` after implementation. It is a
verification guide, not a replacement for the [feature specification](spec.md),
[data model](data-model.md), or [HTTP contract](contracts/openapi.yaml).

## Prerequisites

- PHP 8.2 or newer and Composer
- Node.js 22 or newer and npm
- A local Laravel environment supported by the repository (Open Server is the primary path)
- MySQL 8 for manual validation, or the isolated SQLite configuration used by automated tests
- `APP_TIMEZONE=UTC` and `SELFHANDLER_TIMEZONE` set explicitly in the API environment (for example,
  `Europe/Kyiv`)

## Install Dependencies

From the repository root:

```powershell
composer install --working-dir apps/api
npm --prefix apps/web ci
npm --prefix apps/web run install:browsers
```

Do not commit local `.env` files, databases, logs, or Playwright reports.

## Automated Quality Gates

Run the backend suite:

```powershell
Set-Location apps/api
php artisan test
Set-Location ../..
```

Validate the typed frontend and production build:

```powershell
npm --prefix apps/web run typecheck
npm --prefix apps/web run build
```

Run the isolated desktop and phone browser journeys:

```powershell
npm run test:e2e
```

Expected result: every command exits successfully. Browser tests create their own SQLite database,
start isolated API/web ports, and leave the normal development database untouched.

## Manual Product Validation

Start the API through the configured Open Server workflow or Laravel's local server, then start Vite:

```powershell
npm run dev:web
```

### Scenario 1: Daily Routine Loop (P1)

1. Open Routines and create a daily routine named `Morning water`.
2. Create a weekday routine named `Training` for the current weekday.
3. Open Today and verify both appear in stable order.
4. Mark `Morning water` done and `Training` skipped.
5. Verify scheduled/done/skipped/pending counts and completion rate.
6. Return `Training` to pending, reload the page, and verify one logical log per routine/date.
7. Archive `Morning water` and verify it is absent from current Today but its past result remains.

Expected result: actions persist, the summary changes immediately, retries do not create duplicates,
and loading/saved/error states are never represented by an unexplained blank screen.

### Scenario 2: Evening Review (P2)

1. Open Review for today's date.
2. Save mood, energy, stress, and day rating values between 1 and 10 plus reflection text.
3. Reload the same date and verify every saved value.
4. Attempt to submit an out-of-range rating and verify that save is rejected with a field message.
5. Return to Today and verify that the review is shown as complete.

Expected result: exactly one review exists for the date and repeated saves update it.

### Scenario 3: Goal Context (P3)

1. Create an active goal named `Build a consistent morning`.
2. Link `Morning water` to the goal.
3. Verify the relationship in Goals and active context on Today.
4. Unlink it and verify that the Goal and Routine both remain.
5. Link it again, complete or archive the Goal, and verify it no longer appears as active motivation.

Expected result: linking is idempotent, unlinking affects only the relationship, and inactive goals do
not leak into active context.

### Scenario 4: Seven-Day Progress (P4)

1. Use test fixtures or the application to prepare seven calendar days with known scheduled, done,
   skipped, and pending occurrences.
2. Manually calculate today's completion, the routine streak, and the seven-day completion result.
3. Open Today for the end date and compare all displayed values.
4. Repeat with no scheduled routines in the window.

Expected result: calculations match the rules in [data-model.md](data-model.md), and the empty period
shows a deliberate empty state rather than `NaN`, infinity, or a misleading success percentage.

## Ownership and Timezone Checks

The automated API suite MUST additionally prove:

- user A cannot list, update, archive, link, mark, or review user B's records;
- changing `SELFHANDLER_TIMEZONE` changes the definition of the current calendar date at the boundary
  without altering stored UTC timestamps or calendar-date values;
- a missing current-day log does not prematurely break a streak, while a missing completed past-day
  occurrence does;
- weekday evaluation uses the configured timezone and normalized weekday values.

## Completion Evidence

Record the final successful command output or CI links with the feature implementation. A feature is
ready for completion review only when all P1 acceptance scenarios pass, every implemented later-priority
story passes independently, and the API contract and typed client agree.
