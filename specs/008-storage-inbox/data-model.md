# Data Model: Storage Inbox and Quick Capture

**Feature ID**: `008-storage-inbox`

One additive migration, `2026_08_12_160000_create_storage_items`. Nothing existing is reshaped.

## `projects`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | owner boundary |
| `name` | string(160) | unique per user |
| `description` | text, nullable | |
| `is_archived` | boolean, default false | out of the way, still readable |
| `archived_at` | timestamp, nullable | server-derived |
| timestamps | | |

Unique `(user_id, name)`. Index `(user_id, is_archived)`.

## `tags`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | |
| `name` | string(64) | unique per user, stored trimmed |
| timestamps | | |

Unique `(user_id, name)`. Storage-local: no other module reads or writes them in this feature.

## `items`

Single table plus `type`, per `data-conventions.md` §2.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | |
| `type` | string(16) | `task` or `idea` |
| `title` | string(200) | required, trimmed |
| `description` | text, nullable | |
| `status` | string(16) | `inbox`, `active`, `done`, `dropped`; capture default `inbox` |
| `priority` | string(8), nullable | `low`, `normal`, `high` |
| `due_on` | date, nullable | calendar day, cast `date:Y-m-d`; nothing schedules it yet |
| `project_id` | fk projects, nullable, null on delete | deleting a project keeps its items |
| `parent_id` | fk items, nullable, null on delete | deleting a parent keeps its children |
| `is_blocker` | boolean, default false | meaningful only on a child |
| `completed_at` | timestamp, nullable | server-derived |
| `dropped_at` | timestamp, nullable | server-derived |
| timestamps | | |

Indexes: `(user_id, status)` for the inbox count and the active list, `(user_id, project_id)`,
`(user_id, parent_id)`.

`null on delete` on both foreign keys is the point of FR-013 and FR-017: removing a container must not
remove the work inside it.

## `item_tag`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | fk users, cascade | denormalised owner, as `goal_routine` already does |
| `item_id` | fk items, cascade | |
| `tag_id` | fk tags, cascade | |
| timestamps | | |

Unique `(item_id, tag_id)`.

## Invariants enforced in the application

| Invariant | Where |
|---|---|
| One level of nesting: a child cannot become a parent | `ItemController` validation |
| No self-parent and no cycle | `ItemController` validation |
| Parent, project and tag belong to the same user | model `saving` guards, matching `RoutineLog` |
| A parent cannot be completed while an open blocking child exists | `ItemCompletionGuard`, refused as a validation error |
| `completed_at` / `dropped_at` are server-derived | `Item::applyLifecycle` |

## Derived values

| Value | Owner | Rule |
|---|---|---|
| inbox count | Storage | `status = 'inbox'` for the user |
| project open/completed counts | Storage | one grouped query over `items`, never per project |
| blocking children of an item | Storage | direct children where `is_blocker` and status is open |

Analytics and Review will read these; they do not recompute them.
