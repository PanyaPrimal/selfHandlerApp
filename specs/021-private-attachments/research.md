# Research: Private Attachments with First Consumers

**Date**: 2026-08-14

## Inputs Reviewed

- Canonical roadmap, Attachments design, Data Conventions, Body/Profile, Nutrition, LLM/privacy,
  Android/mobile, and Constitution 1.2.0 documents
- Delivered features 007 and 016 specifications, migrations, BodyMeasurement/Meal models, APIs,
  resources, shared-client surfaces, tests, and query patterns
- Current Laravel private filesystem configuration and authentication/mobile bearer transport
- Official Capacitor 8.1+ Camera, File Transfer, Filesystem, and HTTP contracts

## Decisions

### R-001 — Both named consumers ship now

**Decision**: support photos on BodyMeasurement and Meal, not merely the roadmap minimum of one consumer.
The supported polymorphic aliases are `body_measurement` and `meal`; unknown model names are never accepted.

**Why**: the roadmap user outcome names both body-progress and meal photos. Completing both proves that the
mechanism is genuinely cross-cutting while retaining a small, explicit allowlist.

**Rejected**: a body-only increment or arbitrary class-name input. The former leaves the promised outcome
half-delivered; the latter exposes unsafe polymorphic construction and undefined lifecycle rules.

### R-002 — Private authenticated proxy is the only content route

**Decision**: content streams through authenticated `/api/attachments/{id}/content`; resources expose a
relative content endpoint, never disk/path or a public/signed filesystem URL.

**Why**: the same owner check works for cookie-authenticated web and bearer-authenticated Android, and no
shareable URL outlives the session. Provider-specific temporary URLs remain an internal future option.

**Rejected**: `storage:link`, public paths, embedded base64, or persistent signed URLs.

### R-003 — One private disk abstraction owns all physical I/O

**Decision**: `FileStorage` resolves a configurable logical disk whose default is existing private local
storage. It stores, opens, measures, checks, and deletes opaque relative paths; callers never access a
filesystem path or driver directly.

**Why**: this follows the locked design and permits a later S3/MinIO driver swap without changing domain,
controller, or client contracts.

### R-004 — Normalize every accepted image server-side

**Decision**: trust detected magic bytes plus successful decode, accept JPEG/PNG/WebP only, reject source
files over 5 MiB or unsafe decoded dimensions, apply EXIF orientation, resize within 2560×2560, and re-encode
the same format at JPEG/WebP quality 85 or PNG compression 6. The result is decoded again before storage.

**Why**: re-encoding strips EXIF/GPS and most container surprises, bounds later download cost, preserves
PNG/WebP transparency, and makes stored metadata match controlled bytes.

**Rejected**: copying the original, trusting MIME/extension, accepting SVG/GIF, or client-only compression.
Client Camera resizing is an efficiency hint, not a security boundary.

### R-005 — Quota is stored bytes under serialized owner/parent locks

**Decision**: allow ten photos per parent and 100 MiB of normalized bytes per owner. Upload locks the owner
and parent rows, resolves idempotency, calculates committed count/sum, and commits only if the normalized
result fits. The source 5 MiB limit is checked before image processing.

**Why**: charging controlled stored bytes reflects actual cost. Locking stable existing rows works before an
Attachment exists and prevents concurrent final-slot/final-byte races on MySQL and SQLite tests.

**Rejected**: count-only quota, source-size accounting, or aggregate counters that can drift.

### R-006 — Stable upload identity gives safe retries, not global deduplication

**Decision**: require `upload_key`; uniqueness is `(user_id, upload_key)`. Replay succeeds only when the
existing parent alias/id and normalized SHA-256 match; otherwise it returns conflict. Paths use owner id plus
a fresh UUID and safe format extension rather than content hashes.

**Why**: native/network retries become exactly-once without making identical sensitive photos share paths,
ownership, lifecycle, or timing signals.

### R-007 — Store file before row and compensate every failure

**Decision**: normalize into a request-scoped temporary file, lock/validate in a database transaction,
write the final private file, create metadata, then delete the temp file. Any row/storage exception deletes
the attempted final file; explicit attachment deletion removes bytes first and metadata second.

**Why**: a metadata row must never claim safely persisted content that was not written. A deletion I/O
failure remains retryable because metadata is retained. A missing file counts as already deleted.

### R-008 — Parent and user deletion use one cleanup observer/service

**Decision**: Attachment cleanup is invoked synchronously from registered observers for BodyMeasurement,
Meal, and User `deleting` events. Parent deletion deletes attachments before the parent transaction proceeds;
user deletion purges any remaining supported attachment rows/files before database cascades.

**Why**: database cascades cannot delete private filesystem bytes. Registered observers keep I/O out of
domain models and reuse the same failure semantics as explicit deletion.

### R-009 — Parent responses are additive and eager-loaded

**Decision**: Body measurement collections and Meal resources add oldest-first `attachments`; services/
controllers eager-load them in a bounded number of queries. Attachment writes return one closed resource,
and clients refresh the parent after changes.

**Why**: no generic list endpoint or second attachment state store is needed; each consumer stays the source
of display context while query behavior remains predictable.

### R-010 — Browser and native use different byte adapters behind one client API

**Decision**: browser uses `FormData` for upload and authenticated `fetch(...).blob()` plus revocable object
URLs for preview. Native uses Camera 8.1 `takePhoto`/`chooseFromGallery`, streams the returned `uri` via File
Transfer multipart with Bearer headers/query metadata, and downloads previews into a Filesystem cache path.
The cache path becomes a WebView-safe URI and is deleted on disposal.

**Why**: official Capacitor HTTP documentation limits native complex binary request bodies, while File
Transfer explicitly supports path uploads, multipart `fileKey`, headers/query params, response bodies, and
file downloads. This avoids full-image JavaScript base64 and preserves the online-only boundary.

### R-011 — Cancellation/restoration never implies an offline queue

**Decision**: camera/gallery cancel is a neutral no-op. A restored Camera activity result may be presented
to the active UI for explicit upload, but the app never uploads in background and never persists an upload
queue. Preview cache is best-effort cleaned on lifecycle disposal and on delete.

**Why**: this is honest about the canonical MVP boundary and prevents private photos from becoming hidden
app storage or surprise network traffic.

### R-012 — Additive schema and dependencies only

**Decision**: one reversible 021 migration creates `attachments`; existing Body/Nutrition columns and facts
are untouched. Add compatible Camera 8.x, Filesystem 8.x, and independently versioned File Transfer 2.x to
the web build and mobile native shell; no other dependency or platform capability changes.

**Why**: these three official plugins are the minimum native camera/URI transfer/cache mechanism. Android's
system Photo Picker avoids broad gallery permissions; save-to-gallery is not requested.

## Explicit Deferrals

Image/meal recognition, nutrition inference, AI consent/provider transfer, receipt and GPX parsing,
documents/videos, generic consumer registration, thumbnails/derivatives, editing/cropping, cross-upload
deduplication, public sharing, S3/MinIO rollout, antivirus service, offline upload queue/background transfer,
deployment, workflows, containers, and live data.
