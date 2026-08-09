# Quickstart Validation: Multi-User Authentication

This guide validates feature `003-multi-user-auth` after implementation. It is verification guidance,
not a replacement for the [specification](spec.md), [data model](data-model.md), or
[HTTP contract](contracts/openapi.yaml).

## Prerequisites

- PHP 8.2 or newer and Composer
- Node.js 22 or newer and npm
- A local Laravel environment supported by the repository
- MySQL 8 for normal local use, or the isolated SQLite database created by browser tests
- The SPA and API exposed as one browser site, directly or through the Vite development proxy

## Install Dependencies

From the repository root:

```powershell
composer install --working-dir apps/api
npm --prefix apps/web ci
npm --prefix apps/web run install:browsers
```

Do not commit `.env` files, databases, cookies, logs, or Playwright output.

## Local Session Configuration

Use database-backed sessions and list the exact Vite origin, including its port, as stateful. Example
local values (adapt the ports to the actual launch command):

```dotenv
APP_URL=http://127.0.0.1:8000
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5173,localhost:5173
```

Production uses its fixed private HTTPS hostname, `SESSION_SECURE_COOKIE=true`, an HttpOnly cookie,
and the production hostname in the stateful-domain list. Never use `APP_ENV=local` to bypass login.

Ensure the configured database is migrated:

```powershell
Set-Location apps/api
php artisan migrate
Set-Location ../..
```

## Automated Quality Gates

Run the backend suite:

```powershell
Set-Location apps/api
php artisan test
Set-Location ../..
```

Validate the typed frontend and production bundle:

```powershell
npm --prefix apps/web run typecheck
npm --prefix apps/web run build
```

Run isolated desktop and mobile browser journeys:

```powershell
npm run test:e2e
```

Expected result: every command exits successfully. Playwright uses its own SQLite database and unique
synthetic accounts; it does not authenticate through or mutate the normal development user/database.

## Manual Product Validation

Start the Laravel API through Open Server or its local server, then launch Vite:

```powershell
npm run dev:web
```

### Scenario 1: Register Account A

1. Open a protected URL such as `/routines` in a clean browser profile.
2. Verify redirect to Sign in without a flash of the protected shell.
3. Open Register and create account A with a mixed-case/spaced email and a 12+ character passphrase.
4. Verify the displayed account email is normalized and the new workspace is empty.
5. Create a routine and goal, mark the routine done for today, and save a daily review.
6. Reload the page and verify account A remains signed in and its data remains.

Expected result: registration takes one form submission, the session survives reload, and no implicit
local account is involved.

### Scenario 2: Sign Out and Return

1. Open Account from desktop and phone-sized navigation and sign out.
2. Attempt to revisit a protected URL and verify it leads to Sign in.
3. Sign in with a wrong password and verify the error does not state whether the email exists.
4. Sign in with account A's correct credentials and verify its routine, goal, log, and review return.
5. While authenticated, submit a direct register or login call and verify it returns `409` without
   creating an account or switching the current identity.
6. Sign out again and retry a protected API request with the old browser session.

Expected result: the invalidated session receives `401` and returns no protected data.

### Scenario 3: Register Account B and Prove Isolation

1. While signed out, register a distinct account B.
2. Verify Today, Routines, Goals, and Review contain no account-A data.
3. Create different records for account B using the same calendar date and, where allowed, the same
   title as account A.
4. Using browser developer tools or an API test, submit account-A routine/goal identifiers while
   authenticated as B for update, log, link, unlink, and archive/delete operations.
5. Verify every foreign identifier returns `404`, no partial relationship is created, and account A's
   records are unchanged.
6. Sign back in as A and verify only A's original data appears.

Expected result: the complete cross-account attempt set causes zero disclosures and zero mutations.

### Scenario 4: Validation, CSRF, and Limits

1. Attempt duplicate registration using different email casing and whitespace.
2. Submit mismatched and short passwords; verify field messages and cleared password inputs.
3. Send an unsafe request without the CSRF header; verify it is rejected and not persisted.
4. Trigger the documented login attempt limit; verify `429` and a retryable message.
5. Stop the API during session restoration; verify an unavailable/retry state rather than a false
   signed-out state, then restart it and retry successfully.

Expected result: no failed state claims success, no password/session value appears in output, and
normal use resumes after the transient condition clears.

## Completion Evidence

Record the successful backend, typecheck, build, and Playwright outputs. The feature is ready for
completion review only when all four user stories pass independently, the two-account endpoint matrix
has zero leaks, the contract matches responses, and the design docs no longer describe multi-user
authentication as deferred.
