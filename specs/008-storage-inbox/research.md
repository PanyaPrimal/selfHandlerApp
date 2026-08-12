# Research: Storage Inbox and Quick Capture

**Feature ID**: `008-storage-inbox` · **Date**: 2026-08-12

## R1 — How the polymorphic item is stored

`modules.md` leaves this open ("single-table vs STI vs separate detail tables — to decide at the schema
stage"), but `data-conventions.md` §2 has already decided it for this entity: Storage Item is listed
under "single-table + type + nullable/JSON", next to Goal and Debt, because the types are similar and
share most fields. Class-table is reserved for genuinely divergent types such as Workouts.

**Decision**: one `items` table with a `type` column. In this increment `task` and `idea` differ only in
what the user means by them, not in what they store, so a detail table would be an empty abstraction.
Purchases will add real fields (price, currency, money link); when they arrive, the choice is revisited
against the same rule rather than assumed.

Explicitly rejected: STI packages. `data-conventions.md` §2 forbids them by name — "plain Eloquent
models, without `tightenco/parental` or any other STI magic … every query is visible".

## R2 — Which types belong in this increment

The roadmap ships "task and idea flows" and defers "purchase completion, finance links".

A purchase's defining rule in `modules.md` is an invariant with Finance: "a purchase in the 'bought'
status ⟺ there exists a linked expense transaction or installment-plan debt". Shipping the type without
the link would leave a status that cannot mean what the design says it means, and would invite a second
implementation later.

**Decision**: `task` and `idea` only. List items wait for the List container, which in turn waits for
something to compare it against — see R5.

## R3 — Inbox: a status or a view

Open question in `modules.md` ("a separate status vs a separate view").

**Decision**: a status. The design already describes the flow as "quick capture → inbox → sorting →
processing" and calls the inbox a state an item is *in*. Making it a status means "how much is unsorted"
is a `where status = 'inbox'` count rather than a filter rule each screen reimplements, and the module
owns that aggregate as its own principle requires. A view would have to be defined as "no project and no
tags and not started", which is three implicit rules instead of one explicit column.

## R4 — Hierarchy depth

Open question in `modules.md` ("only 2 levels or arbitrary").

**Findings**: the concrete cases the design names are an idea with dependent purchases and tasks, and a
task with subtasks. Both are one level. Arbitrary depth makes the blocking rule recursive, needs cycle
detection across a path rather than a pair, and makes "is this parent completable" an unbounded query.

**Decision**: one level. An item with a parent cannot become a parent. Blocking is then a single query
against direct children, and cycle prevention reduces to "not itself, and the parent has no parent".
The limit is enforced server-side with a clear message, and lifting it is a later change with a real
case behind it rather than a guess now.

## R5 — Project and List as one container or two

Open question in `modules.md`.

**Decision**: not answered here, and deliberately so. Only `Project` ships, because only tasks and ideas
ship. Deciding whether a List is the same thing wearing a different type requires an actual List with
actual list items to compare; inventing the shared container now would be exactly the speculative
abstraction constitution principle III forbids. Recorded so the question is not lost.

## R6 — Tags

`modules.md` marks tags "a candidate to become the common tag mechanism for the whole app … for now
local to Storage", and the roadmap defers "global tag extraction" until a second consumer exists.

**Decision**: `tags` and an `item_tag` pivot, both user-owned, names unique per user. Setting an item's
tags replaces the set, creating unknown names as it goes, which is what a tag input does. Nothing
outside Storage touches them. Extraction is triggered by the second module that needs compatible
behaviour, not by this one.

## R7 — Blocking semantics

**Decision**: a parent cannot be *completed* while a child that is marked as a blocker is still open
(`inbox` or `active`). Dropping the child also unblocks, because a dropped item is closed.

Only completion is restricted. Editing the parent's title, project, tags or priority stays available:
the blocker describes readiness to finish, not a lock on the record. The refusal is a validation error
naming the blocking children, so the user learns what to do rather than what failed.

## R8 — Query bounds

Every list takes an explicit limit, default 200, maximum 500, and eager-loads tags and children counts
so a long inbox costs a fixed number of queries. Project counts are aggregated in one grouped query
rather than per project, which a test asserts.

## Constitution Check

| Principle | Assessment |
|---|---|
| I | Full contract before implementation. |
| II | Follows `data-conventions.md` on storage shape; resolves the inbox and depth questions in `modules.md` and records that the container question stays open. |
| III | Two types, one container, local tags — each with a consumer now. Purchases, lists, scheduling and global tags deferred with named triggers. |
| IV | No AI. Triage is manual, which the design mandates as its Level 1. |
| V | `user_id` on every new table; cross-account parents, projects and tags refused. |
| VI | Migration, API, ownership, contract and browser coverage move with the code. |
