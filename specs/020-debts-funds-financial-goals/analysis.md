# Specification Analysis: Debts, Funds, Financial Goals, and Purchase Links

**Date**: 2026-08-14

**Mode**: `$speckit-analyze` read-only review after the complete specification package; RED and final
implementation evidence are appended at their checkpoints.

## Inputs Reviewed

- `spec.md`, requirements checklist, `research.md`, `plan.md`, `data-model.md`, `quickstart.md`,
  `contracts/openapi.yaml`, `tasks.md`, Constitution 1.2.0, canonical Goal/Storage/Finance/recurrence/
  notification documents, and delivered 006/008/009/011–013/017–019 contracts/code
- Refreshed GitNexus pre-change impact at `23b5aa0cda4ef2a925c8bb951567f8145d66152d`

## Findings and Remediation

| ID | Severity | Finding | Resolution |
|---|---:|---|---|
| A-001 | High | Replacing 019 operation IDs for the same routes would create client/contract drift. | Restore `listFinancePlannedOccurrences`, `putFinanceOccurrenceOutcome`, `clearFinanceOccurrenceOutcome`, and `showFinanceCashFlow`; extend shapes additively. |
| A-002 | High | Virtual allocation was append-only but its reversal had no closed request variant. | `FundMovementInput` now has explicit closed post and reversal variants with stable idempotency and required reason. |
| A-003 | Medium | Task T167 stated 167 tasks although the sequence ends at T173. | Correct the closure count to 173 and verify continuous unique IDs. |
| A-004 | Medium | Staged impact review mentioned only high/critical, omitting known medium ledger/notification consumers. | T163 now requires every medium/high/critical direct consumer. |
| A-005 | High | Monthly rule's general ten-year ceiling could silently under-produce a very long fixed installment count. | Bound fixed count to 120 and explicitly require the Nth valid date within ten years. |
| A-006 | High | A source-side purchase status could be forged independently from the money invariant. | Keep Storage lifecycle storage, prohibit direct bought mutation, and make one Finance service synchronize it under source/debt locks. |
| A-007 | High | Treating restock expense as delivery would move stock truth into Finance. | Preserve a one-way immutable source link only; Supplements alone resolves inventory/proposal lifecycle. |
| A-008 | Medium | Emergency expense-month mode could treat no history as zero. | Require three complete prior months with at least one expense fact; missing history is an explicit unavailable state. |

## Coverage Result After Remediation

- User stories / acceptance scenarios: **7 / 28**
- Functional requirements / success criteria: **44 / 13**, sequential with no gaps
- OpenAPI: **14 paths / 19 unique operations / 57 schemas / 49 closed object components**
- OpenAPI references: **236**, all resolve; security is global Sanctum
- Implementation tasks: **173**, sequential with no gaps or duplicate task lines
- Requirement traceability: **FR-001–FR-044 covered**; every story has RED, implementation, and gate tasks
- Specification checklist: **16 / 16**
- Unresolved clarification/placeholder markers: **0**
- Critical inconsistencies remaining: **0**
- High inconsistencies remaining: **0**
- Medium inconsistencies remaining: **0**
- Low/editorial inconsistencies remaining: **0**
- Constitution violations: **0**
- Protected/deployment scope: **absent**; handoff remains unrelated/untracked

## Shared-Impact Gate

GitNexus classifies RecurringRule **CRITICAL** (47 direct consumers), Goal **HIGH** (22), Item **HIGH**
(15), RecurrenceMaterializer **HIGH** (17), FinanceLedgerService **MEDIUM** (7), and
NotificationSourceSynchronizer **MEDIUM** (8). Tasks therefore require permanent legacy tests before
production edits, focused direct-consumer checkpoints by phase, a full Laravel/browser regression, and
staged re-analysis before commit.

## Requirement Traceability

| Requirement group | Story / primary tasks |
|---|---|
| FR-001 | US1 / T036, T039–T040, T049–T052 |
| FR-002–FR-005 | US1 / T019, T037–T053 |
| FR-006–FR-012 | US2 / T024–T027, T054–T070 |
| FR-013–FR-018 | US3 / T020, T071–T088 |
| FR-019–FR-025 | US4 / T089–T105 |
| FR-026–FR-029 | US5 / T021, T106–T120 |
| FR-030–FR-036 | US6 / T022–T023, T121–T137 |
| FR-037–FR-039 | Foundation/shared gates / T011–T035, T154–T157 |
| FR-040–FR-041 | US7 / T138–T161 |
| FR-042–FR-044 | Evolution/ownership/deferral / T018, T028–T030, T162–T173 |

