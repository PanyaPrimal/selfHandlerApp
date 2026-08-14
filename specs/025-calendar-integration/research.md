# Research: Calendar Integration

## Decision 1 — One generic boundary, two first calendar adapters

**Decision**: Add an `IntegrationProvider`/`CalendarProvider` contract, with Google Calendar OAuth and Apple
Calendar CalDAV implementations. Provider-specific HTTP/auth/discovery stays behind adapters; ownership,
settings, mappings, orchestration, status, and UI contracts remain common.

**Rationale**: `docs/design/integrations.md` locks a reusable Integration/SyncedItem layer and names Google and
Apple as the first calendar members. Both are real current consumers, so the abstraction is justified without
building any unused fitness/bank behavior.

**Rejected**: Google-only tables/services (forces later rewrite); generic plugin framework (no second kind yet);
ICS-only export (cannot satisfy two-way synchronization).

## Decision 2 — Google web-server OAuth with one-time server state

**Decision**: Generate 256-bit random state, store only a hash-to-owner/expiry record in cache for ten minutes,
consume it atomically once, request offline access and Calendar event scope, and exchange/refresh tokens only on
the server. OAuth callback redirects to the configured web Settings URL with a closed result code.

**Rationale**: Google documents the web-server authorization-code flow, `state`, exact redirect URIs, and
`access_type=offline` for background refresh. One-time server state avoids embedding user identity or reusable
authorization in the URL.

**Source**: https://developers.google.com/identity/protocols/oauth2/web-server

**Rejected**: tokens in the SPA; user ID in unsigned state; client-side implicit flow; persisted authorization
codes; live provider test without workspace credentials.

## Decision 3 — Google incremental cursor and invalidation recovery

**Decision**: Perform a bounded initial list, consume every page, persist encrypted `nextSyncToken` only after the
complete local apply, and use it for later incremental sync. A 410 response clears imported provider-origin
projections/cursor for that integration and performs exactly one full refresh.

**Rationale**: Google's current synchronization guide requires full then incremental sync, pagination to the last
page, persistence of `nextSyncToken`, inclusion of deletions, and a fresh full sync after HTTP 410.

**Source**: https://developers.google.com/workspace/calendar/api/guides/sync

**Rejected**: `updatedMin` polling (can miss/de-duplicate poorly); cursor advance before commit; unbounded history.

## Decision 4 — Apple CalDAV with app-specific password

**Decision**: Accept Apple Account identifier plus app-specific password, encrypt both secret material and any
cursor, discover principal/calendar home/calendars over HTTPS WebDAV, then use bounded `calendar-query` and
ETag-aware GET/PUT/DELETE. Use sync-collection when the server advertises a token; otherwise use bounded ETag
reconciliation. Parse/generate only VEVENT fields required by the normalized envelope.

**Rationale**: Apple documents app-specific passwords for third-party Calendar access. CalDAV is standardized by
RFC 4791; sync collection by RFC 6578; iCalendar by RFC 5545. Supporting ETag fallback keeps the adapter correct
when a server omits a usable collection sync token.

**Sources**:

- https://support.apple.com/en-gb/102654
- https://www.rfc-editor.org/rfc/rfc4791
- https://www.rfc-editor.org/rfc/rfc6578
- https://www.rfc-editor.org/rfc/rfc5545

**Rejected**: primary Apple password; secrets in browser storage; scraping iCloud web pages; pretending Apple
supports the Google OAuth contract; accepting arbitrary non-TLS endpoints in this Apple adapter.

## Decision 5 — Minimal encrypted imported projection

**Decision**: Persist normalized start/end, all-day dates, status and ETag plus encrypted summary. Discard raw
payload, description, attendees, location, organizer, links, alarms, attachments, and conference data. Default UI
mode returns localized “Busy”; title mode decrypts only the summary for the owner at read time.

**Rationale**: Planner needs busy time and optionally a title, not the meeting dossier. Minimal storage reduces
privacy impact while permitting reloads and provider outages.

**Rejected**: raw JSON/ICS storage; converting external events into TimeBlocks; fetching provider data on every
Planner read; plaintext meeting titles.

## Decision 6 — Source authority instead of generic last-write-wins

**Decision**: Origin decides authority. SelfHandler-origin mappings always render current local state and overwrite
remote divergence, reporting a conflict count. Provider-origin mappings update only the imported projection and
never write local domains. A remote deletion of a SelfHandler-origin event causes recreation while still eligible.

