# Implementation Plan: Cross-Module and Periodic Review

**Feature**: `022-cross-module-periodic-review` | **Date**: 2026-08-14 | **Spec**: [spec.md](spec.md)

## Summary

Turn Review into one authenticated composition boundary over registered module-owned daily/period
aggregates, add a transparent deterministic five-component day score, and add owner-scoped weekly/monthly
reflection persistence and responsive EN/RU/UK workspaces. Keep the DailyReview ritual/contracts intact,
avoid aggregate snapshots and per-day query loops, and defer analytics/rollups to 023.

## Technical Context

- **Backend**: Laravel 12, PHP 8.4+, Eloquent, Sanctum, SQLite tests/MySQL target.
- **Frontend**: Vue 3, TypeScript 6, Vue Router, Vite, current UI primitives and i18n registry.
- **Mobile**: Capacitor Android shell reusing the production web build.
- **Persistence**: one additive `periodic_reviews` table; no cached aggregate or score table.
- **Contracts**: additive REST/OpenAPI, strict date/type enums, existing DailyReview and Today compatibility.
- **Performance**: grouped range projections for at most seven days or one calendar month; fixed query budgets.
- **Protected scope**: no deployment paths, workflows, live data, or preserved design handoff changes.

## Constitution Check

- **I Specifications Before Implementation**: spec, research, model, contract, plan, tasks, checklist, and
  analysis precede production changes. **PASS**.
- **II Vision/Delivery Truth**: follows the locked module aggregation and Review/Analytics boundaries.
  **PASS**.
- **III Thin Slice/Simplicity**: one reusable aggregate contract has eight current consumers; one new table;
  no rollup/integration/AI generalization. **PASS**.
- **IV Deterministic Core**: score and aggregates are deterministic; AI is excluded. **PASS**.
- **V Ownership/Privacy**: owner scope, timezone, no journal/provider transfer, no copied private facts.
  **PASS**.
- **VI Contracts/Tests Together**: RED API/domain/schema/OpenAPI/client/E2E tests precede implementation.
  **PASS**.
- **VII Localization**: all async, score, form, accessibility, and changelog copy ships EN/RU/UK with gates.
  **PASS**.

No constitutional exception or complexity waiver is required.

## Architecture Gates

1. Controllers may depend on Review workspace/composer services only; no source model imports.
2. Review composer may depend only on the registry, score service, DailyReview/PeriodicReview-owned queries,
   and period value logic; no source model imports.
3. Every registry source has a unique stable key and both daily/period operations.
4. Each adapter calls a module-owned service; raw queries reside only within the owning module boundary.
5. No source total, contribution, score, or combined JSON is stored.
6. Period range is derived server-side and cannot exceed a week/month.
7. Existing Today keys and DailyReview endpoints remain compatible and tested.
8. UI/mobile consume one workspace response per mode, not fan-out source calls.

## Project Structure

```text
apps/api/
├── app/Contracts/ReviewAggregateSource.php
├── app/Http/Controllers/ReviewWorkspaceController.php
├── app/Http/Controllers/PeriodicReviewController.php
├── app/Models/PeriodicReview.php
├── app/Services/Review/
│   ├── AggregateRegistry.php
│   ├── DayScoreService.php
│   ├── PeriodFactory.php
│   ├── ReviewWorkspaceService.php
│   ├── WellBeingSummaryService.php
│   └── Sources/*.php
├── app/Services/{Routine,Habit}PeriodSummaryService.php
├── app/Services/Planner/PlannerPeriodSummaryService.php
├── database/migrations/*_create_periodic_reviews.php
└── tests/{Unit,Feature}/Review/*

apps/web/
├── src/api/{client.ts,types.ts}
├── src/views/{ReviewView.vue,PeriodicReviewView.vue}
├── src/components/review/{DayScoreCard.vue,ModuleSummaryGrid.vue,ReviewModeNav.vue}
├── src/i18n/locales/{en,ru,uk}.ts
├── src/router.ts
├── src/content/changelog.ts
├── src/__tests__/review-contracts.test.ts
└── e2e/review/periodic-review.spec.ts

specs/022-cross-module-periodic-review/
└── complete Spec Kit package and OpenAPI contract
```

Exact file factoring may remain smaller where a component has only one consumer; the architecture gates
and public contracts are normative.

## Delivery Phases

1. **Specification/baseline**: close formulas, identity, contracts, exclusions; record clean baseline.
2. **Permanent RED contracts**: score, period, registry, persistence, API ownership, compatibility,
   TypeScript, i18n, E2E.
3. **Persistence/period core**: additive migration, model invariants, period factory, well-being projection.
4. **Module aggregate boundary**: module services, adapters, registry, query budgets, correction behavior.
5. **Daily workspace/score**: composer and new endpoint; migrate Today composition additively.
6. **Weekly/monthly API**: workspace GET and idempotent upsert with ownership/concurrency coverage.
7. **Shared client**: types/client, navigation, summaries, score evidence, daily and periodic forms/states.
8. **Localization/mobile/docs**: EN/RU/UK, accessibility/responsive polish, changelog/design, Capacitor sync.
9. **Final gates**: focused/full tests, migrations, OpenAPI, formatting, audits, visual inspection, GitNexus,
   protected-path review, atomic commit/push.

## Verification Strategy

- Unit: period matrices, component formulas/bounds/rounding, equal weights/coverage, registry uniqueness.
- Backend feature: schema/indexes, canonical upsert, ownership/auth, timezone, corrections, all-module fixture,
  exact response shapes, compatibility, query budgets, concurrent retries.
- OpenAPI: parse/ref/auth/closed-schema/operation coverage and examples.
- Frontend: exported types/client calls, state helpers/components, i18n parity/used keys/no hardcoded copy.
- E2E: desktop and exact 390x844 daily/weekly/monthly navigation, save/edit/reload, errors and locale/scheme
  screenshots with contact-sheet inspection.
- Mobile: production build sync, Capacitor fingerprint/plugin/source tests; no APK/device/deployment action.
- Regression/safety: full Laravel and browser suites, migration rollback/reapply, Pint, Composer/npm audits,
  forbidden-path scans, Git diff, GitNexus changed-flow review.

## Complexity Tracking

The registry is justified by eight active sources and the explicit roadmap extension boundary. Separate
PeriodicReview persistence is justified by different identity/fields from DailyReview. No other deliberate
complexity or constitutional deviation is accepted.
