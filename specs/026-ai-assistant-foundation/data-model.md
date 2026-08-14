# Data Model: AI Assistant Foundation with Confirmed Inbox Triage

One additive migration, `2026_08_14_090000_create_ai_assistant_foundation.php`, creates five owner-scoped
tables. No existing table or row changes.

## `llm_connections`

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | FK users, cascade delete, owner scope |
| `name` | varchar(80) | Trimmed; unique per owner |
| `provider` | varchar(24) | `anthropic` or `openai` |
| `model` | varchar(160) | User-selected provider identifier; safe character/length validation |
| `api_key` | text | Laravel encrypted cast, hidden, never serialized |
| `key_hint` | char(4) | Last four characters only; mask display |
| `parameters` | json | Closed `{ "max_output_tokens": 512 }`, range 128–2048 |
| `status` | varchar(16) | `untested`, `ready`, `invalid` |
| `last_tested_at` | timestamp UTC nullable | Latest completed probe |
| `last_used_at` | timestamp UTC nullable | Latest accepted scenario provider call |
| `last_error_code` | varchar(64) nullable | Closed safe code only |
| timestamps | timestamp UTC | Lifecycle |

Indexes/constraints: unique (`user_id`, `name`), index (`user_id`, `status`), checks for provider/status and
token range. Model saving guard requires a valid owner and closed parameters.

## `llm_settings`

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | FK users cascade; unique owner |
| `active_connection_id` | bigint nullable | FK connections, null on delete |
| timestamps | timestamp UTC | Lifecycle |

The model saving guard requires the active connection to have the same owner and `ready` status. The separate
pointer makes zero-or-one active structural without a partial unique index.

## `llm_consents`

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | FK users cascade |
| `scope` | varchar(40) | `storage_inbox` in 026 |
| `granted_at` | timestamp UTC nullable | Current grant instant |
| `revoked_at` | timestamp UTC nullable | Latest revocation instant |
| timestamps | timestamp UTC | Lifecycle |

Unique (`user_id`, `scope`). Exactly one of `granted_at`/`revoked_at` is the latest current state; mutation service
sets both deterministically and confirmation checks `granted_at != null && revoked_at == null`.

## `llm_tool_confirmations`

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | FK users cascade |
| `llm_connection_id` | bigint | FK connections cascade |
| `token_hash` | char(64) | Unique SHA-256 of encrypted client token |
| `proposal_hash` | char(64) | Canonical JSON SHA-256 binding |
| `tool_name` | varchar(80) | `storage_triage_inbox_item` |
| `source_type` | varchar(32) | `item` |
| `source_id` | bigint | Owned Item ID, application-guarded |
| `source_fingerprint` | char(64) | Hash of mutable source fields/timestamp |
| `status` | varchar(16) | `pending`, `applied`, `rejected` |
| `expires_at` | timestamp UTC | Ten-minute authorization limit |
| `applied_at` | timestamp UTC nullable | Successful tool commit |
| `rejected_at` | timestamp UTC nullable | Explicit/implicit rejection when recorded |
| timestamps | timestamp UTC | Lifecycle |

The encrypted client token contains version, nonce, owner/connection/source/tool, exact proposal and expiry.
Neither proposal arguments nor rationale are stored in this table. Row lock + pending check is the replay/race
boundary.

## `llm_audit_events`

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | FK users cascade |
| `llm_connection_id` | bigint nullable | FK connections null on delete |
| `event` | varchar(48) | Closed lifecycle event |
| `scope` | varchar(40) nullable | Closed consent/scenario scope |
| `outcome` | varchar(16) | `succeeded` or `rejected` |
| `error_code` | varchar(64) nullable | Closed safe code |
| `occurred_at` | timestamp UTC | Immutable event time |

Index (`user_id`, `occurred_at`). The model is append-only through `LlmAuditLogger`. No secret, provider body,
prompt, source text, arguments, response, rationale, confirmation token or IP/user-agent is stored.

## Ephemeral Value Objects

### `LlmToolDefinition`

`name`, `description`, JSON Schema, `writes` and `confirmation_required`. Feature 026 registry contains exactly
`storage_triage_inbox_item`, with `writes=true` and `confirmation_required=true`.

### `InboxTriageContext`

- selected owned Item: title and nullable description (the internal ID stays server-side);
- active non-archived owner projects: ID/name, bounded to 100;
- distinct existing owner tag names, bounded to 100;
- `task|idea|purchase`, `low|normal|high`, date/tone/locale/timezone instructions.

### `InboxTriageProposal`

```json
{
  "type": "task",
  "project_id": 12,
  "tags": ["focus"],
  "priority": "high",
  "due_on": "2026-08-15",
  "rationale": "One short user-visible explanation."
}
```

`project_id`, `priority`, and `due_on` may be null. All object properties remain required in the provider schema
to preserve strict output; null expresses absence. Additional properties are forbidden.

## Lifecycle

```text
connection: untested -> ready | invalid -> untested (rotation) -> deleted
consent: denied(default) -> granted -> revoked -> granted
confirmation: pending -> applied
                     \-> rejected/expired (no write)
```

Provider draft generation changes only connection `last_used_at`, confirmation metadata and safe audit. Storage
changes only in the confirmed `pending -> applied` transaction.

## Portability

All five tables are provider-bound/security metadata and are excluded from schema-v1 backup. Restore into a user
with any row in any of these tables is ineligible; restoring credentials or consent would be unsafe and a key
encrypted under another app key may not decrypt.
