---

description: "Dependency-ordered implementation tasks for SelfHandler multi-user authentication"
---

# Tasks: Multi-User Authentication

**Input**: Design documents from `specs/003-multi-user-auth/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/openapi.yaml](contracts/openapi.yaml),
[quickstart.md](quickstart.md)

**Tests**: Required by the project constitution, ownership boundary, and feature success criteria.
Within each story, add the listed tests first, run them, and confirm the new assertions fail before
implementing the behavior.

**Organization**: Tasks are grouped by prioritized user story after the shared session/ownership
foundation. Each story ends with an independently testable browser checkpoint.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it changes different files and has no unmet dependency.
- **[Story]**: Maps the task to a user story in `spec.md`.
- Every task names the exact repository paths it changes or validates.

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Install the standard SPA-session dependency and make local/browser test environments
capable of carrying real sessions.

- [x] T001 Add the compatible `laravel/sanctum:^4.0` dependency to `apps/api/composer.json` and `apps/api/composer.lock`, publish only `apps/api/config/sanctum.php`, and do not add token issuance or browser bearer storage (FR-017, Scope)
- [x] T002 Configure stateful API middleware and safe session defaults in `apps/api/bootstrap/app.php` and `apps/api/.env.example`, including exact local-origin examples and `SESSION_COOKIE=selfhandler_session` (FR-008, FR-017, FR-018)
- [x] T003 [P] Proxy `/sanctum` beside `/api` in `apps/web/vite.config.ts`, and change the isolated Playwright environment to database sessions plus its exact stateful origin in `apps/web/playwright.config.ts` and `apps/web/e2e/global-setup.ts` (FR-017, SC-007)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Remove the implicit user, protect every domain route, and centralize browser session
transport before any account journey is built.

**Critical**: No user-story implementation starts until this phase is complete.

- [x] T004 Add failing unauthenticated-request coverage for every current route/method in `apps/api/tests/Feature/Auth/AuthenticationSecurityTest.php`, asserting `401` after valid CSRF on unsafe requests and no protected data for either `401` or the expected pre-auth `419` when CSRF is absent (FR-011, FR-018)
- [x] T005 Put all existing domain routes behind `auth:sanctum` in `apps/api/routes/api.php`; replace `CurrentUser` with the middleware-established user in `RoutineController.php`, `RoutineLogController.php`, `GoalController.php`, `DailyReviewController.php`, and `TodayController.php` under `apps/api/app/Http/Controllers/`; remove `apps/api/app/Support/CurrentUser.php` (FR-011, FR-012, FR-018)
- [x] T006 [P] Create the safe `id`/`name`/`email` representation in `apps/api/app/Http/Resources/UserResource.php` and add shared auth request helpers in `apps/api/tests/Feature/Auth/AuthTestCase.php` (FR-003, FR-009, FR-020)
- [x] T007 Create `apps/web/src/api/http.ts` for same-origin credentials, CSRF initialization/header copying, one bounded `419` refresh/retry, typed JSON errors, and a domain `401` callback; refactor `apps/web/src/api/client.ts` to use it (FR-010, FR-011, FR-017)

**Checkpoint**: Anonymous domain requests are `401`, no fallback account exists, and the shared client
can safely send session/CSRF requests.

---

## Phase 3: User Story 1 - Create an Independent Account (Priority: P1) MVP

**Goal**: Let a visitor register one normalized, independent account and enter an empty personal
workspace without a second sign-in.

**Independent Test**: Register a new email in a signed-out browser, confirm the returned/current safe
identity, and verify no existing account's routines, goals, logs, reviews, or Today data appears.

### Tests for User Story 1

- [x] T008 [US1] Add failing valid/invalid registration, trimmed lowercase email, safe serialization, password hashing, duplicate email, database uniqueness-backstop, session rotation, current-user restoration, and already-authenticated non-mutation tests in `apps/api/tests/Feature/Auth/RegistrationTest.php` (FR-001-FR-004, FR-008-FR-009, FR-015, SC-001, SC-005)

### Implementation for User Story 1

- [x] T009 [US1] Normalize email centrally in `apps/api/app/Models/User.php` and implement trimmed name/email plus confirmed 12-character password validation in `apps/api/app/Http/Requests/Auth/RegisterRequest.php` (FR-001-FR-003)
- [x] T010 [US1] Implement registration, unique-conflict handling, session rotation, current-user restoration, already-authenticated `409` protection, and safe `201`/`200` responses in `apps/api/app/Http/Controllers/AuthController.php`; expose register/current-user with web session/CSRF middleware in `apps/api/routes/web.php` (FR-002, FR-004, FR-008-FR-009, FR-015, FR-017)
- [x] T011 [US1] Define `User`, registration payload, and validation-error types in `apps/web/src/api/types.ts`, add the register call in `apps/web/src/api/auth.ts`, and create the minimal `register`/`restoreSession` state machine in `apps/web/src/auth/session.ts` (FR-004, FR-009, FR-020)
- [x] T012 [US1] Add the guest `/register` route in `apps/web/src/router.ts` and implement the labelled, autocomplete-correct, pending-safe form in `apps/web/src/views/RegisterView.vue` without retaining password values (FR-001, FR-015, FR-016)
- [x] T013 [US1] Gate initial rendering in `apps/web/src/App.vue`, extract the authenticated shell from it into `apps/web/src/layouts/AppShell.vue`, and replace the hardcoded identity in the shell and `apps/web/src/views/TodayView.vue` with the registered user (FR-004, FR-015, FR-019)
- [x] T014 [US1] Add registration, normalized duplicate, empty-workspace, and reload coverage for desktop and mobile in `apps/web/e2e/auth-flow.spec.ts` with unique-user helpers in `apps/web/e2e/support/auth.ts` (SC-001, SC-002, SC-005, SC-007)

**Checkpoint**: User Story 1 independently replaces the implicit user and is the first usable account
slice.

---

## Phase 4: User Story 2 - Sign In and Sign Out Safely (Priority: P2)

**Goal**: Restore an existing account, preserve it across reloads, and invalidate the current session
through a phone- and desktop-accessible sign-out action.

**Independent Test**: Register, sign out, sign in, reload a protected deep link, and sign out again;
the old session must receive `401` for protected data.

### Tests for User Story 2

- [x] T015 [US2] Add failing successful/generic-failure login, normalized lookup, session rotation, already-authenticated non-switching, logout invalidation, safe response, and unauthenticated/expired current-user tests in `apps/api/tests/Feature/Auth/AuthenticationTest.php` (FR-005-FR-010, FR-015, SC-002, SC-003)

### Implementation for User Story 2

- [x] T016 [US2] Implement normalized login validation/authentication in `apps/api/app/Http/Requests/Auth/LoginRequest.php`, then add login/current-user/logout actions and exact `200`/`204`/`401`/`422` behavior to `apps/api/app/Http/Controllers/AuthController.php` and `apps/api/routes/web.php` (FR-005-FR-010)
- [x] T017 [US2] Add login/current-user/logout calls to `apps/web/src/api/auth.ts` and complete `checking`/`authenticated`/`guest`/`unavailable`, login, logout, expiry, and memoized restoration behavior in `apps/web/src/auth/session.ts` (FR-005, FR-009-FR-010, FR-019)
- [x] T018 [US2] Add protected/guest route metadata, validated redirect restoration, and session-aware guards in `apps/web/src/router.ts`; add `/login` and `/account` through `apps/web/src/views/LoginView.vue` and `apps/web/src/views/AccountView.vue` (FR-005, FR-015)
- [x] T019 [US2] Show the real account and a reachable Account/logout link in desktop and mobile navigation in `apps/web/src/layouts/AppShell.vue` and `apps/web/src/style.css`, clearing protected screen state before guest rendering (FR-010, FR-015, FR-019)
- [x] T020 [US2] Extend `apps/web/e2e/auth-flow.spec.ts` with protected deep-link redirects, generic wrong-password feedback, login, reload restoration, desktop/mobile logout, and rejected old-session requests (SC-002, SC-003, SC-007)
- [x] T021 [US2] Register a disposable user before the existing daily-loop journey and update expected auth bootstrap responses in `apps/web/e2e/mvp-flow.spec.ts` (FR-011, SC-007)

**Checkpoint**: Registration, returning login, restoration, and current-session logout all work
independently on desktop and phone layouts.

---

## Phase 5: User Story 3 - Keep Every Account's Data Private (Priority: P3)

**Goal**: Prove and preserve strict ownership for all current resources, relationships, writes, and
derived views while two accounts use one installation.

**Independent Test**: Prepare disjoint account-A/account-B data and execute the complete endpoint
matrix as each user, including foreign identifiers and client-supplied `user_id`; observe zero foreign
data and zero foreign mutations.

### Tests for User Story 3

- [x] T022 [US3] Add a failing two-account read matrix for routines, goals, reviews, logs, relationship eager loads, and Today aggregates in `apps/api/tests/Feature/Auth/OwnershipBoundaryTest.php` (FR-012-FR-013, SC-004)
- [x] T023 [US3] Extend `apps/api/tests/Feature/Auth/OwnershipBoundaryTest.php` with foreign update/deactivate/log/link/unlink attempts, mixed-owner relationship attempts, same-date/title account-scoped writes, and malicious `user_id` payloads (FR-013-FR-014, FR-019, SC-004)

### Implementation for User Story 3

- [x] T024 [US3] Correct every failing owner filter, foreign-route `404`, nested-owner check, and server-derived write owner in `apps/api/app/Http/Controllers/RoutineController.php`, `RoutineLogController.php`, `GoalController.php`, `DailyReviewController.php`, and `TodayController.php` (FR-012-FR-014)
- [x] T025 [US3] Audit owner-safe relationships/casts and guarded ownership fields in all models under `apps/api/app/Models/`, preserving existing account-scoped unique constraints without adding a request-dependent global scope (FR-012-FR-014, FR-019)
- [x] T026 [US3] Add two simultaneous independent browser contexts, account switching in one context, empty-B workspace, A-data restoration, and foreign API mutation checks to `apps/web/e2e/auth-flow.spec.ts`; ensure `apps/web/src/auth/session.ts` clears account-bound client state on logout/expiry (FR-019, SC-004)

**Checkpoint**: The full backend and browser two-account matrix produces no cross-account disclosure,
existence signal, relationship, or mutation.

---

## Phase 6: User Story 4 - Understand and Recover from Authentication Errors (Priority: P4)

**Goal**: Make validation, credential, rate-limit, CSRF, expiry, and service failures explicit and
retryable without retaining secrets or claiming a false session state.

**Independent Test**: Exercise invalid fields, wrong credentials, repeated attempts, missing CSRF,
expired session, and unavailable API; each yields the specified safe UI state and later recovery.

### Tests for User Story 4

- [x] T027 [US4] Add failing generic enumeration-resistant error, login failure-window/clear-on-success, registration IP limit, and non-secret response/log assertions in `apps/api/tests/Feature/Auth/AuthenticationSecurityTest.php` (FR-006-FR-007, FR-016-FR-017, SC-006)

### Implementation for User Story 4

- [x] T028 [US4] Define registration and login rate-limit behavior in `apps/api/app/Providers/AppServiceProvider.php` and `apps/api/app/Http/Requests/Auth/LoginRequest.php`, including generic messages, retry timing, and successful-login clearing (FR-006-FR-007)
- [x] T029 [US4] Map field `422`, generic credentials, `429`, `419`, expired `401`, and network/5xx failures to accessible pending/error/retry states in `apps/web/src/api/http.ts`, `apps/web/src/auth/session.ts`, `apps/web/src/views/LoginView.vue`, and `apps/web/src/views/RegisterView.vue`; clear password inputs after every failure (FR-006-FR-007, FR-016-FR-017)
- [x] T030 [US4] Add expected-CSRF-rejection, field errors, duplicate email, rate-limit, password clearing, and unavailable-bootstrap/retry browser checks in `apps/web/e2e/auth-flow.spec.ts` (SC-006, SC-007)

**Checkpoint**: Every specified auth failure is clear and recoverable, while passwords, session IDs,
and account-existence details stay undisclosed.

---

## Phase 7: Polish and Cross-Cutting Validation

**Purpose**: Align long-term design, contract, accessibility, and all quality gates without expanding
account-management scope.

- [x] T031 Update the implemented authentication decision and remove the multi-user deferral from `docs/MVP_TECHNICAL_DESIGN.md` and `docs/design/decisions.md`, verify the supersession notes in `specs/001-core-daily-loop/plan.md`, `research.md`, and `tasks.md`, link feature `003-multi-user-auth`, and preserve the excluded future account features (Constitution II)
- [x] T032 [P] Reconcile the implemented status codes, safe user shape, cookie name, CSRF path/header, and protected route list with `specs/003-multi-user-auth/contracts/openapi.yaml`, `apps/web/src/api/types.ts`, and backend tests (FR-020)
- [x] T033 Review keyboard, focus, label, `aria-live`, pending, narrow-screen overflow, protected-content flash, and account-switch behavior across `apps/web/src/views/LoginView.vue`, `RegisterView.vue`, `AccountView.vue`, `apps/web/src/layouts/AppShell.vue`, and `apps/web/src/style.css` (FR-015-FR-016)
- [x] T034 Run the full backend suite, frontend typecheck/build, and desktop/mobile Playwright suites from `specs/003-multi-user-auth/quickstart.md`; resolve every failure and verify no normal development database is touched (SC-007)
- [x] T035 Re-run the `specs/003-multi-user-auth/checklists/requirements.md` gate, record any accepted implementation deviations in `specs/003-multi-user-auth/plan.md`, and confirm `specs/002-homelab-deployment/spec.md` can now treat authentication as a satisfied live-rollout prerequisite

---

## Dependencies and Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: Starts immediately on the current user-managed Git branch.
- **Foundational (Phase 2)**: Depends on Setup and blocks every user story.
- **US1 (Phase 3)**: Depends on Foundational and is the minimum usable account slice.
- **US2 (Phase 4)**: Builds on US1's account/session resource so a registered user can return and exit.
- **US3 (Phase 5)**: Depends on authenticated sessions from US1/US2 and validates all current domain
  operations; its backend matrix can be authored while US2 frontend work proceeds.
- **US4 (Phase 6)**: Depends on the complete auth endpoints and shared transport so every failure path
  can be exercised.
- **Polish (Phase 7)**: Depends on all four stories.

### Within Each User Story

1. Write and run the listed backend or browser assertions first; confirm missing behavior fails.
2. Implement backend validation/session/ownership behavior and re-run its focused tests.
3. Update typed transport and session state at the same contract boundary.
4. Implement the responsive UI journey.
5. Run the story's desktop and mobile browser checkpoint before the next priority.

### Parallel Opportunities

- T003 may run in parallel with T001-T002 because it touches only the Vue/browser-test environment.
- T006 may run in parallel with T004-T005 after the safe user shape is agreed.
- Within US1, backend T008-T010 and frontend shell preparation can be coordinated, but T011-T014
  consume the finalized registration contract.
- T021 is isolated from T015-T020 except for the shared registration helper.
- T022 and T023 are authored in the same file sequentially; frontend research for T026 can proceed
  while T024-T025 fix backend findings.
- T032 can run in parallel with the design-doc update T031 because they touch distinct files.

## Parallel Example: Backend Ownership and Frontend Session UX

```text
Task: T022-T023 [US3] Define the complete two-account API ownership matrix.
Task: T019-T020 [US2] Finish responsive account/logout UX and its browser flow.

