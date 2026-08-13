# Implementation Plan: Private Attachments with First Consumers

**Branch**: current user-selected `master` only
**Date**: 2026-08-14
**Spec**: [spec.md](spec.md)

## Summary

Add one private polymorphic Attachment aggregate and FileStorage boundary, then complete body-progress and
meal-photo upload/view/delete journeys on browser and Android. Normalize images to remove metadata, enforce
serialized owner/parent quotas and idempotency, clean files on explicit/parent/user deletion, extend existing
parent responses additively, and ship the complete localized shared client without recognition, offline,
generic-file, public-link, or deployment scope.

## Technical Context

- Laravel 12 / PHP 8.4 / Eloquent / Sanctum; private Laravel Filesystem disk; GD, EXIF, Fileinfo
- MySQL 8 target with SQLite portable tests and additive reversible migrations
- Existing UserOwnedModel, BodyMeasurement, Meal/MealResource, Body and Nutrition APIs
- Vue 3 / TypeScript / Vite / typed i18n/theme and browser binary APIs
- Capacitor 8.5 shell; Camera 8.1+, Filesystem 8.x, File Transfer 8.x URI streaming/cache
- PHPUnit, Pint, Composer/npm audit, Vitest, Playwright desktop/mobile, Android sync, GitNexus

## Constitution Check

| Principle | Plan evidence | Gate |
|---|---|---|
| Specifications first | Complete 021 artifacts and permanent RED tests precede production code | Pass |
| Vision vs delivery | Locked Attachment design plus canonical 021 outcome; deferrals are explicit | Pass |
| Thin vertical slice | One shared mechanism with two small complete real consumers | Pass |
| Deterministic core | File validation, normalization, quota, ownership, and cleanup require no AI | Pass |
| Ownership/privacy | Private disk, proxy retrieval, owner/parent recheck, metadata stripping | Pass |
| Contracts/tests | Schema/service/API/OpenAPI/types/browser/native move together | Pass |
| Complete localization | Every visible state/action/error/ARIA ships EN/RU/UK | Pass |

No deviation or complexity exception is required.

## Architecture Gates

1. **Ownership**: Attachments owns file metadata, safe processing, storage I/O, quota, retry, streaming,
   and cleanup. Body and Nutrition own parent facts/lifecycle and only expose attachment summaries.
2. **Profile inputs**: Profile locale controls feedback and alternative text; no photo metadata becomes a
   Profile input and no EXIF time/location is retained.
3. **Timezone/date**: Attachment creation is an instant; parent measurement/meal dates retain their existing
   Profile-local semantics. Photos never change the date of a body or nutrition fact.
4. **Recurrence reuse**: no schedule/occurrence is created. Parent deletion follows existing lifecycle and
   invokes attachment cleanup; no timer, queue, or recurring retry subsystem is introduced.
5. **Cross-module direction**: Attachment points polymorphically to BodyMeasurement or Meal. Parent resources
   use a narrow attachment summary; Attachments never calculates body trends or nutrition aggregates.
6. **Evolution**: one additive table/morph alias map plus observers/relations/resources; rollback drops only
   021 metadata after proving files/test fixtures are cleaned and preserves every 020 row.
7. **Contracts**: three authenticated attachment operations, closed schemas/errors, plus additive
   `attachments` arrays in synchronized Body/Nutrition contracts and TypeScript types.
8. **Aggregates**: quota derives from exact stored sizes with bounded `SUM`/`COUNT` under stable row locks;
   no mutable usage counter or duplicated parent state exists.
9. **Privacy**: private disk, opaque paths, normalized bytes, no EXIF/public URL/log body, 404-equivalent
   foreign access, no-store streaming, temporary client previews, and cache disposal.
10. **Deferral**: recognition/AI, receipts/GPX, documents/videos, thumbnails/editing, public sharing,
    object-store rollout, offline/background queue, deployment, workflows, containers, and live data stay absent.

## Project Structure

