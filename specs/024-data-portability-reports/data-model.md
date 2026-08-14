# Data Model: Data Portability and Reports

Feature 024 adds no database table. Reports and archives are ephemeral.

## Human Report DTO

The report input is the exact feature-023 Analytics workspace after normal query validation. It includes metric
definition, period/granularity, points, trend, optional comparison, currency, and generated timestamp. Report
rendering may add localized labels but cannot add/recalculate facts.

## Backup Manifest v1

```text
format                 fixed "selfhandler-backup"
schema_version         integer 1
backup_id              UUID generated per export
created_at             UTC RFC3339 instant
members[]              path, role, size_bytes, sha256
attachments[]          member path + portable id/parent + safe metadata/checksum
counts                 records_by_table, total_records, attachments, total_bytes
exclusions[]            closed stable exclusion codes
limits                  declared schema-v1 ceilings
```

The manifest contains no user/database ID, email, token, storage disk/path, or password material.

## Profile Member

`data/profile.json` contains schema version, account display name, the closed UserProfile field set, and the
optional closed NotificationSettings field set. Target account authentication fields never appear.

## Records Member

`data/records.json` contains a schema version and an object keyed by the schema-v1 table catalog. Each value is
an ordered list of:

```json
{
  "id": "routines:000001",
  "attributes": { "name": "Morning", "created_at": "..." },
  "references": { "goal_id": "goals:000001" }
}
```

- `id` is archive-local and has no source primary key.
- `attributes` is closed per table and excludes `id`, `user_id`, and relational/polymorphic identifiers.
- `references` values are portable IDs, `null`, or `{ "system": "..." }` for global catalogs.
- JSON database columns are JSON values rather than JSON-encoded strings.

## Catalog and Ordering

The catalog covers every authoritative current table with `user_id`, excluding `attachments`, `user_profiles`,
`notification_settings`, `notifications`, and `sessions`, which are handled separately or deliberately excluded.
Parents precede required children. Nullable self/cycle mirrors are inserted null and updated after all records
exist. Polymorphic aliases map recurring owners, Finance sources, and attachment parents to a closed table.

## Attachment Manifest Entry

```text
id, path, parent_type, parent_id, original_name, mime_type, size_bytes,
kind, width, height, sha256, created_at
```

`parent_id` is portable. `path` is an archive member, not a storage path. Restore creates a fresh upload key,
disk selection, and opaque owner-partitioned path.

## Restore Validation

```text
valid, eligible, schema_version, archive_sha256, backup_id, created_at,
counts, total_bytes, exclusions, issues[], restore_token?, expires_at?
```

Issue codes are closed and localized in the UI. Tokens are HMAC-authenticated payloads containing only version,
target user ID, archive digest, and expiry.

## State Transitions

```text
selected -> validating -> invalid
                       -> valid + ineligible
                       -> valid + eligible -> confirmed -> restoring -> restored
                                                               \-> failed
```

Changing the selected file returns to `selected`. API validation itself never changes persistent state.
