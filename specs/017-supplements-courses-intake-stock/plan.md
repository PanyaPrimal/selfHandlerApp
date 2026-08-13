# Implementation Plan: Supplements, Courses, Intake, and Stock

**Feature ID**: `017-supplements-courses-intake-stock`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/openapi.yaml](contracts/openapi.yaml) · **Quickstart**: [quickstart.md](quickstart.md)

## Summary

Deliver a neutral private Supplement catalogue, bounded multi-slot courses through the shared
recurrence engine, correctable taken/skipped facts, immutable stock facts, exact remaining stock and
bounded run-out projections, one-off restock proposals, shared escalating reminders, and module-owned
adherence reused by Planner/Today/Review. The slice uses thirteen authenticated operations and the
existing responsive EN/RU/UK web/Capacitor client. Money, medical advice, and AI remain absent.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12.61; TypeScript 6 / Vue 3.5.

**Primary Dependencies**: Eloquent/UserOwned, Profile timezone/locale/unit helpers, optional Goal,
RecurringRule/PlannedOccurrence, Planner source registry, notification delivery/escalation, Today/
Review module summaries, shared i18n/theme, Capacitor bundle.

**Storage**: MySQL 8 production target; SQLite test portability. One additive migration creates seven
tables, extends RecurringRule with three compatible columns, and adds one nullable occurrence fact FK.

**Testing**: PHPUnit schema/model/service/API/OpenAPI/ownership/concurrency/query/compatibility/
integration tests; Pint; i18n/typecheck/Vitest/build; Playwright desktop/mobile/locales/themes;
mobile wrapper validation.

**Target Platform**: Responsive browser and existing bundled Capacitor Android client. No native
Supplement store, remote schedule, provider, or background inventory authority.

**Performance Goals**: Maximum 366-day adherence and 730-day forecast use fixed documented query
budgets as courses, occurrences, intakes, and stock facts grow; recurrence materialization remains
bounded by slot limit and window rather than row-by-row writes.

**Constraints**: Exact decimal strings, canonical g/ml/piece, Profile-local calendar/DST behavior,
owner isolation, immutable facts, one active proposal, closed contracts, full EN/RU/UK, exact 390×844,
additive compatibility, no clinical claims, no finance/provider/deployment/live data.

**Scale/Scope**: Hundreds of references/courses, tens of thousands of intake/stock facts per user,
1–8 slots/course, 90-day durable occurrence window, 366-day reports, and 730-day projections.

## Localisation Plan

**Message ownership**: `apps/web/src/i18n/locales/en.ts` remains canonical with simultaneous RU/UK;
`apps/api/lang/{en,ru,uk}` owns strict validation/domain/notification copy. Category/form/context/unit/
status/forecast keys are localized; user names/notes are never translated.

**Runtime locale**: Reuse Profile-authoritative locale, English fallback, hydration reconciliation,
and locale-at-notification-delivery semantics.

**Formatting**: Existing locale date/number helpers format Profile-local dates/times and decimal-string
quantities. A Supplement unit helper renders mg/g/ml/piece; canonical API values never depend on locale.

**Backend feedback**: FormRequests/services translate closed-key, unit compatibility, schedule/bound,
fact-time, stock-correction, lifecycle, ownership-safe, and proposal-action failures. Foreign IDs keep
the 404 boundary and reminder parameters remain product keys, not pretranslated text.

**Delivery gates**: Dictionary parity/blank/unknown/unused/hardcoded-copy checks; backend localized
message assertions; EN/RU/UK desktop/mobile flows; both schemes; long medical-boundary copy, overflow,
focus, console, page-error, and notification-render probes.

## Constitution and Architecture Gates

### Gate 1 — Specifications Before Implementation

- Complete spec/checklist, clarification coverage, research, plan, model, closed OpenAPI, quickstart,
  tasks, and read-only analysis before product code.
- Author permanent backend/browser tests first and record intended missing-feature failures.

**Result**: Pass.

### Gate 2 — Neutral Monitoring and Safety Boundary

- The user supplies every substance, dose, context, schedule, and course; no clinical validation or
  recommended regimen is represented as product output.
- UI/API/notifications use neutral “recorded/taken/skipped/remaining” language and explicitly defer
  advice, contraindications, interactions, and AI.

**Result**: Pass.

### Gate 3 — Ownership and Privacy

- Every root/child/fact/proposal repeats `user_id`; relationship services enforce the same owner.
- Goal is optional/access-checked, every endpoint uses Sanctum, and foreign identifiers return 404.
- Account deletion cascades private data; references/courses have no public or hard-delete API.

**Result**: Pass.

### Gate 4 — Shared Recurrence Compatibility

- SupplementCourse is one new owner; interval/cycle/slots extend the shared engine once.
- Defaults and legacy fallback preserve exact routine/habit/sleep/workout rule/occurrence behavior.
- Fact-bearing/rescheduled rows survive schedule/lifecycle changes and materialization stays idempotent.

**Result**: Pass with broad mandatory regression due High/Critical GitNexus impact.

### Gate 5 — Facts and Exact Stock

- Intake snapshots are the sole consumption facts; StockMovement is append-only restock/correction.
- Remainder is a decimal-string projection with no mutable counter, duplicate consumption row, or clamp.
- Taken/skipped/corrected/cleared transactions synchronize occurrence status and every projection.

**Result**: Pass.

### Gate 6 — Forecast and Proposal Ownership

- Supplements owns a transparent max-730-date projection using the shared expander/durable overlay.
- One active proposal is concurrency-safe and keyed to a material shortage fingerprint.
- Restock is never recurrence; price/currency/transaction/budget remain feature 018/020 work.

