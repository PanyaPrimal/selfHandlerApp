# Live Provider Acceptance: Calendar Integration

> **Status:** not yet performed. Feature 025 is complete (`87/87`) except this one external item.
> Its absence does not authorize fabricated success or committed secrets.

Feature 025 was verified entirely against deterministic Google/Apple fixtures — no automated test or browser
journey ever reaches a provider host. This runbook is the remaining evidence: one real account, one real
calendar, one real sync.

**Either provider closes the caveat.** Apple is much cheaper to run because it needs no configuration at all.

## Path A — Apple / iCloud CalDAV (recommended, zero configuration)

`AppleCalendarProvider::configured()` only checks the hardcoded `caldav.icloud.com` discovery URL, so the
provider is always available. The account and app-specific password are entered in the
`/settings/integrations` UI and stored encrypted. **No repository or environment change is required.**

### What the operator must supply

- An iCloud account with at least one calendar.
- An **app-specific password** generated at <https://account.apple.com> → Sign-In and Security →
  App-Specific Passwords. Never the account password.

### Journey

1. `/settings/integrations` → Apple → enter the account email and app-specific password.
   - Record: discovery succeeds and the real calendar list comes back. Note how many calendars, no names
     needed beyond one masked example.
2. Enter a deliberately wrong app-specific password on a fresh attempt.
   - Record: rejected with a safe error, and the pending integration row is cleaned up (the controller
     deletes it on failure).
3. Select exactly one calendar.
4. Run a manual sync.
   - Record: imported event count, elapsed time, and that the entries appear in Planner **read-only**.
5. Confirm the import defaults: **busy-only**, titles hidden until explicitly opted in.
   Then enable titles and re-sync.
   - Record: masked before, real summary after.
6. Change something in iCloud (add, edit and delete one event), re-sync.
   - Record: incremental behaviour — the added event appears, the edit is reflected, the deleted event is
     pruned. Provider-origin events follow provider state.
7. Confirm the export allowlist is empty by default, then opt one category in and verify a SelfHandler-origin
   event reaches iCloud; delete it locally and confirm the projection is restored/removed correctly.
8. Disconnect.
   - Record: only local credentials, cache and mappings are removed; the iCloud calendar itself is untouched.

## Path B — Google Calendar OAuth

### What the operator must supply

A Google Cloud project with the Calendar API enabled and an **OAuth 2.0 Client ID (Web application)**:
client id, client secret, and an authorized redirect URI. The scopes the app requests are
`calendar.events` and `calendar.calendarlist.readonly`. While the consent screen is in Testing mode the
operator's own account must be added as a test user.

### Where to put the credentials

`apps/api/config/integrations.php` reads `GOOGLE_CALENDAR_CLIENT_ID`, `GOOGLE_CALENDAR_CLIENT_SECRET` and
optional `GOOGLE_CALENDAR_REDIRECT_URI` (default: `{APP_URL}/api/integrations/calendars/google/callback`).

**Recommended host — local dev stack.** Put the values in `apps/api/.env`, which is gitignored at both the
repository root and `apps/api/.gitignore`. Register the redirect URI as
`http://localhost:8000/api/integrations/calendars/google/callback` — Google accepts `http://localhost`
redirect URIs for web clients. This touches **no tracked file**.

```
GOOGLE_CALENDAR_CLIENT_ID=...
GOOGLE_CALENDAR_CLIENT_SECRET=...
GOOGLE_CALENDAR_REDIRECT_URI=http://localhost:8000/api/integrations/calendars/google/callback
```

Run: `php artisan serve` (API on 8000) and `npm --prefix apps/web run dev` (Vite proxies `/api` to it).

**Production host.** Put the secret values only in the ACL-protected
`C:\Homelab\SelfHandlerApp\.env`; the production Compose file passes them to the app container. Register
the redirect URI as
`https://selfhandler.drpanya.uk/api/integrations/calendars/google/callback`. Leave the client ID and secret
empty when Google Calendar is intentionally disabled.

### Journey

1. `/settings/integrations` → Google now shows as available rather than `calendar_provider_unavailable`.
2. Start authorization, complete the Google consent screen, land back on the callback.
   - Record: the OAuth state is single-use and the integration reaches the calendar-selection step.
3. Repeat steps 3–8 of Path A (select one calendar, manual sync, busy-only default, title opt-in,
   external change and re-sync, export allowlist, disconnect).
4. Additionally exercise the token refresh: the offline grant should refresh without re-consent.
   - Record: a sync succeeding after the initial access token has expired, or an explicit note that this
     could not be observed within the session.
5. Additionally exercise the scheduled sync: the 15-minute scheduler entry should pick the integration up
   without a manual trigger.

## Evidence template

Fill this in during the run, then copy the summary into `tasks.md` and `quickstart.md` §7.

```
Date:
Operator:
Path: (Apple | Google | both)
Host: (local dev | production | local 18080)
Account (masked):              Calendars discovered: ___
Bad-credential attempt rejected and cleaned up: yes/no
Selected calendar: 1           First sync: ___ events in ___ s
Planner entries read-only: yes/no
Busy-only default correct: yes/no    Titles after opt-in correct: yes/no
External add/edit/delete reflected on re-sync: yes/no/yes
Export allowlist empty by default: yes/no    Opted-in export reached provider: yes/no
Google only — token refresh observed: yes/no/n-a
Google only — scheduled sync fired: yes/no/n-a
Disconnect removed only local data: yes/no
Defects found:
```

## Secret hygiene

- Never paste a client secret, app-specific password, OAuth code, or access/refresh token into a tracked
  file, screenshot, commit message, or this document.
- Mask the account as `f***@icloud.com` / `f***@gmail.com`.
- Do not paste real calendar event summaries into evidence; counts and one neutral test event are enough.
- Before committing evidence:
  ```
  git status --short
  git grep -n -I -E 'GOCSPX-|client_secret|refresh_token'
  ```
- Revoke the app-specific password (Apple) or delete the OAuth client / revoke app access
  (<https://myaccount.google.com/permissions>) after the run if it was created only for this test.
