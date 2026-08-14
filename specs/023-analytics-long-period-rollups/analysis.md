# Pre-Implementation Analysis: Analytics and Long-Period Rollups

## Result

**PASS — ready for RED implementation.** The specification, plan, research, data model, contract, and task graph
agree on user value, module ownership, formulas, ranges, privacy, performance, localisation, and exclusions.

## Cross-Artifact Traceability

| Concern | Specification | Design decision | Tasks | Status |
|---|---|---|---|---|
| Metric catalog/ownership | FR-002–006 | Research 1–3 | T018–T021, T029–T063 | Covered |
| Calendar rollups/trends | FR-007–011 | Research 4–5 | T011–T015, T033–T042 | Covered |
| Period comparison | FR-012–013 | Research 5 | T015, T035, T042, T065 | Covered |
| Fixed correlations | FR-014–018 | Research 6 | T016–T017, T031, T043, T066 | Covered |
| Finance exact/incomplete | FR-019–020 | Research 2–3 | T013, T058–T059, T070 | Covered |
| Performance/no copy | FR-004–006, 021 | Research 1 | T020–T021, T063, T111 | Covered |
| Privacy/contracts | FR-022–024 | Research 7/9 | T022–T026, T064–T073 | Covered |
| Client/i18n/mobile | FR-025–029 | Research 8 | T027–T028, T074–T110 | Covered |
| Explicit exclusions | FR-030 | Deferred | T112–T120 | Covered |

## Architecture Gate Review

1. **Owner — PASS**: modules emit primitives; generic Analytics math never becomes domain truth.
2. **Profile inputs — PASS**: timezone, locale, units, base currency, and today are read, never copied.
3. **Time — PASS**: strict local dates, Monday weeks, calendar months, clipped edges, source-owned UTC mapping.
4. **Scheduling — PASS**: recurrence consumers retain `RecurringRule`/`PlannedOccurrence` ownership.
5. **Cross-module direction — PASS**: source → typed read contract → registry → derived API; no reverse write.
6. **Evolution — PASS**: no migration; additive service methods and routes preserve all prior contracts.
7. **Contracts — PASS**: Laravel/OpenAPI/TypeScript/Vue/E2E work is one dependency-ordered graph.
8. **Aggregates — PASS**: Analytics has no raw model import and only combines numerator/denominator primitives.
9. **Privacy — PASS**: owner-only aggregate response excludes raw content/IDs/files/secrets/external transfer.
10. **Deferral — PASS**: 024–026 and speculative cache/alerts/forecast/custom/AI/offline/deploy work are explicit.

## Consistency Findings

- Seventeen keys in specification/research/OpenAPI are exact and owned; no metric is inferred from raw rows in
  Analytics.
- Query-time rollup is consistent with correction freshness and the roadmap's demand to avoid slow per-day
  scans; fixed grouped query budgets are a permanent acceptance contract.
- Weekly buckets start Monday like feature 022, while comparison uses exact adjacent equal-day ranges and
  publishes dates, so different month lengths are never hidden.
- `sum`, `mean`, `percentage`, and `last` close every initial metric. Weighted evidence prevents averages of
  percentages; sparse Body observations are never filled forward.
- Three correlation pairs, sample floor, coefficient, rounding, classifications, and unavailable states match
  across every artifact. No p-value, cause, diagnosis, or recommendation is implied.
- OpenAPI has exactly three read operations, global Sanctum security, closed response objects, enumerated keys/
  states, bounded arrays/ranges, and decimal strings for exact transport.
- No schema change, schedule copy, write route, external provider, native data store, or deployment work exists.

## Risk Register

| Risk | Mitigation | Permanent verification |
|---|---|---|
| Analytics silently duplicates formulas | module-side primitives + import guard | architecture/source parity tests |
| Long range becomes N×days | grouped source methods + registry dedupe | short/max query-count equality |
| Daily rates average incorrectly | numerator/denominator contract | weighted asymmetric fixture |
| Money drops missing currency | whole-bucket incomplete state | historical-FX matrix |
| Sparse points become invented values | explicit empty and no interpolation | missing/zero/last matrices |
| Correlation sounds causal | fixed labels/disclaimer/no advice fields | contract and UI copy tests |
| Sensitive raw fields leak | allowlisted presenter and response scan | owner/privacy/API interception tests |
| Shared client regresses | route/nav/UI tests plus full suites | desktop/mobile/Android regressions |