**Result**: Pass.

### Gate 7 — Notifications and Cross-Module Direction

- Notifications owns delivery, locale, quiet hours, snooze, dedupe, and three-repeat escalation.
- Planner only projects/reschedules/delegates skip; Today transports and Review presents the one
  Supplements summary. None owns Supplement facts, stock, forecast, proposal, or adherence.

**Result**: Pass.

### Gate 8 — Deterministic Aggregation and Query Bounds

- Adherence uses elapsed effective occurrences; forecast uses exact sorted future dose events.
- Empty/zero/negative/unavailable/end-before-runout/beyond-horizon states are machine-readable.
- Fixed eager/set query budgets and no stored rollup keep corrections immediately authoritative.

**Result**: Pass.

### Gate 9 — Additive Evolution and Portability

- Seven new tables, three default/nullable rule columns, one nullable fact FK, short explicit indexes.
- Existing rows are not rewritten/dropped. Rollback removes new dependencies in safe order.
- Decimal, nullable unique active key, upsert, date/time, and FK behavior are asserted on SQLite/MySQL.

**Result**: Pass.

### Gate 10 — Contracts, Complete Clients, and Evidence

- Thirteen exact operations align Laravel, OpenAPI 3.1, TypeScript, Vue, and tests.
- One accessible responsive workspace covers full lifecycle, EN/RU/UK, schemes, 44px controls, safe
  areas, exact 390×844, and Android transport.
- Full regressions, dependency/security audits, visual evidence, protected-path/handoff audit,
  GitNexus review, atomic commit, and push close the feature.

**Result**: Pass.

## Project Structure

### Documentation

```text
specs/017-supplements-courses-intake-stock/
├── spec.md
├── checklists/requirements.md
├── research.md
├── plan.md
├── data-model.md
├── contracts/openapi.yaml
├── quickstart.md
├── tasks.md
└── analysis.md
```

### API and persistence

```text
apps/api/
├── database/migrations/2026_08_14_000000_create_supplements_courses_intake_stock.php
├── app/Models/{Supplement,SupplementCourse,RecurringRuleSlot,SupplementCourseSlot,
│   SupplementIntake,SupplementStockMovement,SupplementRestockProposal}.php
├── app/Http/Requests/*Supplement*.php
├── app/Http/Controllers/{Supplement,SupplementCourse,SupplementDay,
│   SupplementIntake,SupplementStockMovement,SupplementRestockProposal}Controller.php
├── app/Http/Resources/{Supplement,SupplementCourse,SupplementDay}Resource.php
├── app/Services/{SupplementCourseService,SupplementCourseRecurrence,SupplementIntakeService,
│   SupplementStockService,SupplementStockForecastService,SupplementRestockProposalService,
│   SupplementAdherenceService,RecurringRuleExpander,RecurrenceMaterializer,
│   OccurrenceFactSynchronizer}.php
├── app/Services/Planner/{SourceRegistry,SupplementOccurrenceSource}.php
├── app/Services/Notifications/* existing shared integration files
├── app/{Models,Http/Controllers}/ existing recurrence/Planner/Today integration files
├── routes/api.php
└── tests/{Feature,Unit}/Supplements/
```

### Web and bundled mobile

```text
apps/web/
├── src/views/{SupplementsView,TodayView,ReviewView,PlannerView,SettingsView}.vue
├── src/components/supplements/{SupplementEditor,CourseEditor,IntakeEditor,
│   StockEditor,ForecastCard,AdherenceCard}.vue
├── src/api/{types,client}.ts
├── src/{router.ts,style.css}
├── src/i18n/locales/{en,ru,uk}.ts
└── e2e/supplements/supplements-flow.spec.ts

apps/mobile/
└── existing shared web build + Android sync/validation only
```

**Structure Decision**: Extend implemented shared owners and the one existing client. No generic
repository, universal consumables layer, native domain module, schedule store, medical provider,
finance record, or background aggregate is added.

## Implementation Sequence

1. Complete Spec Kit artifacts, closed schemas, traceability, and read-only consistency analysis.
2. Author permanent schema/decimal/recurrence/domain/API/OpenAPI/integration/query/browser tests and
   record RED failures proving feature absence rather than broken baseline.
3. Add the migration, models, same-owner invariants, decimal unit conversion, and legacy recurrence
   compatibility tests.
4. Implement interval/cycle/multi-slot expansion/materialization and Supplement course lifecycle.
5. Implement idempotent intake facts, occurrence reconciliation, stock ledger, remainder, forecast,
   and concurrency-safe proposal reconciliation.
6. Integrate Planner skip/source, notifications/escalation/restock delivery, Today/Review summaries,
   contracts, and exact query budgets.
7. Build the full accessible localized `/supplements` workspace and shared surface updates.
8. Run focused/affected/full/mobile/visual/audit gates, docs/changelog, Spec Kit closure, memory,
   atomic commit/push, and exact local/remote SHA verification.

## Constitution Re-check After Design

| Principle | Result |
|---|---|
| Specifications before code | Pass — complete design and RED-first order |
| Thin vertical slice | Pass — reference/plan/fact/stock/forecast/action only |
| Deterministic core | Pass — exact ledger, pure recurrence, bounded projections |
| User ownership/privacy | Pass — all private roots/children and 404 isolation |
| Contracts/tests together | Pass — thirteen operations and changed consumers paired |
| Complete localisation | Pass — full EN/RU/UK and delivery-time notification copy |
| Additive data model | Pass — compatible columns/tables/FK, no legacy rewrite |
| Explicit ownership | Pass — shared modules consume/delegate without copying |
| Safety framing | Pass — neutral records only; no medical or regimen output |
