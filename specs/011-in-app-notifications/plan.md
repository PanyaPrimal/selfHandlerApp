# Implementation Plan: In-App Notifications

**Feature ID**: `011-in-app-notifications`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/openapi.yaml](contracts/openapi.yaml) · **Quickstart**: [quickstart.md](quickstart.md)

## Summary

Add one user-owned delivery record and settings home, source synchronisation for timed routine
occurrences and dated Storage tasks, a daily minor-item digest, retry-safe queued processing, quiet
deferral, routine escalation, and a localised in-app inbox with a global unread badge.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12; TypeScript 6 / Vue 3.5.

**Primary Dependencies**: Existing Laravel queue/scheduler, Eloquent, Carbon, Sanctum, Vue Router, and
the repository i18n/control layers. No new package.

**Storage**: MySQL 8 primary; SQLite for automated tests; two additive user-owned tables.

**Testing**: PHPUnit feature/unit/contract tests, Pint, Vue typecheck/Vite build, i18n gate, Playwright
desktop and 390×844 mobile.

**Target Platform**: Authenticated responsive web application and REST API.

**Performance Goals**: One bounded per-user processor; 50-row inbox cap; indexed due/status/source
queries; unread count from one owner-scoped aggregate.

**Constraints**: UTC instants, profile-local calendar math, no deployment edits, no domain-state writes,
no external channel, no untracked handoff changes.

**Scale/Scope**: Existing invite-only multi-user application, two current source families, one channel.

## Localisation Plan

**Message ownership**: Vue `notifications.*` keys own interface copy; Laravel
`lang/{en,ru,uk}/notifications.php` owns delivered title/body; validation remains in complete framework
catalogs. English is the canonical key set.

**Runtime locale**: API requests use profile/Accept-Language middleware. Queued delivery explicitly
maps the recipient's profile locale immediately before rendering; delivered strings are persisted.

**Formatting**: Web renders `sent_at` using `Intl.DateTimeFormat` in active locale and profile time zone.
Badge/digest helpers use existing plural rules. Server calendar/quiet math uses profile time zone and
converts only resulting instants to UTC.

**Backend feedback**: Invalid settings/transitions use existing localised validation/domain catalogs.
Notification delivery catalogs have exact EN/RU/UK key parity.

**Delivery gates**: Existing parity/blank/unknown/unused/hardcoded-copy gate, backend three-locale
delivery tests, typecheck/build, and both Playwright projects.

## Architecture

```text
apps/api/app/
├── Console/Commands/ProcessNotifications.php
├── Contracts/NotificationChannel.php
├── Http/Controllers/NotificationController.php
├── Http/Controllers/NotificationSettingsController.php
├── Http/Requests/ReplaceNotificationSettingsRequest.php
├── Jobs/ProcessUserNotifications.php
├── Models/InAppNotification.php
├── Models/NotificationSettings.php
└── Services/Notifications/
    ├── ChannelRegistry.php
    ├── DailyDigestBuilder.php
    ├── InAppChannel.php
    ├── NotificationDispatcher.php
    ├── NotificationEscalator.php
    ├── NotificationSourceSynchronizer.php
    └── QuietHours.php

apps/web/src/
├── notifications/store.ts
└── views/NotificationsView.vue
```

`ProcessUserNotifications` orchestrates the collaborators but owns no policy. The source synchronizer
uses stable aliases and never changes a source. `ChannelRegistry` has exactly one adapter now, so the
future seam is real without generalising delivery auditing or external credentials early.

## Architecture Gate Answers

1. **Owner**: Notifications owns delivery/settings; recurrence and Storage own domain truth.
2. **Inputs**: Current source rows are read by id/owner; no Planner or domain copies.
3. **Time**: Source/quiet/digest wall times use profile zone; persisted instants use UTC.
4. **Scheduling**: Existing `PlannedOccurrence`; no second recurrence rule. Laravel Scheduler only
   invokes processing.
5. **Cross-module links**: Portable one-way source aliases; source modules do not depend on notifications.
6. **Evolution**: Additive tables/routes/classes; rollback drops only 011 tables.
7. **Contracts**: OpenAPI, backend request/response tests, TS types/client, and browser journeys together.
8. **Aggregates**: Digest counts current eligible sources; no stored domain aggregate.
9. **Privacy**: Session owner on every query/action; user ids absent from client writes.
10. **Deferral**: External channels/audit (trigger: concrete adapter), Android local notifications (012),
    generalized manual/category interval editor (trigger: a user journey), retention/export (023).

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I | Pass | Complete spec/clarifications before application work. |
| II | Pass | Canonical Notifications doc supplies the long-term boundary; this folder is the 011 contract. |
| III | Pass | Two sources and one channel; deferred seams have concrete triggers. |
| IV | Pass | Deterministic policy/state/time math; no AI. |
| V | Pass | Owner FKs/scopes, safe aliases, UTC/profile-zone behavior. |
| VI | Pass | Tests precede implementation and contracts move in one increment. |
| VII | Pass | Full EN/RU/UK backend delivery plus web surface and automated gates. |

**Accepted deviations**: none.

## Phases

| Phase | Content |
|---|---|
| 1 Contract tests | Migration/schema, ownership, API/OpenAPI, state, time, processor, and browser tests fail first. |
| 2 Foundation | Additive schema, models/defaults, config, relationships, channel contract. |
| 3 Sources/jobs | Reconciliation, digest, escalation, quiet math, dispatcher, queued command/schedule. |
| 4 API | Owner-scoped inbox/settings/actions and localised feedback. |
| 5 Interface | Typed client/store, global badge, responsive inbox/settings, three locale catalogs. |
| 6 Closure | Design/changelog/docs, full gates, protected-path audit, atomic commit/push/memory. |

## Risks

| Risk | Mitigation |
|---|---|
| Notification state leaks into source truth | Services expose no source write; tests snapshot domain rows across processing/actions. |
| Retry/worker overlap duplicates delivery | Composite unique identity, per-user unique job, idempotent transitions, retry tests. |
| Cross-midnight/DST quiet math is wrong | Dedicated pure service with same-day, cross-midnight, profile-zone, and DST boundary tests. |
| Digest duplicates direct attention | Mutually exclusive eligibility predicates and count tests with mixed sources. |
| Locale changes before delivery | Render only inside channel delivery from current profile locale. |
| Badge becomes stale | mount/action/focus fetch plus 60-second polling, without coupling delivery to reads. |
| New nav copy/layout regresses mobile | Exact 390×844 tests in all locales and existing complete i18n gate. |
