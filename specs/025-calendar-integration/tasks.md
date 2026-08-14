# Tasks: Calendar Integration

> **Status:** Complete on 2026-08-14 (`87/87`). Repository-owned implementation, contracts,
> localization, mocked provider journeys, visual review, regression gates, Android synchronization,
> documentation and delivery are complete. Live-provider acceptance remains external because no
> Google OAuth client or Apple app-specific password is available or permitted in tracked files.

## Phase 1 — Specification and architecture

- [x] T001 Read canonical roadmap/integrations/recurrence/Planner/data/privacy/localization boundaries and prior contracts.
- [x] T002 Capture branch/HEAD/remote/dirty baseline and preserve protected/untracked paths.
- [x] T003 Inventory SchedulableSource, occurrence owner aliases, Profile time, API/UI/navigation/scheduler/testing patterns.
- [x] T004 Query GitNexus for Planner/recurrence/provider extension flows and inspect key symbol context/impact.
- [x] T005 Research current Google OAuth/incremental sync and Apple app-password/CalDAV/iCalendar primary contracts.
- [x] T006 Specify provider connection, import, opt-in export, source authority, failures, disconnect, localization and deferrals.
- [x] T007 Complete research decisions, additive data model, implementation plan, all architecture gates and constitution checks.
- [x] T008 Publish closed OpenAPI contracts for list/connect/callback/discovery/selection/settings/sync/disconnect.
- [x] T009 Complete requirements checklist and pre-implementation analysis with no critical/high finding.

## Phase 2 — Permanent RED contracts

- [x] T010 Add failing migration/schema tests for three owner tables, checks/FKs/uniques/indexes/identifier lengths/preservation.
- [x] T011 Add failing model tests for encrypted/hidden fields, default settings, serialization and cross-owner guards.
- [x] T012 Add failing OAuth state tests for entropy, expiry, atomic consume, tamper/replay/owner isolation and safe redirect codes.
- [x] T013 Add failing Google provider tests for auth URL/scope/offline, exchange/refresh rotation, calendars and CRUD.
- [x] T014 Add failing Google sync tests for pagination, incremental cursor, deletions, invalid 410 cursor and safe failures.
- [x] T015 Add failing Apple tests for encrypted credentials, discovery redirects/XML/calendars and rejection cleanup.
- [x] T016 Add failing Apple event tests for calendar-query/sync token/ETag fallback and ICS timed/all-day/escaped CRUD.
- [x] T017 Add failing normalization/window tests for UTC, Profile timezone, all-day exclusive end and cross-midnight spans.
- [x] T018 Add failing pull tests for minimal fields, title encryption/busy masking, update/delete/prune and idempotency.
- [x] T019 Add failing export tests for zero default, category allowlist, TimeBlock/occurrence projections and bounds.
- [x] T020 Add failing mapping tests for stable identity, retry after provider/DB boundary, conflicts and deletion direction.
- [x] T021 Add failing lock/status/cursor transaction/auth/transient/scheduled eligibility and disconnect tests.
- [x] T022 Add failing Planner/API ownership/privacy/closed-shape tests for external busy entries and every endpoint.
- [x] T023 Add failing OpenAPI route/ref/schema/security/enums and Portability catalog-exclusion tests.
- [x] T024 Add failing frontend API/type/state/localization contract tests.
- [x] T025 Add failing Playwright connect/configure/sync/Planner/error/retry/disconnect desktop/mobile journeys.
- [x] T026 Run focused RED suites and record failures caused only by absent feature behavior.

## Phase 3 — Persistence and provider contracts

- [x] T027 Add audited `sabre/vobject` direct dependency and lockfile changes.
- [x] T028 Add additive integrations/external_calendar_events/synced_items migrations with MySQL-safe constraints/indexes.
- [x] T029 Implement Integration model enums/default settings/encrypted casts/hidden serialization/owner guards.
- [x] T030 Implement ExternalCalendarEvent timed/all-day invariants/encrypted summary/owner guard/relations.
- [x] T031 Implement SyncedItem alias/origin invariants/encrypted external identity/owner/reference guards.
- [x] T032 Add normalized CalendarDescriptor/EventEnvelope/Page/SyncResult DTOs with closed validation/fingerprints.
- [x] T033 Define IntegrationProvider/CalendarProvider contracts and provider registry with two current adapters only.
- [x] T034 Add bounded provider config, URLs/scopes/timeouts/window/error codes and test-only network isolation.
- [x] T035 Prove migrations/models/contracts/dependency audits green.

## Phase 4 — Google and Apple connections

- [x] T036 Implement one-time cache-backed Google OAuth state issue/atomic consume service.
- [x] T037 Implement Google authorization URL, exchange, encrypted refresh rotation and masked account lookup.
- [x] T038 Implement Google calendar discovery/selection and event list/get/upsert/delete with pagination/cursor/410.
- [x] T039 Implement Apple credential lifecycle and bounded TLS CalDAV redirect/principal/home/calendar discovery.
- [x] T040 Implement Apple XML multistatus parsing, capability/writable/default/timezone descriptors and safe errors.
- [x] T041 Implement Apple VObject event parse/generate for timed/all-day/status/UID/escaping and minimal fields.
- [x] T042 Implement Apple bounded query/sync-collection or ETag fallback and conditional PUT/DELETE behavior.
- [x] T043 Implement provider exception taxonomy mapping auth/rate/timeout/invalid response to closed public codes.
- [x] T044 Implement list/Google authorize+callback/Apple connect/discovery/selection controllers, requests, resources/routes.
- [x] T045 Prove Google/Apple connection/provider HTTP contract/auth/ownership/error tests green without live requests.

