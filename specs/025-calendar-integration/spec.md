# Feature Specification: Calendar Integration

**Feature Branch**: existing user branch

**Created**: 2026-08-14

**Status**: Approved

**Input**: Deliver roadmap feature 025 as a thin, independently useful calendar integration without
deployment changes, duplicated schedules, or invented provider success.

## User Scenarios & Testing

### User Story 1 - Connect a private calendar (Priority: P1)

As an authenticated user, I can connect either Google Calendar through OAuth or Apple Calendar through
an app-specific password, choose one writable calendar, and see a masked connection status without any
provider secret being returned to the client.

**Why this priority**: No synchronization is possible until consent, provider identity, calendar choice,
and credential ownership are explicit and safe.

**Independent Test**: Connect each provider against a contract fake, select a calendar, reload Settings,
and verify only the current owner's masked metadata and capabilities are visible.

**Acceptance Scenarios**:

1. **Given** Google provider configuration is available, **when** a signed-in user completes OAuth with
   calendar scope and selects a calendar, **then** one active owner-scoped connection is shown and access/
   refresh tokens never appear in API, logs, browser storage, or source control.
2. **Given** an Apple Account with an app-specific password, **when** the user submits credentials and
   selects a discovered CalDAV calendar, **then** the secret is encrypted at rest and later responses show
   only masked account metadata.
3. **Given** provider configuration is absent, consent is denied, a nonce is stale/replayed, or credentials
   are rejected, **when** connection is attempted, **then** the user receives localized actionable feedback
   and no active integration or plaintext secret remains.
4. **Given** two SelfHandler users, **when** either requests, changes, syncs, or disconnects the other's
   integration, **then** the resource is not disclosed and no provider request is made.

---

### User Story 2 - See external busy time in Planner (Priority: P1)

As a connected user, I can synchronize the selected external calendar and see its events in Planner as
read-only busy slots, without turning meetings into SelfHandler tasks, routines, or domain facts.

**Why this priority**: Seeing the complete day is the immediately useful inbound calendar outcome and does
not require surrendering local domain ownership.

**Independent Test**: Pull created, updated, all-day, timed, and deleted provider events through each adapter,
then read several Profile-local Planner dates and verify stable, deduplicated, read-only entries.

**Acceptance Scenarios**:

1. **Given** a connected calendar, **when** initial sync completes, **then** external events within the bounded
   window appear once on the correct local calendar dates, including cross-midnight and all-day events.
2. **Given** privacy mode is `busy_only`, **when** Planner renders an imported event, **then** it shows localized
   “Busy” rather than the provider title or description; switching to `title` reveals only the event title.
3. **Given** a provider event is changed or deleted, **when** incremental sync runs, **then** its encrypted local
   projection is updated or removed and no unrelated integration/event changes.
4. **Given** a provider is unavailable, rate-limited, revoked, or returns an expired sync token, **when** sync
   runs, **then** retained Planner data remains consistent, retry state is explicit, and an invalid cursor causes
   one bounded full refresh rather than duplication or data loss.

---

### User Story 3 - Export selected local plans safely (Priority: P2)

As a connected user, I can explicitly choose which local Planner categories are published to my selected
calendar and synchronize them idempotently while SelfHandler remains authoritative for those facts.

**Why this priority**: Outbound visibility is valuable, but privacy and ownership require opt-in filters and a
stable mapping before any domain event leaves the application.

**Independent Test**: Enable Time Blocks and one occurrence category, run repeated syncs, edit/reschedule/
complete/delete local sources, and verify create/update/delete behavior without duplicates or inbound writes.

**Acceptance Scenarios**:

1. **Given** a newly connected integration, **when** no export category is selected, **then** sync pulls external
   busy time but publishes no SelfHandler title, schedule, health, supplement, or finance data.
2. **Given** selected categories, **when** sync runs, **then** only eligible local items in the bounded window are
   created or updated externally and each has one durable mapping.
3. **Given** an exported local item was edited both locally and externally, **when** sync runs, **then** the local
   owner fact wins, the external copy is reconciled, and the result reports a conflict without changing local data.
4. **Given** a local item is no longer eligible or is deleted, **when** sync runs, **then** only the mapped provider
   event created by SelfHandler is removed; imported or unrelated provider events are never deleted.
