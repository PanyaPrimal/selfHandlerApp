# Quickstart Validation: Profile and Settings Foundation

This guide validates feature `004-profile-settings`. It is a runnable verification guide; product
behavior remains defined by [spec.md](spec.md), [data-model.md](data-model.md), and the
[HTTP contract](contracts/openapi.yaml).

## Prerequisites

- PHP 8.4 or newer and Composer
- Node.js 22 or newer and npm
- MySQL 8 for manual migration validation or the isolated SQLite test database
- Existing `003-multi-user-auth` and `001-core-daily-loop` migrations applied
- `APP_TIMEZONE=UTC`
- A valid `SELFHANDLER_TIMEZONE` provisioning fallback, for example `Europe/Kyiv`

## Install Dependencies

From the repository root:

```powershell
composer install --working-dir apps/api
npm --prefix apps/web ci
npm --prefix apps/web run install:browsers
```

Do not commit local environment files, databases, logs, profile screenshots, or Playwright reports.

## Automated Quality Gates

Run backend tests and formatting:

```powershell
Set-Location apps/api
php artisan test
vendor\bin\pint --test
Set-Location ../..
```

Validate the typed client and production build:

```powershell
npm --prefix apps/web run typecheck
npm --prefix apps/web run build
```

Run desktop and exact-390px browser journeys:

```powershell
npm run test:e2e
```

Expected result: every command exits successfully, profile-specific tests pass, and the existing auth
and core-daily-loop suites remain green.

## Migration Preservation Check

Use a disposable database containing at least two users with different routines/logs/goals/reviews.
Record owner ids, row counts, explicit calendar dates, and representative UTC timestamps before the
migration. Apply the new migration, then verify:

1. every existing user has exactly one default profile;
2. the default timezone matches the configured provisioning fallback;
3. all other profile defaults match the contract;
4. domain row counts, owners, explicit calendar dates, and UTC timestamps are unchanged;
5. rerunning migration/status checks creates no duplicate profile.

Do not run a fresh migration against the live homelab as part of this feature validation.

## Manual Product Validation

Start the configured API and web client, register or sign in, then open `/account`.

### Scenario 1: Regional Preferences (P1)

1. Confirm migrated/default timezone, `en-GB`, metric, UAH, neutral, and Mifflin-St Jeor values.
2. Change display name, timezone, locale, units, and base currency and save.
3. Reload and confirm accepted values.
4. Open Today without a date near a controlled timezone boundary and confirm the returned date uses
   the saved profile zone rather than the device zone.
5. Sign in as a second account with a different timezone and confirm independent Today dates/settings.

Expected result: each account retains only its own values and existing historical dates do not move.

### Scenario 2: Anthropometric Baseline (P2)

1. Save birth date, male/female sex, height, weight, and baseline activity with Mifflin-St Jeor.
2. Confirm the profile reports calculation readiness.
3. Switch to imperial display, verify equivalent feet/inches and pounds, save an edited value, then
   switch back and confirm canonical precision.
4. Select Katch-McArdle, clear body fat, and attempt to save.
5. Add a valid body-fat percentage and retry.

Expected result: the invalid save changes nothing; the valid complete state persists and unit toggles
do not drift.

### Scenario 3: Error and Recovery (P3)

1. Submit multiple invalid fields and confirm every field error plus first-error focus.
2. Submit twice while a request is pending and confirm one accepted write.
3. Simulate service unavailability and confirm the unsaved draft remains available.
4. Restore service and retry successfully.
5. Expire the session during an edit and confirm the write is refused without protected data.
6. Repeat the flow at exact 390px width using keyboard-only navigation.

Expected result: saved/unsaved state is truthful, failure never partially applies values, and no
horizontal page overflow occurs.

## Contract and Privacy Checks

Automated tests MUST additionally prove:

- signed-out Profile requests return no profile;
- the fixed current-user route cannot address another user's profile;
- client-supplied owner/account identifiers do not redirect ownership;
- current-user auth responses retain `id`, `name`, and `email` and add only non-sensitive preference
  summary fields;
- full profile reads are the only normal response containing anthropometrics;
- a profile save and account display-name change commit or roll back together;
- Today, scheduling, streaks, logs, and date formatting consistently use the authenticated user's
  profile without an N+1 query pattern.

## Completion Evidence

Record final gate counts in `tasks.md` and update `plan.md` with implementation status and any accepted
contract deviation. The feature is complete only when every task is checked, the OpenAPI and TypeScript
contracts agree, existing live-data semantics are preserved, and no deferred module was pulled in.