After the ownership tests fail as expected:
Task: T024-T025 [US3] Correct explicit controller/model ownership behavior.
```

## Implementation Strategy

### MVP First: User Story 1

1. Complete Setup and Foundational phases.
2. Complete T008-T014 for registration and an authenticated empty workspace.
3. Run focused registration backend and desktop/mobile browser checks.
4. Continue immediately to US2 for a production-usable returning session; do not deploy the
   registration-only checkpoint as the final authentication feature.

### Incremental Delivery

1. Foundation -> stateful CSRF transport and zero implicit users.
2. US1 -> independent account creation.
3. US2 -> login, restoration, and logout.
4. US3 -> proved multi-account privacy across every current domain operation.
5. US4 -> safe, accessible failure recovery.
6. Polish -> source-of-truth alignment and all quality gates.

## Notes

- Existing prototype scoping is evidence, not proof; T022-T025 must exercise every current endpoint.
- `[P]` never authorizes simultaneous uncoordinated edits to the same file.
- Do not create/switch Git branches, install the Spec Kit Git extension, commit, push, or deploy while
  executing this list unless the user separately authorizes those operations.
- Do not add password recovery, verification email, roles, invitations, profile editing, social login,
  passkeys, two-factor authentication, personal access token issuance, or public-internet exposure.