5. **Given** a finance or supplement category, **when** the user enables it, **then** the UI names the privacy risk
   before saving and the category remains an independent opt-in.

---

### User Story 4 - Control and recover synchronization (Priority: P2)

As a connected user, I can run sync on demand, understand its outcome, let safe polling continue later, change
privacy filters, and disconnect without losing local domain data.

**Why this priority**: External systems fail; the feature is trustworthy only when failure, retry, and removal
are explicit and local functionality continues.

**Independent Test**: Exercise success, partial provider failure, concurrent sync, revoked credentials, settings
changes, and disconnect across desktop and exact-phone UI.

**Acceptance Scenarios**:

1. **Given** an active integration, **when** the user chooses Sync now, **then** exactly one serialized sync runs
   and the UI announces imported/exported/updated/deleted/conflict counts and last successful time.
2. **Given** two workers or requests target the same integration, **when** both try to sync, **then** only one owns
   the lock and the other exits safely without duplicate writes.
3. **Given** credentials are revoked, **when** refresh or sync fails with an authorization response, **then** status
   becomes `expired`, scheduled retries stop, and the rest of SelfHandler remains fully functional.
4. **Given** a disconnect confirmation, **when** the user disconnects, **then** encrypted credentials, mappings,
   and imported projections are removed while local facts and remote calendar events remain untouched.

### Edge Cases

- OAuth state is missing, tampered, expired, replayed, belongs to another owner, or callback has no code.
- Google returns paginated results, a 410 invalid cursor, refresh-token rotation, rate limiting, or cancellation.
- CalDAV discovery redirects, contains multiple calendars, returns malformed XML/iCalendar, changes ETag, or
  revokes an app-specific password.
- Events are all-day, have an exclusive end date, cross midnight, span multiple days, use UTC/offset/TZID, omit
  a title, are cancelled, recur remotely, or lie outside the bounded retention window.
- A local occurrence has no time, is rescheduled, becomes done/skipped, loses its owner record, or shares a
  visible title with another item.
- Provider response succeeds but the database write fails, or provider write succeeds before mapping persistence
  fails; replay must converge using the stable external identity and must not create a duplicate.
- The user changes Profile timezone after import; persisted instants remain UTC and Planner dates re-project.
- A long translated provider/calendar/event name must wrap without horizontal overflow at 390 px.

## Requirements

### Functional Requirements

- **FR-001**: The system MUST provide one reusable Integration/CalendarProvider boundary with real Google Calendar
  OAuth and Apple CalDAV adapters; later fitness/bank providers MUST be able to reuse Integration ownership and
  credential rules without calendar conditionals in Planner.
- **FR-002**: Every integration, mapping, and imported event MUST carry `user_id`; owner scope, relationship
  validation, unique indexes, route binding checks, and deletion semantics MUST prevent cross-owner access.
- **FR-003**: Google access/refresh tokens, Apple app-specific passwords, synchronization cursors, and imported
  event titles MUST be encrypted at rest; no API response, log, exception, backup, or frontend store may expose them.
- **FR-004**: Google authorization MUST use an unpredictable one-time ten-minute state, offline access, exact
  redirect URI, and least calendar scope necessary for two-way event sync; callback replay/tamper MUST fail closed.
- **FR-005**: Apple connection MUST use an app-specific password, bounded CalDAV discovery, TLS verification,
  explicit timeouts, and no persistence if credentials/calendar discovery fail.
- **FR-006**: Users MUST select exactly one provider calendar per integration and see only masked account/provider,
  calendar name, status, settings, capability, last-success, and localized error-code information.
- **FR-007**: A user MAY own at most one connection per calendar provider in this increment; a provider account or
  external calendar identity MAY NOT be globally unique across SelfHandler users.
- **FR-008**: Import MUST store only the minimal external projection needed by Planner: encrypted title, UTC start/
  end instants or local all-day dates, all-day flag, provider status/ETag, and mapping identity; descriptions,
  attendees, conference links, locations, attachments, organizer details, and raw payloads MUST be discarded.
