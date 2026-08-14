# Requirements Quality Checklist: Calendar Integration

**Purpose**: Validate specification completeness, clarity, testability, privacy, contracts, and delivery scope.

- [x] CHK001 Every story states independently useful user value, priority, independent test, and Given/When/Then acceptance.
- [x] CHK002 Google OAuth and Apple CalDAV authentication/selection paths are explicit without assuming live credentials.
- [x] CHK003 Integration, SyncedItem, ExternalCalendarEvent, provider DTO, ownership, encryption, and deletion semantics are defined.
- [x] CHK004 The import window, incremental cursors, pagination, 410/full-refresh, CalDAV ETag fallback, and pruning are testable.
- [x] CHK005 Timed/all-day/cross-day/timezone/exclusive-end semantics are unambiguous.
- [x] CHK006 Imported events are read-only Planner projections and cannot mutate or duplicate local domain facts.
- [x] CHK007 Export defaults to none and every permitted category/privacy warning is explicitly enumerated.
- [x] CHK008 Local-versus-provider authority, conflict counting, create/update/delete direction, and retry convergence are explicit.
- [x] CHK009 Secret/raw payload/title minimization, API masking, logging, backups, scopes, TLS, and response boundaries are explicit.
- [x] CHK010 Manual/scheduled sync locking, transient/auth failures, status changes, counts, and cursor transaction boundaries are testable.
- [x] CHK011 Disconnect behavior explicitly preserves local domain facts and all remote calendar events.
- [x] CHK012 REST/OpenAPI/backend/TypeScript/Vue/command contracts and closed enums/errors are included.
- [x] CHK013 EN/RU/UK visible/accessibility/backend copy and locale/timezone/count formatting are included.
- [x] CHK014 Desktop/exact-phone, keyboard, ARIA/status, 44 px, focus, wrapping, no overflow, light/dark and visual inspection are gates.
- [x] CHK015 Additive MySQL migrations, owner/schema/preservation/identifier guards and portability exclusions are covered.
- [x] CHK016 Google Android callback limitation is honest and bounded; shared bundle behavior remains useful.
- [x] CHK017 Architecture gates answer owner, inputs, time, recurrence, direction, evolution, contracts, aggregates, privacy, and deferrals.
- [x] CHK018 Fitness/bank/AI/notifications/offline/RRULE/multiple-calendar/deployment/feature002/live-data scope is explicitly deferred or excluded.
- [x] CHK019 Success criteria are measurable through automated behavior, not file existence.
- [x] CHK020 No placeholder, unresolved clarification, contradiction, unsupported claim, or critical/high analysis finding remains.
