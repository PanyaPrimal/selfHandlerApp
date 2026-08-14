# Tasks: AI Assistant Foundation with Confirmed Inbox Triage

**Input**: [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md),
[plan.md](plan.md), [contracts/openapi.yaml](contracts/openapi.yaml), [analysis.md](analysis.md)

**Tests**: Mandatory. Provider/network, encryption, ownership, consent, tool authorization and replay are security
boundaries; RED evidence precedes production implementation.

## Phase 1 — Specification and baseline

- [x] T001 Verify branch/HEAD/origin/dirty tree and preserve `AGENTS.md`, `CLAUDE.md`, feature002, deployment and handoff paths
- [x] T002 Refresh GitNexus index and query provider/settings/Storage/portability execution flows
- [x] T003 Confirm official Anthropic Messages/strict-tool and OpenAI Responses/strict-function current contracts
- [x] T004 Complete spec, requirements checklist, research decisions, data model, plan, OpenAPI and quickstart
- [x] T005 Complete pre-implementation analysis with no unresolved critical/high finding
- [x] T006 Record current focused/full baseline evidence and network/live-credential limitations
- [x] T007 Run GitNexus impact for every existing symbol/route handler that will be modified and report risk

## Phase 2 — RED schema, provider and security contracts

- [x] T008 Add migration schema tests for all five AI tables, columns, checks, indexes and foreign keys
- [x] T009 Add migration preservation/rollback and MySQL identifier-length coverage
- [x] T010 Add `LlmConnection` encryption, hidden-key, mask, parameter and owner invariant tests
- [x] T011 Add active `LlmSetting` same-owner/ready invariant and zero-or-one selection tests
- [x] T012 Add `LlmConsent` closed-scope/grant/revoke/default-denied tests
- [x] T013 Add `LlmToolConfirmation` hash/lifecycle/expiry and no-plaintext-proposal tests
- [x] T014 Add content-free immutable `LlmAuditEvent` tests
- [x] T015 Add provider contract/value-object/registry tests for supported and unknown providers
- [x] T016 Add Anthropic fixed-host probe/tool-call fixture tests for success and exact request shape
- [x] T017 Add OpenAI fixed-host probe/tool-call fixture tests for success and exact request shape
- [x] T018 Add both adapters' credentials/rate-limit/timeout/unavailable/capability/refusal/truncation/invalid/multiple-call tests
- [x] T019 Add provider exception redaction and unexpected-network prevention tests
- [x] T020 Capture RED results proving production schema/provider/security classes are absent

## Phase 3 — RED API, consent, proposal and confirmation behavior

- [x] T021 Add connection list/create/update/rotate/delete API validation and mask/non-disclosure tests
- [x] T022 Add foreign-owner connection 404/no-provider-call tests
- [x] T023 Add test/activate readiness and one-active-pointer API tests
- [x] T024 Add connection delete active-clear/pending-invalidation/no-remote-call tests
- [x] T025 Add consent grant/revoke/idempotency/closed-scope/ownership API tests
- [x] T026 Add draft precondition tests for auth, owner Inbox item, active ready connection and consent
- [x] T027 Add outbound minimal-context fixture assertions and excluded-domain field scans
- [x] T028 Add strict tool-name/argument/project/tag/date/rationale validation failure tests
- [x] T029 Add no-write-before-confirm and dismiss/no-op tests
- [x] T030 Add encrypted token binding and pending confirmation response tests
- [x] T031 Add successful one-write Item/tag/status response tests
- [x] T032 Add expiry, replay, stale-source, source-status, revoked-consent and switched/deleted-connection tests
- [x] T033 Add foreign-owner confirmation and unknown/unconfirmed tool deny tests
- [x] T034 Add concurrent confirmation at-most-once regression test
- [x] T035 Add safe audit event coverage for success/rejection without content
- [x] T036 Add deterministic Storage-without-AI and provider-outage regression tests
- [x] T037 Add all AI tables to portability catalog exclusion/restore ineligibility RED tests
- [x] T038 Add route/OpenAPI path/ref/enum/no-secret contract tests
- [x] T039 Capture RED results proving API/scenario behavior is not implemented

## Phase 4 — Backend foundation implementation

