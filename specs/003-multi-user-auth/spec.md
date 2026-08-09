# Feature Specification: Multi-User Authentication

**Feature ID**: `003-multi-user-auth`

**Created**: 2026-08-09

**Status**: Draft

**Input**: Add email-and-password registration and authentication so more than one person can have
an independent SelfHandler account and each person's data remains private.

## Clarifications

### Session 2026-08-09

- Q: Should SelfHandler remain bound to one implicit local user? → A: No. Users must be able to
  register separate accounts and authenticate explicitly.
- Q: How may additional users be created in this increment? → A: Self-registration is available
  to anyone who can already reach the privately hosted application; invitations and administrator
  approval are not required in this increment.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Create an Independent Account (Priority: P1)

As a new user, I register with my name, email address, and password so I can begin using SelfHandler
without sharing another person's identity or records.

**Why this priority**: Explicit account creation is the smallest usable replacement for the current
development-only implicit user and enables the first real production user.

**Independent Test**: From a signed-out browser, register a new email address, confirm that the new
session identifies that account, and verify that the new user starts with no routines, goals, logs,
or reviews belonging to another account.

**Acceptance Scenarios**:

1. **Given** a visitor can reach the application and the email is unused, **When** they submit a valid
   name, email, password, and matching password confirmation, **Then** one account is created and the
   user enters an authenticated session.
2. **Given** an email already belongs to an account, **When** a visitor attempts to register the same
   email with different letter casing or surrounding whitespace, **Then** no duplicate account is
   created and the email field is explained.
3. **Given** one or more fields are invalid, **When** registration is submitted, **Then** no account
   or authenticated session is created and each invalid field is explained without losing the safe
   values already entered.
4. **Given** account A already owns product data, **When** account B is registered, **Then** account B
   sees an empty personal workspace and account A's data remains unchanged.

---

### User Story 2 - Sign In and Sign Out Safely (Priority: P2)

As a returning user, I sign in with my email and password, remain signed in while using or reloading
the application, and sign out when I am finished.

**Why this priority**: Registration has durable value only when an account can be used again and its
session can be ended deliberately.

**Independent Test**: Register an account, sign out, sign back in with the same credentials, reload a
protected page, and sign out again; verify that protected content is available only during the valid
session.

**Acceptance Scenarios**:

1. **Given** an existing signed-out account, **When** the correct email and password are submitted,
   **Then** a new authenticated session is established and the user's personal workspace opens.
2. **Given** an unknown email or incorrect password, **When** sign-in is attempted, **Then** no session
   is established and the response does not reveal which credential was wrong.
3. **Given** an authenticated user reloads the browser or follows a protected link, **When** the
   current session is still valid, **Then** the application restores the same signed-in account
   without asking for credentials again.
4. **Given** an authenticated user signs out, **When** the sign-out completes, **Then** the current
   session is invalidated, protected content is cleared from the screen, and protected navigation
   leads to sign-in.
5. **Given** a session is missing, invalid, or expired, **When** a protected operation is requested,
   **Then** no protected data is returned and the user is prompted to sign in again.

---

### User Story 3 - Keep Every Account's Data Private (Priority: P3)

As a SelfHandler user, I can view and change only records owned by my account so multiple users can
use the same installation without seeing or altering one another's personal history.

**Why this priority**: Multiple accounts are unsafe unless ownership is enforced for every current
domain operation, including relationships and date-based summaries.

**Independent Test**: Create accounts A and B with different routines, goals, logs, and reviews;
exercise every list, detail, create, update, relationship, Today, and review operation as both users
and verify that no cross-account data or existence signal is exposed.

**Acceptance Scenarios**:

1. **Given** accounts A and B own different records, **When** either user lists routines, goals, logs,
   reviews, or opens Today, **Then** only records owned by the current account contribute to the
   response and calculations.
2. **Given** account A knows an identifier owned by account B, **When** account A requests, changes,
   deletes, logs, or links that record, **Then** the operation reveals no record and changes nothing.