## Phase 5 — Two-way synchronization and Planner

- [x] T046 Implement Profile-local rolling window and normalized day-overlap/time conversion service.
- [x] T047 Implement pull page transaction, minimal imported event upsert/mapping, cancellation/prune and cursor advance.
- [x] T048 Implement invalid-cursor full refresh that replaces only one integration's provider-origin projections.
- [x] T049 Implement TimeBlock export projection, stable keyed identity, fingerprint and provider event upsert.
- [x] T050 Implement PlannedOccurrence category/title/time projection through explicit owner alias readers without N+1.
- [x] T051 Implement zero-default category filters and sensitive finance/supplement independent opt-in.
- [x] T052 Implement self-origin conflict detection/local-authority overwrite, mapping convergence and safe remote delete.
- [x] T053 Implement per-integration cache lock, attempt/success/error state, auth expiry and closed sync result counts.
- [x] T054 Implement manual sync controller/route and idempotent scheduled command for active due integrations.
- [x] T055 Implement confirmed local-only disconnect with cascade cleanup and no provider/local-domain deletion.
- [x] T056 Implement ExternalCalendarSource and register additive `external_calendar` Planner source/privacy metadata.
- [x] T057 Update Portability catalog exclusions/coverage so provider-bound tables/secrets are never schema-v1 backup data.
- [x] T058 Prove sync/window/idempotency/conflict/failure/locking/disconnect/Planner/ownership tests green.

## Phase 6 — Web, localization, contracts, and Android

- [x] T059 Update repository OpenAPI routes/schemas/errors and prove operation/ref/consumer closure.
- [x] T060 Add TypeScript provider/integration/calendar/settings/sync/error/Planner source contracts and API methods.
- [x] T061 Add `/settings/integrations` route plus desktop/mobile navigation without displacing existing Settings/Data.
- [x] T062 Implement provider availability/status cards and Google browser OAuth launch/callback result handling.
- [x] T063 Implement Apple account/app-password form with no persistence/autofill leakage and masked reload state.
- [x] T064 Implement calendar discovery/selection, privacy detail setting and export-category controls/warnings.
- [x] T065 Implement Sync now result counts, last-success/error/retry/loading/status announcements and concurrency state.
- [x] T066 Implement confirmed disconnect copy/action and prove local/remote preservation contract in UI.
- [x] T067 Render read-only external Planner entries with busy-only/title modes and safe multi-day/time metadata.
- [x] T068 Add responsive light/dark integration/Planner styles, 44 px targets, focus, long-name wrapping and no overflow.
- [x] T069 Add every visible/ARIA/validation/error/status/category/changelog key in EN/RU/UK with locale formatters.
- [x] T070 Add 025 changelog entry and Integration Settings navigation/help in all locales.
- [x] T071 Synchronize shared web bundle into Android and show honest browser-only Google connect capability.
- [x] T072 Prove frontend contract/Vitest/i18n/typecheck/build/audit and Android shared-bundle focused checks green.

## Phase 7 — Acceptance, regression, and delivery

- [x] T073 Prove focused Playwright Google/Apple mocked connect/configure/manual sync/Planner/disconnect journeys green.
- [x] T074 Prove provider auth/transient/error/retry/reload/default privacy/zero-export behavior in browser tests.
- [x] T075 Prove keyboard, accessible names/status/focus, 44 px targets, no runtime errors and no 390 px overflow.
- [x] T076 Capture EN/RU/UK light/dark desktop/mobile screenshots and inspect every image visually.
- [x] T077 Run full Laravel suite, Pint, Composer strict validation/audit and MySQL/schema/preservation guards.
- [x] T078 Run full i18n guards, Vitest, TypeScript typecheck, production build and web audit.
- [x] T079 Run full desktop/exact-phone Playwright suites and account for only documented conditional skips.
- [x] T080 Run Capacitor sync, mobile tests, plugin inventory, bundle fingerprint and mobile audit.
- [x] T081 Verify no live provider request/credential, deployment/workflow/feature002/handoff/native-authority action/change.
- [x] T082 Run GitNexus changed/staged impact review and inspect every affected/high-risk route/symbol/flow.
- [x] T083 Update README/integrations/decisions/roadmap/status with delivered 025 boundary and explicit deferrals/blockers.
- [x] T084 Update quickstart/spec/checklist/analysis/tasks only after actual evidence; remove all placeholders.
- [x] T085 Update durable memory with commit, decisions, gates, live-credential caveat and next feature 026.
- [x] T086 Create one atomic feature commit without attribution and push current `master` only.
- [x] T087 Confirm `HEAD == origin/master` and working tree contains only preserved user/generated exclusions.
