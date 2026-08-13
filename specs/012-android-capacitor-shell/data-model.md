# Data Model: Android Capacitor Shell

**Feature**: `012-android-capacitor-shell`
**Date**: 2026-08-13

## Server Persistence

### `personal_access_tokens`

Add the standard Sanctum table through an additive migration.

| Column | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `tokenable_type` | string | Sanctum morph type; `App\\Models\\User` now |
| `tokenable_id` | bigint | Indexed owner id through morph index |
| `name` | text | `Android · <bounded recognizable device name>` |
| `token` | char(64) | Unique SHA-256 hash; plaintext never stored |
| `abilities` | text/json serialization | Exactly `mobile` for 012 tokens |
| `last_used_at` | timestamp nullable | Updated by Sanctum guard |
| `expires_at` | timestamp nullable/indexed | Required for 012; issued-at + 30 days |
| timestamps | timestamp | Creation/update instants |

The standard polymorphic table is accepted because Sanctum owns its contract. Feature 012 issues tokens
only for `User`, always with an expiry and ability. User deletion cascades through explicit cleanup in
the migration/model boundary where supported; ownership tests ensure orphaned access is impossible.

### Existing `notifications.channels`

No migration. Add portable channel value `android_local` to the allowed application vocabulary.

- `in_app`: server delivery persisted the inbox event.
- `android_local`: an authenticated Android client successfully handed that delivered unread event to
  the Local Notifications plugin.

Appending `android_local` is idempotent. It is presentation evidence, not a new notification, source
status, read status, or guarantee that the OS displayed it to a human.

## Device-Local Sensitive State

### `MobileCredentialVault`

| Value | Storage | Rules |
|---|---|---|
| AES key | Android Keystore alias `selfhandler.mobile.session.v1` | Non-exportable, AES/GCM, generated on first write |
| encrypted token | private SharedPreferences | Base64 ciphertext only |
| IV | same private SharedPreferences | Random 12-byte GCM IV, Base64 |
| schema version | same private SharedPreferences | Non-secret integer for safe future migration |

The preferences file is excluded from backup and the application disables backup. `read` returns only to
the native bridge call; `write` overwrites atomically; `clear` removes ciphertext/IV/version. Decryption,
key invalidation, missing pieces, or malformed payload clears the record and returns no credential.

No token may be persisted in:

- `localStorage`, `sessionStorage`, IndexedDB, cookies, Capacitor Preferences;
- Vue reactive state, application logs, URLs, notification extras, crash metadata;
- `.env.example`, Capacitor config, Gradle properties, resources, tests, screenshots, or git.

## Non-Sensitive Device State

The local notification plugin itself owns pending/delivered descriptors. Each descriptor contains:

| Field | Value |
|---|---|
| `id` | stable positive signed-32-bit mapping |
| `title` / `body` | persisted 011 delivered copy |
| `channelId` | `selfhandler-reminders` |
| `extra.notificationId` | decimal server notification id |
| `extra.actionUrl` | safe relative Planner path or `/notifications` |

No user id, email, token, source class, or domain payload is copied into native extras.

## Token State Machine

```text
absent ──valid login──> issued ──vault write──> active
  ▲                                      │          │
  │                                      │          ├──30d/revoked/401──> invalid
  │                                      │          │
  └────────────vault clear<──logout──────┴──────────┘
```

- Failure before token issuance leaves `absent`.
- Issuance followed by vault-write failure immediately calls best-effort revocation using the in-memory
  token, clears the vault, and refuses authentication.
- Network failure during an ordinary request does not clear an otherwise valid token.
- 401 from a token-authenticated request clears the vault and session state once.
- Logout revokes only `currentAccessToken`; no other mobile or browser session is changed.

## Native Presentation State Machine

```text
sent/in_app
   │ permission granted + not pending/delivered
   ▼
native scheduled ──ack──> sent/in_app+android_local
   │ tap
   └──────────────> server read + safe client route

denied/error/collision ──> remain sent/in_app (retryable after user/system change)
```

Only server `sent` rows can acknowledge native presentation. `read`, `snoozed`, dismissed, actioned, or
cancelled rows are never newly presented by this adapter.

## Ownership And Deletion

- Mobile session inspection/revocation derives the user from the valid current token.
- Notification acknowledgment owner-scopes route-model binding behavior before status/channel checks.
- A user token cannot select another user's notification or profile.
- User deletion invalidates access even if a token row unexpectedly remains because authentication can
  no longer resolve a tokenable user.
- App uninstall deletes the vault. Server token revocation on uninstall cannot be guaranteed without a
  server device-management UI, explicitly deferred.
