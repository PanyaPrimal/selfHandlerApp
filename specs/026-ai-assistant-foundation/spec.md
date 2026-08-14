# Feature Specification: AI Assistant Foundation with Confirmed Inbox Triage

**Feature Branch**: existing user branch

**Created**: 2026-08-14

**Status**: Approved

**Input**: Deliver roadmap feature 026 as a provider-portable BYOK foundation and one useful,
confirm-before-write Storage Inbox triage scenario. Deployment, live credentials, a universal agent, and
unfinished-domain access remain outside the increment.

## User Scenarios & Testing

### User Story 1 - Configure a private BYOK connection (Priority: P1)

As an authenticated user, I can save one or more Anthropic or OpenAI connections, select an active connection,
test it with a context-free probe, rotate or revoke its key, and see only masked credential metadata afterwards.

**Why this priority**: Every optional AI scenario depends on an explicit owner-controlled provider boundary;
credential safety cannot be retrofitted after prompts start leaving SelfHandler.

**Independent Test**: Create, update, test, activate, reload, and delete both provider kinds against HTTP
fixtures; inspect the database and API responses to prove encrypted storage and non-disclosure.

**Acceptance Scenarios**:

1. **Given** a valid owner connection, **when** it is saved, **then** the key is encrypted at rest and every
   response shows only a last-four mask, provider, model, bounded parameters, status, and safe timestamps.
2. **Given** several connections, **when** one is activated, **then** exactly that owner connection is active and
   every other owner and every foreign connection is unchanged and undisclosed.
3. **Given** a rotated key or changed provider/model, **when** the connection is updated, **then** readiness is
   reset until another successful test and the previous key is not recoverable through the API.
4. **Given** provider rejection, timeout, throttling, malformed output, or an unsupported capability, **when** a
   test runs, **then** the user sees a localized closed error code and no upstream body, key, or prompt is logged.
5. **Given** a deleted active connection, **when** deletion completes, **then** its credential is removed, active
   selection is cleared, pending confirmations are invalidated, and deterministic Storage remains available.

---

### User Story 2 - Consent to and review an AI Inbox draft (Priority: P1)

As a user with a tested active connection, I can explicitly consent to the `storage_inbox` scope, ask AI to
classify one owned Inbox item, and review a structured draft before any Storage state changes.

**Why this priority**: Per-scope consent and a visible proposal are the privacy and control boundary for the first
real scenario.

**Independent Test**: Generate a draft for one Inbox item using provider fixtures and prove only the documented
context left the app, the tool call matches the closed schema, and the source item is byte-for-byte unchanged.

**Acceptance Scenarios**:

1. **Given** no active tested connection or no current consent, **when** draft generation is requested, **then**
   no provider request occurs and Settings guidance is returned.
2. **Given** current consent, **when** a draft is requested, **then** only the item title/description and bounded
   owner project/tag references plus the closed triage schema are sent; goals, finance, health, attachments,
   journal, other items, and credentials are absent.
3. **Given** a provider tool call, **when** it returns, **then** SelfHandler validates tool name and every argument
   against its own schema and owned references before issuing an expiring single-use confirmation token.
4. **Given** a valid draft, **when** it is displayed, **then** type, project, tags, priority, due date, rationale,
   provider/model, expiry, and exactly what data category left are clear in EN/RU/UK on desktop and 390x844.
5. **Given** an invalid/unknown tool, foreign project, unsafe date, excessive tag/rationale, refusal, or truncated
   response, **when** validation runs, **then** no token or write is produced and the provider output is discarded.
6. **Given** consent is revoked, **when** another draft is attempted, **then** the request is blocked before the
   provider while already captured deterministic Inbox data remains unchanged.

---

### User Story 3 - Confirm exactly one authorized Storage write (Priority: P1)

As a user reviewing a draft, I can confirm it once to execute the allowlisted Storage triage tool, or dismiss it
without a write.

**Why this priority**: A proposal is useful only if the user can safely apply it through the existing domain
invariants, and the model must never possess write authority.

**Independent Test**: Confirm a pending token and prove one atomic owner-scoped Item/tag update; then replay,
expire, alter the source, revoke consent, and use another owner to prove every unsafe execution is rejected.

**Acceptance Scenarios**:

1. **Given** a matching pending confirmation, **when** the owner confirms, **then** the backend—not the provider—
   executes only `storage_triage_inbox_item`, moves the item from Inbox to active, applies validated fields/tags,
   and returns the normal owned Storage representation.
2. **Given** a dismissed proposal, **when** the user closes or regenerates it, **then** no Storage row or tag changes.
3. **Given** an expired, replayed, altered, foreign-owner, revoked-consent, inactive-connection, or stale-source
   token, **when** confirmation is attempted, **then** it fails without a partial write or provider request.
4. **Given** a provider proposes any unregistered tool or a write reaches the authorization layer without a
   valid confirmation, **when** execution is attempted, **then** it is denied by default.
5. **Given** a valid confirmation is submitted twice concurrently, **when** both requests race, **then** at most
   one consumes the token and exactly one audit event records an applied write.

---

