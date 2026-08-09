# Research: Multi-User Authentication

## Stateful First-Party SPA Authentication

**Decision**: Use Laravel's database-backed browser session with Sanctum's stateful SPA and CSRF
middleware. The Vue client obtains the CSRF cookie before unsafe requests, sends cookies only to its
own site, and never creates or stores a bearer token. Define `/api/auth/*` in the web route group so
session startup and CSRF are mandatory even without `Origin`/`Referer`; protect the existing domain
API group with `auth:sanctum` after enabling stateful API middleware.

**Rationale**: SelfHandler's production topology presents Vue and Laravel as one HTTPS site, and the
local Vite server proxies the same paths. Cookie sessions keep the authentication credential HttpOnly,
give Laravel's normal CSRF protection, reuse the existing `sessions` table, and avoid introducing token
issuance/revocation for a consumer that does not need it. Laravel explicitly recommends stateful
cookie authentication rather than API tokens for first-party SPAs:
https://laravel.com/docs/12.x/sanctum#spa-authentication.

**Alternatives considered**: Browser-stored personal access token; custom JWT; HTTP Basic; applying
the complete `web` middleware group directly without Sanctum. Tokens create client-side credential
storage and revocation work, Basic has poor session/logout semantics, and the standard Sanctum flow
already solves first-party origin recognition plus CSRF initialization.

## Account Creation Policy

**Decision**: Allow self-registration to any visitor who already reached the private application.
Every account has equal capabilities, and registration creates an authenticated session immediately.
An already-authenticated browser cannot submit registration or login to create/switch accounts; the
SPA returns to the workspace and the server returns a non-mutating conflict response to a direct call.

**Rationale**: This directly satisfies the request to create multiple users and keeps the homelab's
private network as the outer admission boundary. An invitation or admin system would add identities,
roles, token delivery, and recovery flows without a current operator need.

**Alternatives considered**: First-user-only bootstrap; invite-only registration; administrator-
created accounts; a shared owner password. These either fail the requested self-registration journey
or create a larger account-management feature.

## Email Identity and Duplicate Concurrency

**Decision**: Trim and lowercase email before validation, lookup, and persistence. Keep the existing
unique database index as the authoritative concurrency constraint; return a normal email validation
error when pre-validation or the database detects a duplicate.

**Rationale**: One normalization rule makes sign-in and uniqueness deterministic across MySQL and
SQLite. Application-level uniqueness alone has a race between validation and insert, whereas the
database constraint guarantees at most one account during simultaneous submissions.

**Alternatives considered**: Preserve case and depend on database collation; use email as the primary
key; add a second normalized-email column. The current product needs no case-preserving login identity,
and a second column/migration is unnecessary while canonical email can be stored directly.

## Password Rule and Storage

**Decision**: Require a confirmed password/passphrase of at least 12 characters with no arbitrary
composition rule. Continue using Laravel's model `hashed` cast and configured adaptive hash cost.
Never log, serialize, repopulate, or persist plaintext passwords in the Vue client.

**Rationale**: Length permits memorable passphrases and is testable without rejecting otherwise strong
passwords for missing symbol categories. Laravel's password rule supports one centralized minimum,
and the existing model already prevents plaintext persistence. Validation options are documented at
https://laravel.com/docs/12.x/validation#validating-passwords.

**Alternatives considered**: Mixed-case/symbol rules; a breached-password network lookup; accepting
short passwords. Composition rules reduce passphrase usability, an external lookup would put network
availability in registration's critical path, and a shorter minimum conflicts with the approved spec.

## Session Lifecycle

**Decision**: Rotate the session identifier after registration and successful login. On logout, log
out the web guard, invalidate the current session, and regenerate the CSRF token. Restore identity
with `GET /api/auth/user`; an absent/expired session returns `401` and never a redirect or HTML page.

**Rationale**: Rotation prevents session fixation; invalidation and token regeneration are Laravel's
recommended manual logout sequence: https://laravel.com/docs/12.x/authentication#logging-out. A current-
account endpoint lets the SPA gate routes without exposing session internals.

**Alternatives considered**: Persist account JSON in local storage; clear only the client state on
logout; invalidate every device session. Local storage can become stale or leak identity, client-only
logout leaves the server credential valid, and all-device management is outside scope.

## Authentication Rate Limits and Enumeration

**Decision**: Apply named request limits to login using normalized email plus IP and to registration
using IP. Invalid login always returns one generic credential message. `429` includes a retry window
but does not disclose whether an email exists; a successful login clears the corresponding failure
key where manual failure counting is used.