- [x] T040 Add `config/ai.php` fixed endpoints, timeouts, bounds, providers, scope and confirmation expiry
- [x] T041 Add reversible `2026_08_14_090000_create_ai_assistant_foundation.php`
- [x] T042 Implement `LlmConnection` encrypted/hidden casts, closed values, defaults, mask and owner validation
- [x] T043 Implement `LlmSetting` active pointer and same-owner/ready guard
- [x] T044 Implement `LlmConsent` current-state semantics and closed scope
- [x] T045 Implement `LlmToolConfirmation` lifecycle/scopes and `LlmAuditEvent` append-only model
- [x] T046 Implement safe AI exception/error-code mapping without upstream bodies
- [x] T047 Implement provider contract and immutable tool definition/call/result value objects
- [x] T048 Implement Anthropic Messages adapter with fixed host, probe, strict forced tool and safe parsing
- [x] T049 Implement OpenAI Responses adapter with fixed host, probe, strict forced function and safe parsing
- [x] T050 Implement runtime `LlmProviderRegistry` with unknown-provider denial
- [x] T051 Implement content-free `LlmAuditLogger`
- [x] T052 Implement connection service create/update/test/activate/delete transactions and readiness reset
- [x] T053 Implement consent service grant/revoke and pending-confirmation invalidation
- [x] T054 Implement connection requests/resource/controllers with owner 404 and no secret serialization
- [x] T055 Implement consent/settings controller and authenticated throttled routes
- [x] T056 Make all focused schema/model/provider/connection/consent tests green

## Phase 5 — Confirmed Inbox triage implementation

- [x] T057 Implement minimal bounded `InboxTriageContextBuilder` using Profile and owned Storage references
- [x] T058 Implement closed triage tool JSON Schema and independent Laravel argument validator
- [x] T059 Implement deny-by-default `LlmToolRegistry` and confirmation-required authorization contract
- [x] T060 Implement source/proposal canonical fingerprints and encrypted confirmation token codec
- [x] T061 Implement proposal service preconditions, provider call, validation, pending row and safe audit
- [x] T062 Implement owner-scoped `StorageInboxTriageTool` transaction and normalized tags
- [x] T063 Implement confirmation row lock/consume/current-authority/source-stale checks and at-most-once execution
- [x] T064 Implement draft/confirm controller responses and localized closed errors
- [x] T065 Add authenticated throttled draft/confirm routes
- [x] T066 Update portability schema-v1 exclusions and restore eligibility for all AI tables
- [x] T067 Make focused proposal/confirmation/ownership/race/Storage/portability tests green
- [x] T068 Run focused Pint and static secret/provider-network scans

## Phase 6 — Frontend contracts and localized UI

- [x] T069 Add exact TypeScript connection/settings/consent/draft/proposal/error types
- [x] T070 Add API client functions for settings, connection lifecycle, consent, draft and confirm
- [x] T071 Add Vitest request-shape, no-key-response and safe-error contract tests
- [x] T072 Add complete EN `/settings/ai`, consent and Inbox proposal copy
- [x] T073 Add structure-identical RU translations
- [x] T074 Add structure-identical UK translations
- [x] T075 Add localized Laravel AI validation/domain/provider messages in en/ru/uk
- [x] T076 Add `/settings/ai` router entry and desktop/mobile navigation destination
- [x] T077 Implement responsive accessible `AiSettingsView` list/add/edit/rotate/test/activate/delete states
- [x] T078 Implement explicit storage scope disclosure and grant/revoke controls
- [x] T079 Extend Storage Inbox with readiness guidance, per-item draft action and exact sharing notice
- [x] T080 Implement proposal review, localized references/date, dismiss/regenerate/expiry/error and explicit confirm
- [x] T081 Update in-memory Storage item/count state after confirmed response without a full unsafe rewrite
- [x] T082 Add busy/status/error live regions, focus handling, 44px controls and no-overflow styles
- [x] T083 Add EN/RU/UK changelog entry and any Data portability exclusion copy
- [x] T084 Make Vitest, i18n parity/used-key/hardcoded, typecheck and production build green

## Phase 7 — Browser, mobile and visual acceptance

- [x] T085 Add deterministic Playwright API fixtures with zero external LLM hosts
- [x] T086 Cover connection create/mask/test/activate/rotate/delete and persistence/reload on desktop/mobile
- [x] T087 Cover consent grant/revoke and zero-call setup guidance
- [x] T088 Cover draft disclosure, no-write review, dismiss, confirm and replay/stale/error states
- [x] T089 Cover foreign-safe/auth/session behavior and no browser/native key storage
- [x] T090 Cover EN/RU/UK, light/dark, keyboard, ARIA/status, exact 390x844 and no overflow
- [x] T091 Capture representative Settings/Storage desktop/mobile screenshots for all locales/schemes
- [x] T092 Inspect every representative screenshot with the image viewer and repair visual defects
- [x] T093 Sync the shared Android bundle and update/verify its deterministic fingerprint
- [x] T094 Pass Android shell/validation/plugin-list/audit without native provider secret logic