- **FR-009**: Imported events MUST appear through `SchedulableSource` as read-only `external_calendar` Planner
  entries and MUST NOT create or mutate TimeBlock, PlannedOccurrence, task, routine, workout, finance, nutrition,
  supplement, notification, review, or analytics facts.
- **FR-010**: Import detail MUST default to `busy_only`; the owner MAY opt into showing provider titles. The API
  MUST never return the stored title while `busy_only` is effective.
- **FR-011**: Synchronization MUST cover a rolling 90 days before through 365 days after the owner's local today,
  prune projections outside that window, handle paginated/incremental provider cursors, and perform one safe full
  refresh when a provider invalidates the cursor.
- **FR-012**: Outbound synchronization MUST default to zero categories and require explicit independent selection
  from `time_block`, `routine`, `sleep`, `habit`, `workout`, `supplement`, and `finance`; training goals and Storage
  tasks remain excluded because they lack a complete timed-event lifecycle in this slice.
- **FR-013**: Export MUST read existing TimeBlock and PlannedOccurrence/RecurringRule facts, use Profile timezone,
  and create only provider events within the bounded window. It MUST NOT create a second rule, occurrence, Planner
  item, or domain schedule.
- **FR-014**: `SyncedItem` MUST provide one durable local-to-external mapping and stable provider identity for each
  exported item, plus external-to-local identity for imports, so retries and pagination are idempotent.
- **FR-015**: For SelfHandler-origin events, local source state is authoritative: provider changes are detected as
  conflicts and overwritten by current local data; provider-origin events are authoritative and read-only locally.
- **FR-016**: Only mappings marked SelfHandler-origin MAY cause provider updates/deletions. Disconnect MUST remove
  local integration state and secrets but leave both local domain facts and all provider events unchanged.
- **FR-017**: Synchronization MUST be serialized per integration, transactionally apply each pulled page locally,
  converge after retry, record no raw provider payload, and return closed outcome counts plus stable error codes.
- **FR-018**: Users MUST be able to run sync immediately; an idempotent command/scheduled poll MUST process only
  active due integrations and stop processing expired/revoked/disconnected integrations.
- **FR-019**: Provider authentication failures MUST set `expired`; transient failures MUST retain `active`, keep the
  prior successful projection/cursor, expose a localized retryable error, and use bounded HTTP timeouts/retries.
- **FR-020**: Integration settings and manual sync MUST be accessible on desktop and exact 390x844, with owned
  controls, 44 px targets, keyboard navigation, visible focus, ARIA/status semantics, no horizontal overflow, and
  light/dark support. Google OAuth launch MUST identify its browser-only callback limitation in the Android shell.
- **FR-021**: API routes, OpenAPI, TypeScript types/client consumers, backend resources, provider adapters, and UI
  MUST change together and use closed enum/error/count shapes.
- **FR-022**: All new user-visible and accessible copy, provider/error/status/category labels, validation/domain
  feedback, and changelog content MUST ship simultaneously in English, Russian, and Ukrainian.
- **FR-023**: The feature MUST add additive MySQL-safe migrations, preservation and identifier-length checks,
  focused ownership/provider/sync tests, full regressions, inspected locale/theme/viewports, and Android bundle sync.
- **FR-024**: The feature MUST NOT modify deployment, feature 002, workflows, production data, native offline
  authority, notification channels, AI, attachment types, or later fitness/bank integrations.

### Localisation Surface

- **Locales**: English (`en-GB`), Russian (`ru-UA`), and Ukrainian (`uk-UA`).
- **New user text**: Settings navigation, provider cards, connection instructions, account/calendar/status labels,
  privacy modes, export categories and warnings, Sync now/disconnect confirmations, timestamps, outcome summaries,
  loading/empty/error/retry states, Planner source labels, validation/domain feedback, ARIA labels, and changelog.
- **Formatting**: Last-sync instants use locale/timezone-aware date-time formatting; result counts use plural-aware
  messages; calendar dates and times reuse the existing Profile locale/timezone controls.
- **Non-translatable content**: Google Calendar, Apple Calendar, CalDAV, provider-supplied calendar names/event
  titles, masked accounts, OAuth codes/state, external IDs, URLs, and technical error identifiers.
