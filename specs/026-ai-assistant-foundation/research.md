# Research: AI Assistant Foundation with Confirmed Inbox Triage

## Decision 1 — Storage Inbox triage is the first confirmed scenario

**Decision**: Implement the documented Module 7 structured/tool-calling scenario for one existing Inbox item.
The provider proposes type/project/tags/priority/due date; SelfHandler validates and applies it only after a
second explicit request.

**Rationale**: Storage from feature 008 is stable, owner-scoped and manually complete. The scenario exercises the
entire roadmap foundation—minimal context, per-scope consent, structured output, tool authorization and
confirm-before-write—without giving a model universal or health/finance access.

**Rejected**: a read-only Analytics narrative (does not prove confirm-before-write); plan-my-tomorrow (too many
domain tools for the first slice); receipt/meal/body vision (higher sensitivity and broader contracts).

## Decision 2 — Two known provider adapters; no custom endpoint

**Decision**: `LlmProvider` has Anthropic Messages and OpenAI Responses adapters resolved at runtime from owner
connection data. Endpoints are fixed HTTPS configuration owned by the server. Provider and model are replaceable;
arbitrary base URLs are refused.

**Rationale**: Two real adapters justify Strategy/Adapter portability. An owner-supplied URL creates SSRF,
redirect, DNS-rebinding and internal-network disclosure risks that are unrelated to proving BYOK portability.

**Primary sources**:

- https://platform.claude.com/docs/en/api/messages/create
- https://developers.openai.com/api/reference/resources/responses/methods/create

**Rejected**: one provider disguised as a generic interface; browser-side SDKs/keys; custom URLs without an egress
policy; provider model catalogues that drift and encourage unsafe defaults.

## Decision 3 — Real strict tool calls plus independent server validation

**Decision**: Force exactly one `storage_triage_inbox_item` call. Anthropic receives a strict client tool with
`input_schema`; OpenAI receives a strict function tool with JSON Schema and parallel calls disabled. The backend
then parses and validates the returned name/arguments independently.

**Rationale**: Provider constrained decoding improves reliability but a provider response is still untrusted
external input. Both current provider contracts make application code—not the model—the executor.

**Primary sources**:

- https://platform.claude.com/docs/en/build-with-claude/structured-outputs
- https://platform.claude.com/docs/en/agents-and-tools/tool-use/how-tool-use-works
- https://developers.openai.com/api/docs/guides/structured-outputs
- https://developers.openai.com/api/docs/guides/function-calling

**Rejected**: regex/JSON extraction from prose; trusting `strict` as authorization; accepting several calls and
choosing one; sending a tool result loop before the user has confirmed.

## Decision 4 — Replace-only encrypted keys and safe readiness

**Decision**: Laravel's authenticated encrypted cast stores the key. A separate four-character hint supports a
mask. The key is never serialized. Create/rotation/provider/model changes set `untested`; only a successful
context-free probe sets `ready`, and only a ready connection may be active.

**Rationale**: The connection settings need useful status without turning the API into a secret-recovery channel.
Readiness is evidence about the exact current configuration, not a permanent account claim.

**Source**: https://developers.openai.com/api/docs/guides/production-best-practices

**Rejected**: environment-owned application keys (not BYOK); plaintext or reversible API output; exposing full
prefixes; treating save as successful provider verification.

## Decision 5 — One active pointer, not competing booleans

**Decision**: A one-row-per-user `llm_settings` record references the active connection. Connections remain
independent rows. Activation locks the setting and verifies same owner/readiness in one transaction.

**Rationale**: A separate pointer structurally allows zero or one active connection and avoids concurrent
`is_active` races or database-specific partial unique indexes.

**Rejected**: active boolean on each row; active provider in Profile; one-connection-only schema that contradicts
the canonical Module 11 decision.

## Decision 6 — Explicit, closed, revocable scope consent

**Decision**: `llm_consents` owns one current record for `storage_inbox`, default denied. Grant/revoke is a
deliberate Settings action. Draft and confirmation re-check consent; revocation invalidates pending confirmations.

**Rationale**: The LLM design requires consent per data category. A global checkbox would silently authorize
future higher-sensitivity scenarios.

**Rejected**: consent inferred from saving a key or clicking Generate; global forever-consent; per-request modal
without durable revocation state.

