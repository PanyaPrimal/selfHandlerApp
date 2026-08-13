# Requirements Checklist: In-App Notifications

**Purpose**: Verify that the delivery contract is complete and testable before planning.
**Created**: 2026-08-13
**Feature**: [spec.md](../spec.md)

## User Value and Scope

- [x] CHK001 Five prioritized journeys cover delivery, triage, quiet time, digest, and source closure.
- [x] CHK002 Each journey has an independently reproducible result at the user boundary.
- [x] CHK003 Direct versus digest source selection is explicit and avoids duplicate attention.
- [x] CHK004 External channels, deployment, and future module reminders are explicitly excluded.

## Data and State

- [x] CHK005 Notification state is distinct from authoritative domain status.
- [x] CHK006 Identity, retry behavior, UTC/profile-time-zone rules, and ownership are unambiguous.
- [x] CHK007 Read, dismiss, snooze, actioned, cancelled, and escalation transitions are testable.
- [x] CHK008 Settings defaults, atomic replacement, quiet-hour precedence, and category effects are set.

## Contracts and Localisation

- [x] CHK009 Inbox, settings, and action endpoint outcomes are specified.
- [x] CHK010 English, Russian, and Ukrainian cover UI, delivery copy, API feedback, and accessibility.
- [x] CHK011 Delivered-event locale behavior and user-authored non-translatable content are explicit.
- [x] CHK012 Desktop/mobile badge, inbox, empty/error/loading states, and polling are included.

## Verification

- [x] CHK013 Duplicate retries, quiet deferral, digest counts, escalation limits, and closure are measurable.
- [x] CHK014 Cross-account reads and mutations are explicitly invisible.
- [x] CHK015 Existing domain rows cannot be mutated by notification processing.
- [x] CHK016 No requirement remains marked NEEDS CLARIFICATION.
