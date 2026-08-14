# Requirements Quality Checklist: AI Assistant Foundation

**Purpose**: Validate specification clarity, privacy, provider contracts, tool authorization and delivery scope.

- [x] CHK001 Every story has priority, independent test and Given/When/Then acceptance.
- [x] CHK002 Two real known provider adapters and runtime owner selection are defined without invented live success.
- [x] CHK003 Multi-connection persistence, one active pointer, readiness, rotation, deletion and masking are explicit.
- [x] CHK004 Encryption-at-rest, replace-only secrets and response/log/source non-disclosure are testable.
- [x] CHK005 Fixed HTTPS endpoints and the explicit custom-endpoint SSRF deferral are unambiguous.
- [x] CHK006 Per-scope opt-in/revocation defaults and confirmation-time recheck are explicit.
- [x] CHK007 The first scenario names exact outbound fields and every excluded sensitive category.
- [x] CHK008 Provider strict tool-call shape and independent backend validation are both required.
- [x] CHK009 Unknown/multiple/malformed/refused/truncated/foreign tool output fails closed.
- [x] CHK010 No write occurs during generation and every write tool requires explicit confirmation.
- [x] CHK011 Encrypted binding, hashes, expiry, replay, race, stale source and owner checks are testable.
- [x] CHK012 Storage owns the one atomic Item/tag mutation and existing model invariants remain authoritative.
- [x] CHK013 Safe error codes and content-free audit prohibit secrets/prompts/responses/arguments/rationale.
- [x] CHK014 Timeouts, output/context bounds, no retry loop and BYOK cost disclosure are explicit.
- [x] CHK015 Five additive tables, MySQL checks/FKs/indexes/rollback and existing-row preservation are covered.
- [x] CHK016 Portability exclusion and non-empty restore target handling enumerate all AI tables.
- [x] CHK017 REST/OpenAPI/backend/TypeScript/Vue/Android contracts move together.
- [x] CHK018 EN/RU/UK visible/accessibility/backend copy and date/number formatting are included.
- [x] CHK019 Desktop/exact-phone, keyboard, status/error, 44px, no-overflow, light/dark and visual gates are included.
- [x] CHK020 Deterministic Storage works without AI and full regression evidence is required.
- [x] CHK021 Architecture gates answer owner, inputs, time, scheduling, direction, evolution, contracts, aggregates,
  privacy and deferral.
- [x] CHK022 Universal chat/RAG, other scenarios, streaming, vision, background, model catalogue, live data,
  deployment and feature002 are explicitly out.
- [x] CHK023 Success criteria prove behavior/security and do not accept file existence as evidence.
- [x] CHK024 No unresolved clarification, placeholder, contradiction or critical/high pre-implementation finding remains.
