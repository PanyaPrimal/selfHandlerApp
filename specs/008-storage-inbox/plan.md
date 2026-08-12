# Implementation Plan: Storage Inbox and Quick Capture

**Feature ID**: `008-storage-inbox`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/openapi.yaml](contracts/openapi.yaml) · **Quickstart**: [quickstart.md](quickstart.md)

## Summary

Add a user-owned capture inbox: one table of items with a `type`, projects, Storage-local tags, one
level of parent/child nesting with blocking, and a `/storage` screen built on the feature 005 controls.

## Technical Context

- Laravel 12 / PHP 8.4, Vue 3 / TypeScript. No new dependency.
- Additive migration only; no existing table, endpoint or behaviour changes.

## Architecture

```
apps/api/app/
  Models/Item.php                   single table + type, lifecycle, relations
  Models/Project.php
  Models/Tag.php
  Services/ItemCompletionGuard.php  the blocking rule, in one place
  Services/StorageSummary.php       inbox count and project counts, one grouped query
  Http/Controllers/ItemController.php
  Http/Controllers/ProjectController.php

apps/web/src/
  views/StorageView.vue             capture, inbox, triage, projects, children
```

**Boundaries**

- The blocking rule lives in `ItemCompletionGuard` and is consulted by every path that closes an item,
  so it cannot be bypassed by a second endpoint later.
- Counts are owned by `StorageSummary`. Review and Analytics will read them.
- Nothing here schedules, reminds or expands. Planner reads this model; it does not copy it.

## Architecture Gate Answers

1. **Owner**: Storage owns the item, its status, its hierarchy and every count derived from them.
2. **Inputs**: the time zone comes from Profile; a due date is a calendar day and nothing more.
3. **Time**: `due_on` is `Y-m-d`; `completed_at` and `dropped_at` are UTC instants set by the server.
4. **Scheduling**: none. A due date is data, not a schedule. Recurring items would use the feature 006
   engine, and no such case exists yet.
5. **Cross-module links**: none. Purchase-to-money and idea-to-goal are deferred with their features.
6. **Evolution**: additive; rollback drops four new tables and nothing else.
7. **Contracts**: new endpoints, an OpenAPI document, typed frontend payloads and browser coverage
   change together.
8. **Aggregates**: inbox and project counts are computed here, in bounded queries.
9. **Privacy**: `user_id` on all four tables; cross-account parents, projects and tags refused.
10. **Deferral**: purchases, lists, global tags, scheduling and AI triage each name the feature that
    brings them.

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I | Pass | Contract authored first. |
| II | Pass | Follows `data-conventions.md` §2; resolves two open questions in `modules.md` and records that the container question stays open. |
| III | Pass | Two types, one container, local tags, one nesting level - each with a consumer now. |
| IV | Pass | Manual triage, which the design mandates as Level 1. No AI. |
| V | Pass | Ownership on every table and every relationship. |
| VI | Pass | Migration, API, ownership, contract and browser tests in the same change. |

**Accepted deviations**: none.

## Phases

| Phase | Content |
|---|---|
| 1 Setup | Fixtures and the shared vocabularies. |
| 2 Foundational | Migration and models with their ownership guards. |
| 3 US1 | Capture and the inbox. |
| 4 US2 | Triage, tags, lifecycle. |
| 5 US3 | Hierarchy, nesting limit, cycles, blocking. |
| 6 US4 | Projects and their counts. |
| 7 US5 | The screen, navigation, responsive and keyboard behaviour. |
| 8 Polish | OpenAPI plus its guard, changelog, full gate, evidence. |

## Risks

| Risk | Mitigation |
|---|---|
| A second task model appears later in Planner | This feature is the owner; the roadmap orders it first for exactly this reason. |
| Cycles or deep nesting corrupt the blocking rule | One level, enforced server-side, with cycle and depth tests. |
| Deleting a container quietly deletes work | `null on delete` on both keys, asserted by test. |
| Counts drift from the items | Computed on read from the items themselves, never cached. |
| A long inbox degrades | Explicit bound plus a fixed-query-count assertion. |