3. **Given** account A creates a routine, goal, log, review, or relationship, **When** the write
   succeeds, **Then** the resulting data is owned by account A regardless of any ownership value sent
   by the client.
4. **Given** two accounts use the same date or the same routine title, **When** they save their own
   records, **Then** account-specific uniqueness rules do not cause a conflict between the accounts.
5. **Given** account A signs out and account B signs in within the same browser, **When** the workspace
   loads, **Then** no cached account-A data remains visible or is reused for account B.

---

### User Story 4 - Understand and Recover from Authentication Errors (Priority: P4)

As a user, I receive clear, accessible feedback during registration and sign-in so network failures,
invalid input, and temporary request limits do not leave me unsure whether an account or session was
created.

**Why this priority**: Authentication is the entry point to the product; ambiguous errors can lead to
duplicate attempts, insecure password handling, or an apparently unusable application.

**Independent Test**: Exercise field validation, invalid credentials, repeated failed attempts, an
unavailable server, and a subsequent successful retry; verify that each state is understandable and
does not expose protected information.

**Acceptance Scenarios**:

1. **Given** a registration or sign-in request is pending, **When** the user submits again, **Then**
   only one request is processed and the form shows a pending state.
2. **Given** authentication requests exceed the allowed attempt rate, **When** another attempt is
   made, **Then** it is temporarily refused with a retryable message and existing accounts remain
   usable after the limit window.
3. **Given** the service or network is unavailable, **When** a user submits an authentication form,
   **Then** no success is claimed, the password is not displayed or persisted, and the user can retry.

### Edge Cases

- Email addresses contain uppercase letters, leading or trailing whitespace, or international domain
  syntax; identity comparison remains deterministic.
- Two registration requests for the same email arrive concurrently; at most one account is created.
- A signed-in user opens a public authentication route; they return to their personal workspace
  instead of creating an accidental second session.
- Authentication expires while a write form has unsaved content; the write is refused and no other
  user's data is shown.
- A direct browser load occurs before session restoration completes; protected content does not flash
  briefly for a signed-out user.
- A user changes browser profiles or devices; each valid session identifies only its own account.
- A client attempts to submit an account or owner identifier alongside a domain write; the supplied
  ownership value is ignored or rejected.
- A record relationship references one owned and one foreign record; no partial relationship is
  created.
- The application is served through its production private HTTPS route or the local development
  route; credentials and session state follow the same observable rules.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Signed-out visitors MUST be able to create an account using a display name, email
  address, password, and password confirmation.
- **FR-002**: Registration MUST normalize email identity consistently, enforce one account per
  normalized email, and remain correct under concurrent duplicate requests.
- **FR-003**: Passwords MUST accept secure passphrases of at least 12 characters, MUST be confirmed at
  registration, and MUST never be returned, logged, or stored in recoverable form.
- **FR-004**: Successful registration MUST establish a new authenticated session for the created
  account without requiring a second sign-in.
- **FR-005**: Existing users MUST be able to sign in using their normalized email and password.
- **FR-006**: Failed sign-in MUST use a generic credential error and MUST NOT reveal whether an email
  is registered.
- **FR-007**: Registration and sign-in attempts MUST be rate-limited by appropriate request and
  identity signals, and temporary limits MUST be communicated without exposing account existence.
- **FR-008**: The system MUST rotate the session identity after registration and sign-in to prevent a
  visitor-controlled session from becoming an authenticated session unchanged.
- **FR-009**: The client MUST be able to retrieve the currently authenticated account's non-secret
  identity so it can restore session state after a reload.
- **FR-010**: Users MUST be able to sign out; sign-out MUST invalidate the current authenticated
  session and the client MUST discard protected account data.
- **FR-011**: All current domain and aggregate operations MUST require authentication and MUST return
  no protected data when the session is absent, invalid, or expired.
