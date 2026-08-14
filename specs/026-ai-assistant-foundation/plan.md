# Implementation Plan: AI Assistant Foundation with Confirmed Inbox Triage

**Branch**: existing user branch | **Date**: 2026-08-14 | **Spec**: [spec.md](spec.md)

## Summary

Add owner-scoped multi-connection BYOK settings with encrypted keys, Anthropic/OpenAI adapters, fixed-endpoint
connection probes, per-scope consent and content-free audit. Deliver one Storage Inbox scenario as a forced strict
tool call that returns a validated, encrypted, expiring proposal token; only a second explicit confirmation can
execute the allowlisted Storage tool. Provide localized `/settings/ai` and Inbox review UI while deterministic
Storage remains independent.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12; TypeScript 6 / Vue 3.5

**Primary Dependencies**: Existing Eloquent/UserOwned/Profile/Storage, Laravel Crypt/HTTP/validation/transactions,
Vue/i18n/API client; no provider SDK or new runtime dependency

**Storage**: Five additive MySQL 8 owner-scoped tables; encrypted Eloquent key cast and encrypted client proposal
token; no prompt/response/raw provider content persisted

**Testing**: PHPUnit/Laravel unit/feature with HTTP fixtures and network prevention, migration/MySQL/ownership/
portability/OpenAPI tests, Pint, Vitest, vue-tsc, Vite, Playwright desktop/exact-390, i18n and Android bundle gates

**Target Platform**: Authenticated web and shared Capacitor Android client; provider traffic/server secrets remain
Laravel-only

**Performance Goals**: One provider call per draft, short timeouts, no background loop, <=100 projects/tags,
<=5 proposed tags, <=2048 output-token ceiling, indexed owner reads, one locked confirmation row

**Constraints**: AI optional; explicit scope consent; no write during generation; deny unknown/unconfirmed tools;
fixed HTTPS endpoints; no live calls/keys; no deployment/feature002/universal agent

**Scale/Scope**: Two providers, several connections with one active, one consent scope, one tool/scenario, five
tables, settings and draft/confirm endpoints, one Settings view and one bounded Storage UI extension

## Localisation Plan

**Message ownership**: Existing TypeScript dictionaries own Settings/Storage/changelog UI. Laravel `messages.php`
owns safe provider, consent, validation, stale/expiry/tool errors. Every key ships together in en/ru/uk.

**Runtime locale**: Existing Profile-authoritative middleware/frontend i18n remains authoritative. User connection
names, model identifiers, item/project/tag text and provider names are never translated.

**Formatting**: Test/use/expiry UTC instants display through locale/timezone formatters; `due_on` remains a
Profile-local date; output-token limits use locale number formatting.

**Safety copy**: Settings names the external data category and BYOK cost. Storage repeats what leaves before the
request and distinguishes proposal from saved state. Errors are closed/localized; provider bodies never surface.

**Delivery gates**: dictionary parity/used-key/hardcoded guards, three-locale backend messages, Vitest contracts,
EN/RU/UK browser journeys, light/dark desktop/exact-phone screenshots and visual inspection.

## Constitution Check

### Pre-Research Gate: PASS

- Specification and architecture artifacts precede production edits.
- Connection, consent/draft, confirmation and optional/failure behavior are independently testable stories.
- Storage remains deterministic, manually complete and available without provider/network/consent.
- Owner scope, encryption, minimal data, explicit consent, deny-by-default tools and confirmation bind privacy.
- Database/provider/REST/OpenAPI/TypeScript/Vue/Android and permanent tests move atomically.
- Full EN/RU/UK, responsive accessibility and honest live-key caveat are explicit gates.

### Post-Design Gate: PASS

Research resolves first scenario, provider portability, current strict contracts, custom endpoint exclusion,
active selection, encryption/masking, consent, context, tool schema, replay-safe confirmation, Storage ownership,
errors/audit, bounds/retention and deferrals. No critical/high ambiguity remains.

## Architecture Gates

1. **Owner**: AI owns connection/settings/consent/confirmation/audit and provider orchestration. Storage owns the
   Item/tag/project mutation; provider output is not a product fact before confirmation.
2. **Inputs**: Profile supplies locale, timezone, calendar date and recommendation tone at request time. AI does
   not copy them into mutable user facts.
3. **Time**: test/use/audit/expiry/apply timestamps are UTC. Proposed `due_on` is a calendar date interpreted in
   current Profile timezone; no UTC conversion or moving deadline occurs.
4. **Scheduling**: No recurrence, PlannedOccurrence, reminder or scheduling state is introduced. A triaged due
   date remains the existing Storage date until Planner's established projection reads it.