## Decision 7 — Minimal ephemeral context, no general RAG

**Decision**: The context builder loads one owned Inbox item, bounded active owner projects and existing tag names,
closed enums, current Profile date/timezone, locale and tone. It sends no other items or modules and persists no
prompt/response content.

**Rationale**: The scenario needs reference identities to produce an applicable draft. Anything else increases
privacy/token cost and becomes an undeclared universal context layer.

**Rejected**: whole-database dumps, raw SQL/RAG, goals/finance/health context, attachments, provider-side files,
or storing prompts for later convenience.

## Decision 8 — Encrypted token plus one-way single-use record

**Decision**: After validation, encrypt the exact proposal and binding metadata with the app key. Persist only
token hash, proposal hash, owner/connection/source/fingerprint/tool, expiry and lifecycle. Confirmation locks and
consumes the row atomically, rechecks all current authority, and then executes the tool.

**Rationale**: The client must round-trip the reviewed proposal without editable authority. The database record
provides replay/race control without retaining the generated rationale or tool arguments in plaintext.

**Rejected**: trusting a client-resubmitted proposal; stateless signed token with replay risk; plaintext draft
table; auto-apply inside the provider request.

## Decision 9 — Deny-by-default tool registry

**Decision**: The registry contains one write tool with a closed schema. Unknown names are rejected. Every write
definition declares confirmation required; execution is inaccessible without a consumed authorization object.

**Rationale**: Authorization belongs to backend code and must stay separate from provider tool selection. The
first slice should demonstrate the default for future tools, not create a permissive generic dispatcher.

**Rejected**: method names from model strings, dynamic container resolution, arbitrary route calls, or a flag the
model can set to claim confirmation.

## Decision 10 — Storage owns the mutation

**Decision**: `StorageInboxTriageTool` updates exactly one owned Inbox `Item` in a transaction, normalizes owner
tags, checks an active owner project, sets status active and relies on existing model invariants. AI owns only the
proposal/authorization/audit boundary.

**Rationale**: This preserves feature 008's source of truth and keeps the LLM from becoming a task database.

**Rejected**: AI-owned item copies; writing through a controller; direct SQL that bypasses model ownership;
creating a second task/tag mechanism.

## Decision 11 — Safe closed errors and content-free audit

**Decision**: Map provider/network/parser/tool failures to closed public codes. `llm_audit_events` records only
owner, optional connection, event, scope/scenario, outcome, safe code and UTC instant. Application logs receive
the same content-free identifiers, never upstream bodies or request data.

**Rationale**: Operators need lifecycle evidence, but retaining prompts, provider bodies or arguments would
create a second sensitive-data store.

**Rejected**: upstream body passthrough; verbose HTTP logging; audit rows containing item title, rationale, API
key, token, prompt, response or tool JSON.

## Decision 12 — Explicit timeout, output and retention bounds

**Decision**: Provider calls have short connect/request timeouts, no automatic mutating retry, one forced call,
bounded context lists and max output tokens. Pending confirmations expire after ten minutes; expired rows are
logically rejected and can be pruned later. Connections/consents/audit remain until owner deletion.

**Rationale**: BYOK still costs money and external failures must not hold application workers indefinitely.

**Rejected**: streaming/background workers in the first slice, invisible retries, unbounded prompt/history, or
pretending token price/model limits are stable application facts.

## Architecture Gate Summary

| Gate | Resolution |
|---|---|
| Owner | AI owns provider/consent/authorization/audit; Storage owns Item/tag state |
| Profile inputs | Locale, timezone and tone are read, never copied as AI facts |
| Time | Provider/audit/test/expiry instants UTC; `due_on` remains Storage calendar date in Profile timezone |
| Scheduling | No recurrence/occurrence/notification mechanism is added |
| Direction | Storage snapshot -> external proposal -> confirmed Storage tool; no autonomous reverse path |
| Evolution | Five additive owner tables; existing rows untouched; reversible dependency order |
| Contracts | Providers, DB, REST/OpenAPI, TypeScript/Vue and tests move together |
| Aggregates | No aggregate is recomputed or persisted; bounded reference lists only |
| Privacy | Encrypted keys/token, minimal consented payload, content-free logs/audit, no backup |
| Deferral | Custom endpoints, universal agent, other scenarios, live keys, deployment explicitly excluded |
