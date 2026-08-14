# Feature Specification: Data Portability and Reports

**Feature Branch**: existing user branch

**Created**: 2026-08-14

**Status**: Draft

**Input**: Roadmap feature 024 and the export, ownership, aggregate, attachment, and data-convention
boundaries in `docs/design/`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Share a Human-Readable Analytics Report (Priority: P1)

As a user, I can download the currently selected Analytics trend as CSV or PDF, so I can inspect it
outside SelfHandler or share a bounded report with a doctor or coach.

**Independent Test**: Select a supported metric/range/granularity, download both formats, and verify the
localized metadata, exact values, evidence states, period comparison, filename, and private download headers.

**Acceptance Scenarios**:

1. **Given** a valid Analytics selection, **When** CSV is downloaded, **Then** it contains UTF-8 localized
   report metadata and one ordered row per exact interval, including value, evidence count, state, and reason.
2. **Given** the same selection, **When** PDF is downloaded, **Then** it is a valid A4 PDF with the same metric,
   exact range, summary, interval rows, evidence limitations, and optional comparison in the Profile locale.
3. **Given** missing, incomplete, zero, or missing-FX evidence, **When** either report is generated, **Then** it
   preserves that distinction and never invents, interpolates, or silently drops a value.
4. **Given** another or anonymous user, **When** the report route is requested, **Then** no owner data is exposed.

---

### User Story 2 - Download a Complete Portable Backup (Priority: P1)

As a user, I can download a versioned machine-readable backup of my authoritative SelfHandler data and
private attachments, so the data is not trapped in the application.

**Independent Test**: Populate every supported owner table and both attachment parent types, export the ZIP,
and verify the closed manifest, records, portable references, file checksums, exclusions, and absence of auth
secrets, server paths, foreign-owner data, and embedded attachment blobs.

**Acceptance Scenarios**:

1. **Given** owned data across modules, **When** a backup is downloaded, **Then** the ZIP contains schema-v1
   `manifest.json`, `data/profile.json`, `data/records.json`, and checksum-addressed attachment members.
2. **Given** records reference each other, public food/exercise catalog entries, or polymorphic owners,
   **When** exported, **Then** references use portable record IDs or stable system keys, never database IDs.
3. **Given** private attachments exist, **When** exported, **Then** their bytes are separate ZIP members listed
   in the manifest with parent reference, MIME, size, dimensions, safe filename, and SHA-256.
4. **Given** account credentials, sessions, tokens, invitations, queues, caches, and derived notification
   deliveries, **When** exported, **Then** they are absent and the manifest names these deliberate exclusions.

---

### User Story 3 - Verify and Restore into an Empty Account (Priority: P1)

As a user, I can validate a supported backup and explicitly restore it into my empty account, so I can prove
portability without overwriting existing data or crossing ownership boundaries.

**Independent Test**: Validate an archive from one account, restore it into a different empty account, and
compare all supported records, settings, relationships, and attachment bytes while retaining target login
credentials and newly assigned database/storage identities.

**Acceptance Scenarios**:

1. **Given** a supported intact archive, **When** validation runs, **Then** it performs no writes and returns
   schema, counts, bytes, exclusions, empty-target eligibility, issues, expiry, and a target-user/archive-bound
   restore token.
2. **Given** a valid token, identical archive, exact confirmation phrase, and empty target, **When** restore is
   submitted, **Then** all supported data is recreated atomically with target ownership and remapped references.
3. **Given** any existing domain record, unsupported schema, malformed JSON, undeclared/duplicate/path-traversal
   ZIP member, checksum/MIME/size mismatch, dangling reference, expired/wrong-user token, or wrong confirmation,
   **When** restore is attempted, **Then** it fails without database rows or private files left behind.
4. **Given** source name/Profile preferences in the backup, **When** restored, **Then** those product settings
   replace target defaults while email, password, verification, sessions, tokens, and account identity do not.

---