5. **Cross-module links**: Storage -> ephemeral context -> provider proposal -> confirmed Storage tool. AI never
   becomes an Item owner and the provider never writes a domain route directly.
6. **Evolution**: Five additive tables with closed checks/FKs/indexes and dependency-safe rollback. Existing rows
   and tables are untouched; portability catalog/eligibility explicitly handles every new table.
7. **Contracts**: Provider value objects/adapters, migrations/models/resources, REST/OpenAPI, TypeScript client/
   types, Vue Settings/Storage and error codes change together.
8. **Aggregates**: No aggregate is recomputed. Context reads bounded owned references only; Review/Analytics are
   unaffected.
9. **Privacy**: Encrypted replace-only keys, four-character hint, minimal explicit scope, fixed endpoints,
   no content logs/audit/backup, encrypted proposal, hashed replay records and foreign-owner 404 boundaries.
10. **Deferral**: Custom endpoints, other scenarios/domains, universal chat/RAG, multi-tool loops, streaming,
    vision/files, background jobs, model/pricing catalogue, usage billing, live calls, deployment and feature002.

## Project Structure

```text
specs/026-ai-assistant-foundation/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── tasks.md
├── analysis.md
├── checklists/requirements.md
└── contracts/openapi.yaml

apps/api/
├── app/Contracts/LlmProvider.php
├── app/Data/Ai/{LlmToolCall,LlmToolDefinition}.php
├── app/Exceptions/AiAssistantException.php
├── app/Http/Controllers/Ai/{LlmConnectionController,LlmConsentController,InboxTriageController}.php
├── app/Http/Requests/Ai/{StoreLlmConnectionRequest,UpdateLlmConnectionRequest}.php
├── app/Http/Resources/Ai/LlmConnectionResource.php
├── app/Models/{LlmConnection,LlmSetting,LlmConsent,LlmToolConfirmation,LlmAuditEvent}.php
├── app/Services/Ai/
│   ├── Providers/{AnthropicLlmProvider,OpenAiLlmProvider}.php
│   ├── LlmProviderRegistry.php
│   ├── LlmConnectionService.php
│   ├── LlmConsentService.php
│   ├── LlmAuditLogger.php
│   ├── InboxTriageContextBuilder.php
│   ├── InboxTriageProposalService.php
│   ├── LlmToolRegistry.php
│   ├── LlmToolAuthorizationService.php
│   ├── LlmConfirmationTokenService.php
│   └── StorageInboxTriageTool.php
├── config/ai.php
├── database/migrations/2026_08_14_090000_create_ai_assistant_foundation.php
├── routes/api.php
└── tests/{Unit,Feature}/Ai/...

apps/web/src/
├── api/{client.ts,types.ts}
├── views/{AiSettingsView.vue,StorageView.vue}
├── layouts/AppShell.vue
├── router.ts
├── i18n/locales/{en,ru,uk}.ts
├── content/changelog.ts
├── __tests__/ai-assistant-contracts.test.ts
└── e2e/ai/{ai-assistant-flow.spec.ts,ai-assistant-visual.spec.ts,support.ts}
```

## Delivery Strategy

1. Freeze Spec Kit artifacts and run pre-implementation analysis.
2. Add RED schema/provider/security/ownership/confirmation/contract/frontend/journey tests.
3. Implement additive schema/models/value objects and safe error/audit boundaries.
4. Implement fixed-endpoint provider adapters, connection/consent APIs and fixture tests.
5. Implement context, strict tool proposal, encrypted confirmation and Storage tool with race/stale tests.
6. Implement OpenAPI/TypeScript/localized Settings and Storage review UI; sync Android web bundle.
7. Run focused then full backend/frontend/E2E/mobile gates; inspect screenshots and scan secrets/protected paths.
8. Update design decisions/roadmap/technical design/changelog/analysis/tasks/memory, run GitNexus change detection,
   commit atomically, push current master and prove parity.

## Risk Controls

- **Credential exposure**: encrypted hidden cast, replace-only API, four-character hint, request validation, safe
  exceptions and explicit response/log/source scans.
- **External-data overreach**: closed scope and context builder with captured HTTP fixture assertions.
- **Prompt injection/tool abuse**: forced one closed tool, strict schema plus local validation, deny-by-default
  registry, model never executes.
- **Unconfirmed/replayed/stale write**: encrypted binding, hashed DB record, expiry, row lock, current authority and
  source fingerprint checks before one transaction.
- **Provider drift/cost**: user model choice, small HTTP adapters, context-free probe, output/time bounds, no retry
  loop and live-key caveat.
- **SSRF**: fixed server-owned HTTPS hosts; custom endpoint deferred.
- **Regression**: AI absent from deterministic call paths and full Storage/Planner/portability/browser suites.
