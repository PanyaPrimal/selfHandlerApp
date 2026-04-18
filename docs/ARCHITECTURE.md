# Architecture

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

Capacitor wrapper around the web client for Android first, with room for iOS later.

## Infrastructure

Local backend development is based on Open Server:

- Open Server as the Windows local environment manager
- PHP 8.3 for Laravel
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
3. Add auth for single-user flow, but keep model boundaries ready for future multi-user support.
