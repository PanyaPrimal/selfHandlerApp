# Offline workspace and personal mentor

Owner request: 2026-09-18. Extends the prior offline and universal-assistant deferrals in features012/026. Delivery is not accepted until the checks below pass.

## Contracts

- Existing API clients remain compatible. New clients persist personal reads and unsent commands by account. A successful local save survives process restart. UI distinguishes local, pending, rejected, conflicting and synchronized states.
- Server-authorized mutations use an operation UUID and request fingerprint. Retry after a lost response returns the original result without repeating side effects. Reusing a UUID with different input fails. An outdated base revision never silently overwrites newer data. Ordinary clients also advance revisions.
- No cache/queue may be replayed for a different authenticated account. Expired credentials pause synchronization, preserving edits. Logout explicitly handles unsent data. Passwords, provider keys, session tokens and integration secrets are excluded from the offline response cache.
- Web app shell supports offline restart after an initial online visit. Android bundles the same interface. Foreground/reconnection sync is required; background scheduling is best effort. First registration/login, external integrations and cloud AI require connectivity.
- Provider/model/key are saved in the user's account settings; microphone activation never asks for a provider. OpenAI is the owner's choice. Keep existing Anthropic connections supported. Keys stay encrypted on the server.
- Mentor has personal conversations and editable memory. Bounded tools retrieve only the caller's records from every supported module. Context contains a feature catalogue, profile, current date/timezone, recent conversation and requested facts. Untrusted stored text is data, not authority. No arbitrary SQL or cross-account access.
- Actions use existing validation and domain services. Mutating proposals require an explicit review/confirmation, are bound to owner/input/revision and cannot be applied twice. No automatic financial or destructive changes from model text.
- Show actual token usage and estimated cost, including reasoning/cache categories. Persist a monthly budget and reserve bounded costs before concurrent paid calls. Unknown model pricing must be visible, never reported as zero cost.
- Voice recordings are durable drafts, owner scoped and limited in size/duration. Offline recording is supported; cloud transcription resumes only on the owner's enabled setting/explicit action. Transcript can be corrected before sending. No raw audio retained server-side after transcription.

## Acceptance

- Two account isolation through lists, direct IDs, tools, caches, proposals, audio and pending queue.
- Airplane-mode restart/read/write; process termination; reconnection; lost acknowledgements; repeated UUID; two-device conflicts; rejected validation; expired session and logout; browser storage errors.
- Real SQLite/MySQL transactional receipt and conflict tests; browser persistence and UI tests; Android compilation and signed packaged assets.
- Mocked provider tests cover success, malformed calls, refusal, timeout, context limits, no key disclosure, budget reservation, stale confirmation and duplicate apply. Real OpenAI acceptance remains explicitly pending until a key is supplied in settings.
- Compact320/360px UI and large text, EN/RU/UK, no horizontal overflow. Record exact supported offline operations; do not advertise a module as fully offline until its read/write/projection tests pass.