**Rationale**: Combined identity/network signals slow targeted guessing without letting a single
email value block every user behind one address. Laravel provides atomic counters and remaining-window
information through its rate limiter: https://laravel.com/docs/12.x/rate-limiting.

**Alternatives considered**: IP-only login throttling; account-only throttling; permanent lockout;
CAPTCHA. Each is either too broad, creates an account-existence/denial risk, or adds an external
service not justified for a private homelab.

## Ownership Enforcement

**Decision**: Require authentication for the entire current domain route group, remove the
development-only `CurrentUser` resolver, and keep explicit `user_id` filters/checks at every list,
aggregate, route-model, nested relationship, and write boundary. Controllers read the user established
by middleware and derive all write ownership from it. Foreign identifiers return `404`; list/aggregate
requests simply omit foreign data.

**Rationale**: The tables already contain owner keys and account-scoped uniqueness. Route middleware
prevents anonymous access, while explicit query constraints provide defense at the data boundary and
make the expected behavior visible in tests. A global scope tied to request state could surprise CLI,
migration, and maintenance code.

**Alternatives considered**: Authentication middleware only; global Eloquent scope only; repository
layer. Middleware does not prevent cross-user identifiers, a global scope hides important ownership
behavior in non-HTTP contexts, and a repository layer adds no value for the five existing controllers.

## Vue Session State and Route Protection

**Decision**: Add a small singleton module built from Vue refs with states `checking`,
`authenticated`, `guest`, and `unavailable`. Router guards await one memoized restoration request
before protected views mount. Split shared HTTP/CSRF/error behavior into `api/http.ts`; keep auth calls
and domain calls in separate modules. Do not add Pinia.

**Rationale**: Authentication is currently the only cross-route state. A tiny module prevents duplicate
bootstrap calls and protected-content flashes without introducing a store framework. An unavailable
bootstrap is distinct from a valid guest session so an outage does not masquerade as logout.

**Alternatives considered**: Fetch the user independently in every view; persist auth in local
storage; add Pinia. The first races and duplicates requests, the second is not authoritative, and the
third is unnecessary for one bounded state machine.

## CSRF and HTTP Retry Behavior

**Decision**: The shared fetch wrapper explicitly uses same-site credentials, requests
`/sanctum/csrf-cookie` before unsafe operations, copies the decoded `XSRF-TOKEN` value to
`X-XSRF-TOKEN`, and performs at most one CSRF refresh/retry after `419`. A domain `401` expires the
client session; the expected bootstrap `401` is handled locally as guest.

**Rationale**: Native fetch does not automatically copy the XSRF cookie into Laravel's header. A
single bounded retry recovers from token rotation without risking unbounded duplicate writes. The
Sanctum CSRF sequence is documented at
https://laravel.com/docs/12.x/sanctum#csrf-protection.

**Alternatives considered**: Disable CSRF for API routes; rely on SameSite alone; retry every error.
All weaken state-changing request protection or can duplicate operations.

## Responsive Authentication UI

**Decision**: Add guest-only Login and Register views plus a protected Account view showing safe
identity and logout. Move the existing navigation shell into a protected layout, replace the hardcoded
identity, and include Account/logout in phone navigation. Forms retain only safe name/email values,
clear password fields after failures, and expose labelled field errors, pending state, `aria-live`
feedback, and retryable service/rate-limit messages.

**Rationale**: The current desktop user pill is hidden on narrow screens, so logout must have a
phone-reachable route. A protected layout prevents any domain view from firing before session restore.

**Alternatives considered**: Logout only in the sidebar; modal-only authentication; a full editable
profile page. The first breaks mobile, the second complicates deep links, and profile editing is out of
scope.

## Verification Strategy

**Decision**: Use Laravel feature tests for registration, session rotation/invalidation, CSRF,
throttling, safe serialization, and a complete two-account endpoint matrix. Use Vue type checking and
production build for typed integration, and Playwright desktop/mobile flows for protected redirects,
registration, reload, login, logout, service errors, and account switching. Browser tests use the
database session driver and disposable unique accounts.

**Rationale**: These are the closest useful boundaries required by the constitution. The current
Playwright `array` session driver cannot persist authentication across HTTP requests; the already
migrated SQLite `sessions` table provides isolated persistence.

**Alternatives considered**: Browser tests only; seed one shared local user; add a frontend unit-test
framework. Browser-only failures are harder to locate, a shared user masks isolation defects, and the
small UI state machine does not yet justify another test stack.
