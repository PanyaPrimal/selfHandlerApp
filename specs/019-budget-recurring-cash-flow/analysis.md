# Specification Analysis: Budget and Recurring Cash Flow

**Date**: 2026-08-13

**Mode**: `$speckit-analyze` read-only review after the complete specification package; RED and final
implementation evidence are appended at their checkpoints.

## Inputs Reviewed

- `spec.md`, requirements checklist, `research.md`, `plan.md`, `data-model.md`, `quickstart.md`,
  `contracts/openapi.yaml`, `tasks.md`, canonical Finance/recurrence/notification documentation,
  Constitution 1.2.0, and delivered 006/009/011/012/018 contracts and code
- Refreshed GitNexus shared-engine/Planner/Notifications pre-change impact at
  `7ee74dc374c7a8113b937f4bbb9b80705f780880`

## Pre-task Findings and Resolutions

| ID | Severity | Finding | Resolution |
|---|---:|---|---|
| A-001 | High | Root and child limits could double-count a consolidated monthly budget. | Reject same-month ancestor/descendant overlap. |
| A-002 | High | Planned amounts read from a mutable operation would rewrite accepted past intent. | Snapshot each materialized occurrence; update only unfactored/unmoved future rows. |
| A-003 | High | Direct occurrence→ledger linkage cannot represent a durable explicit skip. | Add one outcome fact, optional unique group for actual, and derived occurrence link/status. |
| A-004 | High | One notification identity cannot emit both approaching and exceeded. | Use distinct source types with one budget id and cancel/re-arm by current eligibility. |
| A-005 | Medium | Day 31 behavior was unspecified. | Skip nonexistent days; never clamp to month end. |
| A-006 | Medium | Cash flow could mix missing FX into a partial total. | Null the entire consolidated monetary set and list missing currencies. |
| A-007 | Medium | Shared-engine changes affect multiple shipped recurrence consumers. | Require legacy set-equality/materializer/fact/Planner/notification and full regression gates. |
| A-008 | Medium | Actual correction could be mistaken for outcome deletion. | Only skipped facts clear; actual money continues through 018 append-only reversal. |
| A-009 | High | An optional end date and bounded schedule could otherwise imply an infinite rule. | Null now means an explicit deterministic inclusive ten-year ceiling from `starts_on`. |
| A-010 | Medium | New Planner/notification enums could leave their earlier OpenAPI contracts stale. | Tasks explicitly update and re-test the 009 and 011 contracts with the adapters. |

## Pre-implementation Coverage Result

- User stories / acceptance scenarios: **6 / 24**
- Functional requirements / success criteria: **37 / 12**, sequential with no gaps
- OpenAPI: **7 paths / 11 unique operations / 35 schemas / 24 closed object components**
- OpenAPI references: **119**, all resolve; security is global Sanctum
- Implementation tasks: **120**, sequential with no gaps or duplicates
- Requirement traceability: **FR-001–FR-037 covered**; every story has RED, implementation, and gate tasks
- Unresolved placeholders or clarification markers: **0**
- Specification checklist failures: **0**
- Whitespace/patch failures: **0**

- Critical inconsistencies remaining: **0**
- High inconsistencies remaining: **0**
- Medium inconsistencies remaining: **0**
- Low/editorial inconsistencies remaining: **0**
- Constitution gates: **all pass**
- Protected/deployment scope: **absent**; handoff remains unrelated/untracked

The package is ready for RED-first implementation. No production change begins until the expected
absent-019 RED baseline is recorded. A second read-only analysis and exact closure evidence remain
mandatory after implementation.

## RED Baseline

The permanent 019 tests were added before production code and run against 018 HEAD
`7ee74dc374c7a8113b937f4bbb9b80705f780880`:

- Backend focused: **27 failed / 3 passed**. Failures were limited to absent 019 tables/models/factories,
  monthly constants/relations, Finance planning services/controllers/routes, Planner source, and Finance
  notification types; the legacy daily/weekly recurrence and static OpenAPI parse/ref tests passed.
- Web focused Vitest: **1 failed**, exactly because `getFinanceBudgets` and the other new typed client
  operations did not yet exist.
- Desktop focused Playwright: **2 failed**, both at the first missing `Budgets` tab (no earlier runtime or
  existing Finance-loop failure).

