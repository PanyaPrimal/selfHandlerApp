# Pre-Implementation Analysis: AI Assistant Foundation

## Result

**PASS — no critical or high finding.** Specification, research, data model, plan, OpenAPI contract, checklist and
tasks describe the same two-provider, one-scope, one-tool, confirm-before-write Storage vertical slice.

## Traceability Review

| Concern | Specification | Design/contract | Planned evidence | Finding |
|---|---|---|---|---|
| BYOK connections | US1, FR-001–007 | research D2/D4/D5, five-table model | encryption/provider/ownership tests | None |
| Consent/context | US2, FR-008–010 | research D6/D7, draft contract | zero-call/minimal-payload tests | None |
| Structured tool | US2, FR-011–013 | research D3/D9, closed schema | adapter/parser/validator tests | None |
| Confirmation/write | US3, FR-014–018 | research D8–D10 | expiry/replay/race/stale/atomic tests | None |
| Audit/failure | US4, FR-019–020 | research D11/D12 | redaction/error/outage tests | None |
| UI/localization | FR-021–024 | localisation plan, TypeScript/OpenAPI | i18n/unit/E2E/visual/mobile gates | None |
| Evolution/contracts | FR-025–029 | plan gates 6–9 | schema/portability/OpenAPI/full regression | None |
| Deferral | FR-030 | plan gate 10 | changed-path/network/roadmap audit | None |

## Canonical Design Reconciliation

- Module 11 requires user-owned provider/key/model records, several connections, one active selection,
  encryption, masked settings and a runtime provider contract. The data model and APIs implement that exact
  infrastructure for two known providers.
- `llm-layer.md` requires per-scope consent, aggregates/minimal data, backend-executed tool calls and
  confirm-before-write. Inbox triage is the documented low-sensitivity structured/tool scenario and provides the
  complete control loop without a universal agent.
- Feature 008 remains authoritative for Item, Project and Tag. AI holds no duplicate task fact and cannot write a
  controller/domain route itself.
- Custom endpoints are an unresolved design question in the canonical doc. This spec resolves the delivery
  boundary conservatively: known fixed endpoints now; arbitrary URLs only after an explicit SSRF/egress design.
- Model names/prices/capabilities change independently. The user supplies the model and a real probe supplies
  readiness; no stale model catalogue or price promise is stored in product code.

## Constitution Review

1. **Specifications first**: all required artifacts and tasks precede production edits.
2. **Distinct truth**: long-term LLM scenarios remain canonical; 026 freezes the first delivery boundary.
3. **Thin slice**: two provider adapters plus one Storage scenario, not a platform-wide agent.
4. **Deterministic core**: Storage remains complete without AI and no provider enters a critical path.
5. **Ownership/privacy**: every row owner-scoped; keys/token encrypted; payload explicit/minimal; no content audit.
6. **Contracts/tests**: provider, DB, REST/OpenAPI/TypeScript/Vue/Android and permanent tests move together.
7. **Localization**: complete EN/RU/UK and responsive accessibility evidence are enumerated.

## Risk Review

- **Credential disclosure (high, resolved before implementation)**: authenticated encryption, hidden cast,
  last-four-only mask, replace-only API, closed errors, no browser/native storage and scan tests.
- **Prompt injection/unauthorized tool (high, resolved before implementation)**: forced one strict call,
  independent validation, deny-by-default registry and backend-only executor.
- **Unconfirmed/replayed/cross-owner write (high, resolved before implementation)**: encrypted binding, one-way DB
  hashes, expiry, row lock, same owner/readiness/consent/source checks and atomic mutation.
- **External private-data exposure (high, resolved before implementation)**: closed opt-in scope, exact bounded
  context builder, fixture request inspection and no prompt/response retention.
- **Provider drift/outage/cost (medium, resolved)**: user model, two small adapters, fixed endpoints, short bounds,
  no automatic loop, closed capability errors and deterministic fallback.
- **Custom endpoint SSRF (high, eliminated from boundary)**: no user-supplied host or redirect target.

## Findings to Carry as Tests

- Prove save/list/test/update/activate/delete never serializes or logs decrypted key/token/proposal.
- Prove inactive/untested/no-consent/foreign-item requests result in zero provider traffic.
- Snapshot both provider request bodies and assert exact context/schema/tool names plus fixed hosts.
- Reject all parser stop reasons/shapes except one valid forced tool call, then revalidate arguments locally.
- Prove draft generation leaves Item/Tag/Project unchanged.
- Prove expiry, replay, concurrent confirmation, source edit/status change, consent revoke, connection switch/delete
  and foreign user make no additional write/provider call.
- Prove one valid confirmation reuses Storage ownership/invariants and results in one exact item/tag change.
- Prove every AI table is excluded from backup and makes a restore target non-empty/ineligible.
- Prove full deterministic Storage/Planner/Review/Analytics behavior remains green with zero AI rows.
- Prove automated tests fail any unexpected network request.

