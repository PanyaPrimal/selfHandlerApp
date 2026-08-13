# Architecture

> Detailed product and domain design lives in [design/](design/) — module specs, ER diagrams, and cross-cutting mechanisms. Start at [design/README.md](design/README.md).

## Goal

Build SelfHandler as a monorepo with separated delivery units and a shared local development setup.

## Applications

### `apps/api`

Laravel API for:

- auth
- routines
- goals
- tasks
- ideas
- daily reviews
- analytics

### `apps/web`

Vue 3 SPA for desktop and mobile web usage.

### `apps/mobile`

Capacitor 8 wrapper around the production web client for Android. It packages `apps/web/dist` and has
no `server.url`, remote HTML, or live-update path. The only platform-specific source is configuration,
resources, lifecycle integration, and a credential-vault plugin; product routes remain in Vue.

The bundled WebView cannot reuse the browser's same-origin cookie session. Android therefore exchanges
existing-account credentials for one 30-day Sanctum token with exactly the `mobile` ability, retrieves
that token from Android Keystore immediately before native HTTP calls, and revokes only that device
token on sign-out. The plaintext token exists only across issue/write and just-in-time read/request
boundaries. Browser Fetch, session cookies, and CSRF remain a separate unchanged transport.

Android Local Notifications is a presentation adapter over feature 011: after explicit permission, it
mirrors already-delivered unread inbox records when the app synchronises or resumes, then records the
`android_local` channel. There is no stopped-app wakeup or FCM in this increment.

## Infrastructure

Local backend development is based on Open Server:

- Open Server as the Windows local environment manager
- PHP 8.4 for Laravel
- MySQL 8 for the primary database
- Redis as an optional local cache/queue backend
- Vue web app running separately through Vite during frontend development

Open Server is the primary local backend runtime because the project is also a learning path for PHP and Laravel on Windows.

## Delivery Model

- API and web stay decoupled through REST.
- Mobile reuses the web client instead of becoming a separate frontend codebase.
- Local development is optimized for Open Server first.
- Docker and homelab deployment may be added later, but they are not the current default.

## Suggested Near-Term Scope

1. Bootstrap API and web.
2. Define first domain slice: routines + daily review.
3. Deliver explicit multi-user registration/authentication with user-owned boundaries through
   [`003-multi-user-auth`](../specs/003-multi-user-auth/spec.md); keep roles and collaboration outside
   the first account slice.