This is the expected absent-feature RED. Production implementation may now begin.

## Final Implementation Closure

The implementation closes all six stories without changing the documented scope or ownership model.
Monthly budgets derive actuals from the immutable 018 ledger in one bounded query set, including child
categories, reversals, historical FX evidence, incomplete conversion states, and exact 80/100 percent
boundaries. A dedicated query-budget regression proves that one and five monthly budgets both execute
five SQL queries.

Monthly recurring operations now use the shared recurrence engine with normalized month days,
deterministic short-month skips, a ten-year null-end ceiling, immutable occurrence snapshots, and
future-only reconciliation. Explicit actual/skipped facts are idempotent and owner-scoped; actualization
creates one ordinary 018 ledger transaction, while corrections remain append-only reversals and never
rewrite the accepted occurrence outcome. Cash flow includes open and actual plans, excludes skips,
separates mandatory/discretionary expense, reports outcome counts, and becomes wholly incomplete when a
required historical conversion is unavailable.

Planner and Notifications remain adapters over Finance-owned projections. Planner actions delegate to
the Finance service and expose safe Finance deep links. Direct reminders, approaching/exceeded budget
families, locale-at-delivery rendering, correction closure, and same-identity re-arming all share the 011
delivery/quiet-hours/escalation rules. Existing 009 and 011 contracts remain backward compatible.

### Final Evidence

- Specification: **6 / 24** stories/scenarios, **FR-001–FR-037**, **SC-001–SC-012**, and **120 / 120**
  implementation tasks; no unresolved placeholders or unchecked requirement items.
- API contract: OpenAPI 3.1, **7 paths / 11 unique authenticated operations / 35 schemas / 24 closed
  object components / 119 resolving local references**; registered routes and documented operations
  match exactly, including synchronized 009 Planner and 011 Notifications contracts.
- Backend: full Laravel suite **572 passed / 7603 assertions**; Pint check passed; strict Composer
  validation passed; locked dependency audit reported no advisories.
- Persistence: isolated SQLite rollback removed only the 019 budget/planning tables, preserved the
  pre-existing Finance schema and all three currency rows, and reapplied cleanly; all identifiers remain
  within the MySQL 64-character limit.
- Web: i18n guard passed **1479 keys across EN/RU/UK and 94 source files**; TypeScript/build passed
  (**170 modules**); Vitest **9 files / 36 tests** passed. The final focused planning/visual run passed
  **6 / 6**; full Playwright passed **96 with 8 conditional skips** on desktop and **103 with 1
  conditional skip** on mobile.
- Visual/accessibility: **36** final Finance budget/plan/cash-flow screenshots cover EN/RU/UK,
  light/dark, and desktop/390×844 viewports; every contact sheet was manually inspected after explicit
  loading waits, with no clipping, fallback copy, stale loading surface, or unusable control found.
- Mobile: Node **15 / 15**, npm audit **0 vulnerabilities**, Capacitor sync and validation passed with
  four expected plugins and shared-bundle fingerprint `612be657d1ee`.
- Graph impact: refreshed GitNexus contains **8,617 nodes / 21,190 edges / 487 clusters / 300 flows**.
  Shared-service review classifies `RecurrenceMaterializer` HIGH (17 direct consumers),
  `NotificationSourceSynchronizer` MEDIUM (8), `PlannerController` LOW (1), and the final budget query
  refactor LOW (4); every direct consumer is represented in the passing full suite. The staged detector's
  aggregate critical result (**479 changed symbols / 81 affected flows**) includes known index-baseline
  false positives for deployment and the handoff; the authoritative cached Git diff contains **108 files**
  and none from either protected scope.
- Hygiene: `git diff --check`, protected/deployment-path, secret-pattern, and >1 MiB file audits pass;
  `design_handoff_selfhandler_mvp/` remains unrelated and untracked.

### Final Consistency Result

- Critical/high/medium/low specification inconsistencies remaining: **0 / 0 / 0 / 0**
- Requirement and story traceability gaps: **0**
- Constitution violations: **0**
- Deferred-scope leakage: **0**
- Deployment changes or actions: **0**

Feature 019 is complete and ready for its single atomic commit. Debts, funds, financial goals,
purchase/restock links, investments, provider FX, integrations, AI, native offline authority, and
deployment remain explicitly deferred.
