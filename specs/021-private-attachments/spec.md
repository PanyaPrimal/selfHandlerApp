# Feature Specification: Private Attachments with First Consumers

**Feature ID**: `021-private-attachments`

**Created**: 2026-08-14

**Status**: Complete

**Input**: Deliver the canonical private Attachment mechanism and its first complete consumers. A user
can add, privately view, and remove progress photos from body measurements and meal photos from Nutrition
on the shared web/Android client. Storage, access, cleanup, quotas, retries, ownership, privacy, and
localization are complete. Image recognition, receipt parsing, GPX, document/video support, offline upload
queues, AI processing, deployment, and any public media URL remain outside this increment.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Keep a Body Progress Photo (Priority: P1)

The user adds a photo to an existing body measurement so the visual record stays next to the dated body
fact. The server removes camera metadata, stores only a safe normalized image, and returns an attachment
summary without exposing its physical location.

**Independent Test**: create a measurement, upload each accepted image format, read the measurement,
inspect the normalized file and metadata, retry the same upload, and reject malformed, oversized, foreign,
or unsupported input without changing the measurement.

**Acceptance Scenarios**:

1. **Given** an owned body measurement, **When** a valid photo is uploaded, **Then** one private attachment
   is returned on that measurement and no public or physical path is disclosed.
2. **Given** a camera image containing orientation or EXIF metadata, **When** it is accepted, **Then** the
   stored image is visibly upright, bounded, compressed, and contains no original metadata.
3. **Given** the same stable upload identity and payload, **When** delivery is retried, **Then** the same
   attachment is returned without storing or charging quota twice.
4. **Given** a foreign or absent measurement, **When** upload is attempted, **Then** it behaves as though
   the measurement does not exist and no file or metadata row remains.

---

### User Story 2 - Keep a Meal Photo (Priority: P1)

The user adds a dish photo to an existing meal and sees it in that meal's Nutrition context. The image is
evidence only: it does not infer food, quantities, macros, or healthiness and does not change meal facts.

**Independent Test**: create meals across dates and owners, upload/view/delete photos, fetch day and meal
responses, and prove Nutrition totals and entries remain identical.

**Acceptance Scenarios**:

1. **Given** an owned meal, **When** a valid dish photo is uploaded, **Then** that meal exposes the ordered
   attachment summary through its existing Nutrition reads.
2. **Given** a meal photo, **When** Nutrition totals are recalculated, **Then** entries, macros, hydration,
   estimates, and quality are unchanged.
3. **Given** a deleted meal, **When** cleanup completes, **Then** its attachment records and private files
   are gone without affecting another meal or measurement.
4. **Given** a foreign meal identity, **When** it is used for upload, **Then** the caller learns nothing
   about that meal and consumes no quota.

---

### User Story 3 - View and Remove a Private Photo Safely (Priority: P1)

The user can view a photo only while authenticated as its owner and can explicitly remove it. Browser and
Android retrieval never depend on a public media directory, stable public URL, or filesystem knowledge.

**Independent Test**: request content and deletion as owner, another authenticated user, and anonymous
caller; inspect response headers and bytes; delete repeatedly; and verify database/file consistency.

**Acceptance Scenarios**:

1. **Given** an owned attachment, **When** its content is requested, **Then** the exact normalized bytes
   stream with the safe image type and private, no-store, nosniff response controls.
2. **Given** another user or no authentication, **When** content or delete is requested, **Then** no private
   byte, name, metadata, existence signal, or storage path is disclosed.
3. **Given** an owned attachment, **When** it is deleted, **Then** both metadata and physical bytes are
   removed and its parent no longer lists it.
4. **Given** missing bytes for an otherwise owned metadata row, **When** it is viewed or deleted, **Then**
   viewing fails safely and deletion still repairs the orphaned metadata.

---

### User Story 4 - Stay Within Predictable Storage Limits (Priority: P1)

Uploads are bounded per file, per body measurement or meal, and per user. Concurrent uploads cannot exceed
those limits, and rejected or failed attempts do not leak temporary files or consume quota.

