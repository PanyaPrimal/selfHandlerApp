# Implementation Plan: Data Portability and Reports

**Branch**: existing user branch | **Date**: 2026-08-14 | **Spec**: [spec.md](spec.md)

## Summary

Add localized CSV/PDF downloads over the existing Analytics workspace plus an independently versioned ZIP
backup/restore boundary. Export all authoritative owned product data through an explicit portable schema, list
private attachments as hashed members, validate uploads without writes, and allow atomic reconstruction only
into an empty locked account after signed preflight and explicit confirmation. Add a `/settings/data` workspace,
Analytics export actions, EN/RU/UK copy, shared Android synchronization, and adversarial round-trip evidence.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12; TypeScript 6 / Vue 3.5

**Primary Dependencies**: Existing Analytics/Profile/Eloquent/BCMath/ZipArchive/FileStorage/Vue/i18n; add direct
`dompdf/dompdf` 3.x runtime dependency for safe UTF-8 PDF rendering and bundled DejaVu fonts

**Storage**: Existing MySQL 8 tables and private attachment disk; request-scoped OS temporary ZIP only; no migration

**Testing**: PHPUnit/Laravel unit/feature, Pint, Composer validate/audit, Vitest, vue-tsc, Vite, Playwright
desktop/exact-390, i18n guards, Capacitor shell fingerprint/tests/audit

**Performance Goals**: One bounded table query per catalog entry; chunked archive assembly; at most 100,000 rows,
100 attachments, 110 ZIP members, and 256 MiB uncompressed input; no per-row relation queries

**Constraints**: owner-only/no-store; lossless canonical scalars; no credentials/paths/IDs; empty-only atomic
restore; no deployment/system backup, merge, external provider, background retention, or native authority

**Scale/Scope**: Two report GETs, one backup GET, validate/restore POSTs, one Settings view, one Analytics action set

## Localisation Plan

**Message ownership**: Existing TypeScript dictionaries own UI; Laravel `reports.php` owns report labels and
filenames; Laravel `messages.php` owns validation feedback. Every key ships in en/ru/uk.

**Runtime locale**: Existing Profile-authoritative request middleware and frontend i18n remain authoritative.

**Formatting**: Reports format labels/dates/counts for Profile locale but retain canonical decimal value columns;
UI uses current locale for dates/counts/bytes. Backup JSON never localizes keys or canonical values.

**Delivery gates**: dictionary parity/used-key/hardcoded guards, Laravel three-locale report tests, PDF Cyrillic
extraction, frontend contracts, EN/RU/UK browser journeys and inspected light/dark screenshots.

## Constitution Check

### Pre-Research Gate: PASS

- Specification precedes production changes and links canonical roadmap/design boundaries.
- Reports and empty-account recovery are independently useful vertical slices with API/UI/contracts/tests.
- Deterministic local generation/validation requires no AI or external provider.
- Explicit catalog, exclusions, owner/token binding, no-store delivery, and atomic recovery protect private data.
- REST/OpenAPI/TypeScript/Vue and permanent tests move together.
- Complete EN/RU/UK human copy is planned; machine schema remains stable English identifiers.

### Post-Design Gate: PASS

Research closes report composition, PDF dependency, schema, references, exclusions, archive bounds, preflight,
empty-target semantics, atomicity, file compensation, UI, and deferrals. No constitution deviation remains.

## Architecture Gates

1. **Owner**: Analytics owns report composition; each source module owns exported truth; Portability owns only
   schema mapping/validation/reconstruction. Every restored row receives locked target ownership.
2. **Inputs**: Profile provides locale, timezone, units, base currency, theme, and report formatting.
3. **Time**: Reports reuse Profile-local Analytics dates; backup preserves date-only values and stored UTC
   timestamps without conversion; manifest generation/expiry are UTC RFC3339.
4. **Scheduling**: RecurringRule/PlannedOccurrence rows and mirrors are portable/remapped; no schedule engine is
   duplicated or re-expanded during restore.
