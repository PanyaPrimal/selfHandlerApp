# Data Model: Multi-User Authentication

This feature reuses the existing account, session, and domain ownership schema. The business model
does not require a new account table or ownership migration. Email is normalized before persistence;
password material is hashed before storage; session payloads remain server-side.

## Account (`users`)

An independent person who may authenticate and owns all personal SelfHandler records.

### Fields

| Field | Type | Required | Rules |
|---|---|---:|---|
| `id` | identifier | yes | Primary key; exposed as safe account identity |
| `name` | string | yes | Trimmed display name, 1-100 characters |
| `email` | string | yes | Trimmed and lowercase, valid email, maximum 255, globally unique |
| `password` | password hash | yes | Hash of a confirmed plaintext value of at least 12 characters |
| `email_verified_at` | instant | no | Remains null; verification is outside this feature |
| `remember_token` | secret string | no | Not used or exposed by this feature |
| `created_at`, `updated_at` | instant | yes | Application-managed UTC timestamps |

### Safe Representation

Only the following fields cross the API boundary:

```json
{
  "id": 42,
  "name": "Alex Example",
  "email": "alex@example.test"
}
```

`password`, `remember_token`, timestamps, session IDs, and verification internals are never part of
the account response.

### Constraints and Transitions

- Unique `email` is the final duplicate-concurrency guard after normalization.
- Registration: `absent -> persisted account + authenticated session` in one successful request.
- A failed validation, duplicate insert, or session establishment rolls back/returns failure without
  claiming an authenticated account.
- Account editing, deletion, verification, recovery, roles, and invitations have no transition in this
  feature.

## Authenticated Session (`sessions`)

A server-side, time-bounded browser relationship to exactly one Account.

### Fields

| Field | Type | Required | Rules |
|---|---|---:|---|
| `id` | opaque identifier | yes | Rotated after registration and successful login; cookie value is HttpOnly |
| `user_id` | identifier | no | Null for anonymous session; Account ID once authenticated; indexed |
| `ip_address` | string | no | Request metadata; never returned to the SPA |
| `user_agent` | text | no | Request metadata; never returned to the SPA |
| `payload` | encrypted/encoded server state | yes | Not a public contract |
| `last_activity` | integer timestamp | yes | Supports idle expiry and cleanup |

### Lifecycle

```text
anonymous --register/login + rotate--> authenticated(account A)
authenticated(account A) --valid request--> authenticated(account A)
authenticated --logout/invalidate--> invalid
authenticated --idle expiry--> invalid
invalid --register/login + rotate--> authenticated(account X)
```

- One session identifies at most one Account.
- Signing out invalidates the current session only.
- The browser may hold separate valid sessions in separate profiles/devices; each is independently
  scoped to its Account.
- The readable `XSRF-TOKEN` cookie is an anti-forgery token, not an authentication credential. The
  actual session cookie remains inaccessible to scripts.

## Login Attempt Window

A short-lived rate-limit record keyed by normalized email plus requester IP, with a separate broader
IP control where configured.

### State

| Field | Meaning |
|---|---|
| key | One-way or non-secret normalized request identity used only for throttling |
| attempts | Failed or accepted requests counted in the active window |
| available_at | Earliest retry time once the limit is exhausted |

This state uses the configured application cache/rate limiter. It is not an account record, is not
returned except as generic retry timing, and expires automatically.

## Owned Records

The following existing records continue to carry one immutable `user_id` owner:

| Record | Owner-sensitive behavior |
|---|---|
| Routine | Lists, updates, archive/delete behavior, Today scheduling |
| Goal | Lists, updates, and Routine relationships |
| Goal-Routine Link | Both related records and the link share the authenticated owner |
| Routine Log | Parent Routine and log share the authenticated owner; unique per owner/routine/date |
| Daily Review | Unique per owner/review date |
| Today Aggregate | Derived only from the authenticated owner's records |

### Ownership Invariants

1. Client input never chooses `user_id`; the authenticated Account supplies it.
2. A parent/nested record must have the same owner as every created child or relationship.
3. List and aggregate queries start with the authenticated owner predicate.
4. A foreign identifier produces the same `404` result as an unavailable identifier and no mutation.
5. Account-scoped unique constraints include `user_id`, so different users may use the same dates,
   routine names, or other domain values where the domain otherwise permits them.
6. Signing out or switching accounts clears browser-held domain results before the next protected
   screen renders.

## Validation Rules

### Registration

- `name`: required string, trim whitespace, 1-100 characters.
- `email`: required string, trim and lowercase, valid email syntax, maximum 255, unique.
- `password`: required string, at least 12 characters, matches `password_confirmation`.
- `password_confirmation`: required by confirmation semantics; never stored.

### Login

- `email`: required string, trim and lowercase, valid email syntax, maximum 255.
- `password`: required string.
- An unknown email and a wrong password share one validation response.

## Migration Disposition

- `users.email` already has a unique index.
- `users.password` and hidden serialization are already present.
- `sessions` already supports database-backed sessions.
- All current domain/pivot tables already contain `user_id` and account-scoped keys.
- Sanctum personal access tokens are not a domain entity in this feature and no token-issuing endpoint
  or client storage is introduced.