**Independent Test**: fill every boundary exactly, exceed it by one byte/photo, race two final uploads,
force storage/database failures, delete and upload again, and verify exact usage plus disk contents.

**Acceptance Scenarios**:

1. **Given** an image at the maximum accepted size and an owner with room, **When** it is uploaded, **Then**
   post-normalization bytes are charged exactly once and reported usage remains within quota.
2. **Given** a parent or user at its limit, **When** another upload is attempted concurrently or serially,
   **Then** the complete upload is rejected and neither a row nor an orphan file remains.
3. **Given** an attachment is deleted with a successfully removed file, **When** usage is read again,
   **Then** that attachment's stored bytes are immediately available for a later upload.
4. **Given** storage or persistence fails after processing starts, **When** the operation ends, **Then** all
   temporary/final bytes created by that attempt are removed and the database remains unchanged.

---

### User Story 5 - Use Photos on the Shared Browser and Android Client (Priority: P2)

The user can choose an image in a browser and, on Android, explicitly take a photo or choose one from the
gallery. Upload, progress/busy feedback, private preview, accessible deletion, quota feedback, and retry
work in English, Russian, and Ukrainian at desktop and exact mobile width.

**Independent Test**: complete body and meal photo journeys in every locale/theme/viewport, exercise the
native camera/gallery and transfer adapters, retry failures, leave each surface, and verify temporary
preview data is released.

**Acceptance Scenarios**:

1. **Given** the browser client, **When** a supported image is selected, **Then** it is uploaded online,
   appears privately, and the same input can be selected again after completion.
2. **Given** the Android client, **When** the user chooses camera or gallery, **Then** the full image URI is
   transferred without base64 expansion and the refreshed parent contains the returned attachment.
3. **Given** a private preview on Android, **When** its surface is left or the attachment is removed, **Then**
   the temporary local preview file is deleted and is not an offline photo library.
4. **Given** any supported locale, scheme, or viewport, **When** upload, failure, empty, loading, preview,
   quota, or delete states appear, **Then** copy and accessible labels are localized and controls fit with
   no overlap or horizontal overflow.

## Edge Cases

- A file extension claims JPEG while magic bytes are PNG, or the payload is text/SVG/polyglot data.
- A decoded image has enormous dimensions, invalid/truncated bytes, transparent pixels, or unsupported
  server codec despite an otherwise accepted MIME declaration.
- An EXIF orientation requires rotation or mirroring, including orientations 2 through 8.
- Normalization makes an image larger than the source or exceeds the configured stored-file ceiling.
- Two concurrent uploads use the same stable upload identity with different payloads or different parents.
- A parent is deleted between ownership validation and attachment persistence.
- A user deletion cascades metadata while private files still require physical cleanup.
- The disk is unavailable during store, stream, or delete; a database delete must not silently orphan bytes.
- An attachment row refers to a disallowed attachable type, missing parent, foreign owner, or missing file.
- The mobile camera activity is interrupted and later restores a result; no background/offline upload is
  claimed, and the user must explicitly complete an online upload.
- Authentication expires while a native upload or preview download is in flight.
- A screen refresh occurs while object URLs or native cache files are being prepared or disposed.

## Functional Requirements

- **FR-001**: The system MUST maintain one owner-scoped polymorphic Attachment entity rather than adding
  consumer-specific path or photo columns.
- **FR-002**: Attachment metadata MUST record owner, supported parent identity, logical disk/path, safe
  original name, detected MIME, stored byte size, kind, safe image dimensions, content digest, stable
  upload identity, and creation time without storing file bytes in the database.
- **FR-003**: Feature 021 MUST accept only `photo` attachments on owned BodyMeasurement and Meal parents;
  every other parent/kind MUST be rejected as outside scope.
- **FR-004**: Accepted input MUST be a decodable JPEG, PNG, or WebP whose detected content matches the
  allowed image set; extension and client MIME alone MUST NOT establish trust.
