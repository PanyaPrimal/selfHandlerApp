# Pre-Implementation Analysis: Interface Personalisation and Complete Localisation

**Date**: 2026-08-13
**Artifacts checked**: constitution 1.2.0, spec, requirements checklist, research, plan, data model,
OpenAPI delta, quickstart and tasks.

## Result

No critical or high-severity inconsistency remains. Implementation may begin after the roadmap and
active-feature pointer tasks are completed.

## Constitution Coverage

| Principle | Evidence | Result |
|---|---|---|
| Specifications before implementation | Complete approved artifacts and checked requirements | Pass |
| Design/delivery truth | `docs/design/localization.md`; feature-specific detail stays here | Pass |
| Thin vertical slice | Existing profile/theme plus all current UI; later modules deferred | Pass |
| Deterministic core | `Intl`, fixed catalogs and deterministic palette algorithm | Pass |
| User ownership/privacy | Session-derived profile, presentation-only caches | Pass |
| Contracts/tests together | Strict OpenAPI PATCH plus T005-T010/T033-T035 | Pass |
| Complete localisation | Explicit surface, three catalogs and enforcement gates | Pass |

## Cross-Artifact Traceability

- Every FR group maps to task groups in `tasks.md`.
- Every user story has focused browser coverage and measurable outcomes.
- Data-model state transitions match FR-007-FR-011 and the PATCH contract.
- Theme schema fields and enum values match between spec, data model and OpenAPI.
- The 4.5:1 threshold appears consistently in FR-018, data model, research and SC-004.
- Deployment exclusion is explicit in FR-027, plan constraints and full-gate protected-path audit.

## Resolved Findings

1. **Account locale collision**: Keeping locale in the full Account draft would let a later profile save
   revert a global change. T023 removes it from draft ownership and injects accepted session locale.
2. **Stale preference requests**: Optimistic updates need ordering. Research R3/data model/T021 require
   a monotonically increasing sequence and ignore stale completions.
3. **Raw custom background risk**: Literal arbitrary background colours cannot guarantee component
   contrast. R7/FR-017 derive bounded complete tokens and enforce the measured threshold.
4. **Guest versus profile authority**: R3 and FR-008/FR-011 explicitly make cache guest/prepaint state
   and profile authenticated truth.
5. **Backend English leakage**: FR-012/FR-013 and T014-T016 cover framework and custom API feedback,
   not only Vue copy.
6. **Changelog duplicate identity**: Research R9/T031 removes the current duplicate `storage-inbox`
   identifier while moving content to message keys.

## Medium/Low Risks to Watch During Implementation

- The hardcoded-copy heuristic can report technical literals. Exceptions must be individual and
  documented; directory-wide bypasses would violate FR-024.
- Russian/Ukrainian copy may expand controls. T010 and the full mobile suite must check real scroll
  width and global-control placement rather than screenshots alone.
- Laravel has many custom validation strings. T016 should use a repository-wide search after changes,
  and T006 must exercise representative controller/service refusals.
- Prehydration duplicates a small normalization subset. T013 must keep constants/schema compatible
  with runtime and browser tests must cover old/malformed caches.

## Authorization to Implement

All checklist items are complete, no NEEDS CLARIFICATION markers remain, and no constitution exception
requires user approval. Proceed sequentially through `tasks.md` on the existing branch.

## Post-Implementation Reconciliation

Completed on 2026-08-13. The implementation matches the approved artifacts with no critical or
high-severity drift:

- the strict partial PATCH accepts locale, a complete theme, or both atomically and derives ownership
  from the session;
- the existing theme JSON gained backward-compatible background defaults without a migration;
- 608 canonical message keys have exact EN/RU/UK parity, and the self-testing repository gate rejects
  parity, blank, unknown, unused, and hardcoded-copy defects;
- cached prehydration and runtime normalization share the same bounded locale/theme vocabulary, while
  successful authentication restores profile authority;
- every current route, shared control default, static changelog entry, API validation message and
  custom domain refusal is localised;
- preset/custom backgrounds derive the complete surface/text/border palette and maintain the tested
  minimum 4.5:1 text contrast in light and dark schemes;
- mobile testing found and closed one layering defect: open control surfaces now sit above the fixed
  global preference toolbar.

### Verification Evidence

- Laravel: 216 passed, 1459 assertions.
- Pint: passed.
- Localisation gate: 608 keys across three locales and 55 source files, passed.
- Vue typecheck and Vite production build: passed.
- Playwright desktop: 55 passed initially, seven expected copy-model regressions corrected, then all
  seven passed in focused reruns; seven viewport-conditional skips.
- Playwright mobile: 64 passed initially, four failures corrected, then all four passed in focused
  reruns; one desktop-only conditional skip.
- OpenAPI parse, whitespace and protected-path audits are recorded in the closing task evidence.