### User Story 4 - Use Portability Accessibly Across Clients (Priority: P2)

As a user, I can understand report and backup risks, progress, results, and recovery actions in English,
Russian, or Ukrainian on desktop, exact-phone, and the synchronized Android shell.

**Independent Test**: Exercise downloads, file selection, validation, confirmation, failure/retry, and success
in all locales and schemes at desktop and 390x844, then verify shared Android bundle parity.

**Acceptance Scenarios**:

1. **Given** the Data page, **When** used by keyboard or assistive technology, **Then** controls have names,
   visible focus, status announcements, 44 px targets, and no hidden horizontal overflow.
2. **Given** an invalid or non-empty restore target, **When** validation finishes, **Then** localized actionable
   issues are visible before confirmation becomes available.
3. **Given** a network/download failure, **When** the user retries, **Then** prior account data is unchanged and
   the operation can be attempted again without stale success state.

### Edge Cases

- CSV fields contain commas, quotes, newlines, Unicode, formulas, or leading spreadsheet control characters.
- PDF text contains Cyrillic, long missing-FX reasons, many interval rows, or no available values.
- An attachment is missing or changes between metadata read and archive assembly.
- ZIP input has duplicate names, absolute/backslash/traversal paths, symlinks, undeclared members, compression
  bombs, too many entries, oversized JSON, invalid UTF-8, duplicate portable IDs, or trailing JSON fields.
- A public catalog system key is missing on the restore target.
- Self-references and occurrence/fact mirror cycles require deferred reference updates.
- Validation succeeds and the target gains data before restore; restore must re-check emptiness under lock.
- File writing or a database insert fails midway; compensating cleanup leaves neither rows nor restored files.
- The source and target account IDs, emails, attachment paths, and auto-increment sequences differ.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST provide authenticated CSV and PDF downloads for the same metric, inclusive range,
  granularity, and comparison query accepted by the feature 023 Analytics workspace.
- **FR-002**: Reports MUST consume the existing `AnalyticsWorkspaceService` output and MUST NOT query raw module
  tables or recalculate owner formulas.
- **FR-003**: CSV MUST be UTF-8 with BOM and RFC 4180 quoting; cells beginning with `=`, `+`, `-`, `@`, tab, or
  carriage return MUST be neutralized against spreadsheet formula execution without changing numeric columns.
- **FR-004**: PDF MUST be a real A4 PDF with embedded Cyrillic-capable font support and bounded, escaped,
  dependency-local HTML; it MUST NOT load remote resources or execute scripts.
- **FR-005**: Both reports MUST include generated time, metric/aggregation/unit, exact selected range,
  granularity, evidence meaning, ordered points, summary, missing reasons, and enabled comparison evidence.
- **FR-006**: Report filenames and all human labels/states/reasons MUST follow the authenticated Profile locale;
  machine values remain canonical and user-authored content is not translated.
- **FR-007**: Report and backup responses MUST use private/no-store/nosniff download headers and attachment-safe
  filenames; anonymous and foreign-owner access MUST be rejected.
- **FR-008**: Backup MUST be a ZIP with top-level schema-v1 manifest and separate JSON profile/record members;
  attachment bytes MUST be members referenced by the manifest and MUST NOT be base64/blob fields in JSON.
- **FR-009**: Backup schema version 1 MUST be closed, documented, deterministic in table/record order, and use
  portable IDs/references instead of database primary keys, user IDs, or private storage paths.
- **FR-010**: Backup MUST include target-portable account display name, full user Profile, notification
  settings, every authoritative owner-scoped domain row from delivered modules 001 and 005-023, and every
  supported private Body/Meal attachment.
- **FR-011**: Backup MUST exclude email, password, verification state, remember/session/access tokens,
  invitations, cache/queue/job state, public catalog rows, and rebuildable notification deliveries; exclusions
  MUST be explicit in the manifest and documentation.
