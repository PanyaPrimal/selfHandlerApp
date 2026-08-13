# Data Model: In-App Notifications

**Feature ID**: `011-in-app-notifications` · **Date**: 2026-08-13

## `notification_settings`

One recoverable settings home per user.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key. |
| `user_id` | bigint | Required FK, unique, cascade on user deletion. |
| `quiet_hours_enabled` | boolean | Default `true`. |
| `quiet_starts_at` | time | Required, default `23:00`. Local wall-clock time. |
| `quiet_ends_at` | time | Required, default `08:00`. Must differ from start when enabled. |
| `digest_enabled` | boolean | Default `true`. |
| `digest_time` | time | Required, default `08:00`. Local wall-clock time. |
| `categories` | JSON | Complete boolean map: `routine`, `storage`; both default `true`. |
| timestamps | timestamp | Audit metadata in UTC. |

Missing rows are recreated by `User::ensureNotificationSettings()` with these defaults. API writes
replace the complete settings shape atomically; unknown fields and incomplete category maps fail.

## `notifications`

One source delivery record per escalation number.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key. |
| `user_id` | bigint | Required FK, cascade on user deletion. |
| `source_type` | varchar(48) | `planned_occurrence`, `storage_item`, or `daily_digest`. Portable alias. |
| `source_id` | unsigned bigint | Required. Model id or local date encoded as `YYYYMMDD` for a digest. |
| `type` | varchar(48) | `routine_reminder`, `storage_due`, or `daily_digest`. |
| `category` | varchar(24) | `routine`, `storage`, or `digest`. |
| `title` | varchar(200), nullable | Filled in the recipient locale at successful delivery. |
| `body` | text, nullable | Filled in the recipient locale at successful delivery. |
| `action_url` | varchar(500), nullable | Relative, allow-listed application path; Planner day for 011. |
| `content` | JSON | Bounded rendering parameters: source title, date, and/or digest counts. |
| `scheduled_at` | timestamp | Required UTC instant for the next delivery attempt. |
| `status` | varchar(16) | State enum below; default `scheduled`. |
| `channels` | JSON | Successful adapter keys; empty before delivery, `['in_app']` afterward. |
| `escalation_count` | unsigned smallint | Initial/digest `0`; routine repeats `1..2`. |
| `next_escalation_at` | timestamp, nullable | UTC instant at which the next repeat may be created. |
| `max_escalations` | unsigned smallint | Routine `2`; Storage/digest `0`. |
| `snoozed_until` | timestamp, nullable | Server-calculated UTC instant while snoozed. |
| `sent_at` | timestamp, nullable | Most recent successful delivery. |
| `read_at` | timestamp, nullable | User read time. |
| `dismissed_at` | timestamp, nullable | User dismissal time. |
| `actioned_at` | timestamp, nullable | Source completion reconciliation time. |
| `cancelled_at` | timestamp, nullable | Source invalidation reconciliation time. |
| timestamps | timestamp | Audit metadata in UTC. |

### Keys and indexes

- Unique `notifications_source_escalation_unique` on
  `(user_id, source_type, source_id, escalation_count)`.
- Due-work index `(status, scheduled_at)`.
- Inbox index `(user_id, status, sent_at)`.
- Source reconciliation index `(user_id, source_type, source_id)`.

The explicit unique name remains below MySQL's 64-character identifier limit. `source_id` is never
nullable, avoiding MySQL/SQLite differences in nullable unique indexes.

### Status enum and transitions

| Status | Meaning | May become |
|---|---|---|
| `scheduled` | Future/quiet-deferred record, not visible. | `sent`, `cancelled`, `actioned`. |
| `sent` | Delivered and unread. | `read`, `dismissed`, `snoozed`, `cancelled`, `actioned`. |
| `read` | Delivered and read; remains history. | `dismissed`, `snoozed`, `cancelled`, `actioned`. |
| `snoozed` | Hidden until `snoozed_until`. | `sent`, `cancelled`, `actioned`. |
| `dismissed` | User terminal state; stops source-family escalation. | none. |
| `actioned` | Source completed. | none during the same source lifecycle. |
| `cancelled` | Source skipped/invalid/disabled/moved/overdue. | none during the same source lifecycle. |

Source reconciliation may re-arm a non-dismissed count-zero record if the owning fact is explicitly
returned to a new pending future state. Because attempt-level delivery history is deferred, re-arming
clears delivered fields on that row rather than inventing another source identity. A dismissed family
is never re-armed automatically.

## Source validity

### `planned_occurrence`

Direct when all hold:

- owner matches;
- occurrence status is `planned`;
- recurrence owner is an active, unarchived, non-deleted routine;
- `occurrence_time` exists;
- effective date is `rescheduled_to ?? occurrence_date` and has not passed in profile time zone.

`done` actions notifications; `skipped`, inactive/archive/delete, or past effective day cancels them.
The direct UTC instant is constructed from effective local date/time in the profile time zone.

### `storage_item`

Direct when all hold: owned task, open status, high priority, and `due_on` equals the profile local day.
It is scheduled for that day's digest wall-clock time. `done` actions it; dropped/delete, different due
day, non-task, non-open, or non-high priority cancels it. Non-high open tasks remain digest candidates.

### `daily_digest`

Synthetic snapshot created only after its configured local time when at least one eligible minor source
exists. Its content stores `total`, `routine_count`, `storage_count`, and local `date`. It never
escalates and is not retroactively rewritten after delivery.

## Model relationships

```
User 1 ─── 1 NotificationSettings
User 1 ─── * InAppNotification

InAppNotification ── source alias/id ──> PlannedOccurrence | Item | local digest date
```

The source link is deliberately not an Eloquent polymorphic relation: portable aliases prevent class
names in data and each source adapter enforces its own ownership/validity query.

## Deletion and retention

User deletion cascades both settings and notifications. Source deletion does not cascade across the
alias boundary; reconciliation cancels the reminder. General retention/export/delete behavior is
feature 023 and is not invented here.