## Phase 8 — Full verification and delivery

- [x] T095 Run full Laravel suite, Pint, Composer validate/audit and record counts
- [x] T096 Run full i18n/Vitest/typecheck/build/npm audit and record counts
- [x] T097 Run full Playwright suite with no unexpected skip/console/page/network error
- [x] T098 Run MySQL schema/preservation/identifier and OpenAPI route/ref/consumer closure audits
- [x] T099 Update roadmap status, decisions/modules/LLM design, technical design and feature analysis with evidence/caveat
- [x] T100 Update durable memory and complete the final requirement-to-evidence traceability audit
- [x] T101 Verify tasks/checklist reflect only proven work and no placeholder/TODO remains in 026 scope
- [x] T102 Run GitNexus detect-changes, review affected flows, git diff/check/status/secret/protected-path audits
- [x] T103 Create one atomic feature commit without co-author, push current master, prove HEAD/origin parity
- [x] T104 Refresh GitNexus index and stop at the completed 026 Spec Kit boundary

## Dependencies

- Phase 1 gates all production work.
- Phase 2 RED tests gate foundation implementation; Phase 3 RED tests gate scenario/API implementation.
- Phase 4 gates Phase 5; provider readiness and consent must exist before a proposal.
- Phase 5 gates Storage confirmation UI; frontend contract work may start once OpenAPI is frozen.
- Phase 6 gates browser acceptance; Phase 7 gates full delivery.
- T103 requires every prior applicable task green and documented; T104 is post-push verification only.

## Requirement Traceability

| Requirements | Tasks |
|---|---|
| FR-001–007 BYOK/providers | T003, T010, T015–T024, T040–T056, T069–T078, T086 |
| FR-008–013 consent/context/structured tool | T012, T025–T030, T047–T061, T078–T080, T087–T088 |
| FR-014–018 confirmation/Storage write | T013, T030–T034, T045, T059–T065, T080–T081, T088 |
| FR-019–020 audit/errors | T014, T018–T019, T035, T046, T051–T055, T064, T075 |
| FR-021–024 localized responsive UI | T069–T094 |
| FR-025–029 schema/contracts/regression/docs | T008–T009, T036–T039, T041, T066–T068, T095–T102 |
| FR-030 exclusions | T001, T005–T007, T019, T085, T094, T099–T104 |

## Scope Guard

- Deployment, `specs/002-homelab-deployment`, live keys/data, branches/worktrees/merge and
  `design_handoff_selfhandler_mvp/` are excluded.
- Custom endpoints, chat/universal RAG, other scenarios, streaming, vision, background AI, model catalogue and
  usage billing remain deferred.
- A task is checked only after its implementation and named evidence actually pass.

## Completion Evidence (2026-08-14)

- RED capture: the new foundation/API contract run produced **17 failures and 1 passing pre-existing
  guard** before production classes, schema and routes existed.
- Focused backend closure: **65 passed / 678 assertions** across AI, Storage, Portability and MySQL
  identifier coverage. Pint, Composer strict validation/audit, OpenAPI refs/routes and production
  secret/custom-endpoint scans passed.
- Full backend invocation: **844 passed / 11 known host-GD failures / 11,029 assertions**. The failures
  are isolated to pre-existing attachment JPEG/WebP/byte-fixture tests under Herd PHP without the
  required GD functions; every feature-026 and affected suite is green.
- Frontend closure: **58/58 Vitest**, **2,057** EN/RU/UK keys over **121** source files, typecheck,
  215-module build and zero npm advisories. Final full Playwright: **251 passed / 11 expected
  project-conditional skips / 0 failed**; focused Storage+AI: **13 passed / 1 expected skip**.
- Visual/mobile closure: all 24 AI locale/scheme/viewport screenshots were inspected; Android sync,
  deterministic fingerprint `d0d3d727fad7`, validation, **19/19** shell tests, seven expected plugins
  and zero npm advisories passed.
- GitNexus staged analysis reports **medium** risk, 77 changed files and three existing client-flow
  associations; no high/critical finding. Provider, Storage, Portability, client and full browser
  regressions cover the affected boundaries. Protected paths and seven-file handoff remain unstaged.
- Live provider credentials/data/requests, deployment, feature 002 and all stated deferrals remain
  excluded. The one feature commit, origin parity and refreshed graph are the terminal delivery steps;
  no work continues beyond this completed boundary.
