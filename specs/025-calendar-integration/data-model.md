# Data Model: Calendar Integration

## `integrations`

One optional calendar connection for one owner/provider.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | FK users, cascade delete, owner scope |
| `provider` | varchar(32) | `google_calendar` or `apple_calendar` |
| `kind` | varchar(24) | `calendar` in feature 025 |
| `status` | varchar(24) | `pending`, `active`, `expired`, `revoked` |
| `external_account_id` | varchar(512), nullable | Provider opaque account identity, never globally unique |
| `external_account_label` | text, nullable | Encrypted cast; masked in resources |
| `external_calendar_id` | text, nullable | Encrypted cast; opaque provider ID/URL |
| `external_calendar_name` | text, nullable | Encrypted cast; owner-visible display name |
| `access_token` | text, nullable | Encrypted cast, never serialized |
| `refresh_token` | text, nullable | Encrypted cast, never serialized |
| `secret` | text, nullable | Encrypted Apple app-specific password, never serialized |
| `token_expires_at` | timestamp UTC, nullable | Refresh boundary |
| `sync_cursor` | text, nullable | Encrypted provider cursor/token |
| `settings` | json | Closed normalized settings |
| `last_sync_at` | timestamp UTC, nullable | Last attempt |
| `last_success_at` | timestamp UTC, nullable | Last complete pull+push |
| `last_error_code` | varchar(48), nullable | Closed non-secret code |
| timestamps | timestamp UTC | Laravel timestamps |

Indexes/constraints:

- unique (`user_id`, `provider`)
- index (`status`, `last_sync_at`)
- check `kind = calendar`
- check provider/status closed sets
- model saving guard requires owner and kind/provider consistency

`settings` closed shape:

```json
{
  "import_detail": "busy_only",
  "export_categories": [],
  "calendar_writable": true,
  "calendar_timezone": "Europe/Kyiv"
}
```

`export_categories` is a sorted unique subset of `time_block`, `routine`, `sleep`, `habit`, `workout`,
`supplement`, `finance`. Unknown settings/values fail validation; stored legacy missing keys receive defaults.

## `external_calendar_events`

Minimal provider-origin event projection owned by Integrations and consumed read-only by Planner.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | FK users, cascade delete |
| `integration_id` | bigint | FK integrations, cascade delete |
| `external_id_hash` | char(64) | HMAC/SHA-256 lookup identity, no raw ID |
| `summary` | text, nullable | Encrypted provider title |
| `starts_at` | timestamp UTC, nullable | Timed events only |
| `ends_at` | timestamp UTC, nullable | Timed exclusive end |
| `start_date` | date, nullable | All-day events only |
| `end_date` | date, nullable | All-day exclusive end |
| `is_all_day` | boolean | Selects instant/date representation |
| `status` | varchar(16) | `confirmed` or `tentative`; cancelled rows are deleted |
| `created_at`, `updated_at` | timestamp UTC | Local projection lifecycle |

Indexes/constraints:

- unique (`integration_id`, `external_id_hash`)
- index (`user_id`, `starts_at`, `ends_at`)
- index (`user_id`, `start_date`, `end_date`)
- checks require exactly one valid timed or all-day representation and end strictly after start
- model saving guard requires event owner equals Integration owner

## `synced_items`

Durable mapping and conflict/idempotency state.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | FK users, cascade delete |
| `integration_id` | bigint | FK integrations, cascade delete |
| `origin` | varchar(16) | `selfhandler` or `provider` |
| `local_type` | varchar(32) | `time_block`, `planned_occurrence`, or `external_event` |
| `local_id` | bigint | Model ID; guarded manually for hybrid polymorphism |
| `external_id` | text | Encrypted provider event ID/href |
| `external_id_hash` | char(64) | Stable lookup identity |
| `external_etag` | varchar(512), nullable | Provider version marker |
| `remote_updated_at` | timestamp UTC, nullable | Provider timestamp when available |
| `local_fingerprint` | char(64), nullable | Last pushed normalized local envelope hash |
| `last_synced_at` | timestamp UTC, nullable | Last converged mapping |
| timestamps | timestamp UTC | Mapping lifecycle |

Indexes/constraints:

- unique (`integration_id`, `external_id_hash`)
- unique (`integration_id`, `local_type`, `local_id`)
- index (`user_id`, `local_type`, `local_id`)
- closed origin/local type checks
- model guard verifies Integration owner and referenced local owner/integration
- cascade from Integration; explicit observer cleanup from TimeBlock/PlannedOccurrence/external event as needed

## Non-Persisted DTOs

### `CalendarDescriptor`

`external_id`, `name`, `timezone`, `writable`, `is_default`. IDs are returned only during authenticated selection
and submitted back encrypted into Integration.

### `CalendarEventEnvelope`

`external_id`, `etag`, `updated_at`, `status`, `summary`, `starts_at`, `ends_at`, `start_date`, `end_date`,
`is_all_day`, `origin_key`. Raw payload and all discarded provider properties never enter this DTO.

### `CalendarSyncResult`

Closed integer counters: `imported`, `updated`, `removed`, `exported`, `deleted`, `conflicts`, `unchanged`, plus
`completed_at`. Errors use HTTP problem/validation response with a closed `calendar_*` code rather than a partial
success body.

## Ownership and Relationship Rules

1. Every row has `user_id` and uses `UserOwned`.
2. Child `user_id` must equal parent Integration `user_id`; database FKs and model guards both enforce it.
3. Polymorphic local references use an explicit closed alias map, never PHP class names.
4. API never accepts user IDs and never returns provider secrets, raw external IDs, raw payloads, or cursors.
5. Provider-origin events cannot reference TimeBlock/PlannedOccurrence. SelfHandler-origin mappings cannot
   reference ExternalCalendarEvent.
6. Disconnect deletes only these three local integration tables; local domain tables and provider events remain.

## Time Semantics

- Timed external/provider events normalize to UTC instants; Planner calculates day overlap in current Profile
  timezone on each read.
- All-day events retain provider date-only start and exclusive end and appear on every date in `[start, end)`.
- Local TimeBlock date/time is interpreted in current Profile timezone for export.
- Untimed PlannedOccurrence exports as one all-day Profile-local date; timed occurrences export as an instant with
  a conservative one-hour end unless the owner-specific projection supplies an end.
- Sync timestamps/token expiry are UTC; UI formats them with Profile locale/timezone.

## State Transitions

```text
absent -> pending -> active
pending -> absent             failed/denied connection cleanup
active -> expired             auth/refresh rejection
active -> revoked             explicit provider revocation signal
expired/revoked -> active     successful reconnect
any -> absent                 confirmed disconnect (local cleanup only)
```

Only `active` integrations synchronize. Transient errors retain `active`; successful sync clears `last_error_code`.

## Additive Evolution and Preservation

- Three new tables only; no existing row rewrite.
- Existing Planner sources and API response shape gain the additive `external_calendar` source.
- Backup schema v1 deliberately excludes Integration/SyncedItem/ExternalCalendarEvent and secrets because external
  connections are environment/provider-bound; its catalog drift exclusion is updated and tested.
- Rollback drops only new tables after dependent foreign keys; no current domain data is altered.
