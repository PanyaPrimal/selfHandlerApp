# Implementation Plan: Multi-User Authentication

**Feature**: `003-multi-user-auth` | **Date**: 2026-08-09 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/003-multi-user-auth/spec.md`

## Summary

Replace the implicit development user with explicit multi-user registration and authentication.
Laravel will use stateful, cookie-based SPA sessions with request-forgery protection; Vue will restore
the session before resolving protected routes and will never persist a password or bearer token.
Every existing domain route remains server-scoped to the authenticated account, with a two-account
test matrix proving that routines, goals, relationships, logs, reviews, and Today aggregates cannot
cross the ownership boundary.

## Technical Context

**Language/Version**: PHP `^8.4` (Laravel runtime currently 12.65) and TypeScript `~6.0`

**Primary Dependencies**: Laravel `^12.61.1`, Laravel Sanctum 4.x stateful SPA middleware, Eloquent,
Vue `^3.5`, Vue Router `^5.1`, Vite `^8`

**Storage**: Existing MySQL 8 `users`, `sessions`, and user-owned domain tables; isolated SQLite for
backend and browser tests

**Testing**: PHPUnit 11 feature tests, `vue-tsc`, Vite production build, Playwright 1.61 on desktop and
phone viewports

**Target Platform**: Responsive, online first-party web application served as one HTTPS site in
production; Windows/Open Server plus a Vite proxy is the primary local path

**Project Type**: Monorepo web application with Laravel REST API, Vue SPA, and a future Capacitor shell

**Performance Goals**: Session restoration and protected-route resolution complete within two seconds
under normal homelab conditions; form submission receives a visible result within two seconds under
normal local conditions

**Constraints**: HttpOnly session cookie; CSRF protection for every state-changing browser request;
database-backed production sessions; no browser-stored bearer credentials; no email delivery, roles,
invitations, recovery, or public-internet admission in this increment

**Scale/Scope**: One private homelab installation, a small number of independent accounts, four auth
operations, three auth/account screens, four protected product screens, and all current API routes

## Constitution Check

*GATE: Passed before research and re-checked after Phase 1 design.*

- **Specifications Before Implementation**: PASS. [spec.md](spec.md) defines prioritized registration,
  session, ownership, and error-state journeys before application changes.
- **Vision and Delivery Sources**: PASS with required alignment work. The feature resolves the
  authentication choice deferred in `docs/MVP_TECHNICAL_DESIGN.md`; implementation tasks update that
  file and `docs/design/decisions.md` so delivered multi-user behavior is no longer listed as deferred.
- **Thin Vertical Slices**: PASS. The feature adds only first-party email/password accounts and the
  UI/API/session behavior needed to use them. Roles, verification, recovery, tokens, sharing, and
  external identity providers stay out of scope.
- **Deterministic Core**: PASS. Authentication, validation, rate limits, and ownership checks are
  deterministic and have no LLM or external service dependency.
- **User-Owned Data and Privacy**: PASS. The authenticated account is the only ownership source;
  foreign identifiers behave as unavailable; passwords and session credentials are never returned or
  persisted by the client.
- **Contracts and Tests**: PASS. The design pairs an OpenAPI auth contract, backend session and
  ownership feature tests, typed Vue consumers, build/type checks, and desktop/mobile browser flows.
- **Branch Governance**: PASS. Work remains on the branch already checked out by the user; no Git
  branch or Spec Kit Git extension is created.

### Post-Design Re-check

PASS. Phase 1 introduces one established session-auth dependency and one small Vue session module,
each with an immediate current consumer. No speculative account-management platform, frontend store,
token API, mail system, or extra service is added. Existing `users` and `sessions` storage is reused.

## Project Structure

### Documentation (this feature)

```text
specs/003-multi-user-auth/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── openapi.yaml
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
apps/api/
├── app/
│   ├── Http/
│   │   ├── Controllers/AuthController.php
│   │   └── Requests/Auth/
│   │       ├── LoginRequest.php
│   │       └── RegisterRequest.php
│   ├── Http/Resources/UserResource.php
│   ├── Models/User.php
│   ├── Providers/AppServiceProvider.php
│   └── Support/CurrentUser.php (removed)
├── bootstrap/app.php
├── config/session.php
├── config/sanctum.php
├── routes/api.php
├── routes/web.php
└── tests/Feature/Auth/
    ├── AuthTestCase.php
    ├── AuthenticationTest.php
    ├── AuthenticationSecurityTest.php
    ├── OwnershipBoundaryTest.php
    └── RegistrationTest.php