- **FR-012**: Public Food/Exercise references MUST use existing non-null stable `system_key`; missing public
  references MUST invalidate restore rather than bind to a numeric ID or similarly named row.
- **FR-013**: Polymorphic recurring owners, Finance source links, and attachment parents MUST use closed alias
  maps whose referenced row is present and owned in the archive.
- **FR-014**: Every manifest member MUST have exact normalized path, role, byte size, and lowercase SHA-256;
  attachment entries MUST also declare portable parent, MIME, dimensions, safe original filename, and kind.
- **FR-015**: Export MUST fail safely if an owned attachment byte stream is missing, unreadable, different in
  size/hash, or no longer matches its immutable metadata.
- **FR-016**: Restore validation MUST be read-only and MUST validate archive/member bounds, paths, duplicates,
  closed JSON schemas, UTF-8, version, hashes, counts, allowed tables/columns/types, unique IDs, all references,
  public keys, attachment signatures/dimensions/quotas, and target emptiness.
- **FR-017**: Validation MUST return a signed, short-lived token bound to target user, archive SHA-256, schema
  version, and expiry only when the archive is valid and target is eligible; no uploaded archive is retained.
- **FR-018**: Restore MUST require the identical archive, valid token, literal documented confirmation, and an
  empty target rechecked while locking the target user; validation and restore uploads are independently parsed.
- **FR-019**: An empty target means no rows in any authoritative/domain owner table, attachments, or notification
  deliveries; existing Profile and singleton notification settings are defaults that MAY be replaced.
- **FR-020**: Restore MUST retain target account identity/authentication, assign target `user_id` to every row,
  allocate new database IDs and opaque file paths, and remap every internal/system/polymorphic reference.
- **FR-021**: Database restore MUST be one transaction; file writes MUST use compensating cleanup on every
  failure. Success MUST return restored table/record/attachment counts and archive SHA-256.
- **FR-022**: Restore MUST preserve canonical decimal strings, JSON values, UTC timestamps, date-only values,
  domain statuses, archive/deletion state, immutable facts, idempotency keys, and historical snapshots.
- **FR-023**: Archive processing MUST be synchronous and bounded to 100,000 records, 100 attachment members,
  64 MiB per JSON member, 5 MiB per attachment, and 256 MiB total uncompressed bytes; excess fails closed.
- **FR-024**: ZIP validation MUST reject symlinks, encryption, unsupported compression, directory entries,
  undeclared members, duplicate normalized names, more than 110 members, and any unsafe path.
- **FR-025**: The web MUST add report actions to Analytics and an authenticated `/settings/data` workspace for
  backup download, local archive selection, preflight summary, exact confirmation, restore, retry, and success.
- **FR-026**: The UI MUST never enable restore before successful validation and confirmation; changing the file
  MUST clear its token/summary/confirmation, and any API failure MUST clear stale success.
- **FR-027**: All visible and assistive copy MUST ship together in EN/RU/UK with locale-aware dates/counts/bytes,
  English fallback, dictionary parity, used-key, and hardcoded-copy gates.
- **FR-028**: Browser and exact-phone flows MUST be keyboard/assistive-technology usable with 44 px targets,
  status announcements, visible focus, no horizontal overflow, both schemes, and explicit destructive wording.
- **FR-029**: The existing shared Vue bundle MUST be synchronized to Android; downloads/uploads remain online
  browser/WebView operations with no native database, offline archive, APK, device, signing, or deployment work.
- **FR-030**: Feature 024 MUST NOT add deployment/system backups, database dumps, scheduled exports, cloud
  storage, sharing links, email delivery, arbitrary import formats, merge/overwrite restore, calendar/provider
  integration, AI summaries, or attachment consumers beyond the existing Body/Meal photo boundary.

### Localisation Surface *(mandatory)*

