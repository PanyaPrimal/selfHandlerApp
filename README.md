# selfHandlerApp

Monorepo for the SelfHandler project.

## Purpose

SelfHandler is a personal system for managing routines, health, goals, tasks, ideas, and reviews in one place.

## Stack

- Backend: Laravel 12
- Database: MySQL 8
- Current cache, sessions, and queues: database-backed; Redis is deferred until a feature needs it
- Web: Vue 3 + Vite
- Mobile: Capacitor
- Local backend runtime: Open Server

The current web application supports complete English, Russian, and Ukrainian interfaces. Language
and appearance are account preferences, while small validated browser caches apply them before Vue
mounts to avoid a wrong-language or wrong-theme first frame. New UI copy must pass the repository
localisation gate with `npm --prefix apps/web run check:i18n`.

Authenticated users also have one in-app notification inbox for timed routine occurrences, important
dated Storage tasks, and a daily digest. Per-user quiet hours, category settings, snooze, retry-safe
processing, and routine escalation are owned by feature 011; external delivery channels remain deferred.

The Android package in `apps/mobile` wraps the same production Vue bundle with Capacitor 8. Existing
accounts use a separate 30-day, `mobile`-scoped Sanctum token stored by a custom Android Keystore
AES-GCM plugin; browser cookie/CSRF auth is unchanged. Android local notifications are an opt-in
presentation of unread inbox events after synchronisation, not background push delivery.

## Monorepo Layout

- `apps/api` - Laravel API
- `apps/web` - Vue web client
- `apps/mobile` - Capacitor shell and mobile-specific setup
- `docs` - project docs and decisions

## First Milestones

1. Bootstrap monorepo structure.
2. Create Laravel API app.
3. Create Vue web app.
4. Attach Capacitor to the web client.
5. Configure Open Server workflow for local backend development.

## Spec-Driven Workflow

SelfHandler uses GitHub Spec Kit for feature delivery. Long-term product and domain design remains in
[`docs/design/`](docs/design/README.md); each implementation increment lives under `specs/` with its
own specification, plan, contracts, and dependency-ordered task list.

The project is initialized for Codex skills and PowerShell. Start a new feature with
`$speckit-specify`, then use `$speckit-plan`, `$speckit-tasks`, and `$speckit-analyze` before
implementation. Project governance is defined in
[`.specify/memory/constitution.md`](.specify/memory/constitution.md).

Spec Kit's Git extension is intentionally not installed. Work stays on the branch already selected by
the user; project automation must not create or switch branches.

## Homelab Deployment

Feature [`002-homelab-deployment`](specs/002-homelab-deployment/spec.md) defines the fixed private
production target. SelfHandler runs beside DealFlow as the isolated Docker Compose project
`selfhandler`: Nginx/Vue on loopback port 18080, internal PHP-FPM, internal MySQL 8.4, and separate
database/private-file volumes. Private HTTPS is provided by tailnet-only Tailscale Serve on port 8443;
the existing DealFlow Funnel on 443 is not shared or reset.

Public-repository code is qualified only on GitHub-hosted runners. The homelab runner belongs to a
separate private operations repository and accepts only the exact reviewed image digests plus its
checksum-verified deployment bundle. Runtime and recovery details are documented in
[`deployment/README.md`](deployment/README.md).

Local deployment validation commands:

```powershell
npm run validate:deployment
```

## Android Build

Node-only configuration, build, sync, and static native-source validation are available now:

```powershell
$env:SELFHANDLER_MOBILE_API_ORIGIN = 'https://selfhandler.example.test'
npm --prefix apps/mobile ci
npm run sync:android
```

Native compilation additionally requires Android Studio 2025.2.1+, JDK, Android SDK API 36, and
`adb`. See [`apps/mobile/README.md`](apps/mobile/README.md) for signing, sideloading, security, and the
manual device journey. Deployment is a separate concern and is not changed by the Android package.
