<!--
Sync Impact Report
- Version change: 1.0.0 -> 1.1.0
- Amended constraints:
  - Application runtime baseline: Laravel 11 -> Laravel 12 and PHP 8.4
- Rationale:
  - The supported Laravel 12 line removes known dependency advisories and matches the immutable
    production runtime required by feature 002.
- Reviewed artifacts:
  - specs/002-homelab-deployment/plan.md: compatible
  - specs/002-homelab-deployment/research.md: compatible
  - specs/002-homelab-deployment/tasks.md: compatible
- Follow-up TODOs: none
-->
# SelfHandler Constitution

## Core Principles

### I. Specifications Before Implementation

Every product feature MUST have an active Spec Kit feature specification before application code is
created or materially changed. The specification MUST describe user value, prioritized user stories,
testable acceptance scenarios, explicit scope boundaries, and measurable success criteria. Technical
decisions belong in the feature plan, and executable work belongs in the feature task list. Existing
prototype code is evidence and may be reused, but it MUST NOT override an approved specification.

### II. Vision and Delivery Have Distinct Sources of Truth

`docs/design/` is the canonical description of the intended long-term product, domain language, and
locked cross-cutting decisions. `specs/<feature>/` is the canonical delivery contract for the active
increment. Feature artifacts MUST link to relevant design documents instead of copying them. If an
active feature exposes a conflict with the long-term design, the conflict MUST be resolved explicitly
and every affected source of truth updated in the same change.

### III. Thin Vertical Slices and Deliberate Simplicity

Implementation MUST proceed through independently usable vertical slices that include the minimum
necessary user interface, API behavior, persistence, and verification. A feature MUST NOT implement
deferred modules, generalized engines, integrations, or infrastructure merely because they appear in
the long-term vision. New abstractions require at least one current consumer and a documented reason
in the plan. The simplest design that satisfies the active specification is preferred.

### IV. Deterministic Core, Optional AI

Every core SelfHandler capability MUST remain fully usable without an LLM. Domain calculations,
validation, state transitions, and aggregates MUST be deterministic application behavior. AI MAY
explain, summarize, classify, or propose actions on top of that behavior, but it MUST NOT silently
recompute authoritative values. Any AI-initiated write MUST show the intended change and require
explicit user confirmation. Sensitive context MUST be minimized according to `docs/design/llm-layer.md`.

### V. User-Owned Data and Privacy by Design

Every domain record MUST be owned by a user from its first migration, even while the product behaves
as single-user. Reads, writes, relationships, and uniqueness constraints MUST preserve that boundary.
Secrets and provider tokens MUST be encrypted and MUST NOT be committed, logged, or exposed to the
client. Private attachments and health or financial data MUST default to the least exposure necessary
for the active feature. Dates, money, units, deletion, and archiving MUST follow
`docs/design/data-conventions.md`.

### VI. Contracts and Tests Move Together

Behavior changes MUST include verification at the closest useful boundary. Laravel domain and API
behavior requires automated backend tests; TypeScript contracts and Vue behavior require type-safe
client updates; a user-visible cross-application flow requires Playwright coverage when practical.
API response or request changes MUST update backend tests, frontend types, and affected consumers in
the same feature. Tests MUST verify user-observable outcomes and ownership boundaries, not only happy
path implementation details.

## Product and Technology Constraints

- Product and repository documentation MUST be written in English. Personal learning notes outside
  the repository may remain in another language.
- The delivery architecture is a monorepo with Laravel 12 on PHP 8.4 in `apps/api`, Vue 3 and Vite
  in `apps/web`, and a Capacitor shell in `apps/mobile`.
- Web and API communicate through explicit REST contracts. Mobile reuses the web client unless an
  approved feature plan demonstrates a platform-specific need.
- MySQL 8 is the intended primary database. SQLite MAY be used for isolated automated tests when the
  tested behavior is database-portable.
- Open Server and PowerShell on Windows are the primary local-development path. Docker and homelab
  deployment remain optional until specified by a feature.
- Shared mechanisms such as recurrence, notifications, attachments, integrations, and long-period
  analytics MUST be introduced only by a feature that needs them and MUST respect their design docs.

## Development Workflow and Quality Gates

1. Begin each product increment with `$speckit-specify`; use `$speckit-clarify` when a decision can
   materially change scope, privacy, or user experience.
2. Produce and review `plan.md` before generating `tasks.md`. The plan MUST include a constitution
   check and name any deliberate deviation; an unexplained violation blocks implementation.
3. Use `$speckit-analyze` after task generation. Critical constitution or coverage findings MUST be
   resolved before `$speckit-implement`.
4. Work on the branch already checked out by the user. Agents and project automation MUST NOT create,
   switch, merge, or delete Git branches without explicit user instruction. The Spec Kit Git extension
   MUST remain uninstalled unless the user changes this rule.
5. Before declaring a feature complete, run the checks relevant to changed behavior:
   - backend: `php artisan test` from `apps/api`;
   - frontend: `npm run typecheck` and `npm run build` from `apps/web`;
   - end-to-end: `npm run test:e2e` from the repository root for affected product flows.
6. A feature is complete only when its acceptance scenarios pass, its documentation and contracts
   match the implementation, and remaining work is recorded explicitly rather than implied.

## Governance

This constitution governs feature specifications, implementation plans, task lists, and code changes.
Where guidance conflicts, this constitution takes precedence for delivery workflow; locked product and
domain decisions remain authoritative in `docs/design/` unless amended explicitly.

Amendments require a documented rationale, an update to the Sync Impact Report, and a semantic version
change. MAJOR versions remove or redefine a principle incompatibly, MINOR versions add a principle or
materially expand governance, and PATCH versions clarify wording without changing obligations. Every
feature plan and pre-implementation analysis MUST check constitution compliance. Exceptions require
explicit user approval and MUST be recorded in the affected plan under Complexity Tracking.

**Version**: 1.1.0 | **Ratified**: 2026-08-07 | **Last Amended**: 2026-08-10