apps/web/
├── src/
│   ├── api/
│   │   ├── auth.ts
│   │   ├── client.ts
│   │   ├── http.ts
│   │   └── types.ts
│   ├── auth/
│   │   ├── redirect.ts
│   │   └── session.ts
│   ├── layouts/AppShell.vue
│   ├── views/
│   │   ├── AccountView.vue
│   │   ├── LoginView.vue
│   │   └── RegisterView.vue
│   ├── App.vue
│   └── router.ts
├── e2e/
│   ├── support/auth.ts
│   ├── auth-flow.spec.ts
│   └── mvp-flow.spec.ts
├── playwright.config.ts
└── vite.config.ts

docs/
├── MVP_TECHNICAL_DESIGN.md
└── design/decisions.md
```

**Structure Decision**: Keep the existing Laravel and Vue delivery units. Add one focused backend
controller, two input request objects, a small dependency-free Vue session singleton, and three route
views. Split the shared HTTP transport from domain/auth functions so CSRF and session-expiry behavior
has one implementation. Do not add Pinia, a frontend unit-test stack, a repository layer, an auth
starter-kit UI, or a separate identity service.

## Implementation Strategy

### Foundation

1. Add Sanctum only for its stateful first-party SPA session and CSRF flow; do not expose personal
   access tokens or store bearer tokens in the browser. Define auth endpoints in `routes/web.php`
   under `/api/auth` so session startup and CSRF remain mandatory even when a request lacks trusted
   SPA origin headers; protect domain API routes with `auth:sanctum`.
2. Configure trusted local and production first-party origins, stateful API middleware, secure session
   defaults, named auth rate limits, and a single password rule of at least 12 characters.
3. Add the auth contract and test helpers before controllers. Reuse the existing `users` and
   `sessions` tables; no account-domain migration is required.
4. Remove `CurrentUser` and its local/testing fallback, read the authenticated user directly after
   middleware, and place every current domain route behind explicit session authentication.

### User Story Delivery

1. **P1 Registration**: normalize email before validation/storage, rely on the unique database key as
   the concurrency backstop, hash the password through the model, rotate the session, return a safe
   account resource, expose current-account restoration, reject register/login while already
   authenticated, and deliver the accessible registration view.
2. **P2 Session lifecycle**: implement generic-error sign-in, current-account bootstrap, current
   session logout/invalidation, central CSRF transport, router guards, protected shell, and mobile-
   reachable Account/logout view.
3. **P3 Ownership**: retain explicit owner filters and relationship checks, ensure route model access
   returns `404` for foreign records, ignore client ownership fields, and execute the complete
   two-account API/browser matrix.
4. **P4 Recovery states**: map validation, invalid credentials, throttling, expired sessions, CSRF
   refresh, and unavailable-service failures to deterministic UI states without retaining passwords.

### Contract Evolution

Add `POST /api/auth/register`, `POST /api/auth/login`, `GET /api/auth/user`, and
`POST /api/auth/logout`, plus Sanctum's CSRF-cookie endpoint. The account resource exposes only `id`,
`name`, and normalized `email`. Every existing domain operation requires the stateful session cookie;
unsafe requests also carry the CSRF header. A missing session is `401`, validation is `422`, throttling
is `429`, an already-authenticated public auth submission is `409`, and a foreign owned identifier is
`404`. See [contracts/openapi.yaml](contracts/openapi.yaml).

## Complexity Tracking

No constitution violations or complexity exceptions are required.

## Implementation Result

Feature `003-multi-user-auth` was implemented and verified on 2026-08-09 with no accepted
functional or security-contract deviations.

The delivered source tree has two small structural differences from the planning sketch:

- Backend authentication coverage is split into `RegistrationTest.php` and
  `AuthenticationTest.php`, with shared helpers in `AuthTestCase.php`, instead of one
  `AuthenticationApiTest.php` file. This keeps the registration and session-lifecycle stories
  independently readable without changing coverage.
- The validated internal redirect helper lives in `apps/web/src/auth/redirect.ts` because it has
  three consumers and keeping it outside the router avoids a router-to-view import cycle.

The final security review also made API JSON rendering explicit for every `/api/*` request, even
without an `Accept` header, and made `selfhandler_session` the runtime configuration default. The
registration race test deterministically exercises the endpoint's unique-constraint recovery path.

Completion evidence:

- Laravel: 29 tests, 281 assertions passed.
- Frontend typecheck and production build passed; the production build transformed 43 modules.
- Playwright: 10 of 10 desktop/mobile journeys passed against an isolated SQLite database.
- Changed backend paths passed Pint; Composer validation and repository whitespace checks passed.
- The normal development database was not used by browser tests.

Feature `002-homelab-deployment` may now treat the application-authentication dependency as
satisfied. Its live-rollout gate still has to prove the private HTTPS hostname, secure HttpOnly
session cookie, exact Sanctum stateful domain, and absence of production seeding before deployment.