- **FR-012**: Every current domain list, lookup, write, relationship, Today calculation, and daily
  review operation MUST be scoped to the authenticated account.
- **FR-013**: A request involving another account's record MUST behave as though that record is not
  available and MUST make no data change.
- **FR-014**: The server MUST derive ownership from the authenticated account and MUST NOT trust an
  account or owner identifier supplied by the client.
- **FR-015**: Public registration and sign-in operations and screens MUST be available only to
  signed-out visitors; an authenticated request MUST NOT create another account or switch accounts,
  and protected application screens MUST be available only after session restoration confirms a
  valid account.
- **FR-016**: Authentication forms MUST expose labels, field-level validation, pending states, and
  retryable service errors without preserving or redisplaying password values.
- **FR-017**: Authentication MUST use browser-safe session and request-forgery protections on every
  state-changing request and MUST protect session credentials from script access.
- **FR-018**: Production authentication MUST NOT create or select an implicit fallback user, and a
  production-equivalent test MUST fail if protected data is accessible without an explicit session.
- **FR-019**: Two or more registered accounts MUST be able to use the same installation concurrently
  without shared in-memory client state, ownership collisions, or cross-account cached data.
- **FR-020**: Authentication and ownership behavior MUST be represented consistently in the API
  contract, backend tests, frontend types, and end-to-end user flows.

### Scope Boundaries

This feature includes open self-registration on the privately reachable application, email/password
sign-in, session restoration, current-session sign-out, authentication screens, protected navigation,
and strict ownership enforcement for every domain capability present in the current codebase.

It excludes email verification, password reset or recovery email, changing a password, social login,
passkeys, two-factor authentication, roles, administrators, invitations, account deletion, profile
settings beyond the registration name, collaboration or record sharing, public-internet exposure,
and management of all sessions on other devices.

### Key Entities

- **Account**: A person's independent SelfHandler identity, described by a display name and one unique
  normalized email, with protected password credentials and ownership of all personal records.
- **Authenticated Session**: A time-bounded browser relationship to exactly one account, created only
  after valid registration or credentials and invalidated for that browser at sign-out.
- **Owned Record**: Any routine, goal, relationship, routine log, daily review, or derived result whose
  visibility and mutation rights belong to exactly one account.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A first-time user can create an account and reach an empty personal workspace in under
  two minutes using only the application UI.
- **SC-002**: A returning user can sign in, reload a protected route, and retain the same account in
  100% of valid-session acceptance runs.
- **SC-003**: Signing out prevents the invalidated session from reading or changing protected data in
  100% of acceptance runs.
- **SC-004**: A two-account ownership matrix across every current list, detail, create, update,
  delete, relationship, Today, log, and review operation produces zero cross-account disclosures or
  mutations.
- **SC-005**: Duplicate normalized-email registration, including simultaneous attempts, creates no
  more than one account in 100% of concurrency acceptance runs.
- **SC-006**: Invalid input, invalid credentials, rate limits, expired sessions, and service failures
  each produce a clear recoverable UI state while exposing zero passwords, session credentials, or
  foreign account data.
- **SC-007**: Backend, frontend, and end-to-end authentication suites pass on both local development
  and production-equivalent same-origin configurations before the feature is considered complete.

## Assumptions

- Anyone who can reach the private SelfHandler application may self-register during this increment;
  network access remains the outer admission boundary for the homelab deployment.
- Every account has equal product capabilities. Administrative account management and collaboration
  are future features and are not implied by multi-user support.
- Users have a unique email address they control, but this increment does not send email or verify
  ownership of that address.
- Existing prototype domain tables already carry account ownership. Existing development data is
  preserved, but no implicit development account is used to authenticate browser requests after this
  feature is complete.
- The web application and API are presented as one site in production. Local development may use a
  development proxy while preserving the same cookie and request-protection behavior.
- The canonical design decision that multi-user authentication was deferred is updated as part of
  this feature so the long-term design and delivered behavior remain aligned.