- **Locales**: English (`en-GB`), Russian (`ru-UA`), and Ukrainian (`uk-UA`).
- **New user text**: report actions, Data navigation/page, privacy and exclusion explanations, file chooser,
  validation/loading/eligible/ineligible states, archive metadata/counts/bytes, confirmation, errors, retry,
  restore result, and ARIA/status labels.
- **Backend report text**: report title/metadata/table headings, state/reason/aggregation/granularity labels,
  comparison wording, disclaimer, and filename stem in each Laravel locale.
- **Formatting**: Profile-locale calendar dates, UTC generated timestamp with explicit zone, locale-aware display
  numbers/counts/bytes, while CSV machine numeric values and ZIP JSON remain canonical.
- **Verification**: Backend report locale matrices, frontend dictionary gates, all-locale desktop/phone journeys,
  both schemes, PDF text extraction/Cyrillic tests, and visual screenshot inspection.

### Key Entities

- **Human Report**: Ephemeral CSV/PDF rendering of one feature-023 Analytics workspace; never restore input.
- **Backup Manifest v1**: Closed archive inventory with format/version/ID/time, members, counts, attachments,
  exclusions, bounds, and hashes; contains no database IDs or credentials.
- **Portable Record**: Table-scoped portable ID, closed attributes, and portable/system references.
- **Restore Validation**: Read-only archive/target result plus issues and short-lived signed token.
- **Restore Result**: Atomic target-owned recreation counts and archive digest.

## Success Criteria *(mandatory)*

- **SC-001**: CSV and PDF fixtures match the same Analytics workspace for ready/empty/incomplete/real-zero/
  comparison cases in all three locales; PDF opens and extracted Cyrillic text is intact.
- **SC-002**: A full-catalog round trip recreates every supported row, relationship, Profile/setting value, and
  attachment byte/checksum in another empty account while preserving its login identity.
- **SC-003**: Owner/foreign/anonymous matrices prove reports and archives contain only the requester’s data and
  a token/archive from one user cannot restore for another.
- **SC-004**: A corruption/security matrix rejects every listed ZIP/JSON/reference/file attack without writes.
- **SC-005**: Failure injection at database and file stages proves complete transaction rollback and file cleanup.
- **SC-006**: API/OpenAPI/TypeScript contracts are closed, authenticated, bounded, and consumed by matching UI.
- **SC-007**: Users complete report download and validate/restore journeys on desktop and exact 390x844 with
  keyboard, status announcements, no console/page errors, and no horizontal overflow.
- **SC-008**: EN/RU/UK light/dark screenshots are visually inspected and all localization/a11y gates pass.
- **SC-009**: Full backend/frontend/E2E/Android/audit/protected-path gates pass with no unexpected failure/skip.
- **SC-010**: No deployment, workflow, feature 002, handoff, credential, external provider, calendar, AI, or
  native-authority file is changed by the feature commit.

## Assumptions

- “Complete backup” means complete authoritative product data at schema v1, not account credentials or
  reconstructible delivery/runtime state.
- Restore is intentionally new-account recovery, not import/merge. Refusing a non-empty target is safer and
  independently useful; overwrite/merge requires separate conflict/deletion semantics.
- Upload transport limits outside the application may need operator configuration for large legitimate archives,
  but feature 024 does not modify deployment configuration.

## Dependencies and Explicit Exclusions

- **Depends on**: Profile/localisation/theme, all delivered domain modules through 022, Analytics 023,
  attachment storage 021, authenticated browser/mobile transport.
- **Delegates to**: Analytics for report facts/formulas, Profile for locale/timezone/units/currency, attachment
  storage for private bytes, each module’s current relational truth for export.
- **Defers to 025**: calendar/external integration and arbitrary provider imports.
- **Defers to 026**: AI narratives, external processing, provider credentials/consent.
- **Excluded**: deployment backup/recovery, live data, scheduled jobs, cloud/email delivery, public links,
  merge/overwrite, CSV import, background restore, new attachment kinds, native offline authority, and preserved
  generated/handoff paths.