**Rationale**: The roadmap explicitly keeps local domain facts authoritative. This rule is deterministic, does not
depend on incomparable clocks, and prevents an external calendar edit from silently rescheduling health/finance
facts.

**Rejected**: global last-write-wins (clock skew and violates local authority); manual merge UI (too large for the
first slice); two-way mutation of occurrences/time blocks from imports.

## Decision 7 — Explicit export allowlist and stable event identity

**Decision**: Default export list is empty. Users independently opt into Time Blocks and recurrence owner
categories. Export only existing TimeBlock and PlannedOccurrence identities in the rolling window, using a stable
opaque UID/idempotency key derived through a keyed hash rather than exposing database IDs. Store mappings after
provider upsert and use provider-supported idempotent identity to converge if persistence fails.

**Rationale**: Health, supplement, sleep, and finance names can be sensitive or visible on shared calendars.
Explicit opt-in is required by privacy-by-design. Durable mappings and provider identities prevent duplicates.

**Rejected**: export everything; source IDs in external UIDs; RRULE conversion in the first slice; exporting
Storage/training deadlines whose full update/delete/time contract is not yet normalized.

## Decision 8 — Rolling window, UTC instants, Profile-local calendar dates

**Decision**: Sync 90 days behind through 365 days ahead of Profile-local today. Persist timed event instants in
UTC and all-day exclusive date bounds as dates. At Planner read, expand an event over each overlapping local day
using the current Profile timezone. Untimed local occurrences become Profile-local all-day events; timed sources
become explicit zoned instants.

**Rationale**: This bounds provider/data volume while retaining useful recent context and future plans. Separating
instants from all-day dates avoids timezone drift and follows existing data conventions.

**Rejected**: unlimited history; storing local timestamps without timezone; treating an all-day exclusive end as
inclusive; freezing a derived Planner date on timed imports.

## Decision 9 — Per-integration lock and page transactions

**Decision**: Acquire an atomic cache lock for manual/scheduled sync. Pull pages first, applying each page and its
cursor boundary transactionally, then export selected local items. Advance `last_sync_at` only after both phases;
store closed transient/auth error codes, never exception bodies. Provider writes are replay-safe but cannot be part
of a database transaction.

**Rationale**: External calls cannot be rolled back with MySQL. Serialization, idempotent identities, mappings,
and cursor-after-commit provide convergence without claiming impossible distributed atomicity.

**Rejected**: database transaction around network calls; parallel sync for one connection; raw error persistence;
cursor update before local event writes.

## Decision 10 — Disconnect is locally destructive but remotely conservative

**Decision**: Require explicit confirmation, delete the owner-scoped Integration and cascade mappings/imported
projections/credentials, but do not delete any provider events. Local TimeBlocks and occurrences are untouched.

**Rationale**: A disconnect should revoke SelfHandler-held access without surprising destructive writes to a
shared external calendar. Provider-side removal can be an explicit later feature.

**Rejected**: silently delete exported events; retain tokens for reconnect; delete local schedules.

## Decision 11 — UI and Android boundary

**Decision**: Add `/settings/integrations` to the shared Vue client. Browser supports Google OAuth and Apple
credential flow. The synchronized Android bundle supports status/settings/manual sync and Apple connection, and
shows a localized browser-only note for Google connection; native OAuth deep links remain deferred.

**Rationale**: It delivers the shared product without inventing unverified native intent/deep-link behavior. The
core/calendar integration remains usable independently of Android SDK availability.

**Rejected**: fake native OAuth completion; excluding integrations from the Android bundle; separate native UI.

## Decision 12 — Dependencies and testability

**Decision**: Use Laravel HTTP client and XML extensions for provider transport/discovery. Add `sabre/vobject` as
the audited direct dependency for standards-compliant iCalendar parsing/generation. Bind provider transports so
tests use Laravel HTTP fakes and deterministic fixtures; do not make live calls in automated gates.

**Rationale**: Hand-written iCalendar escaping, folding, recurrence/timezone parsing is security- and data-loss-
prone. VObject is narrowly scoped; provider contract fakes prove behavior without credentials.

**Rejected**: full Google SDK (large dependency for a small API surface); full SabreDAV server/client stack;
hand-written ICS parser; live CI credentials.
