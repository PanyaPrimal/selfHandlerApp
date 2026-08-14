# Implementation Plan: Calendar Integration

**Branch**: existing user branch | **Date**: 2026-08-14 | **Spec**: [spec.md](spec.md)

## Summary

Add one owner-scoped Integration/SyncedItem boundary with real Google Calendar OAuth and Apple Calendar CalDAV
adapters. Pull minimal encrypted external busy projections into Planner, push only explicitly selected Time Blocks
and PlannedOccurrence categories, preserve source authority and idempotency, expose manual/scheduled sync and
safe disconnect in a localized `/settings/integrations` workspace, and keep the shared Android client aligned.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12; TypeScript 6 / Vue 3.5

**Primary Dependencies**: Existing Eloquent/UserOwned/Profile/SchedulableSource/Laravel HTTP/cache/scheduler/Vue/
i18n; add audited direct `sabre/vobject` for RFC 5545 parse/generation

**Storage**: Three additive MySQL 8 tables; encrypted Eloquent casts for secrets, cursors, external IDs/names;
cache-backed one-time OAuth state and per-integration locks

**Testing**: PHPUnit/Laravel unit/feature with HTTP/cache fakes and provider fixtures, Pint, Composer validate/audit,
Vitest, vue-tsc, Vite, Playwright desktop/exact-390, i18n guards, Capacitor fingerprint/tests/audit

**Target Platform**: Authenticated web and shared Capacitor Android client; provider calls remain server-side

**Performance Goals**: Initial pull/export bounded to 455 local days; provider pagination applied in pages; one
sync per integration; indexed day overlap; no provider calls during Planner reads; no per-event domain N+1

**Constraints**: local domain facts authoritative; provider-origin read-only; zero export by default; minimal
provider data; encrypted secrets/titles; no live credentials/calls; no deployment/native offline authority

**Scale/Scope**: Two providers, one calendar/provider/user, three tables, settings/connect/callback/select/sync/
disconnect endpoints, one Settings view, one Planner source, one scheduled command

## Localisation Plan

**Message ownership**: Existing TypeScript dictionaries own Settings/Planner/changelog UI; Laravel `messages.php`
owns connection, provider, validation, sync and error feedback. Every key ships in en/ru/uk.

**Runtime locale**: Existing Profile-authoritative request middleware/frontend i18n remain authoritative. Provider
names and user-supplied calendar/event names are not translated.

**Formatting**: Last-success timestamps use locale/timezone formatting; outcome counts use plural-aware messages;
Planner dates/times reuse existing formatters and Profile timezone.

**Backend feedback**: Provider exceptions map to closed safe codes; controllers translate public messages in the
request locale and never return provider bodies, IDs, tokens, cursor, credentials, or stack detail.

**Delivery gates**: dictionary parity/used-key/hardcoded guards, Laravel three-locale message tests, frontend
contracts, EN/RU/UK browser journeys and inspected light/dark desktop/exact-phone screenshots.

## Constitution Check

### Pre-Research Gate: PASS

- Specification precedes production changes and resolves canonical integrations/roadmap requirements.
- Connect/import/export/control are independently testable vertical stories with API/UI/persistence/evidence.
- Core Planner/domain behavior remains deterministic and fully functional without either provider.
- Owner scoping, encryption, minimal projection, zero-export default, source authority, and safe disconnect bound
  secrets and external exposure.
- Provider/API/OpenAPI/TypeScript/Vue and permanent HTTP-contract/browser tests move together.
- Complete EN/RU/UK copy, responsive accessibility, and Android shared-client behavior are explicit gates.

### Post-Design Gate: PASS

Research closes both provider authentication mechanisms, incremental sync, mapping/idempotency, conflict/source
authority, privacy filters, time model, failure/locking, disconnect, UI/native limitations, dependencies, and
deferrals. No constitution deviation remains.

## Architecture Gates

1. **Owner**: Integrations owns connections, mappings, minimal external projections, provider orchestration and
   sync state. TimeBlock/recurrence owner modules retain every local fact and transition.
2. **Inputs**: Profile supplies locale and calendar timezone; no integration copy can override them. Calendar
   display metadata and privacy/export choices are connection settings, not Profile inputs.
3. **Time**: Provider/local timed values normalize to UTC; all-day and source dates remain date-only; overlap and
   export expansion use Profile timezone; cursor/attempt/success/expiry timestamps are UTC.
4. **Scheduling**: Existing RecurringRule/PlannedOccurrence and TimeBlock are read directly. No RRULE, occurrence,
   notification, or schedule copy is introduced; remote recurrence is imported as provider-expanded events.
