# Live Provider Acceptance: AI Assistant Foundation

> **Status:** not yet performed. Feature 026 is complete (`104/104`) except this one external item.
> Its absence does not authorize fabricated success or a committed credential.

Feature 026 ships two BYOK adapters (Anthropic Messages, OpenAI Responses) verified entirely against
recorded fixtures — no automated test ever reaches a provider host. This runbook is the remaining evidence:
one real key, one real model, one real draft/confirm journey.

## 1. What the operator must supply

| Item | Notes |
|---|---|
| An Anthropic **or** OpenAI API key | One provider is enough to close the caveat. Both is better evidence. |
| A **strict-tool-capable model** | Required — see below. |
| Acceptance of a small billable charge | The whole journey is a few hundred tokens. On `claude-haiku-4-5` that is well under one cent. |

**The model must support structured outputs / strict tool use.** `AnthropicLlmProvider::propose()` sends
`strict: true` on the tool definition together with `tool_choice: {type: "tool", disable_parallel_tool_use: true}`;
`OpenAiLlmProvider::propose()` sends `strict: true` on the function tool plus `parallel_tool_calls: false`.
`strict` needs no beta header, but a model without structured-output support answers `400`, which the app
maps to the `unsupportedCapability` error — a red journey that proves nothing about the happy path.

- **Anthropic — recommended `claude-haiku-4-5`** (cheapest capable model). Also valid: `claude-opus-5`,
  `claude-sonnet-5`, `claude-opus-4-8`, `claude-fable-5`.
- **OpenAI** — any current model whose Responses API supports strict function calling.

### Where the key lives

Nothing is configured in the repository — `config/ai.php` already pins both provider hosts, and the key is
entered in the `/settings/ai` UI. It is then **stored server-side, per user, encrypted at rest**:

- `llm_connections.api_key` is cast `encrypted` (`LlmConnection::casts()`), so Laravel encrypts it with the
  application key before it reaches MySQL. The row is owned via `user_id` (`UserOwned`).
- The connection therefore **follows the account, not the device** — signing in from a phone, another
  browser, or the Android shell shows the same connections with no re-entry.
- The key is never handed back out: `api_key` and `key_hint` are in `$hidden`, and `LlmConnectionResource`
  exposes only `key_mask` (`••••` plus the last four characters).
- Consequence worth knowing: the encryption is at rest against `APP_KEY`. Anyone holding both the database
  and `APP_KEY` can decrypt. That is the same trust boundary as every other secret in this app.

## 2. Preconditions

- A running stack with a signed-in user. Either the live stack
  (`https://desktop-gh03uov.tail31a802.ts.net`, or `http://127.0.0.1:18080`) or a local dev stack
  (`php artisan serve` + `npm --prefix apps/web run dev`).
- Outbound HTTPS to `api.anthropic.com` / `api.openai.com` from wherever the API runs.
  On the Docker stack that is the `app` container, not the browser.
- At least one Storage Inbox item with a real title and description to triage.

## 3. Journey

1. **Add the connection.** `/settings/ai` → add connection: name, provider, model id, API key,
   `max_output_tokens` (128–2048; the default 512 is fine). Save.
   - Record: the connection is created and the key is immediately masked to its last four characters.
     The plaintext key is never returned by `GET /api/ai/settings`.
2. **Probe.** Run the connection test. The adapter sends a context-free probe and requires the reply to be
   exactly `SELFHANDLER_OK` with `stop_reason: end_turn` (Anthropic) / `status: completed` (OpenAI).
   - Record: pass/fail and round-trip time.
3. **Negative credential check.** Add a second connection with a deliberately wrong key and test it.
   - Record: it fails with `credentials_invalid` (401/403 mapped), stays inactive, and no key material
     appears in the response or logs.
4. **Activate.** Activate only the working connection. Confirm exactly one active pointer exists.
5. **Consent.** Read the `storage_inbox` disclosure and grant it. Confirm the scope is off by default and
   revocable.
6. **Draft.** Request a triage proposal for one Inbox item.
   - Record: the provider returns exactly one `storage_triage_inbox_item` tool call; the backend re-validates
     the arguments against its own closed schema; the source Item is **unchanged** while the proposal is visible.
7. **Dismiss and regenerate.** Dismiss once — verify no write happened. Regenerate.
8. **Confirm.** Confirm the proposal.
   - Record: exactly one Storage-owned write; the Item becomes active with the reviewed values; the existing
     Storage UI reflects it.
9. **Replay/expiry.** Re-submit the same confirmation token.
   - Record: rejected (one-use), and no second write. If practical, also let a capability expire (10 min).
10. **Revoke.** Revoke `storage_inbox` consent and attempt another draft.
    - Record: refused, zero provider traffic.
11. **Audit.** Inspect `llm_audit_events` for the journey.
    - Record: append-only, content-free — no prompt text, no item content, no key material.

## 4. What must be true afterwards

- Outbound payload contained only: the selected Inbox item's title/description, owned project/tag names,
  and Profile locale/timezone/tone. No finance, health, journal, attachment, credential, or other-item data.
- Exactly one Storage write across the whole journey, and it happened only at step 8.
- No key material in the database in plaintext, in any API response, in application logs, or in screenshots.

## 5. Evidence template

Fill this in during the run, then copy the summary into `tasks.md` and `quickstart.md` §Recorded evidence.

```
Date:
Operator:
Host: (production | local 18080 | local dev)
Provider:                      Model:
Probe: pass/fail               round-trip: ___ ms
Wrong-key test: credentials_invalid yes/no
Active connections after activation: ___ (must be 1)
Consent default state: denied yes/no
Draft: single tool call yes/no   backend re-validation passed yes/no
Source item unchanged while proposal visible: yes/no
Dismiss caused a write: yes/no (must be no)
Confirm: writes observed ___ (must be 1)
Replay rejected: yes/no        Expiry rejected: yes/no (or n/a)
Post-revoke draft refused with zero provider traffic: yes/no
Audit events content-free: yes/no
Approximate cost:
Defects found:
```

## 6. Secret hygiene

- Never paste a key into a tracked file, a screenshot, a commit message, or this document.
- Mask any key reference as its last four characters only.
- Before committing evidence:
  ```
  git grep -n -I -E 'sk-ant-|sk-proj-|Bearer [A-Za-z0-9_-]{20,}'
  git status --short
  ```
- Delete the throwaway wrong-key connection from step 3 when finished.