- **FR-005**: Each source upload MUST be no larger than 5 MiB and its decoded dimensions MUST be bounded
  before full processing so decompression bombs are rejected.
- **FR-006**: Accepted images MUST be auto-oriented, resized within 2560×2560 without enlargement,
  re-encoded at a documented quality, and stripped of EXIF/location/embedded source metadata.
- **FR-007**: The normalized image MUST retain transparency where the accepted format supports it and MUST
  remain a valid image whose stored MIME, extension, dimensions, digest, and byte count match its bytes.
- **FR-008**: Physical paths MUST be unguessable, owner-partitioned logical paths and MUST never appear in
  API resources, logs intended for users, DOM attributes, validation payloads, or public storage.
- **FR-009**: The configured attachment disk MUST be private and accessed only through one FileStorage
  abstraction; direct public filesystem URLs MUST NOT be created.
- **FR-010**: Upload MUST require one stable 1–100 character client identity. A retry with the same owner,
  parent, identity, and normalized content MUST return the original attachment; any mismatch MUST conflict.
- **FR-011**: A BodyMeasurement or Meal MUST hold at most 10 attachments and an owner MUST hold at most
  100 MiB of normalized attachment bytes.
- **FR-012**: Parent-count and owner-byte quota checks MUST be serialized with the relevant owned rows so
  concurrent uploads cannot commit beyond either limit.
- **FR-013**: Rejected validation, ownership, processing, quota, storage, or persistence attempts MUST leave
  no attachment row, final file, or temporary file from that attempt.
- **FR-014**: Every content read and deletion MUST re-establish authenticated ownership through both the
  Attachment row and its current supported parent; foreign identities MUST be 404-equivalent.
- **FR-015**: Private content MUST stream through the authenticated API with correct normalized Content-Type
  and Length plus `private, no-store`, `nosniff`, restrictive content disposition, and sandbox policy.
- **FR-016**: Attachment resources MUST expose only public identity, kind, safe display name, MIME, stored
  size, width, height, created time, and an authenticated relative content endpoint.
- **FR-017**: Explicit deletion MUST remove physical bytes before deleting metadata; missing bytes are
  treated as already removed, while a disk failure preserves metadata for retry.
- **FR-018**: Hard deletion of a BodyMeasurement, Meal, or User MUST remove every owned attachment file and
  metadata row without touching another owner's or parent's files.
- **FR-019**: Parent reads MUST eager-load attachment summaries in deterministic oldest-first order and
  MUST stay within fixed query budgets rather than querying once per parent.
- **FR-020**: Adding or deleting a meal photo MUST NOT change Meal entries, immutable Nutrition snapshots,
  macros, water, estimate state, quality, day totals, or summaries.
- **FR-021**: Adding or deleting a body photo MUST NOT change measurement metric/date/value/note, body trend,
  body-goal progress, or latest-measurement semantics.
- **FR-022**: Browser upload MUST use multipart binary transfer and browser preview MUST use authenticated
  binary retrieval with revocable temporary object URLs.
- **FR-023**: Native upload MUST transfer the selected full-resolution URI as multipart binary with auth and
  idempotency metadata; it MUST NOT load the full photo into JavaScript base64.
- **FR-024**: Native preview MUST download authenticated bytes to an app-cache path, expose only a temporary
  WebView-safe URI, and delete that cache file when released or after attachment deletion.
- **FR-025**: Android MUST expose separate camera and gallery actions and preserve the online-only boundary;
  cancellation or activity restoration MUST not silently enqueue or upload a photo.
- **FR-026**: The shared client MUST provide preview, upload busy state, retryable error, localized quota/type/
  size feedback, and an explicit destructive delete confirmation on Body and Nutrition surfaces.
- **FR-027**: Every photo control and preview MUST be keyboard/screen-reader usable with visible focus,
  descriptive localized alternative text, live feedback, and at least 44×44 px touch targets.
- **FR-028**: New UI and error copy MUST exist with key parity in English, Russian, and Ukrainian and fit
  desktop plus exact 390×844 mobile layouts in both supported color schemes.
