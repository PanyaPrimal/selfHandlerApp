# Implementation Analysis: Calendar Integration

## Result

**PASS — no critical or high finding.** Specification, research, data model, plan, OpenAPI contract, checklist,
and tasks describe the same two-provider, one-calendar, minimal two-way synchronization slice.

## Traceability Review

| Concern | Specification | Design/contract | Planned evidence | Finding |
|---|---|---|---|---|
| Provider connection | US1, FR-001–007 | research D1–D4, OpenAPI connect/callback/select | provider/OAuth/ownership tests | None |
| Imported busy time | US2, FR-008–011 | minimal event model, ExternalCalendarSource | time/privacy/delete/cursor/Planner tests | None |
| Selected export | US3, FR-012–016 | mapping model, source authority, allowlist | default/filter/idempotency/conflict/delete tests | None |
| Failure/control | US4, FR-017–019 | lock/page transaction/error states | concurrency/retry/auth/scheduler tests | None |
| UI/localization | FR-020–023 | localization plan and closed TypeScript/OpenAPI | i18n/unit/E2E/visual/Android gates | None |
| Scope/privacy | FR-003, 008, 010, 012, 016, 024 | architecture gates 5/9/10 | secret/log/backup/protected-path tests | None |

## Canonical Design Reconciliation

- `docs/design/integrations.md` asks for a shared Integration/SyncedItem mechanism and two-way Google/Apple
  calendars. The feature supplies both real adapter contracts rather than an ICS placeholder.
- The older document suggests launch-wide last-write-wins; the newer roadmap explicitly says local domain facts
  remain authoritative. Origin-based authority resolves the conflict deterministically: SelfHandler-origin is
  local-authoritative, provider-origin is provider-authoritative.
- External events become Integration-owned Planner busy projections, not domain data. This preserves the
  `SchedulableSource`/Planner read direction locked by features 006 and 009.
- Native Google OAuth deep-link completion has no current Android SDK/device/toolchain and is explicitly deferred;
  server/browser OAuth, shared status/settings/sync, and Apple credential connection remain implemented/testable.

## Constitution Review

1. **Specifications first**: all required artifacts and tasks precede source changes.
2. **Distinct truth**: long-term provider design remains in docs; this increment freezes one delivery boundary.
3. **Thin slice**: only calendars, one selected calendar/provider/user, bounded projections and current consumers.
4. **Deterministic core**: Planner/domains remain available without providers; no AI enters the path.
5. **Ownership/privacy**: every row owner-scoped; secrets/titles/cursors encrypted; data/scopes/export minimized.
6. **Contracts/tests**: provider, persistence, REST/OpenAPI/TypeScript/Vue/CLI and permanent tests move together.
7. **Localization**: the complete EN/RU/UK surface and automated/browser evidence are enumerated.

## Risk Review

- **Distributed write ambiguity (medium, resolved)**: stable provider identity + mapping + serialized replay converges;
  plan does not claim DB/provider atomicity.
- **Provider protocol drift (medium, resolved)**: official current contracts, small adapter surface, closed DTOs and
  HTTP fixtures; live acceptance remains external.
- **Sensitive calendar exposure (high, resolved before implementation)**: default no export/busy-only, explicit
  category choices, encrypted minimal fields, no details/raw payload, remote-conservative disconnect.
- **Timezone/event span errors (high, resolved before implementation)**: distinct UTC instant versus all-day date
  representations, exclusive ends, current Profile-local overlap matrix.
- **Cross-owner/provider deletion (high, resolved before implementation)**: redundant user IDs, owner guards,
  closed origins, SelfHandler-origin-only remote mutation, ownership matrix.

## Findings to Carry as Tests

- Reject callback replay and owner mismatch even when the authorization code is otherwise accepted.
- Prove no export request occurs with default settings, including sensitive categories.
- Prove `busy_only` prevents title serialization rather than merely hiding it with CSS.
- Prove a cursor is retained on transient/page failure and replaced only after complete committed apply.
- Prove disconnect and foreign-owner requests make no provider delete/request and preserve local facts.
- Prove backup catalog explicitly excludes all three provider-bound tables without weakening drift coverage.
- Prove all provider HTTP traffic is faked/blocked in automated gates.

## Clarification Status

No unresolved material ambiguity. The documented decisions are conservative, reversible within the additive model,
and do not require user input or external credentials before implementation.

## Post-Implementation Verification (2026-08-14)

**PASS.** The delivered migrations, encrypted models, provider adapters, sync coordinator, Planner
source, REST/OpenAPI/TypeScript contracts, localized settings UI, Android bundle, and permanent tests
match the specified two-provider/one-calendar boundary. Cross-feature Planner and Portability contracts
were updated additively. No provider-bound table or secret enters schema-v1 backup.

The full browser matrix exposed one shared fixed-popup positioning defect after Integrations increased
desktop navigation height. Trace evidence showed transform coordinates changing from viewport to
document space after `scrollIntoView`; fixed top/left positioning without transform repaired the issue.
The original scenario and shared popup contracts subsequently passed `16/16` across desktop/mobile,
and the final Calendar journey/visual matrix passed `6/6` with all 12 images inspected.

Live-provider acceptance is the sole external caveat. No Google OAuth client or Apple app-specific
password exists in this workspace, so no live success is claimed and no secret was committed.