```text
specs/021-private-attachments/
├── spec.md                 ├── research.md
├── checklists/requirements.md
├── plan.md                 ├── data-model.md
├── contracts/openapi.yaml  ├── quickstart.md
├── tasks.md                └── analysis.md

apps/api/
├── config/attachments.php, database/migrations/*create_attachments_table.php
├── app/Models/Attachment.php + BodyMeasurement/Meal relations
├── app/Services/Attachments/{FileStorage,ImageNormalizer,AttachmentService}.php
├── app/Observers/{BodyMeasurementObserver,MealObserver,UserAttachmentObserver}.php
├── app/Http/{Requests,Resources,Controllers}/Attachment*
├── routes/api.php, lang/{en,ru,uk}/*
└── tests/{Unit,Feature}/Attachments + affected Body/Nutrition contracts

apps/web/
├── src/api/{types.ts,client.ts,attachments.ts}
├── src/components/attachments/{AttachmentGallery,AttachmentUploader}.vue
├── src/views/{BodyView,NutritionView}.vue
├── src/i18n/locales/{en,ru,uk}.ts, src/style.css, src/content/changelog.ts
├── src/__tests__/attachments-contracts.test.ts
└── e2e/attachments/{attachments-flow,attachments-visual}.spec.ts

apps/mobile/
├── package.json/package-lock.json + Camera/Filesystem/File Transfer Android registration
└── tests/attachment-platform.test.mjs
```

## Delivery Phases

1. Finalize research/model/OpenAPI/tasks/analyze and record baseline plus shared impact.
2. Add permanent schema/model/service/API/client/browser RED tests and record exact absent-021 failures.
3. Deliver additive table/model/factory/config, private FileStorage, safe ImageNormalizer, and invariants.
4. Deliver idempotent serialized upload, private stream, explicit/parent/user cleanup, and contracts.
5. Add bounded attachment projections to Body and Nutrition without changing their facts/aggregates.
6. Deliver browser/native byte adapters, reusable gallery/uploader, and Body/Nutrition composition.
7. Complete EN/RU/UK accessibility/responsiveness, native plugins, recovery, and visual journeys.
8. Run full regression/rollback/audit/safety/GitNexus gates, docs/memory, atomic commit and push.

## Verification Strategy

- Permanent RED-first schema/model/storage/normalization/quota/ownership/API/client/browser tests.
- Real JPEG/PNG/WebP, EXIF orientation/GPS, malformed/polyglot/truncation/dimension fixtures.
- Storage fake plus forced write/read/delete/database failures and disk/row set-equality assertions.
- Exact idempotency, mismatch, serial/concurrent parent count and owner byte quota tests.
- Parent/user deletion cleanup, missing-file repair, foreign/anonymous access, safe response headers.
- Fixed query-budget and set-equality tests for 20 BodyMeasurement/Meal rows and parent aggregates.
- Full Laravel, Pint, Composer validate/audit, identifiers, isolated rollback/reapply preserving 020.
- OpenAPI parse/ref/closed/security/route parity and synchronized earlier Body/Nutrition contracts.
- i18n parity/used-key/hardcoded-copy, TypeScript, Vitest, production build, dependency audits.
- Focused/full Playwright desktop/mobile and inspected EN/RU/UK × light/dark × Body/Nutrition screenshots.
- Mobile Node tests, safe HTTPS-origin Capacitor sync/plugin validation/fingerprint; no APK/deploy.
- Diff/secrets/logging/dependency/large/generated/protected/handoff audits and refreshed staged GitNexus impact.

## Complexity Tracking

One Attachment table is required because ownership, polymorphic parent identity, idempotency, quota sums,
stream/delete authorization, and cleanup need queryable constraints. Image bytes remain on an abstract private
disk, not in database JSON/BLOB. Three small services separate driver I/O, untrusted image normalization,
and transactional domain policy so consumers never manipulate paths or bytes directly. No usage counter,
derivative table, queue, provider, or per-consumer photo table is introduced.