## Clarification Result

No user question is required. The design's open metric-pair and precomputation questions are safely closed by a
fixed minimal catalog and correction-safe query-time aggregation. A future persisted cache is triggered only by
measured performance evidence and needs its own invalidation/backfill specification.

## Pre-Implementation Gate

No critical or high inconsistency remains. Implementation may begin with T011's permanent failing contracts.

## Final Implementation Traceability

**PASS.** All four user stories and FR-001–FR-030 are implemented without widening the feature:

- A closed 17-metric catalog delegates bounded owner-local primitives to ten source-module services;
  Analytics has no source-model dependency, persistence, migration, background job, or write route.
- Generic decimal rollups preserve real zero, missing, incomplete, weighted evidence, sparse last values,
  strict Profile-local bucket boundaries, adjacent equal-period comparison, and correction freshness.
- OLS trends and the three fixed pairwise-complete Pearson findings expose exact evidence and closed
  unavailable reasons without implying causation, diagnosis, advice, or a confidence claim.
- The authenticated aggregate-only API, OpenAPI, TypeScript contracts, URL-canonical EN/RU/UK workspace,
  accessible SVG/table, comparison/correlation cards, complete state handling, desktop navigation, mobile
  More access, changelog, synchronized Android bundle, and canonical documentation agree.
- Manual contract review found and closed one final mismatch: the API now accepts the OpenAPI boolean query
  forms `compare=true|false` as well as the client forms `1|0`, while rejecting other truthy strings.

## Final GREEN Evidence

- Focused Analytics: **43 tests / 379 assertions**; affected source/Profile/Review compatibility:
  **309 / 7,830**; final full Laravel: **773 / 10,311**. Global Pint, strict Composer validation, and
  Composer advisory/abandonment audit pass.
- Frontend: i18n **1,814 keys × 3 locales / 114 source files**, Vitest **49/49**, TypeScript, production
  build, and production npm audit (**0 vulnerabilities**) pass. The existing >500 kB main-bundle warning
  remains non-failing and unchanged in kind.
- Focused Analytics functional journeys pass in both projects; the visual spec passes **2/2** and produced
  **24** final EN/RU/UK × light/dark × ready/empty × desktop/mobile screenshots. Every final image was
  manually inspected for localization, number wrapping, table/chart parity, correlation states, contrast,
  navigation, and overflow.
- Full Playwright: desktop **113 passed / 8 documented conditional skips / 0 failed**; exact 390×844 mobile
  **118 passed / 3 documented conditional skips / 0 failed**. The combined invocation exceeded a 15-minute
  shell limit, so the identical full projects were rerun separately and completed in 8.7 and 8.3 minutes.
- Android shared bundle sync/validation passes with all **7** expected Capacitor plugins and fingerprint
  `76076c128b8b`; native-source Node tests **19/19** and mobile production audit **0 vulnerabilities** pass.
  No Gradle/APK, emulator, device, signing, native data authority, or deployment action ran.
- Final static audit: exactly three `GET|HEAD` Sanctum routes; zero Analytics write calls, source-model imports
  other than authenticated `User`, migrations, protected tracked paths, or forbidden-scope files;
  `git diff --check` passes. All seven preserved handoff files remain unrelated untracked files with their
  baseline blob identities, and generated root `AGENTS.md`/`CLAUDE.md` remain excluded.
- GitNexus pre-change impacts were reviewed before shared-symbol edits. Its staged mapper reported **79 files,
  low risk, and 0 affected flows**, while its 022 symbol index also emitted known line-shift false positives for
  protected Markdown; direct cached Git path checks prove those files are not staged or modified. A post-commit
  index refresh records the final graph.