### User Story 4 - Keep AI optional, understandable, and recoverable (Priority: P2)

As a user, I can understand connection readiness, consent and provider failure, use Storage normally without AI,
and audit safe lifecycle metadata without private prompt content being retained or exported.

**Why this priority**: External service failure and sensitive-data handling are normal operating states, not
edge cases.

**Independent Test**: Run the entire deterministic Storage suite with no AI rows/network, exercise safe failure
states, inspect logs/backups/API/browser storage, and verify no secret or prompt/response content appears.

**Acceptance Scenarios**:

1. **Given** no connection, revoked consent, provider outage, or depleted BYOK quota, **when** Storage is used,
   **then** capture, manual triage, projects, tags, Planner and summaries continue unchanged.
2. **Given** connection/test/consent/draft/confirm/delete events, **when** audit rows are inspected, **then** they
   contain owner, event, outcome, safe code and UTC timestamp only—not keys, item text, prompts, provider bodies,
   tool arguments, or generated rationale.
3. **Given** schema-v1 backup/restore, **when** catalog and eligibility checks run, **then** AI credentials,
   consents, confirmations and audit rows are explicitly excluded and a non-empty AI target is ineligible.
4. **Given** the shared web bundle runs in Android, **when** AI Settings or Storage opens, **then** provider calls
   still originate only from Laravel and no key is placed in browser/native storage.

## Functional Requirements

- **FR-001**: Connections MUST be `UserOwned`, support `anthropic` and `openai`, allow several named records, and
  store provider, model, encrypted API key, four-character hint, bounded common parameters and readiness state.
- **FR-002**: API keys MUST use Laravel authenticated encryption, be hidden from serialization/logs/errors, and
  be replace-only; responses MUST return a non-sensitive mask and never a reversible fragment beyond last four.
- **FR-003**: Active selection MUST be a separate one-per-owner setting referencing an owned connection; only a
  successfully tested connection may become active.
- **FR-004**: Provider/model/key changes MUST reset readiness; deletion MUST revoke the credential locally, clear
  active selection and invalidate pending confirmations without attempting a remote deletion.
- **FR-005**: A provider test MUST send a fixed context-free bounded probe through the selected adapter, enforce
  HTTPS fixed server endpoints, timeout/retry limits, and return only a closed success/error contract.
- **FR-006**: `LlmProvider` and runtime registry contracts MUST isolate Anthropic Messages and OpenAI Responses
  request/response details; custom endpoints MUST NOT be accepted in this increment.
- **FR-007**: Automated suites MUST fake/block provider HTTP; no live key, success claim or provider data may be
  required. Live acceptance remains an explicitly recorded external caveat.
- **FR-008**: Consent MUST be opt-in and revocable per closed scope; feature 026 supports only
  `storage_inbox`, records grant/revoke UTC timestamps, and defaults to denied.
- **FR-009**: Draft generation MUST require authentication, owner-scoped Inbox source, active tested connection,
  current scope consent, bounded per-owner throttling, and no write inside the provider round trip.
- **FR-010**: Context assembly MUST send only the selected item's title/description, active owner projects,
  bounded existing owner tags, closed types/priorities, current calendar date/timezone for date interpretation,
  and the Profile tone/locale; no other row or module data may leave.
- **FR-011**: The provider MUST be forced to emit one `storage_triage_inbox_item` call whose arguments contain
  `type`, nullable `project_id`, unique tags, `priority`, nullable `due_on`, and bounded `rationale`.
- **FR-012**: SelfHandler MUST validate every tool call again after receipt; strict provider schemas are defense in
  depth, never authorization.
- **FR-013**: Unknown tools, duplicate/multiple calls, malformed JSON, schema drift, foreign/archived project,
  unsupported refusal/stop reason, oversized values, or non-calendar dates MUST fail closed without persistence.
- **FR-014**: A validated proposal MUST produce an encrypted, owner-bound, source-bound, proposal-bound,
  connection-bound, expiring token plus a one-way token/proposal fingerprint record; raw proposal/source text
  MUST NOT be persisted in plaintext.
- **FR-015**: Every write tool MUST require explicit confirmation. The authorization registry MUST deny unknown
  tools and unconfirmed writes by default; read tools may never silently gain write behavior.
- **FR-016**: Confirmation MUST atomically consume one pending token, re-check owner, active/tested connection,
  consent, source fingerprint/status and proposal ownership, then call the registered backend tool once.
- **FR-017**: The triage tool MUST preserve Storage ownership/invariants, change only the selected Inbox item,
  set it active, apply type/project/tags/priority/due date, and create only normalized owner tags.
- **FR-018**: Expired, replayed, stale, revoked, inactive or foreign confirmation MUST return a closed localized
  error, create no partial mutation and never call the provider.
- **FR-019**: Safe audit events MUST cover connection create/update/test/activate/delete, consent grant/revoke,
  draft accept/reject and confirmation apply/reject without retaining secrets, source text, prompts, responses,
  tool arguments or rationale.
- **FR-020**: Provider exceptions/bodies MUST map to closed codes for invalid credentials, rate limit, timeout,
  unavailable service, unsupported capability, refusal, invalid response and generic failure.
