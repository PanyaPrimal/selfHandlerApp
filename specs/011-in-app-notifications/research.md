# Research: In-App Notifications

**Feature ID**: `011-in-app-notifications` · **Date**: 2026-08-13

## R1 — Scheduling record versus domain fact

The design requires a notification to report a source without duplicating its state.

| Option | Verdict |
|---|---|
| Add reminder/status columns to each module | Rejected: quiet hours, delivery state, snooze, and escalation would be reimplemented by every module. |
| Treat Planner entries as notification records | Rejected: Planner is a read projection and owns neither recurring occurrences nor Storage work. |
| **A user-owned notification with a stable source identity** | **Adopted.** |

`source_type` is a portable alias (`planned_occurrence`, `storage_item`, `daily_digest`), not a PHP
class name. The notification owns delivery state; `PlannedOccurrence.status` and `Item.status` remain
authoritative. Source reconciliation only transitions notification rows.

## R2 — Laravel Notifications and the persistent schedule

Laravel's built-in database channel writes a generic inbox row at send time. This feature must also
hold a future schedule, snooze, quiet deferral, source identity, escalation, and terminal closure.

**Decision**: use a purpose-built `notifications` table represented by `InAppNotification`, plus a
small `NotificationChannel` contract. The `InAppChannel` is the first adapter and marks the durable
record delivered. This keeps the strategy boundary without creating a second generic Laravel row that
would drift from the schedule. Future push/email/Telegram adapters can deliver the same record and add
their successful key to `channels`.

Laravel's `Notifiable` trait remains harmless and available; this increment does not pretend that its
generic database payload is the richer domain entity described by `notifications.md`.

## R3 — Source selection and digest boundary

Three useful alternatives were considered:

- notify for every Planner entry: noisy and would include time blocks the user just created as their
  own visual plan;
- digest everything: misses the implementation-intention value of a timed reminder;
- **direct attention for timed/important sources, aggregate minor sources**: adopted.

A planned occurrence with `occurrence_time` receives a direct reminder at its effective local date and
time. An open high-priority task due today receives a direct reminder at the user's digest time because
Storage has only a calendar date. Untimed planned occurrences and other due open tasks are counted in
one digest. Time blocks do not remind in 011: they have no reminder choice, and silently interpreting
every block as an alert would be surprising.

The digest source id is `YYYYMMDD`, an integer stable inside its owner/type namespace. That lets the
same composite identity protect both model-backed and synthetic notifications without nullable-unique
semantics that differ between MySQL and SQLite.

## R4 — Identity and retries

**Decision**: unique `(user_id, source_type, source_id, escalation_count)`. Initial reminders and
digests use escalation count zero; repeats use one and two. `updateOrCreate`/upsert handles source
schedule changes before delivery. A unique queued job per user prevents simultaneous routine runs, and
the database key is the final guard if workers retry or overlap.

No separate idempotency-key table is needed. No channel delivery audit exists until a second concrete
adapter needs attempt-level history.

## R5 — One processor, explicit collaborators

The scheduler needs one minute precision, but one monolithic service would mix domain discovery, state
reconciliation, policy, quiet-time math, copy, and channel I/O.

**Decision**: `notifications:process` enqueues `ProcessUserNotifications` for every user. The job calls
small synchronous collaborators in this order:

1. `NotificationSourceSynchronizer` creates/updates direct source rows and closes invalid ones.
2. `DailyDigestBuilder` creates today's digest when its local time is reached.
3. `NotificationEscalator` schedules due routine repeats.
4. `NotificationDispatcher` applies enabled categories and quiet hours, resolves channels, and delivers.

Each collaborator is independently testable. The queued job is `ShouldBeUnique` for one user and safe
to retry because writes use the source identity.

## R6 — Quiet-hour calculation

Quiet times are local wall-clock values and may cross midnight; delivered instants are UTC.

- If start is earlier than end, `[start, end)` on one local day is quiet.
- If start is later than end, `[start, 24:00) ∪ [00:00, end)` is quiet.
- Equal endpoints are invalid while enabled, avoiding an ambiguous zero-hour versus 24-hour interval.
- A due instant inside the interval becomes the next local end converted to UTC. DST conversion uses
  the profile time-zone rules rather than adding a fixed offset.

Digest time may fall inside quiet hours. Rejecting that combination would make settings unexpectedly
coupled; quiet delivery simply defers it.

## R7 — Snooze and state transitions

Snooze accepts a bounded duration rather than a client-supplied timestamp. The server calculates UTC,
so a stale or incorrectly zoned browser cannot schedule the wrong instant.

```
scheduled ──deliver──> sent ──read──> read
    ▲                    │  ╲            │
    │                    │   dismiss     dismiss
    │                    ▼      ╲        ▼
    └────due──── snoozed         dismissed

scheduled/snoozed/sent/read ──source done──> actioned
scheduled/snoozed/sent/read ──invalid/skipped/overdue──> cancelled
```

Repeated `read` and `dismiss` calls are harmless. Snooze is allowed only from `sent` or `read` and
clears the current escalation deadline. Re-delivery makes the record unread and starts a fresh interval.

## R8 — Locale ownership

Scheduled copy cannot be generated days early because the user may change profile locale first.

**Decision**: store a notification type plus small JSON content parameters while scheduled. At channel
delivery, temporarily resolve the profile locale, generate `title`/`body` from the backend notification
catalog, and persist the delivered event copy. The API returns those strings. Existing delivered rows
are not rewritten after a later locale change, just as user-authored historical text is not rewritten.

English, Russian, and Ukrainian Laravel catalogs cover delivered content and validation. Vue catalogs
cover the surrounding interface and browser-formatted delivered time.

## R9 — Inbox freshness

WebSockets would add infrastructure for one in-app-only consumer.

**Decision**: the global authenticated store fetches on shell mount, after notification actions, on
window focus, and every 60 seconds while mounted. The delivery system is scheduler/queue driven; polling
only refreshes the visible badge and never creates/delivers notifications.

## R10 — Security and privacy

- Controllers bind a notification then verify `isOwnedBy`, returning 404 for an unowned id.
- Settings are resolved through the authenticated user's one-to-one relationship.
- List/count queries are owner-scoped before every status filter.
- Source aliases and action paths reveal no class name, email, token, or other account identifier.
- Content parameters contain only the user-authored source title and local date needed for the copy.

## Constitution Check

| Principle | Assessment |
|---|---|
| I | Specification and clarification are complete before application changes. |
| II | Implements the canonical notifications boundary and resolves its open questions in feature scope. |
| III | One channel and two real source families; future adapters/audit/general reminders are deferred. |
| IV | All timing, aggregation, state, and plural selection are deterministic; no AI. |
| V | Both tables are user-owned; source and action queries are owner-scoped; UTC/time-zone rules are explicit. |
| VI | Migration, API/OpenAPI, job, source, ownership, state, and browser checks move together. |
| VII | Backend delivery and frontend interface ship together in EN/RU/UK with existing automated gates. |
