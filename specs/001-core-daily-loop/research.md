# Research: Core Daily Loop

> **Authentication supersession (2026-08-09):** The `CurrentUser` fallback described below is retained
> only as historical research for the original 001 implementation order. Feature
> `003-multi-user-auth` replaces that choice; later 001 work MUST use its explicit authenticated user
> and MUST NOT restore the fallback.

## Existing Prototype Disposition

**Decision**: Evolve the existing Laravel/Vue vertical slice in place and judge every file against the
new specification. Preserve working contracts where they match; refactor or replace behavior that does
not meet lifecycle, ownership, timezone, progress, or UI-state requirements.

**Rationale**: The prototype already proves the monorepo, API/client connection, responsive layout,
and isolated Playwright environment. Recreating those pieces provides no user value, while treating
them as normative would let accidental early choices override the approved feature specification.

**Alternatives considered**: Delete all prototype code and restart; accept the existing MVP technical
design unchanged. Both were rejected because the first wastes validated setup and the second bypasses
the Spec Kit review now requested by the user.

## Routine Representation for the First Slice

**Decision**: Represent each checklist action as a Routine with a simple daily or selected-weekday
schedule. Defer long-term routine templates, nested activities, and `RecurringRule` materialization.

**Rationale**: This creates an independently useful P1 loop using one current consumer and matches the
explicit thin-slice rule. The long-term model in `docs/design/modules.md` remains authoritative for a
future feature that introduces templates and reusable recurrence.

**Alternatives considered**: Implement routine templates and activities now; implement the complete
recurrence engine now. Both enlarge the first delivery without being required by its acceptance tests.

## Schedule History Without the Recurrence Engine

**Decision**: Allow schedule-defining fields to change freely until the first Routine Log exists. Once
history exists, keep the schedule immutable and guide the user to archive the Routine and create a
replacement. Preserve `archived_at` so past-date evaluation can distinguish historical from current
planning, and always expose existing logs in historical views.

**Rationale**: Applying a new weekday set retroactively would silently rewrite seven-day denominators
and streaks. Full schedule versioning or planned occurrences belongs to the deferred recurrence-engine
feature; immutability keeps this slice accurate with a small, explicit rule.

**Alternatives considered**: Apply edits retroactively; version every schedule; materialize all daily
occurrences now. The first corrupts history and the other two prematurely implement deferred systems.

## Archive and Delete Semantics

**Decision**: Add `is_archived` to Routine and Goal. Use `is_active` only to pause routine scheduling,
status for the goal lifecycle, and `deleted_at` only for future trash/delete behavior.

**Rationale**: `docs/design/data-conventions.md` explicitly separates domain archiving from soft
deletion. Historical logs, links, and progress remain meaningful for archived records.

**Alternatives considered**: Reuse `is_active` as archive; soft-delete records when the user hides
them. Both make history ambiguous and conflict with the constitution.

## User Ownership Enforcement

**Decision**: Introduce one lightweight `UserOwned` model concern that applies current-user scoping
when a user is authenticated, while controllers still resolve the local/testing user explicitly and
include `user_id` in writes, relationship checks, and unique constraints.

**Rationale**: Four current models need the same boundary, so the abstraction has multiple immediate
consumers. Explicit controller ownership remains defense in depth and keeps the temporary local-user
bootstrap understandable.

**Alternatives considered**: Controller filters only; a repository layer. Filters alone are easy to
omit, while a repository layer adds indirection with no current need.

## Timezone Boundary

**Decision**: Keep Laravel's `APP_TIMEZONE` on `UTC`, add a separate `SELFHANDLER_TIMEZONE` environment
setting for the temporary single-user calendar boundary, and keep stored timestamps in UTC. Interpret
route date parameters and schedule weekdays in the configured SelfHandler timezone.

**Rationale**: Profile does not yet exist, but the first slice still needs one explicit and testable
calendar boundary. The Profile feature can later replace the configuration value with a user setting
without changing stored calendar dates or public contracts.

**Alternatives considered**: Set Laravel's global timezone to Kyiv; assume server local time; build
Profile now. A separate calendar setting is portable, preserves UTC persistence, and keeps Profile
outside this feature.

## Streak and Seven-Day Progress

**Decision**: Compute progress on demand from routines and logs over a bounded date window. A streak
walks backward through scheduled occurrences; a missing occurrence breaks the streak only after that
calendar date has ended. Skipped occurrences break it immediately. No rollup table is introduced.

**Rationale**: The first slice has small single-user data and needs only today plus seven days. Raw
logs remain the source of truth, while a future long-period Analytics feature can introduce the
designed daily-rollup layer when its query scale justifies it.

**Alternatives considered**: Persist streak counters; create `daily_metrics` now. Both add cache
invalidation and reconciliation work before there is a long-period analytics consumer.

## API Contract Style

**Decision**: Keep the existing REST resource paths and JSON envelope conventions, add a DELETE action
for clearing a routine log to pending, and extend Today with one nested progress object.

**Rationale**: This minimizes disruption to the typed Vue client while giving every specified state a
clear operation. OpenAPI documents the boundary and drives paired backend/frontend updates.

**Alternatives considered**: Replace the API with GraphQL; create command-style endpoints for every
transition. Neither is justified for five entities and four screens.

## Verification Strategy

**Decision**: Use Laravel feature tests for contracts and ownership, focused unit tests for schedule/
streak calculations, TypeScript type checking plus production build for client contracts, and one
Playwright suite that covers each user story on desktop and phone viewports.

**Rationale**: These are the closest useful boundaries required by the constitution. They protect the
cross-application path without introducing a second frontend unit-test stack during the first slice.

**Alternatives considered**: Browser tests only; add a frontend component-test framework immediately.
Browser-only failures are slower to diagnose, while a new component stack is not yet required by the
small view layer.