## Clarification Status

No unresolved material ambiguity. Provider/version facts were checked against current official Anthropic and
OpenAI documentation; model identifiers remain user data and live acceptance remains external.

## Post-Implementation Verification

**PASS with explicit external-environment caveats.** The implemented schema, provider adapters, API,
confirmed Storage tool, localized web UI, shared Android bundle, contracts and fixture-only acceptance
match the pre-implementation boundary. No deployment or live provider request was performed.

### Requirement-to-evidence closure

| Requirements | Implementation evidence | Permanent verification |
|---|---|---|
| FR-001–007 connections/providers | five-table migration; connection model/resource/service/controllers; fixed `ai.php`; Anthropic/OpenAI adapters and registry | `AiAssistantFoundationTest`, `LlmProviderAdapterTest`, `AiAssistantApiTest` |
| FR-008–013 consent/context/strict proposal | closed consent service; bounded context builder; strict tool definition; independent proposal validator; deny-by-default registry | `AiAssistantApiTest`, `AiAssistantConfirmationFailureTest`, exact intercepted provider request assertions |
| FR-014–018 confirmation/write | encrypted token codec; hash-only pending row; owner/source/connection/proposal binding; locked one-time authorization; Storage-owned executor | `AiAssistantApiTest`, `AiAssistantConfirmationFailureTest`, real two-process `AiConfirmationConcurrencyTest` |
| FR-019–020 audit/errors | append-only content-free audit model/logger; localized closed exception codes; upstream bodies discarded | foundation, adapter, API and failure suites plus secret/content scans |
| FR-021–024 UI/localization | `/settings/ai`; Storage proposal review; EN/RU/UK parity; responsive/focus/status/touch styles; changelog | Vitest contract suite, i18n guard, AI Playwright flow/visual suites, 24 locale/scheme/viewport screenshots inspected |
| FR-025–029 contracts/evolution | OpenAPI, TypeScript client/types, additive migration, portability exclusion/restore denial, updated canonical docs | OpenAPI route/ref tests, 65-test focused regression, schema identifier test, full frontend/browser/mobile gates |
| FR-030 exclusions | no custom URL, live key, prompt history, universal agent, background run, native provider code, deployment, feature 002 or handoff change | fixed-host fixture assertions, changed-path/network/secret scans and final status audit |

### Recorded quality evidence

- Backend focused AI/Storage/Portability/schema gate: **65 passed, 678 assertions**. Provider fixtures
  cover both exact request shapes and closed credentials/rate-limit/timeout/unavailable/refusal/
  truncation/invalid/multiple-call outcomes. The concurrency test uses two PHP processes and proves one
  mutation plus one replay denial.
- Full Laravel invocation: **844 passed, 11 failed, 11,029 assertions**. All 11 failures are confined
  to the pre-existing `ImageNormalizerTest` under the local Herd PHP build: JPEG/WebP GD functions are
  absent and the related byte-boundary fixture differs. Feature 026 and every affected domain suite
  pass; the repository cannot repair or truthfully mark that host extension as present. The existing
  official-PHP/GD acceptance caveat therefore remains an environment gate, not an AI regression.
- Pint passed; `composer validate --strict` passed; Composer audit reported no advisory. MySQL's
  64-character identifier audit, migration rollback/preservation and OpenAPI route/ref/secret closure
  all passed.
- Frontend: **16 files / 58 Vitest tests**, **2,057** structure-identical EN/RU/UK keys across **121**
  checked source files, typecheck and production build (**215 modules**) passed; npm audit found zero
  vulnerabilities. The established >500 KiB application-bundle warning remains non-blocking.
- Browser: focused final Storage+AI gate **13 passed / 1 expected project skip**; final complete run
  **251 passed / 11 expected viewport-project skips / 0 failed** across **262 tests in 55 files**. AI
  fixtures make zero external provider requests. Representative EN/RU/UK light/dark desktop/mobile
  Settings and proposal screenshots were inspected after the final responsive repair.
- Android shared bundle: sync and deterministic validation passed with fingerprint
  `d0d3d727fad7`; **19/19** shell tests passed; seven expected Capacitor plugins were listed; npm audit
  found zero vulnerabilities. Native compilation/device installation remains the already documented
  feature-012 toolchain caveat and no AI-native credential/provider logic was added.

### External acceptance and deferrals

No Anthropic/OpenAI key, live user data, billable provider success, deployment, or feature-002 change
is claimed. An operator may later record live acceptance with their own available model and account.
Custom endpoints, model/pricing catalogues, chat/universal RAG, streaming, vision, background AI,
additional tools/scenarios, usage billing, prompt retention and native AI authority still require new
specifications.
