# Pre-Implementation Analysis: In-App Notifications

**Date**: 2026-08-13
**Artifacts checked**: constitution 1.2.0, spec, requirements checklist, research, plan, data model,
OpenAPI contract, quickstart, and tasks.

## Result

No critical or high-severity inconsistency remains. Implementation may begin after failing contract,
domain, and browser tests are in place.

## Constitution Coverage

| Principle | Evidence | Result |
|---|---|---|
| Specifications before implementation | Complete specification, clarification decisions, checklist | Pass |
| Design/delivery truth | Canonical notifications/recurrence/planner sources linked; 011 details local | Pass |
| Thin vertical slice | Two current sources, one channel; external adapters/audit deferred | Pass |
| Deterministic core | Explicit source predicates, state machine, time rules, fixed type policy | Pass |
| User ownership/privacy | User FKs, owner scopes, opaque aliases, 404 boundary | Pass |
| Contracts/tests together | OpenAPI/API/TS/browser and queue/scheduler tests mapped in tasks | Pass |
| Complete localisation | UI, backend delivery, validation, formatting, and all gates named | Pass |

## Cross-Artifact Traceability

- FR-001–FR-007 map to schema/channel/job/source-boundary tasks.
- FR-008–FR-014 map to source/digest/escalation tests and services.
- FR-015–FR-020 map to settings, quiet-hours, state, and snooze tasks.
- FR-021–FR-026 map to OpenAPI, controllers, store, shell, view, and browser coverage.
- Every user story has a focused backend and/or browser test before its implementation.
- The data-model status table matches the action contract and UI-visible states.
- All timestamps are consistently classified as UTC instants or profile-local wall times.
- Direct and digest eligibility are mutually exclusive in spec, research, and task coverage.

## Resolved Findings

1. **Laravel generic database notification mismatch**: R2 uses one richer schedule/inbox row rather
   than duplicating it into the framework's generic payload table.
2. **Synthetic digest uniqueness**: local `YYYYMMDD` is a required numeric source id, so the composite
   uniqueness rule works identically on MySQL and SQLite.
3. **Locale race**: title/body are rendered at delivery from current profile locale, not at future-row
   creation.
4. **Snooze clock trust**: the API accepts a finite duration and the server computes the UTC instant.
5. **Dismiss versus source reset**: dismissal is terminal for the source family; source-driven
   cancellation/action can be re-armed only when the fact returns to a new pending state.
6. **Digest duplication**: timed occurrences and high-priority tasks are explicitly excluded from the
   minor digest count.
7. **Read-triggered delivery temptation**: UI reads never create or deliver. Scheduler/queue owns
   reliability; client polling only refreshes inbox state.

## Medium/Low Risks to Watch During Implementation

- Bulk `update` does not emit model events; source reconciliation must be explicit and tested rather
  than rely on observers of `PlannedOccurrence`.
- A cross-midnight quiet end must select the correct local calendar day before conversion to UTC.
- Source titles are user content and must not be passed through translation or HTML rendering.
- `action_url` must remain a relative allow-listed Planner path before `Router.push`.
- Poll timers/listeners must be cleaned up when `AppShell` unmounts or the session changes.
- The existing i18n hardcoded-copy gate must include the new Vue view and Laravel delivery catalogs.

## Authorization to Implement

All checklist items are complete, no NEEDS CLARIFICATION marker remains, and no constitution exception
requires user approval. Proceed sequentially through `tasks.md` on the existing branch.

## Post-Implementation Reconciliation

**Date**: 2026-08-13

The implementation matches the approved boundary: one user-owned schedule/inbox record, stable source
aliases, source-authoritative closure, runtime channel resolution, current-profile localisation at
delivery, UTC instants, profile-local calendar rules, and no deployment changes. The canonical design
now records the concrete first consumers and defers Android/native delivery to feature 012.

The final implementation review found one test-fixture defect after the dispatcher gained a last-moment
source check: three delivery tests used nonexistent planned-occurrence ids. They now create real owned
occurrences, while the new race test proves a source completed after synchronization is actioned instead
of delivered. GitNexus reported LOW impact and no callers for the three corrected test methods.

## Verification Evidence

- Laravel: `247 passed` with `1612 assertions`; notification delivery focused suite: `7 passed` with
  `31 assertions`; Pint apply/check passed.
- Contract: OpenAPI 3.1 parses with 5 paths and all 6 authenticated notification operations match real
  routes; the notification scheduler is registered every minute.
- Localisation/web: `664` nonblank keys in exact EN/RU/UK parity across 57 scanned source files;
  TypeScript typecheck and the 108-module production build passed.
- Browser: focused notifications matrix passed `8/8`; complete desktop project passed `66` with 7
  viewport-condition skips, and complete mobile project passed `72` with 1 desktop-only skip.
- Accessibility/responsive: exact unread count has a live-region announcement; notification actions,
  settings, keyboard flow, and 390×844 no-overflow behavior are covered by the focused matrix.
- Repository: `git diff --check`, staged scope, protected deployment paths, and preserved untracked
  `design_handoff_selfhandler_mvp/` were audited before the atomic commit.
- GitNexus whole-diff risk was reviewed rather than accepted at face value: its CRITICAL class-level
  result came from 43 imports of `User`, whose existing behavior was unchanged; the new settings helper's
  HIGH fan-out is confined to six notification collaborators covered by the full suite. Its apparent
  deployment/handoff markdown changes were index false positives and are absent from git diff/stage.

No critical, high, or unresolved medium finding remains. Requirements FR-001–FR-026 and success
criteria SC-001–SC-010 are covered by implementation plus automated evidence.