- **Verification**: Dictionary parity, used-key and hardcoded-copy guards; backend message parity; provider result
  contract tests; EN/RU/UK Playwright flows and inspected light/dark desktop/exact-phone screenshots.

### Key Entities

- **Integration**: One owner/provider calendar connection, encrypted credential/cursor material, chosen external
  account/calendar metadata, settings, status, success/error timestamps, and sync lease metadata.
- **SyncedItem**: Owner-scoped idempotency/conflict mapping between one integration and either an imported external
  event or an eligible local TimeBlock/PlannedOccurrence.
- **ExternalCalendarEvent**: Minimal encrypted imported busy projection owned by Integrations and read by Planner;
  it is not a domain event or editable local fact.
- **CalendarDescriptor**: Non-persisted provider result used to choose a calendar by opaque external ID, display
  name, writable/default capability, and timezone.
- **CalendarEventEnvelope**: Non-persisted normalized provider DTO for pull/push without exposing raw payloads.

## Success Criteria

### Measurable Outcomes

- **SC-001**: Google and Apple contract suites prove connect, calendar discovery/selection, token/credential
  encryption/masking, refresh/auth failure, pagination/cursor reset, CRUD, time normalization, and closed errors.
- **SC-002**: Repeating the same import/export sync three times produces exactly one mapping/event per identity and
  zero duplicate local or provider writes after convergence.
- **SC-003**: Ownership matrices prove every integration/event/mapping endpoint and background sync is user-isolated,
  and secrets/raw provider fields are absent from responses/log assertions/backups.
- **SC-004**: A calendar matrix proves timed/all-day/cross-midnight/updated/deleted events appear on correct
  Profile-local Planner days, with titles hidden by default and no local domain mutations.
- **SC-005**: Export matrices prove the default sends zero events, every category is independent, only selected
  bounded sources leave the app, local conflict authority wins, and only SelfHandler-origin mappings delete remote.
- **SC-006**: Manual and scheduled concurrency/failure tests prove one lock, stable cursors, preserved prior data,
  explicit counts/status/errors, and clean recovery after retry.
- **SC-007**: Users complete connect/configure/sync/disconnect journeys on desktop and exact 390x844 with keyboard,
  status announcements, no console/page errors, no horizontal overflow, and no local-data loss.
- **SC-008**: OpenAPI/backend/TypeScript/Vue contracts are closed and matching; Laravel/Pint/Composer, i18n/Vitest/
  type/build/audit, full Playwright, Capacitor checks, migration guards, and visual inspection pass.
- **SC-009**: No deployment, workflow, feature 002, handoff, live-provider request, production-data, AI, fitness,
  bank, or native offline-authority file/action is included in the feature commit.

## Assumptions

- Provider credentials are intentionally absent from the repository and test environment. Real adapters are
  verified with HTTP contract fakes; live consent/sync evidence remains an operator/user acceptance step.
- Google OAuth is available in the browser when configured. Existing Android users can use an already-connected
  integration and Apple credential flow; native Google OAuth deep-link handling requires a later native feature.
- One selected calendar per provider is sufficient for a first useful slice and avoids cross-calendar conflict
  policy. Multiple calendars/accounts are a later independently specified expansion.
- Imported events are online projections, not offline/native authority and not included in analytics/review.

## Dependencies and Explicit Exclusions

- **Depends on**: Profile timezone/locale, RecurringRule/PlannedOccurrence 006, Planner/SchedulableSource 009,
  localization/theme 010, Android shared client 012, and stable occurrence owners through 020.
- **Delegates to**: Profile for timezone/locale; each module for local title/time/status; Planner only for projection;
  providers for external-origin event truth; Laravel encryption/cache/scheduler for secrets/state/polling.
- **Defers**: multiple accounts/calendars per provider, manual conflict UI, native Google OAuth deep links, webhooks,
  remote RRULE publication, Storage/training-goal export, attendees/descriptions/locations, external-event editing,
  offline sync, ICS feeds, push notification integration, Strava/Garmin/Health/banks, and every AI scenario.
- **Excluded**: deployment/live rollout, live provider credentials or calls, feature 002, workflows, handoff assets,
  production data, new notification channels, attachment parsing, and local-domain writes from provider events.
