# Feature Specification: In-App Notifications

**Feature ID**: `011-in-app-notifications`

**Created**: 2026-08-13

**Status**: Ready for implementation

**Input**: Deliver reliable in-app reminders from existing scheduled facts, with an unread inbox,
quiet hours, snooze, a daily digest, escalation, and automatic closure when the source is no longer
pending.

**Design sources**: [Notifications subsystem](../../docs/design/notifications.md) ·
[Module 5 — Planner](../../docs/design/modules.md#module-5--planner) ·
[Recurrence Engine](../../docs/design/recurrence-engine.md) ·
[Delivery Roadmap — 011](../../docs/design/delivery-roadmap.md#011--in-app-notifications) ·
[Feature 006](../006-unified-recurrence/spec.md) · [Feature 008](../008-storage-inbox/spec.md) ·
[Feature 009](../009-planner-day/spec.md)

## Why This Feature Exists

SelfHandler can already say what belongs on a day, but it cannot bring that plan back to the user's
attention. Adding reminder flags independently to routines, Storage, and each later module would
duplicate delivery state and make quiet hours, snooze, escalation, and deduplication disagree.

This feature introduces that shared delivery boundary with one real channel: the authenticated in-app
inbox. It consumes existing facts without becoming their owner. A reminder can be read, dismissed, or
snoozed; whether the routine or task is actually complete still belongs to its source module.

## Clarifications

### Session 2026-08-13

- Q: Which existing facts create reminders in the first increment?
  A: A timed, planned `PlannedOccurrence` creates a direct routine reminder. An open, high-priority
  Storage task due today creates a direct due reminder. Untimed planned occurrences and other open
  Storage tasks due today are minor items and are counted in the daily digest. Completed, skipped,
  dropped, archived, inactive, deleted, moved, or overdue facts do not produce a live reminder.
- Q: What does a notification own?
  A: Delivery state only: schedule, channel result, unread/read/dismissed/snoozed/actioned/cancelled
  state, escalation count, and the delivered copy. It never writes occurrence or item status.
- Q: How are duplicate jobs prevented?
  A: Every source-backed reminder is unique per user, stable source alias, source id, and escalation
  count. A digest uses its local `YYYYMMDD` as the stable source id. Jobs may run or retry repeatedly
  without producing another row for the same identity.
- Q: What happens during quiet hours?
  A: Delivery is deferred to the first instant outside the user's quiet interval. Quiet hours may
  cross midnight. They do not discard a reminder or mutate its source.
- Q: What does snooze mean?
  A: The user chooses 15 minutes, one hour, four hours, or one day. The existing inbox record becomes
  hidden until the server-calculated UTC instant, then returns as unread. Snooze pauses escalation for
  that record; dismissal stops escalation for that source family.
- Q: What is escalated now?
  A: Direct routine reminders only. The type policy is server configuration: 30-minute intervals and
  at most two repeats in this increment. Direct Storage reminders and the digest do not repeat.
- Q: How does the digest avoid duplicate reminders?
  A: It counts only minor sources: untimed planned occurrences and non-high-priority open Storage tasks
  due on the user's current local day. Sources receiving a direct reminder are excluded.
- Q: When is content localised?
  A: At delivery, from the recipient's then-current profile locale. Delivered copy is an event record
  and is not rewritten later. User-authored routine/task titles remain exactly as entered.
- Q: Are channel delivery attempts stored separately?
  A: No. The notification's `channels` field records successful adapters. A separate delivery audit
  table is deferred until another concrete channel creates an auditing need.
- Q: How is processing invoked?
  A: Laravel Scheduler enqueues one unique per-user processing job every minute. That job reconciles
  sources, creates the day's digest when due, schedules escalation, applies quiet hours, and delivers
  due records through the channel registry.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Receive A Reliable Reminder (Priority: P1)

As a signed-in user, I receive one in-app reminder when a timed routine or important dated task needs
my attention, even if the scheduler retries.

**Independent Test**: Create a timed routine occurrence and a high-priority Storage task due now, run
processing twice, and see exactly one delivered notification for each source with an unread badge.

**Acceptance Scenarios**:

1. **Given** a timed planned occurrence is due, **When** notification processing runs, **Then** one
   localised in-app reminder is delivered with an action back to that day in Planner.
2. **Given** an open high-priority Storage task is due today, **When** processing runs, **Then** one
   direct reminder is delivered and links back to its Planner day.
3. **Given** the same processor or queued job runs twice, **When** the source identity is unchanged,
   **Then** no duplicate initial or escalation reminder is created.
4. **Given** unread reminders exist, **When** any authenticated screen opens, **Then** the global shell
   shows their count and offers the notification inbox on desktop and at exactly 390×844.
5. **Given** another account has reminders, **When** the inbox or badge is read, **Then** none of that
   account's records or counts are exposed.

---

### User Story 2 - Triage The Inbox (Priority: P1)

As a user, I can read, dismiss, snooze, or follow a reminder without claiming that its source is done.

**Independent Test**: Mark one notification read, dismiss another, snooze a third, and confirm only the
snoozed one returns unread after its server-calculated delay while no domain status changes.

**Acceptance Scenarios**:

1. **Given** an unread reminder, **When** I mark it read, **Then** the unread badge decrements and the
   record remains in history.
2. **Given** a live reminder, **When** I dismiss it, **Then** it leaves the active inbox and no later
   escalation is created for the same source.
3. **Given** a live reminder, **When** I snooze it for an offered duration, **Then** it is hidden until
   that UTC instant and is delivered again as unread afterward.
4. **Given** a reminder action, **When** I follow it, **Then** the reminder becomes read and Planner
   opens the relevant calendar day; the source itself remains pending.
5. **Given** a notification owned by someone else or an unsupported transition, **When** an action is
   attempted, **Then** the record is not exposed or changed.

---

### User Story 3 - Protect Quiet Time (Priority: P1)

As a user, I set a quiet interval and choose which existing categories may notify me.

**Independent Test**: Save a cross-midnight quiet interval and disable Storage reminders, then confirm
a due routine reminder is deferred to the interval end and no new Storage reminder is delivered.

**Acceptance Scenarios**:

1. **Given** quiet hours are enabled, **When** a reminder becomes due inside them, **Then** it is
   rescheduled for the first allowed instant in the profile time zone.
2. **Given** a quiet interval that crosses midnight, **When** delivery is evaluated on either side of
   midnight, **Then** both halves are quiet and the computed end is correct.
3. **Given** a category is disabled, **When** processing runs, **Then** no new reminder or escalation
   from that category is delivered and pending records are cancelled.
4. **Given** settings are invalid or incomplete, **When** they are replaced, **Then** field-level
   localised feedback is returned and no setting changes.
5. **Given** a digest time lies in quiet hours, **When** the digest is due, **Then** quiet hours win and
   delivery is deferred rather than rejected or discarded.

---

### User Story 4 - Start With A Digest (Priority: P2)

As a user, I receive one concise daily summary of minor work instead of a burst of separate reminders.

**Independent Test**: Put two untimed routines and three normal-priority Storage tasks on today, run
processing at the digest time, and receive one digest reporting five items and no five direct records.

**Acceptance Scenarios**:

1. **Given** minor items exist on the local day and digest is enabled, **When** its configured time is
   reached, **Then** exactly one localised digest is delivered with the total and per-category counts.
2. **Given** no minor items exist or digest is disabled, **When** the time arrives, **Then** no empty
   digest is created.
3. **Given** high-priority or timed sources have direct reminders, **When** a digest is built, **Then**
   those sources are not counted again.
4. **Given** the processor retries on the same local day, **When** the digest already exists, **Then**
   it does not create a duplicate.

---

### User Story 5 - Stop When The Source Stops (Priority: P2)

As a user, reminders close themselves when I complete, skip, drop, move, archive, deactivate, or pass
the relevant source day.

**Independent Test**: Deliver a routine reminder, mark the occurrence done through the existing
routine flow, process again, and confirm its notifications are actioned and no escalation appears.

**Acceptance Scenarios**:

1. **Given** a routine source becomes done, **When** reconciliation runs, **Then** every live reminder
   in that source family becomes actioned and escalation stops.
2. **Given** a routine source becomes skipped or overdue, **When** reconciliation runs, **Then** its
   pending/live reminders become cancelled without changing the occurrence.
3. **Given** a Storage task is done, **When** reconciliation runs, **Then** its direct reminder becomes
   actioned; dropped, moved, deleted, or no-longer-high-priority sources are cancelled.
4. **Given** a routine reminder remains planned after delivery, **When** its interval elapses outside
   quiet hours, **Then** a uniquely numbered repeat is delivered up to the configured maximum.
5. **Given** the repeat limit is reached, **When** processing continues, **Then** no further reminder
   is created and the owning fact remains untouched.

## Requirements *(mandatory)*

### Functional Requirements — Delivery Boundary

- **FR-001**: A notification MUST persist delivery state separately from every domain source.
- **FR-002**: A stable source alias and source id MUST identify the fact without storing PHP class names.
- **FR-003**: The initial and every escalation record MUST be unique per user, source, source id, and
  escalation count.
- **FR-004**: A channel contract and registry MUST have an in-app implementation as its first consumer;
  channel selection MUST happen at runtime.
- **FR-005**: Processing MUST be a retry-safe, unique per-user queued job invoked every minute by
  Laravel Scheduler.
- **FR-006**: Timestamps that represent instants MUST be stored in UTC; calendar-day and quiet-hour
  calculations MUST use the profile time zone.
- **FR-007**: Notifications MUST NOT write source completion, skip, task status, or any other domain fact.

### Functional Requirements — Sources, Digest, and Escalation

- **FR-008**: A timed planned occurrence MUST create a direct reminder for its effective original or
  rescheduled day and time.
- **FR-009**: An open high-priority Storage task due today MUST create one direct reminder at the digest
  time; a changed due date or priority MUST update/cancel a still-pending record rather than duplicate it.
- **FR-010**: The daily digest MUST aggregate only untimed planned occurrences and non-high-priority open
  Storage tasks for the current local day.
- **FR-011**: An empty or disabled digest MUST not create a notification.
- **FR-012**: Direct routine reminders MUST use a configurable 30-minute/two-repeat policy in this
  increment; direct Storage and digest notifications MUST not escalate.
- **FR-013**: A dismissed source family, disabled category, non-pending source, overdue occurrence, or
  reached maximum MUST stop escalation.
- **FR-014**: Source reconciliation MUST action completed sources and cancel skipped, dropped, moved,
  deleted, disabled, archived, inactive, or overdue sources without mutating them.

### Functional Requirements — Preferences and State

- **FR-015**: Every user MUST have one recoverable notification-settings record with quiet-hours,
  digest, and routine/storage category defaults.
- **FR-016**: Settings replacement MUST be all-or-nothing and reject unknown fields; an enabled quiet
  interval MUST have different start and end times.
- **FR-017**: Quiet hours MUST support same-day and cross-midnight intervals and defer to their next end.
- **FR-018**: Users MUST be able to mark a sent reminder read, dismiss it, or snooze it for 15 minutes,
  one hour, four hours, or one day.
- **FR-019**: State transitions MUST be server-authoritative, idempotent where repeating the same action
  is harmless, and refuse impossible transitions without partial writes.
- **FR-020**: Snooze MUST use a server-calculated UTC time, hide the record, pause escalation, and return
  it unread after delivery; dismiss MUST stop the source family's repeats.

### Functional Requirements — API and Interface

- **FR-021**: An authenticated list endpoint MUST return at most 50 visible sent/read notifications,
  newest first, plus the owner's unread count; `unread` and `all` views MUST be supported.
- **FR-022**: Settings and notification-action endpoints MUST be documented by OpenAPI and guarded
  against route drift.
- **FR-023**: A `/notifications` screen MUST expose active/history views, action links, read, dismiss,
  snooze, quiet hours, digest time, and category controls using the existing control system.
- **FR-024**: The global authenticated shell MUST expose the inbox and exact unread count on desktop
  and mobile, refresh after inbox actions, and poll while the session remains authenticated.
- **FR-025**: Empty, loading, failure, deferred, snoozed, read, and saved states MUST be explicit and
  keyboard-operable with no horizontal overflow at 390×844.
- **FR-026**: Every API read and write MUST derive ownership from the authenticated session; an
  unowned notification MUST remain indistinguishable from a missing one.

### Localisation Surface *(mandatory)*

- **Locales**: English (`en-GB`), Russian (`ru-UA`), and Ukrainian (`uk-UA`).
- **New user text**: Notifications navigation/badge labels, page headings, filters, settings, helpers,
  state labels, read/dismiss/snooze/actions, empty/loading/error/saved feedback, delivered routine,
  Storage, escalation and digest copy, validation/domain feedback, accessibility labels, enum labels,
  and changelog content.
- **Formatting**: Server delivery uses the profile locale; the web client formats delivered instants in
  the active locale/time zone and uses locale plural rules for badge/digest helpers.
- **Non-translatable content**: SelfHandler, user-authored routine/task titles, identifiers, URLs, and
  technical channel key `in_app`.
- **Verification**: Exact catalog parity, used-key/unknown-key/blank-key/hardcoded-copy checks, backend
  delivery in all three locales, and desktop/mobile browser journeys.

### Key Entities

- **Notification**: One scheduled or delivered in-app event with stable source identity, delivery and
  triage state, delivered copy, action, channel list, and escalation metadata.
- **NotificationSettings**: The user's quiet interval, digest choice/time, and enabled source categories.
- **NotificationChannel**: A runtime delivery adapter; `in_app` is the only implementation now.
- **ProcessUserNotifications**: The retry-safe per-user job that synchronises sources and delivery state.

## Success Criteria *(mandatory)*

- **SC-001**: Running source generation or delivery twice creates zero duplicate identities.
- **SC-002**: Every due direct source outside quiet hours appears in the inbox within one scheduler cycle.
- **SC-003**: Every source due inside quiet hours is delivered at the computed interval end, with no loss.
- **SC-004**: Read, dismiss, snooze, and source closure produce the specified state and unread count while
  leaving domain rows unchanged except for the user's explicit existing-domain action.
- **SC-005**: A digest of N eligible sources produces one notification reporting exactly N and no direct
  notification for those same sources.
- **SC-006**: Routine escalation produces at most two uniquely identified repeats at 30-minute intervals.
- **SC-007**: Two accounts never see or mutate each other's notification, settings, or unread count.
- **SC-008**: Delivered title/body copy is correct in English, Russian, and Ukrainian and user titles are
  preserved verbatim.
- **SC-009**: The documented contract matches routes, payloads, enums, and status transitions.
- **SC-010**: Laravel, Pint, localisation, Vue typecheck/build, and both Playwright projects pass.

## Scope Boundaries

### Out of Scope

FCM/Web Push, Capacitor local notifications, email, Telegram, SMS, iOS, a separate per-channel delivery
audit table, arbitrary user-authored reminders, category-specific interval editing, browser permission
prompts, live sockets, external calendar synchronisation, deployment changes, and notifications for
modules that do not yet exist. Android/local-notification delivery is feature 012.

## Assumptions

- The scheduler and queue worker are operational environment responsibilities already supported by
  Laravel; this feature registers work but does not modify excluded deployment assets.
- Polling every 60 seconds is sufficient for an in-app-only unread badge; delivery itself remains
  scheduler-driven.
- A delivered notification is an event record in the language used at delivery and is not retroactively
  translated after a profile locale change.

## Dependencies

Features 004, 005, 006, 008, 009, and 010. No new runtime package.
