# Offline workspace and personal mentor

Owner request: 2026-09-18. Extends the prior offline and universal-assistant deferrals in features012/026. Delivery is not accepted until the checks below pass.

## Contracts

- Existing API clients remain compatible. New clients persist personal reads and unsent commands by account. A successful local save survives process restart. UI distinguishes local, pending, rejected, conflicting and synchronized states.
- Server-authorized mutations use an operation UUID and request fingerprint. Retry after a lost response returns the original result without repeating side effects. Reusing a UUID with different input fails. An outdated base revision never silently overwrites newer data. Ordinary clients also advance revisions.
- No cache/queue may be replayed for a different authenticated account. Expired credentials pause synchronization, preserving edits. Logout explicitly handles unsent data. Passwords, provider keys, session tokens and integration secrets are excluded from the offline response cache.
- Web app shell supports offline restart after an initial online visit. Android bundles the same interface. Foreground/reconnection sync is required; background scheduling is best effort. First registration/login, external integrations and cloud AI require connectivity.
- Provider/model/authentication are saved in the user's account settings; microphone activation never asks for a provider. OpenAI is the owner's choice. Keep existing API/Anthropic connections supported. API keys stay encrypted on the server. The 2026-09-19 owner request adds ChatGPT subscription authentication through official Codex App Server managed device login.
- Mentor has personal conversations and editable memory. Bounded tools retrieve only the caller's records from every supported module. Context contains a feature catalogue, profile, current date/timezone, recent conversation and requested facts. Untrusted stored text is data, not authority. No arbitrary SQL or cross-account access.
- Actions use existing validation and domain services. Mutating proposals require an explicit review/confirmation, are bound to owner/input/revision and cannot be applied twice. No automatic financial or destructive changes from model text.
- Show actual token usage and estimated cost, including reasoning/cache categories. Persist a monthly budget and reserve bounded costs before concurrent paid calls. Unknown model pricing must be visible, never reported as zero cost.
- Voice recordings are durable drafts, owner scoped and limited in size/duration. Offline recording is supported; cloud transcription resumes only on the owner's enabled setting/explicit action. Transcript can be corrected before sending. No raw audio retained server-side after transcription.

## Acceptance

- Two account isolation through lists, direct IDs, tools, caches, proposals, audio and pending queue.
- Airplane-mode restart/read/write; process termination; reconnection; lost acknowledgements; repeated UUID; two-device conflicts; rejected validation; expired session and logout; browser storage errors.
- Real SQLite/MySQL transactional receipt and conflict tests; browser persistence and UI tests; Android compilation and signed packaged assets.
- Mocked provider tests cover success, malformed calls, refusal, timeout, context limits, no key disclosure, budget reservation, stale confirmation and duplicate apply. Real provider acceptance remains explicitly pending until the owner connects ChatGPT or supplies an API key in settings.
- Compact320/360px UI and large text, EN/RU/UK, no horizontal overflow. Record exact supported offline operations; do not advertise a module as fully offline until its read/write/projection tests pass.

## ChatGPT subscription connection

- Each SelfHandler account connects its own ChatGPT account. The application derives the bridge account identity from the authenticated user; request bodies cannot choose another owner. Operator/Codex desktop credentials are never copied into the service.
- Official managed OAuth persists and refreshes credentials in a private per-account directory. Tokens, device codes and account metadata are excluded from offline caches. Disconnect cancels an unfinished login and disables subscription mentor requests.
- Subscription turns use ephemeral Codex threads with a clean environment, restricted filesystem reads and no model-executed tools. Structured proposals use the existing Laravel personal-context reader and confirmation services. No SQL, filesystem, shell, plugin or arbitrary-network capability is granted to model instructions.
- The model catalogue comes from the connected account. Display shared subscription usage; stop when a reported subscription window is exhausted or quota cannot be verified. Never fall back to an API key, buy credits or redeem reset credits automatically. A subscription turn's incremental API charge is zero; the subscription's own price and shared limits remain separate.
- For subscription mode, voice input uses the Android recognition activity or browser speech service, produces an editable draft and makes no OpenAI transcription API call. Availability and offline recognition depend on the installed speech service. Existing durable audio recording/cloud transcription remains an API-connection feature; do not claim local transcription on every device.
- Verify per-account isolation, managed-login start/cancel, quota rejection before model execution, explicit retry after pre-execution refusal, no paid transcription fallback, 320px settings, packaged Android code and isolated runtime startup. An unauthenticated startup test is not evidence of a real model response.
