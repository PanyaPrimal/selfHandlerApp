# SelfHandler — Attachments

> A cross-cutting mechanism for attaching files (photos/documents/tracks) to any domain record. One mechanism for the whole app — not a `photo_path` column in every module.
>
> Related: [Data Conventions](data-conventions.md) (disk abstraction, user_id) · decisions: [Decisions Log](decisions.md)

---

## Why It Exists and Who the Consumers Are

| Module | What we attach | Why |
|--------|------------------|-------|
| 0 Profile / body measurements | Body progress photos (before/after) | result marker |
| 2 Nutrition | Meal photo | photo recognition → components + weight (see [Modules Spec](modules.md)) |
| 3 Workouts / running | GPS track (GPX), photos | geo/route (usually from integrations) |
| 7 Storage | Images for ideas, documents for tasks | context |
| 10 Finance | Receipt photo | spending proof |
| 11 AI | Photo as agent input | meal/receipt breakdown |

Without a single mechanism, every module ends up with its own upload/preview/cleanup/storage — duplication.

---

## Decisions (locked in 2026-06-13)

- **Storage — local disk + disk abstraction** (Laravel Filesystem, `local` driver). Files live on the homelab server; switching to S3/MinIO later is a driver swap, no code rewrite. NOT a BLOB in the DB (bloats the database, hurts performance).
- **Polymorphic association** — a single attachment can hook onto any entity.

---

## The `Attachment` Entity

- `id`, `user_id`
- **Polymorphic association** `attachable_type` + `attachable_id` — what it's attached to (measurement / meal / workout / idea / transaction / …)
- `disk` (Laravel disk name: local/s3) + `path` (path on the disk) — do NOT hardcode an absolute path
- `original_name`, `mime`, `size`
- `kind` (optional): photo / document / track / receipt — for UI and logic (a track ≠ a photo)
- `meta` (JSON, optional): image dimensions, geo, recognition results (for the nutrition photo feature)
- `created_at`

> The file lives physically on disk; the DB holds only metadata and the path. Download/preview goes through the `FileStorage` service (see below), never direct filesystem access.

---

## `FileStorage` — The Single Service

- A wrapper over Laravel Filesystem: `store(file, attachable, kind)` / `url(attachment)` / `delete(attachment)` / `stream(attachment)`
- **Disk abstraction:** the code works with a logical disk (`config('filesystems.default')`), not with paths. Switching local→S3 = a config change.
- **Inbound validation:** allowed mime types/sizes (photo vs document vs gpx), limits.
- **Previews/thumbnails** for images (generated on upload or on demand) — optional, later.
- **Cleanup:** when a domain record is deleted, remove the associated attachments (from disk too). The policy is to be aligned with [Data Conventions](data-conventions.md) (a soft-deleted record → keep the file until final cleanup).

---

## Capacitor / Mobile Scenario

- The photo source on mobile is the **camera/gallery** (Capacitor Camera plugin) → uploaded to the backend via the API.
- ⚠️ **Offline:** a photo taken with no connectivity (gym, outdoors) → a local upload queue, delivered once the network is back. Tied to the broader offline open question (see backlog review). For the MVP, explicitly online-only; the queue comes later.
- Client-side compression before upload (photos are heavy) — desirable.

---

## Security / Privacy

- Files are **private** (no public URL): access via signed URLs / proxying through the backend with a `user_id` check.
- Especially: body photos and receipts are sensitive. Do not serve them via a direct link.
- Multi-user readiness: an attachment is scoped by `user_id` ([Data Conventions](data-conventions.md)).

---

## Responsibility Boundaries

- An **attachment** = file + metadata + association. It carries no domain logic.
- **What to do with the photo** (recognize a meal, parse a receipt) lives in the owning module or [Modules Spec](modules.md). Attachment only stores and serves the file.
- A GPS track as a file (GPX) is stored here; parsing the track into running metrics is Module 3 / integrations.

---

## Open Questions

1. Previews/thumbnails — generate on upload (more disk) vs on the fly (more CPU).
2. Offline upload queue on Capacitor — in which release (online-only for the MVP).
3. Storage limits (the homelab disk is finite) — quota / cleanup of old attachments.
4. Deduplication of identical files (hash) — is it needed.
