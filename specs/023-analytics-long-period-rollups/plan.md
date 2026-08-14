# Implementation Plan: Analytics and Long-Period Rollups

**Branch**: existing user branch | **Date**: 2026-08-14 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/023-analytics-long-period-rollups/spec.md`

## Summary

Deliver a responsive Analytics workspace for 17 proven module-owned metrics, deterministic daily/weekly/monthly
trends, adjacent-period comparison, and three fixed Pearson correlations. Add one narrow aggregate-series
contract and module-side grouped sources; keep Analytics free of raw domain queries and persistent copies.
Expose three authenticated closed read endpoints, mirror them in TypeScript, render dependency-free accessible
SVG/table views in EN/RU/UK, synchronize the existing Android client, and verify bounded query count/privacy.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12; TypeScript 6 / Vue 3.5

**Primary Dependencies**: Existing Eloquent/BCMath/Carbon, Vue Router, in-house i18n/theme/UI controls,
Capacitor 8 shared bundle; no new runtime dependency

**Storage**: Existing MySQL 8 source tables only; no Analytics migration or persisted aggregate cache

**Testing**: PHPUnit/Laravel feature and unit tests, Pint, Composer validate/audit, Vitest, vue-tsc, Vite,
Playwright desktop/exact-390, i18n guards, Capacitor shell tests/fingerprint/audit

**Target Platform**: Authenticated browser application and existing Android Capacitor shell

**Project Type**: Laravel REST API + Vue SPA + shared Android web bundle monorepo

**Performance Goals**: Query count fixed by required source count for short and maximum ranges; at most 94 daily,
106 weekly, or 121 monthly response points across current/comparison; no per-day source calls

**Constraints**: Owner-only aggregate reads; strict 93/730/3,653-day trend and 366-day correlation bounds; exact
money strings; Profile-local dates; no raw sensitive payloads, persistence, external calls, offline authority,
or deployment changes

**Scale/Scope**: 17 metrics from 10 owners, three fixed correlations, three GET operations, one new workspace

## Localisation Plan

**Message ownership**: English keys in `apps/web/src/i18n/locales/en.ts`, matching Russian/Ukrainian keys,
plus localized Laravel validation messages in all three backend dictionaries

**Runtime locale**: Existing authenticated Profile locale with pre-hydration cache, immediate switching,
English fallback, and synchronized `<html lang>` remain unchanged

**Formatting**: Existing locale helpers for dates/numbers/percent; add metric-aware duration/rating/kg and
Profile-base-currency formatting without changing canonical API decimal strings

**Backend feedback**: Strict date/range/metric/granularity errors use `messages.analytics_*` and active locale

**Delivery gates**: Dictionary parity, used-key/hardcoded-copy scan, Vitest formatter/contracts, backend locale
matrix, EN/RU/UK desktop/mobile E2E, and light/dark screenshots inspected visually

## Constitution Check

### Pre-Research Gate: PASS

- Specification precedes production changes and links the canonical design.
- One independently useful vertical slice includes API, UI, Android reuse, contracts, and tests.
- Deterministic formulas work without AI; no external provider is introduced.
- No new user data is stored; every source remains owner-scoped and privacy-minimized.
- REST/OpenAPI/TypeScript/consumer changes and permanent tests move together.
- Full user-visible copy and accessibility text are scoped for EN/RU/UK.

### Post-Design Gate: PASS

Research closes the metric catalog, rollup operators, bounds, comparison, correlations, API, UI, privacy, and
deferrals. Data model adds no duplicated source state. No constitution deviation requires justification.

## Architecture Gates

1. **Owner**: Each source module owns primitives and formulas; Analytics owns only generic bucket/trend/
   comparison/Pearson algorithms and static pair definitions. No new fact/state transition is persisted.
2. **Inputs**: Locale, timezone, unit system, base currency, and date defaults come from existing Profile.
3. **Time**: Strict date-only ranges are Profile-local; weeks are Monday-based; months are calendar months;
   source UTC instants are mapped by their owner before emission.
4. **Scheduling**: Routine/Supplement/Habit/Planner metrics reuse existing `RecurringRule` and
   `PlannedOccurrence`; no schedule table/status copy is added.
5. **Cross-module direction**: Module source → `AnalyticsMetricSource` → registry/engine → API/UI, read-only and
   one-way. Correlations align aggregate dates only.
6. **Evolution**: No schema change. Existing services receive additive series methods; old API behavior remains.
7. **Contracts**: Laravel unit/feature/OpenAPI tests, closed schemas, TypeScript types/client, and Vue consumers
   change in one feature.
8. **Aggregates**: Source modules emit numerator/denominator/sums/observations; Analytics never imports models
   or reimplements domain formulas.
9. **Privacy**: Authenticated owner scope; aggregate-only payload; sensitive classifications; no notes,
   attachments, transactions, identifiers, secrets, logs, or external transmission.
10. **Deferral**: 024 owns reports/portability, 025 integrations, 026 AI. Persisted caches require measured need;
    alerts, forecasts, arbitrary analysis, raw drill-down, offline/native data, and deployment remain excluded.

## Project Structure

### Documentation

```text
specs/023-analytics-long-period-rollups/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── analysis.md
├── contracts/openapi.yaml
└── checklists/requirements.md
```

### Source Code

```text
apps/api/
├── app/Contracts/AnalyticsMetricSource.php
├── app/Http/Controllers/AnalyticsController.php
├── app/Services/Analytics/
│   ├── AnalyticsCatalog.php
│   ├── AnalyticsRegistry.php
│   ├── CorrelationService.php
│   ├── DateBucketFactory.php
│   ├── MetricRollupService.php
│   ├── TrendService.php
│   └── Sources/*AnalyticsSource.php
├── app/Services/*                         # additive owner-side series methods/services
├── routes/api.php
├── lang/{en,ru,uk}/messages.php
└── tests/{Unit,Feature}/Analytics/

apps/web/
├── src/api/{client.ts,types.ts}
├── src/components/analytics/{MetricTrendChart.vue,MetricTrendTable.vue,CorrelationCard.vue}
├── src/views/AnalyticsView.vue
├── src/{router.ts,style.css}
├── src/i18n/locales/{en,ru,uk}.ts
├── src/__tests__/analytics-contracts.test.ts
└── e2e/analytics/{analytics-flow.spec.ts,analytics-visual.spec.ts}
```

**Structure Decision**: Reuse the monorepo boundaries and existing module services. Analytics source adapters
contain no Eloquent models; owner-side series methods live beside their existing calculations. The Vue client is
the Android source of truth, so no native feature fork is created.

## Delivery Phases

1. Freeze specification/checklist and capture baseline/GitNexus impacts.
2. Add permanent failing math, boundary, query-budget, privacy, API, and browser contracts.
3. Implement closed metric definitions, daily primitive sources, registry, calendar buckets, rollup/trend/
   comparison/correlation services, validation, controller/routes, and OpenAPI.
4. Add TypeScript contracts/client, `/analytics`, accessible SVG/table/cards, URL state, responsive styles, and
   complete EN/RU/UK copy.
5. Integrate navigation/changelog/docs and synchronize the Android shared bundle.
6. Run focused/full gates, exact visual matrix, privacy/protected-path checks, GitNexus staged review, then one
   atomic commit/push and post-commit index refresh.

## Verification Strategy

- Unit matrices: bucket boundaries, operators, rounding, zero/missing/incomplete, OLS, previous range, Pearson,
  thresholds, insufficient samples, and zero variance.
- Module integration: every metric matches owner behavior; correction/delete/profile/FX changes are live;
  short/max query counts match; no per-day method call.
- API: defaults/strict bounds, catalog, all metrics, comparison toggle, correlations, owner/foreign/anonymous,
  aggregate-only response, localized errors, OpenAPI routes/references.
- Web: TypeScript contract guards, URL state, formatters, SVG/table equivalence, loading/empty/error/retry,
  keyboard/ARIA and responsive behavior.
- E2E: deterministic seeded values, comparison, correlation unavailable/ready, all locales/schemes/viewports,
  no overflow, Android shared-bundle parity.
- Full regressions: Laravel, Pint, Composer, i18n, Vitest, typecheck/build/audit, desktop/mobile Playwright,
  Capacitor sync/tests/plugin inventory/fingerprint/audit.

## Complexity Tracking

No constitution violations.