- **FR-021**: Settings and Storage surfaces MUST expose complete EN/RU/UK visible, validation, error, status,
  accessibility and changelog copy with existing locale authority and English fallback.
- **FR-022**: `/settings/ai` MUST support list/add/edit/rotate/test/activate/delete, readiness, mask, parameters,
  explicit external-processing warning and `storage_inbox` consent without displaying a stored key.
- **FR-023**: Storage Inbox MUST show setup/consent guidance, per-item draft action, disclosed context categories,
  busy/error/retry/dismiss/expiry/stale states, a readable proposal, and an explicit confirm button.
- **FR-024**: All controls MUST be keyboard/screen-reader operable, expose status/errors, meet 44px touch targets,
  wrap without horizontal overflow, and work in light/dark on desktop and exact 390x844.
- **FR-025**: REST routes, OpenAPI, backend resources/errors, TypeScript types/client functions, Vue consumers and
  tests MUST change together; secrets and encrypted tokens MUST be absent from every response schema.
- **FR-026**: Five additive MySQL-compatible owner-scoped tables MUST enforce identifiers, foreign keys, closed
  values, lifecycle timestamps, useful indexes and reversible down order without changing existing rows.
- **FR-027**: AI tables MUST be explicitly excluded from portability schema v1; restore eligibility MUST reject a
  target containing any AI connection/setting/consent/confirmation/audit row.
- **FR-028**: Existing deterministic Storage/API/UI/tests MUST remain green with no AI configuration, and AI
  failure MUST never enter capture/manual-triage/Planner/Review/Analytics critical paths.
- **FR-029**: Documentation MUST resolve provider portability, custom-endpoint SSRF, consent scope, context,
  tool authorization, confirmation/replay, retention, cost limits, live acceptance and deferrals.
- **FR-030**: Deployment, feature 002, live provider execution, arbitrary custom endpoints, chat/universal agent,
  streaming, vision/files, cross-module RAG, autonomous/multi-tool loops, background runs, cost accounting,
  prompt retention, provider model catalogues and every other LLM scenario MUST remain out of scope.

## Key Entities

- **LLM Connection**: one owner/provider/model/encrypted-key configuration with common limits and readiness.
- **LLM Setting**: one owner row selecting at most one active tested connection.
- **LLM Consent**: current grant/revoke record for one closed external-data scope.
- **Tool Confirmation**: one expiring, single-use, hashed authorization record bound to encrypted proposal data.
- **LLM Audit Event**: immutable safe metadata about an AI boundary transition, never content.
- **Inbox Triage Context**: ephemeral minimal owner data sent to the active provider for one selected Inbox item.
- **Inbox Triage Proposal**: validated structured arguments for one allowlisted Storage write plus explanation.

## Success Criteria

- **SC-001**: Database/API/log/source scans find zero plaintext API keys and every create/update/list response
  exposes only a four-character mask.
- **SC-002**: Ownership tests return not-found for every foreign connection, item, project, consent and
  confirmation path and prove no provider request or write occurs.
- **SC-003**: Both adapters pass fixed HTTP-fixture tests for success, credentials, rate limit, timeout,
  unavailability, refusal/truncation, invalid/multiple tool calls and no-secret diagnostics.
- **SC-004**: Consent-off, inactive and untested states make zero provider requests; deterministic Storage remains
  fully usable and its existing regression suite passes unchanged.
- **SC-005**: Captured outbound request fixtures contain only the documented context and schema, with zero
  finance/health/journal/attachment/other-item/raw-secret fields.
- **SC-006**: Valid drafts make zero writes until confirmation; valid confirmation makes exactly one atomic write;
  replay/expiry/stale/revoke/foreign/race cases make zero additional writes.
- **SC-007**: OpenAPI refs/routes, Laravel resources, TypeScript contracts and consumer request shapes close with
  no undocumented response field or secret.
- **SC-008**: Laravel full suite, MySQL schema/preservation/identifier tests, Pint, i18n guards, Vitest, typecheck,
  build, full Playwright and shared Android bundle gates pass.
- **SC-009**: EN/RU/UK light/dark desktop and exact 390x844 Settings/Storage journeys have no console/page error,
  inaccessible control or horizontal overflow; representative screenshots are visually inspected.
- **SC-010**: Roadmap, decisions/modules/LLM docs, changelog, tasks, analysis and durable memory state the exact
  delivered boundary and the live-key acceptance caveat without claiming deployment or live provider success.

## Explicit Deferrals

- Arbitrary/OpenAI-compatible endpoints until an SSRF/DNS-rebinding/redirect/egress policy is designed.
- Universal chat, cross-module Q&A/RAG, autonomous agents, multi-tool loops and background AI jobs.
- Every scenario other than Storage Inbox triage, including Analytics narrative, review summary, planning,
  nutrition, finance, supplements, training and vision.
- Streaming, prompt caching optimization, model/pricing catalogues, budget accounting, usage dashboards and
  prompt/response history.
- Deployment, feature 002, live provider credentials/data, native secret storage and offline/native AI authority.
