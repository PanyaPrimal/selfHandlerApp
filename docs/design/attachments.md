# SelfHandler — Private Attachments

> Delivered by feature `021-private-attachments` for private body-progress and meal photos.
>
> Related: [Data Conventions](data-conventions.md) · [Decisions Log](decisions.md) ·
> [Feature specification](../../specs/021-private-attachments/spec.md)

## Delivered Boundary

One owner-scoped polymorphic `Attachment` entity serves two real consumers:

| Parent | Supported attachment | Domain effect |
| --- | --- | --- |
| `BodyMeasurement` | progress photo | none; metrics, notes, trends, and goals remain unchanged |
| `Meal` | meal photo | none; entries, nutrition snapshots, hydration, and summaries remain unchanged |

Feature 021 accepts only `photo` attachments in JPEG, PNG, or WebP. It does not implement document,
receipt, GPX, recognition, macro inference, health inference, AI transfer, or public-sharing behavior.

## Data and Ownership

The database stores immutable attachment identity and metadata: owner, allowlisted parent type/id,
logical disk/path, safe display name, detected MIME and extension, normalized byte size, dimensions,
SHA-256 digest, stable client upload identity, and timestamps. Image bytes remain on the configured
private Laravel Filesystem disk rather than in the database.

Every create, content read, and deletion resolves both the authenticated owner and the owned parent.
The morph map is explicit and closed to `BodyMeasurement` and `Meal`. Resources expose a relative
authenticated content endpoint but never disk names, paths, EXIF, hashes, public URLs, or signed URLs.

## Image Pipeline and Limits

Input is accepted only after magic-byte inspection and successful image decode. Extension claims do
not establish type. The pipeline rejects malformed, unsupported, oversized, and unsafe-dimension
payloads before writing final bytes; applies EXIF orientation; resizes within 2560×2560 without
enlargement; retains PNG/WebP transparency; and re-encodes in the detected format. The normalized
result is inspected and decoded again before storage.

- Source and normalized file maximum: 5 MiB.
- Decoded source maximum: 40,000,000 pixels.
- Per-parent maximum: 10 photos.
- Per-owner normalized-byte maximum: 100 MiB.
- Encoding: JPEG/WebP quality 85; PNG compression level 6.

A user row is locked before the parent row so concurrent uploads share one lock order. The service
then checks the parent count and exact owner-byte sum. A stable client identity makes the same retry
return the existing attachment; conflicting reuse is rejected. Storage and persistence failures use
compensating cleanup.

## Private Delivery and Cleanup

Content streams only from `/api/attachments/{attachment}/content` after authentication and ownership
checks. Responses use the normalized content type plus private, no-store, and `nosniff` controls. There
is no storage symlink or direct filesystem endpoint.

Explicit deletion removes bytes before metadata. A missing file is treated as repairable deletion,
while other disk failures retain metadata and return a safe localized error. Hard deletion of a body
measurement, meal, or user cleans attachments in bounded batches. Domain deletion is aborted when
private-file cleanup cannot complete, avoiding a knowingly orphaned file.

## Browser and Android

The browser uploads multipart binary data and renders authenticated response blobs through temporary
object URLs. Android presents separate Camera and Gallery actions, transfers the returned native URI
as authenticated multipart data, downloads previews to temporary application cache, and deletes cache
artifacts after use or failure. Full images never cross the JavaScript bridge as base64.

Uploads and previews are online-only. Android process restoration may offer a recovered Camera result,
but the user must explicitly submit it after connectivity returns; there is no background or offline
upload queue.

## Deferred Extensions

Additional parent types and file kinds require a separately specified consumer, allowlist, policy,
contract, and tests. Deferred work includes thumbnails, deduplication, receipts/documents, GPX,
recognition or other inference, public/shared links, external file providers, background transfer, and
offline synchronization.