5. **Cross-module direction**: Owner modules -> normalized export projection -> provider; provider -> encrypted
   ExternalCalendarEvent -> Planner SchedulableSource. Provider input never flows back into owner modules.
6. **Evolution**: Three additive owner-scoped tables, closed aliases/enums, no existing row rewrite. Portability v1
   explicitly excludes provider-bound integration state and retains a catalog drift test.
7. **Contracts**: Provider interfaces/DTOs, migrations/models/resources, REST/OpenAPI, TypeScript client/types,
   Vue settings/Planner, CLI schedule, and error codes change atomically.
8. **Aggregates**: None. Sync result counters are request results, not product aggregates; Analytics/Review do not
   consume external events in this increment.
9. **Privacy**: Secrets/cursors/IDs/titles encrypted, response masking, minimal scopes/data, default busy-only and
   no export, closed allowlist, owner locks, no raw payload/logging/backup, remote-conservative disconnect.
10. **Deferral**: Multiple calendars/accounts, native Google OAuth, webhooks, RRULE export, Storage/training export,
    event editing/details, offline/ICS, other provider kinds and AI stay separate.

## Project Structure

```text
specs/025-calendar-integration/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── analysis.md
├── tasks.md
├── contracts/openapi.yaml
└── checklists/requirements.md

apps/api/
├── app/Contracts/{IntegrationProvider,CalendarProvider}.php
├── app/Data/Calendar/*
├── app/Exceptions/CalendarIntegrationException.php
├── app/Http/{Controllers,Requests,Resources}/Integrations/*
├── app/Models/{Integration,SyncedItem,ExternalCalendarEvent}.php
├── app/Services/Integrations/{Google,Apple,CalendarSyncService,...}/*
├── app/Services/Planner/ExternalCalendarSource.php
├── app/Console/Commands/SyncCalendarIntegrations.php
├── config/integrations.php
├── database/migrations/*calendar_integrations*.php
├── lang/{en,ru,uk}/messages.php
└── tests/{Unit,Feature}/Integrations/*

apps/web/
├── src/api/{client.ts,types.ts}
├── src/views/IntegrationSettingsView.vue
├── src/{router.ts,style.css}
├── src/i18n/locales/{en,ru,uk}.ts
├── src/__tests__/calendar-integration-contracts.test.ts
└── e2e/integrations/{calendar-flow.spec.ts,calendar-visual.spec.ts}
```

**Structure Decision**: Extend the current Laravel API/shared Vue/Capacitor monorepo. Provider transport belongs
to Laravel; Planner receives one read-only source; the shared web view remains the only UI implementation.

## Delivery Phases

1. Freeze spec/research/model/plan/OpenAPI/checklist/tasks, record baseline and GitNexus impacts.
2. Add additive migrations/models plus permanent failing ownership/encryption/contract tests.
3. Add provider DTO/contracts and failing Google OAuth/events and Apple CalDAV/iCalendar HTTP-contract suites.
4. Implement provider adapters, registry, state/credential lifecycle and connection/calendar selection endpoints.
5. Add imported event/mapping persistence, normalization, lock/cursor recovery, bounded pull/push/conflict/delete.
6. Register external Planner source, manual endpoint, scheduled command, disconnect and closed resources/errors.
7. Add OpenAPI/TypeScript client, localized Settings UI, Planner projection, navigation/changelog/styles, Android sync.
8. Run focused/full/manual visual/protected-path/GitNexus gates; finish docs/status/memory/tasks/checklist; commit/push.

## Verification Strategy

- Models/schema: encryption/hidden serialization, owner guards, enum/check/unique/FK/identifier/preservation tests.
- Google: one-time state, auth URL/scope/offline, callback exchange/calendar list, refresh rotation, CRUD, pages, 410.
- Apple: credential masking, discovery redirects/XML, calendars, ICS time/all-day/escaping, ETag CRUD, sync/fallback.
- Sync: default zero export, category allowlist, rolling window, mapping idempotency, replay after failure, conflict
  authority, provider/local delete direction, cursor transaction, locks, error status, scheduled eligibility.
- Planner/API: day overlap/timezone/privacy title modes/read-only actions, auth/ownership, closed response/errors,
  OpenAPI route/ref closure and matching TypeScript consumers.
- Web/E2E: provider paths/failures, calendar selection/settings warnings, sync counts/retry/disconnect, reload,
  keyboard/ARIA/44px/no overflow, all locales/schemes/viewports, Android bundle parity.
- Full regressions: Laravel/Pint/Composer, i18n/Vitest/type/build/audit, desktop/mobile Playwright, Capacitor checks.

## Complexity Tracking

No constitution violations. Two provider adapters are required current consumers of the documented shared layer;
the broader non-calendar provider factory, multi-calendar model, and manual conflict UI remain deliberately absent.