## Completion Standard

The package completed `$speckit-implement` without entering the prohibited deployment, feature 002,
workflow/live-data, branch/worktree/merge, handoff, compound-interest, investment, provider,
report/portability, calendar-integration, AI, or native-offline-authority scopes.

## RED Baseline

Permanent 020 tests were added against 019 HEAD `23b5aa0cda4ef2a925c8bb951567f8145d66152d`
before any production implementation:

- Backend focused: **5 failed** at the first absent 020 table, model, factory, or service. Database refresh,
  authentication/support fixtures, and every pre-020 migration completed before those failures.
- Web focused Vitest: **1 failed**, exactly because the new counterparty/debt/fund/goal/source typed client
  methods were not exported.

The failures are the expected absent-feature boundary. Production schema/model implementation may begin.

## Final GREEN Evidence

- Laravel: **632 passed / 8966 assertions / 0 failed / 0 skipped** after the last PHP change. The
  focused 020 Finance contract adds **2 passed / 812 assertions**.
- Migration evolution: isolated 020 rollback removed only its additions, preserved seeded 019 rows,
  and reapplied successfully: **1 passed / 14 assertions**.
- Static and supply-chain gates: Pint passed; strict Composer validation passed; locked Composer audit,
  web npm audit, and mobile npm audit each reported **0 vulnerabilities**.
- Shared web client: i18n guard passed **1627 keys across EN/RU/UK and 98 source files**; Vitest passed
  **10 files / 37 tests**; the production build type-checked and transformed **176 modules**.
- Full browser regression: desktop passed **99** with **8 conditional skips** and mobile exact 390×844
  passed **104** with **3 conditional skips**, both with zero failure. The post-changelog targeted run
  passed **6/6** across desktop/mobile.
- Final 020 browser evidence: the commitment journey and source journey passed on desktop while their
  intentionally desktop-only mobile variants skipped; the final visual run passed **4/4** and generated
  exactly **60** EN/RU/UK × light/dark × desktop/exact-390 screenshots. Every final screenshot was
  reinspected after the last CSS/wait change; no loading frame, truncation, overlap, unreadable control,
  or horizontal overflow remained.
- Mobile shell: Node passed **15/15**, Capacitor synchronized four plugins, validation passed, and the
  final shared bundle fingerprint is **`78849023faba`** using the documented safe HTTPS test origin.
- Safety: `git diff --check` passed; strong-secret and suspicious added-assignment scans returned zero;
  no dependency manifest/lock changed; no authorized candidate exceeded 1 MiB; no generated output or
  protected/deployment path entered status. The untracked handoff remained seven files with manifest
  hash **`86fc680bc0265c902af697aa41a2d668b0b2f240`**.
- Final Spec Kit analysis: **173/173** continuous unique task IDs, **44/44 FR** with expanded
  traceability, **13/13 SC**, **28/28 acceptance scenarios**, **16/16 checklist items**, zero unresolved
  markers, and zero remaining critical/high/medium/low inconsistencies.
- GitNexus was force-refreshed to **9391 nodes / 23367 edges / 532 clusters / 300 flows**. The staged
  change-impact result classified the 156-file feature as **CRITICAL** with **784 changed symbols** and
  **97 affected flows**. Direct review covered RecurringRule (**CRITICAL / 54**), Goal (**CRITICAL /
  31**), Item (**HIGH / 22**), RecurrenceMaterializer (**HIGH / 19**), FinanceLedgerService (**MEDIUM /
  10**), and NotificationSourceSynchronizer (**MEDIUM / 8**) consumers. Every production d=1 consumer
  is covered by the green full suite and the focused recurrence, Goal/body/training/nutrition/habit,
  Storage, Planner, Notifications, Finance, and source-link integrations.
- The cached MCP context still labelled document symbols from the preceding 019 commit as changed.
  This mapping artifact does not alter the Git boundary: authoritative cached-path inspection reports
  **156 staged files**, **0 protected/deployment**, **0 handoff**, **0 generated agent context**, and
  **0 dependency manifests/locks**.
