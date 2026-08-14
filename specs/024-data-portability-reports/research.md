# Research: Data Portability and Reports

## Decisions

### Separate human reports from machine backup

- **Decision**: CSV/PDF render only the feature-023 Analytics workspace. Backup/restore uses a distinct
  `application/zip` schema and never accepts a report as restore input.
- **Why**: Reports optimize for people, localization, and bounded sharing. Backup optimizes for lossless data,
  stable references, validation, and evolution. Treating CSV/PDF as backups would lose relations and precision.

### Reuse Analytics output

- **Decision**: Both report formats receive the validated `AnalyticsWorkspaceService` DTO.
- **Why**: Feature 023 keeps every formula with its owning module. A second report query path would drift.

### Use Dompdf directly for PDF

- **Decision**: Add `dompdf/dompdf` 3.x, use bundled DejaVu Sans, remote fetching disabled, A4 portrait, and
  escaped in-memory HTML.
- **Why**: The runtime has `ZipArchive` but no PDF renderer or redistributable Cyrillic font. Dompdf renders
  UTF-8 HTML and bundles DejaVu fonts, avoiding browser/headless deployment coupling.
- **Evidence**: <https://github.com/dompdf/dompdf> and <https://github.com/dompdf/dompdf/releases>.

### ZIP with manifest-addressed attachments

- **Decision**: `manifest.json`, `data/profile.json`, and `data/records.json` are JSON members; each attachment
  is a separate `attachments/<portable-id>.<ext>` member declared and hashed in the manifest.
- **Why**: JSON stays bounded/readable, files stream separately, and every member can be checked before writes.

### Portable references, not source IDs

- **Decision**: Export assigns deterministic table-scoped IDs and replaces relational/polymorphic links with
  those IDs. Global Food/Exercise references use `system_key`.
- **Why**: Auto-increment IDs and private paths differ across accounts and installations; system keys are the
  existing stable public-catalog identity.

### Explicit catalog over database introspection at restore time

- **Decision**: Code owns a schema-v1 table order, attribute allowlist, JSON columns, references, deferred
  nullable references, and polymorphic maps.
- **Why**: It makes the archive closed/reviewable, blocks newly added sensitive columns from leaking by default,
  and makes MySQL/SQLite behavior deterministic.

### Empty-account, preflight-gated restore

- **Decision**: Validation performs no writes and returns a 10-minute HMAC token bound to target user, archive
  digest, schema, and expiry. Restore re-parses the same upload, requires `RESTORE`, locks/rechecks target
  emptiness, then inserts in one transaction.
- **Why**: Stateless validation avoids retaining private uploads. Empty-only restore avoids undefined merge,
  overwrite, duplicate, and immutable-ledger semantics.

### Direct trusted reconstruction with compensation

- **Decision**: After complete closed-schema validation, use ordered Query Builder inserts with target ownership,
  new IDs, and deferred nullable updates. Write regenerated private paths during the transaction and remove every
  written path if any operation fails.
- **Why**: Replaying public endpoints would regenerate schedules, recalculate snapshots, and reject immutable
  historical facts; raw restoration preserves them while database constraints and preflight protect invariants.

### Derived/runtime exclusions

- **Decision**: Exclude auth identity/secrets, invitations, sessions/tokens, framework cache/jobs, public catalog
  rows, and `notifications` delivery rows. Include display name, Profile, notification settings, all domain rows,
  immutable facts/snapshots, planned occurrences, and private attachments.
- **Why**: The exclusions are unsafe to transplant or rebuildable runtime state. Notification source sync can
  regenerate deliveries from restored authoritative occurrences/settings.

### Synchronous bounded operation

- **Decision**: Build/parse a temporary ZIP per request with 100,000-record, 100-attachment, 64-MiB JSON,
  5-MiB attachment, 110-member, and 256-MiB uncompressed ceilings.
- **Why**: Current per-user attachment quota is 100 MiB. Bounded synchronous work is the smallest complete slice;
  persisted jobs/download URLs would introduce cleanup, encryption, and retention state not yet required.

## Rejected Alternatives

- **Database dump**: installation-specific, includes secrets/internal IDs, unsafe across ownership boundaries.
- **One giant JSON/base64 file**: expands blobs and prevents per-member streaming/checksums.
- **Restore into populated accounts**: requires merge and destructive conflict policy beyond this feature.
- **Reuse deployment backup/recovery**: explicitly forbidden and serves operator/system recovery, not user data.
- **Browser-generated PDF**: couples correctness to viewport/font/browser and complicates Android downloads.
- **Persist uploaded archives or restore jobs**: adds sensitive retention and cleanup without a current need.