5. **Cross-module direction**: Modules/Analytics -> report or portable snapshot -> API/UI. Restore maps schema-v1
   records back into their owning tables; no module imports Portability or reads report state.
6. **Evolution**: No DB migration. Schema version and explicit catalog make later additive archive readers
   deliberate; unsupported versions fail closed.
7. **Contracts**: OpenAPI, Laravel requests/responses/download headers, archive JSON schemas, TypeScript client,
   and Vue consumers change atomically.
8. **Aggregates**: Reports consume Analytics output. Backup preserves facts/snapshots but never persists a report
   or new aggregate.
9. **Privacy**: Auth required; no-store; target/digest-bound HMAC; credentials/runtime state excluded; no external
   transfer; attachment paths regenerated; archive inputs never retained.
10. **Deferral**: 025 integrations and 026 AI stay separate. Deployment backup, scheduled/cloud/email exports,
    merge/overwrite, CSV import, native offline state, new attachment kinds, and live operations remain excluded.

## Project Structure

```text
specs/024-data-portability-reports/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── analysis.md
├── tasks.md
├── contracts/{openapi.yaml,backup-manifest.schema.json,backup-records.schema.json}
└── checklists/requirements.md

apps/api/
├── app/Http/{Controllers,Requests}/Portability/*
├── app/Services/Reports/*
├── app/Services/Portability/*
├── config/portability.php
├── lang/{en,ru,uk}/{messages.php,reports.php}
└── tests/{Unit,Feature}/{Reports,Portability}/*

apps/web/
├── src/api/{client.ts,types.ts}
├── src/views/{AnalyticsView.vue,DataSettingsView.vue}
├── src/{router.ts,style.css}
├── src/i18n/locales/{en,ru,uk}.ts
├── src/__tests__/portability-contracts.test.ts
└── e2e/portability/{portability-flow.spec.ts,portability-visual.spec.ts}
```

## Delivery Phases

1. Freeze specification/contracts/checklist, record baseline, and review GitNexus impacts.
2. Add failing report format/security/locale tests and archive schema/attack/round-trip/ownership/rollback tests.
3. Add PDF dependency, shared report renderer, controllers/requests/routes/download headers, and OpenAPI.
4. Implement explicit schema-v1 catalog, deterministic ZIP export, strict reader, token, eligibility, and atomic
   restore with remapping/deferred updates/file compensation.
5. Add TypeScript contracts/client, Analytics report actions, `/settings/data`, nav/changelog/styles, and EN/RU/UK.
6. Synchronize Android shared bundle; run focused/full/manual visual/protected-path/GitNexus gates; complete docs,
   tasks/checklist/roadmap/memory; atomically commit and push.

## Verification Strategy

- Report units: CSV quoting/formula neutralization/BOM, PDF signature/pages/escaped text/Cyrillic, exact DTO parity.
- Archive units: catalog coverage drift guard, deterministic IDs/order, JSON scalar handling, polymorphic/system refs,
  manifest hashes, path/compression/member/size/UTF-8/closed-schema attacks, token tamper/expiry/user/hash binding.
- Integration: full model factory catalog round trip, self/cyclic refs, public refs, archive/deletion states, Profile/
  settings, Body/Meal attachments, missing-file export, target non-empty race, DB/file failure compensation.
- API: defaults/strict report queries, owner/foreign/anonymous, no-store/content disposition, multipart validation,
  confirmation/token, issue codes, OpenAPI route/ref closure.
- Web/E2E: blob downloads, file reset, validate/eligible/ineligible/errors, confirm/restore, reload, keyboard/ARIA,
  no overflow, all locales/schemes/viewports, Android bundle parity.
- Full regressions: Laravel/Pint/Composer, i18n/Vitest/type/build/audit, desktop/mobile Playwright, Capacitor checks.

## Complexity Tracking

No constitution violations. Direct ordered reconstruction is limited to validated schema-v1 archives because
replaying public commands would alter immutable history and scheduled projections.