- **FR-029**: The public contract MUST use closed request/resource objects, authenticated operations,
  bounded strings/numbers, explicit error variants, and additive parent response members.
- **FR-030**: The attachment schema and parent response changes MUST be additive and reversible while all
  existing users, measurements, meals, entries, trends, summaries, and routes remain compatible.
- **FR-031**: Operations MUST avoid logging request bodies, image bytes, physical paths, auth tokens, EXIF,
  or private content; safe failures MAY log opaque attachment/owner identifiers and exception class.
- **FR-032**: Image recognition, macro inference, health inference, AI/file-provider transfer, receipt/GPX
  parsing, generic document/video consumers, offline upload queues, public sharing, and deployment MUST NOT
  be introduced by this feature.

## Success Criteria *(mandatory)*

- **SC-001**: All four body-photo upload scenarios and all four meal-photo scenarios pass with identical
  pre/post parent facts and aggregates except for additive attachment summaries.
- **SC-002**: Owner, foreign-user, and anonymous content/delete matrices disclose private bytes and metadata
  only to the owner, with zero public media route or physical path in API/client output.
- **SC-003**: JPEG, PNG, and WebP fixtures are normalized within 2560×2560; orientation fixtures are upright;
  EXIF/location marker scans find zero retained source metadata.
- **SC-004**: Exact 5 MiB, 10-per-parent, and 100 MiB-per-owner boundaries pass, while each boundary +1 and
  a two-request race fail atomically with zero leaked rows/files.
- **SC-005**: Idempotent retry returns one identity and one quota charge; conflicting reuse returns one
  deterministic conflict with no mutation.
- **SC-006**: Parent/user delete, missing-file repair, and forced disk/database failure tests leave database
  and private disk in the specified consistent/retryable state.
- **SC-007**: Attachment-enriched body and Nutrition collections stay within their fixed query budgets for
  at least 20 parents, with no per-parent attachment query.
- **SC-008**: The closed 021 API contract parses, all references resolve, every new operation is authenticated,
  and route parity plus prior body/Nutrition contracts pass.
- **SC-009**: EN/RU/UK key parity, used-key/hardcoded-copy checks, typecheck, unit tests, and production build
  all pass with no untranslated attachment state.
- **SC-010**: Desktop and exact 390×844 browser journeys plus inspected locale/scheme screenshots show no
  clipping, overlap, inaccessible control, private-path leak, or horizontal overflow.
- **SC-011**: Mobile adapter tests prove URI streaming and cache cleanup without full-image base64; Android
  synchronization discovers Camera, Filesystem, and File Transfer alongside existing plugins.
- **SC-012**: Full backend/browser regressions, migration rollback/reapply, dependency audits, safety scans,
  protected-path audit, handoff integrity, and refreshed staged impact analysis all pass before atomic push.

## Assumptions

- BodyMeasurement and Meal are the two complete first consumers; broader polymorphic support is deliberately
  denied until its owning feature defines kind, quota, and lifecycle semantics.
- Limits are 5 MiB source bytes, 10 photos per parent, 100 MiB normalized bytes per owner, and 2560×2560
  normalized dimensions. These bounded MVP defaults are configurable without weakening server enforcement.
- JPEG quality 85, PNG compression level 6, and WebP quality 85 balance legibility and private storage cost.
- The owner must be online for upload and preview. Native cache files are transport artifacts, not an
  offline gallery or upload queue.
- The authenticated proxy is the sole retrieval mechanism in 021; expiring external-provider URLs can be
  added later behind the same storage abstraction.

## Dependencies and Explicit Exclusions

- Depends on delivered owner-scoped Body measurements (007), Nutrition meals (016), authentication,
  Profile locale, shared client, and Android shell.
- Does not alter deployment, workflows, containers, feature 002, live data, or the untracked design handoff.
- Defers recognition, AI, receipts, GPX, documents, videos, deduplication across upload identities, previews/
  thumbnails, edits/crops, public sharing, object storage rollout, malware services, and native offline queues.
