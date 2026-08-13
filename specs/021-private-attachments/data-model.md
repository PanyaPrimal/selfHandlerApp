# Data Model: Private Attachments with First Consumers

**Date**: 2026-08-14

All Attachment rows are private and carry `user_id`. The polymorphic association uses an explicit morph map;
API aliases never accept PHP class names. Files live on a configured Laravel disk; the database stores only
metadata and an opaque relative path.

## Attachment

| Field | Rules |
|---|---|
| `id` | internal primary identity |
| `user_id` | owning user; indexed; cascade is a final DB safeguard after file cleanup |
| `attachable_type`, `attachable_id` | morph alias/id; only `body_measurement` and `meal` in 021 |
| `disk` | logical configured disk name, 1–64; never serialized |
| `path` | opaque owner-partitioned UUID path, 1–512; unique; never serialized |
| `original_name` | basename-only sanitized display name, 1–255 |
| `mime_type` | normalized `image/jpeg`, `image/png`, or `image/webp` |
| `size_bytes` | exact normalized file length, positive and at most configured stored ceiling |
| `kind` | `photo` only in 021 |
| `width`, `height` | positive normalized pixel dimensions, each ≤ 2560 |
| `sha256` | lowercase 64-character digest of normalized bytes |
| `upload_key` | trimmed caller retry identity, 1–100; unique with owner |
| `meta` | nullable closed server metadata; null in 021 because dimensions/digest are queryable columns |
| `created_at` | immutable UTC instant; no `updated_at` |

Indexes and constraints:

- unique `(user_id, upload_key)` for retry serialization
- unique `(disk, path)` for physical identity
- index `(attachable_type, attachable_id, created_at, id)` for ordered parent reads/count
- index `(user_id, size_bytes)` for bounded owner quota sum
- service invariants require Attachment owner == current supported parent owner

Public Attachment resource fields are `id`, `kind`, `original_name`, `mime_type`, `size_bytes`, `width`,
`height`, `created_at`, and relative `content_url`. It excludes user id, parent internals, disk, path, digest,
upload key, and meta.

## BodyMeasurement Addition

- `morphMany(Attachment)` ordered by `created_at`, then `id`
- collection reads eager-load attachment summaries
- upload/delete does not mutate metric, measurement date, value, unit, note, goal projection, or trend
- hard delete invokes synchronous attachment cleanup before deleting the measurement

## Meal Addition

- `morphMany(Attachment)` ordered by `created_at`, then `id`
- MealResource serializes eager-loaded attachment summaries
- day/meal reads eager-load attachments together with entries in a bounded query set
- upload/delete does not mutate meal date/type/time/name/note/estimate or any MealEntry snapshot/aggregate
- hard delete invokes synchronous attachment cleanup before Meal/MealEntry database deletion completes

## User Lifecycle Integration

- the existing User model does not gain a public relation or attachment behavior
- Attachment queries use explicit `user_id` for exact quota aggregation and final cleanup
- upload locks the User row, then the resolved parent row, before retry/quota/store/create
- a registered observer invokes synchronous cleanup on hard delete before database cascades

## Image Processing State

```text
untrusted upload
  → source size/type/dimension probe
  → decode and EXIF orientation
  → bounded resize/re-encode into request temp file
  → normalized decode/type/dimension/size/digest verification
  → locked idempotency and quota check
  → private final write
  → Attachment row commit
  → temp cleanup
```

Any transition failure cleans attempt-owned temporary/final bytes and creates no row. Normalization never
uses the user's filename as a physical path.

## Upload Identity and State Transitions

- no existing `(owner, upload_key)`: validate normalized content, quota, store, create, return `201`
- existing same parent alias/id and SHA-256: return the existing attachment unchanged with `200`
- existing with different parent or SHA-256: return deterministic `409`; no mutation/write/quota charge
- an Attachment has no edit transition; only create, stream, and delete

## Quota Semantics

- source request file: ≤ 5 MiB before decode
- normalized dimensions: ≤ 2560×2560
- parent: committed attachment count < 10 before create
- owner: `SUM(size_bytes) + normalized size ≤ 100 MiB`
- both checks occur while User and parent rows are locked; replay is resolved before quota charging
- explicit successful deletion immediately removes its size from the derived next-upload sum

## Deletion and Failure Semantics

- explicit: authorize owner/current parent → delete file if present → delete row
- missing file: stream fails safely; explicit/parent/user cleanup may delete orphan metadata
- disk delete error: preserve row and return/fail so cleanup can retry
- parent deletion: delete all attached files/rows before parent deletion proceeds
- user deletion: delete all remaining owned files/rows before cascade proceeds
- rollback: migration drops only the Attachment table after test-created files are cleaned; existing Body,
  Nutrition, User, and 020 schema/data remain unchanged
