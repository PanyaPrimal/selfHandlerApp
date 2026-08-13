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
dated Storage tasks, timed habit occurrences, and a daily digest. Per-user quiet hours, category
settings, snooze, retry-safe processing, and occurrence escalation are owned by feature 011 and its
feature 013 adapter; external delivery channels remain deferred.

Feature 013 provides a first-class Habits surface for recurring yes/no and numeric habits, explicit
abstinence, and stepped day/week reduction ceilings. Check-ins are module-owned facts; streaks,
completion, numeric totals, and limit state are calculated on read. Planner and notifications consume
the shared occurrences without copying habit state.

Feature 014 completes the first Sleep and rich-routine slice. The combined Routines & Sleep workspace
owns recurring sleep plans, actual cross-midnight records, ordered routine activities, and explicit
morning/evening choices. Today, Review, Planner, and notifications consume the same owner-scoped
occurrences and module summaries; alarms, wearable imports, offline writes, and advanced sleep
analytics remain deferred.

Feature 015 adds the Workouts workspace: a shared/private exercise catalogue, recurring strength,
cardio, flexibility, and sport programs, planned and manual facts, deterministic progression and
records, and typed training goals. Workout occurrences and race deadlines appear in Planner; timed
workouts reuse notification quiet hours and delivery; Today and Review present one module-owned day
summary. Canonical facts remain kilograms/metres/seconds, and wearable imports, GPS/GPX, licensed
program content, advanced periodisation, and AI coaching remain deferred.

Feature 016 adds the Nutrition workspace: private foods and solid recipes, exact immutable meal
snapshots, beverage-derived hydration, and correction-safe day/range progress. One transparent daily
target is materialized from Profile, an optional body goal, and explicit planned Workout energy; it
never drifts, while completed-workout refinement remains a separate read-only comparison. Today and
Review display the same Nutrition-owned DTO. Photo recognition, provider catalogues, medical advice,
long-period rollups, and AI assessment remain deferred.

Feature 017 adds a neutral Supplements workspace: private references, bounded multi-slot courses on
shared recurrence, correctable taken/skipped facts, an append-only exact stock ledger, a bounded
run-out forecast, and one active one-off restock proposal. Planner, escalating in-app reminders,
Today, Review, and the EN/RU/UK web/Android clients consume the same module-owned occurrence and
adherence truth. Medical advice, inferred regimens, product recommendations, finance transactions,
and AI assessment remain deferred.

Feature 018 adds the Finance ledger foundation: private multi-currency accounts, two-level income and
expense categories, immutable actuals and paired transfers, append-only reversals and reconciliation,
manual historical exchange rates, and derived exact balances and summaries. Profile remains the only
source of base currency. Budgets, recurring cash flow, debts, funds, purchases, investments,
integrations, and financial AI remain deferred to their owning increments.

Feature 019 adds monthly category budgets, recurring income and expense plans, explicit actual/skip
outcomes, and an exact planned cash-flow projection. Monthly recurrence supports up to ten selected
days and skips dates absent from short months. Planner and in-app notifications consume immutable
Finance occurrence snapshots; budget warnings use separate approaching/exceeded identities. Debts,
funds, financial goals, and purchase/restock links remain owned by feature 020.

Feature 020 completes the first Finance commitment slice: owner-scoped counterparties, debts in both
directions, fixed or flexible principal-only repayment, virtual or linked-account saving funds,
emergency-fund targets/top-ups, and Finance Goals whose progress is derived from those aggregates.
Debt and fund occurrences reuse recurrence, Planner, notifications, immutable ledger transactions,
and append-only correction. Storage purchases become bought only through an active direct expense or
installment debt; Supplement restock expenses retain their source link without changing stock or the
proposal. The responsive EN/RU/UK web client and the synchronized Android bundle expose the same
closed contracts. Interest/amortization, investments, provider rates, imports/exports, integrations,
AI, and native offline authority remain deferred.

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
