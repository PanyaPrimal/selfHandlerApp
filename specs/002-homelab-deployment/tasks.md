# Tasks: Homelab Deployment

**Input**: Design documents from `specs/002-homelab-deployment/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/`, `quickstart.md`

**Tests**: Deployment, rollback, recovery, privacy, and live-auth verification are explicit feature
requirements, so contract and integration tests are mandatory and precede their implementations.

**Organization**: Tasks are grouped by user story so each operational journey has an independent
verification boundary. Requirement IDs in task text make coverage auditable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it touches different files and has no incomplete dependency
- **[Story]**: Maps work to one specification user story
- Every task names its concrete repository path

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Establish deployment directories, deterministic inputs, and hosted-only public CI.

- [X] T001 Create the deployment directory skeleton plus separate checked-in application/ops configuration templates in `deployment/env.production.example`, `deployment/ops-config.example`, and `deployment/README.md` (FR-001, FR-017)
- [X] T002 [P] Add Docker build-context exclusions for source secrets, dependencies, test output, and VCS data in `.dockerignore` (FR-017)
- [X] T003 [P] Add root developer scripts for deployment validation and the input-free trusted-workflow launcher in `package.json` and `deploy.ps1` (FR-002)
- [X] T004 [P] Add hosted-only backend/frontend/E2E workflow gates with minimal permissions and SHA-pinned actions in `.github/workflows/ci.yml` (FR-004, FR-018)
- [X] T005 Add JSON Schema validation dependencies only to the deployment test harness requirements/documentation in `deployment/tests/README.md` (FR-003, FR-007)

---

## Phase 2: Foundational Runtime and Trust Boundary

**Purpose**: Build the fixed, isolated runtime and non-secret readiness contract required by every
user story.

**⚠️ CRITICAL**: No production operation starts until this phase passes.

- [X] T006 [P] Write failing fixed-target, Compose-isolation, forbidden-command, manifest-schema, and public-runner trust tests in `deployment/tests/test_contracts.py` (FR-001, FR-003, FR-015, FR-018, FR-025)
- [X] T007 [P] Write failing Laravel readiness response/database-failure tests in `apps/api/tests/Feature/HealthTest.php` from `contracts/health.openapi.yaml` (FR-012, FR-017)
- [X] T008 Implement the database-backed non-secret readiness endpoint and release configuration in `apps/api/app/Http/Controllers/HealthController.php`, `apps/api/routes/api.php`, and `apps/api/config/app.php` (FR-012, FR-017)
- [X] T009 [P] Create the multi-target pinned PHP/Composer and Node/Nginx build in `deployment/docker/Dockerfile` (FR-003, FR-016)
- [X] T010 [P] Configure unprivileged Nginx SPA serving plus the strict FastCGI proxy allowlist in `deployment/docker/nginx.conf` (FR-015, FR-016)
- [X] T011 [P] Configure non-root PHP-FPM and runtime-only Laravel writable-path initialization/optimization in `deployment/docker/php-fpm.conf` and `deployment/docker/app-entrypoint.sh` (FR-016, FR-017)
- [X] T012 Create the fixed production `web`/`app`/`db` topology, networks, volumes, healthchecks, resource/log bounds, read-only filesystems, and loopback-only binding in `deployment/compose.production.yaml` (FR-010, FR-012, FR-015, FR-016)
- [X] T013 Create the generated-name disposable override with no production resource references in `deployment/compose.validation.yaml` (FR-025, SC-010)
- [X] T014 Implement shared fixed constants, secret-safe logging, the exclusive `C:\Homelab\.locks\selfhandler-production.lock`, Compose-label lookup, capacity checks, schema fingerprinting, and atomic JSON state writes in `deployment/scripts/shared.ps1` (FR-001, FR-005, FR-017, FR-019, FR-021)

**Checkpoint**: Runtime images and readiness can be validated without creating any production resource.

---

## Phase 3: User Story 1 - Release SelfHandler Safely (Priority: P1) 🎯 MVP

**Goal**: Qualify and install one exact paired release on the fixed target without changing existing
records/files or allowing public-repository code to run in an untrusted context.

**Independent Test**: A disposable production-shaped deployment passes all gates, preserves controlled
data during paired replacement, exposes only its generated web port, and reports the exact revision.

### Tests for User Story 1

- [X] T015 [P] [US1] Write a failing production-shaped build/start/migrate/readiness/auth/persistence/isolation smoke in `deployment/tests/production_smoke.py` (FR-004, FR-010, FR-012, FR-015, FR-016, FR-025; SC-004, SC-006, SC-010)
- [X] T016 [P] [US1] Write failing release-manifest generation, canonical workflow metadata, OCI revision, provenance-attestation, and immutable-identity tests in `deployment/tests/test_release.py` (FR-003, FR-004, FR-021)
- [X] T017 [P] [US1] Write failing preflight no-mutation tests for dependency, health, capacity, duplicate release, and unsynchronized revision failures in `deployment/tests/test_deploy.py` (FR-004, FR-005; SC-001, SC-002)

### Implementation for User Story 1

- [X] T018 [P] [US1] Implement deterministic release/deployment-bundle manifest generation plus canonical run metadata, OCI revision, attestation, and all operational JSON Schema validation in `deployment/release_manifest.py` (FR-003, FR-004, FR-021)
- [X] T019 [P] [US1] Add trusted private hosted immutable paired-image build, production-shaped smoke, digest capture, GitHub build-provenance attestations, and artifact publication in `deployment/private-ops/.github/workflows/deploy-selfhandler.yml`; keep public `.github/workflows/ci.yml` read-only and credential-free (FR-003, FR-004, FR-018)
- [X] T020 [US1] Implement fixed-target preflight, exact digest pull, current-release verification, and bootstrap/routine mode detection in `deployment/scripts/deploy-production.ps1` (FR-001, FR-003, FR-005)
- [X] T021 [US1] Implement one-shot candidate migration with migration-state evidence and explicit prohibition of seeding/entrypoint migrations in `deployment/scripts/deploy-production.ps1` (FR-011)
- [X] T022 [US1] Implement paired web/app replacement preserving named volumes and verifying actual digests in `deployment/scripts/deploy-production.ps1` (FR-010, FR-012)
- [X] T023 [US1] Implement local readiness, private-route readiness, runtime-isolation, and real session-auth smoke gates in `deployment/scripts/deploy-production.ps1` and `deployment/scripts/auth-smoke.ps1` (FR-012, FR-015, FR-016; SC-004, SC-006)
- [X] T024 [US1] Implement protected prepared/pending journals keyed by immutable release identity, append-only release attempts, cross-run resume, and trusted atomic active-release/finalization in `deployment/private-ops/.github/workflows/deploy-selfhandler.yml`, `deployment/scripts/shared.ps1`, `deployment/scripts/deploy-production.ps1`, and `deployment/scripts/finalize-release.ps1` (FR-021)
- [X] T025 [P] [US1] Add a private-operations repository template whose hosted job checks out only canonical `master` and whose homelab job performs no public checkout and executes only its checksum-verified qualified bundle in `deployment/private-ops/.github/workflows/deploy-selfhandler.yml` (FR-002, FR-004, FR-018, FR-019)
- [X] T026 [P] [US1] Add a checksum-pinned one-time private runner/bootstrap guide and workflow contract in `deployment/private-ops/README.md` (FR-018)
- [X] T027 [US1] Make `deploy.ps1` dispatch and follow the fixed private workflow without accepting target/ref inputs or printing credentials in `deploy.ps1` (FR-001, FR-002, FR-017)
- [X] T028 [US1] Run the User Story 1 disposable smoke and record expected commands/results in `specs/002-homelab-deployment/quickstart.md` (SC-001, SC-002, SC-004, SC-006, SC-010)

**Checkpoint**: One reviewed release can be installed and verified on a disposable fixed-shape target.

---

## Phase 4: User Story 2 - Recover from a Failed Release (Priority: P2)

**Goal**: Return an unhealthy replacement to the complete previous image pair without deleting or
rewinding persistent data.

**Independent Test**: Failure injection makes the candidate fail readiness and the previous pair
becomes healthy within the rollback bound against the forward schema and unchanged stores.

### Tests for User Story 2

- [X] T029 [P] [US2] Extend failure injection to assert paired rollback, forward-schema compatibility, data preservation, and bounded recovery in `deployment/tests/production_smoke.py` (FR-011, FR-013; SC-005, SC-006)
- [X] T030 [P] [US2] Write failing rollback-history and unrecoverable-rollback evidence tests in `deployment/tests/test_deploy.py` (FR-013, FR-014, FR-021)

### Implementation for User Story 2

- [X] T031 [US2] Add previous paired-digest capture and automatic paired application rollback without `migrate:rollback` in `deployment/scripts/deploy-production.ps1` (FR-011, FR-013)
- [X] T032 [US2] Add rollback health verification, terminal `rolled_back`/`recovery_required` records, and preserved bundle references in `deployment/scripts/deploy-production.ps1` (FR-013, FR-014, FR-021)
- [X] T033 [P] [US2] Add trusted hosted compatibility evidence that a locally preloaded previous app image boots against the candidate forward schema in `deployment/private-ops/.github/workflows/deploy-selfhandler.yml` and `deployment/tests/production_smoke.py` (FR-011)
- [X] T034 [US2] Run the intentionally unhealthy disposable replacement and document measured rollback evidence in `specs/002-homelab-deployment/quickstart.md` (SC-005, SC-006, SC-010)

**Checkpoint**: Application replacement failure has a tested automatic and observable recovery path.

---

## Phase 5: User Story 3 - Back Up and Restore Personal Data (Priority: P3)

**Goal**: Produce daily and pre-mutation encrypted off-host database/private-file bundles and prove
that a controlled bundle restores into a clean disposable target.

**Independent Test**: Tamper/cleanup tests pass and a disposable restore verifies every controlled
record/file without touching either production stack.

### Tests for User Story 3

- [X] T035 [P] [US3] Write failing exact four-member bundle, constant-time manifest HMAC sidecar, payload allowlist, checksum, size, age, wrong-target, baseline-null-release, and unsafe-archive tests in `deployment/tests/test_recovery.py` (FR-007, FR-008, FR-023, FR-024)
- [X] T036 [P] [US3] Write failing plaintext-cleanup and predeploy-upload-order contract tests in `deployment/tests/test_backup.py` (FR-006, FR-008, FR-009; SC-003)
- [X] T037 [P] [US3] Write a failing database/private-file disposable round-trip drill in `deployment/tests/recovery_smoke.py` (FR-023, FR-024, FR-025; SC-007, SC-010)

### Implementation for User Story 3

- [X] T038 [P] [US3] Implement safe recovery manifest creation/authentication/validation and archive-member validation in `deployment/recovery.py` (FR-007, FR-023)
- [X] T039 [US3] Implement consistent MySQL dump, stable current private-volume archive, exact manifest/HMAC sidecar validation with ACL-protected host key, age encryption success/ciphertext checksum validation, and guaranteed plaintext cleanup in `deployment/scripts/backup-production.ps1`; reserve decrypt validation for the off-host identity-backed drill (FR-006, FR-007, FR-008)
- [X] T040 [US3] Gate every migration/replacement on completed off-host encrypted artifact upload and gate bootstrap terminal success on an uploaded, immutably bound post-auth recovery point in `deployment/private-ops/.github/workflows/deploy-selfhandler.yml` (FR-006, FR-009; SC-003)
- [X] T041 [P] [US3] Add serialized daily encrypted backup and 24-hour freshness artifact retention workflow in `deployment/private-ops/.github/workflows/backup-selfhandler.yml` (FR-019, FR-020; SC-008)
- [X] T042 [US3] Implement target-confirmed drill/production restore, pre-restore safety backup, safe extraction, clean-volume restoration, and probes in `deployment/scripts/restore-production.ps1` (FR-023, FR-024)
- [X] T043 [US3] Implement generated disposable restore-drill orchestration and production-resource denylist in `deployment/scripts/recovery-drill.ps1` (FR-024, FR-025)
- [X] T044 [US3] Run the encrypted disposable round trip and document controlled record/file and duration evidence in `specs/002-homelab-deployment/quickstart.md` (SC-007, SC-010)

**Checkpoint**: A validated backup exists outside the host before mutation and recoverability is proven.

---

## Phase 6: User Story 4 - Inspect Production State (Priority: P4)

**Goal**: Report exact release, local/private health, stores, backup freshness, isolation, and alerts
without revealing protected values.

**Independent Test**: Healthy, private-route-failed, backup-overdue, and secret-canary scenarios yield
the expected separate statuses with no canary in output.

### Tests for User Story 4

- [X] T045 [P] [US4] Write failing healthy/degraded/overdue/secret-canary inspection tests in `deployment/tests/test_inspect.py` (FR-017, FR-020, FR-022; SC-008, SC-009)
- [X] T046 [P] [US4] Write failing additive Tailscale Serve configuration tests that preserve DealFlow HTTPS 443 and change only SelfHandler 8443 in `deployment/tests/test_tailscale.py` (FR-015, FR-025)

### Implementation for User Story 4

- [X] T047 [US4] Implement secret-safe structured inspection validated by `contracts/health-report.schema.json` for actual digests, local/private readiness, database, stores, isolation, capacity, history, and backup age in `deployment/scripts/inspect-production.ps1` (FR-020, FR-022)
- [X] T048 [US4] Implement additive Tailscale Serve 8443 configuration, before/after snapshot comparison, scoped rollback, and DealFlow 443 verification in `deployment/scripts/configure-private-route.ps1` (FR-012, FR-015, FR-025)
- [X] T049 [P] [US4] Add the trusted private manual inspection workflow with artifact output and no public checkout in `deployment/private-ops/.github/workflows/inspect-selfhandler.yml` (FR-018, FR-022)
- [X] T050 [US4] Exercise separate local/private-route failure and overdue-backup cases and record expected alert codes in `specs/002-homelab-deployment/quickstart.md` (SC-008, SC-009)

**Checkpoint**: Operators can distinguish application, route, backup, capacity, and isolation failures safely.

---

## Phase 7: Polish, Live Bootstrap, and Cross-Cutting Gates

**Purpose**: Validate the whole delivery contract, install the trusted executor, and perform the first
fixed production rollout.

- [X] T051 [P] Pin every third-party GitHub Action and container base/database image to reviewed immutable commits/digests in `.github/workflows/*.yml`, `deployment/private-ops/.github/workflows/*.yml`, and `deployment/docker/Dockerfile` (FR-003, FR-018)
- [X] T052 [P] Document fixed target, secret names, runner boundary, backups, recovery, Tailscale coexistence, and break-glass behavior in `deployment/README.md` and `README.md` (FR-001, FR-014, FR-017, FR-018)
- [X] T053 Run `composer validate --strict`, `php artisan test`, `npm run typecheck`, `npm run build`, and `npm run test:e2e` and record no unresolved regression in `specs/002-homelab-deployment/tasks.md` (FR-004)
- [X] T054 Run all deployment unit/contract tests, Compose config checks, image builds, production smoke, rollback injection, and encrypted recovery smoke in `deployment/tests/` and mark their tasks complete (FR-004, FR-025)
- [X] T055 Execute a read-only Spec Kit cross-artifact coverage/constitution audit for `specs/002-homelab-deployment/spec.md`, `plan.md`, and `tasks.md`, resolving any critical/high gap before live rollout (FR-004)
- [ ] T056 Create the private `PanyaPrimal/selfhandler-ops` repository from `deployment/private-ops/`, configure least-privilege settings, and register a separate `homelab,selfhandler` runner under a dedicated non-administrator SelfHandler OS identity without attaching the public repository, as documented in `deployment/private-ops/README.md` (FR-018)
- [ ] T057 Create the protected fixed-target application `.env`, separate ops config/ACL secret files at `C:\Homelab\SelfHandlerApp\ops`, verify empty new production volumes, acquire the SelfHandler host lock, and preserve the existing DealFlow state according to `deployment/README.md` (FR-001, FR-017, FR-019, FR-025)
- [ ] T058 Run the input-free trusted deployment, encrypted empty pre-migration baseline, additive private Serve route, explicit probe-account registration/login plus session-cookie/CSRF/owned-data/logout smoke, post-auth encrypted recovery point, and DealFlow regression check according to `specs/002-homelab-deployment/quickstart.md` (FR-002, FR-006, FR-009, FR-012, FR-015; SC-001, SC-003, SC-004, SC-006)
- [ ] T059 Run production inspection, confirm exact paired digests and backup freshness, update feature status/operational evidence in `specs/002-homelab-deployment/tasks.md`, and update durable project status in `C:\Code\memory\projects\selfhandler\overview.md` (FR-020, FR-021, FR-022; SC-008, SC-009)

### Automated validation evidence (2026-08-10)

- Composer validation/audit and Pint passed; Laravel passed 32 tests with 289 assertions.
- npm audit reported zero vulnerabilities; Vue typecheck/build passed; Playwright passed 10/10
  desktop/mobile authentication and MVP scenarios.
- Deployment contracts passed 92/92; both production and disposable Compose configurations rendered;
  actionlint passed all four workflow files; Windows PowerShell 5.1 parsed 10 scripts and 24 embedded
  workflow blocks.
- The production-shaped Docker run completed in 105.6 seconds and restored the previous paired release
  after an unhealthy candidate in 8.0 seconds while preserving session, database, and private-file
  probes. It changed zero production resources and left zero disposable resources.
- The encrypted disposable recovery round trip completed in 27.6 seconds, restored one of one
  controlled database records and one of one private files, and touched zero production projects.
- The final Spec Kit analysis mapped all 25 functional requirements and 10 success criteria to tasks
  (100% coverage), with zero critical/high findings. Independent security review returned GO with no
  live-deploy blocker; the documented one-time host/private-repository prerequisites remain T056-T059.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)** starts immediately.
- **Foundational (Phase 2)** depends on Setup and blocks all runtime stories.
- **US1 (Phase 3)** depends on Foundational and establishes the deploy/release path.
- **US2 (Phase 4)** depends on US1's paired release state and deployment flow.
- **US3 (Phase 5)** depends on the fixed target/shared lock and blocks live US1 mutation because a
  pre-deployment recovery bundle is mandatory.
- **US4 (Phase 6)** depends on release/backup state contracts but its tests and report implementation
  can proceed in parallel with most US2/US3 implementation.
- **Polish/live bootstrap (Phase 7)** depends on all four stories and every automated gate.

### User Story Dependencies

- **US1**: independently proves reviewed image creation and safe disposable replacement.
- **US2**: uses US1's previous release pair but is independently verified through failure injection.
- **US3**: uses the fixed volumes and release identity; its round-trip drill is independently testable.
- **US4**: reads US1/US3 evidence without mutating it and is independently testable with fixtures.

### Within Each User Story

- Write the listed failing tests before the corresponding implementation.
- Manifest/runtime primitives precede orchestration.
- Backup upload completes before migration or replacement.
- Migration completes once before replacement; rollback swaps images only.
- Live bootstrap is forbidden until every disposable test and Spec Kit audit passes.

### Parallel Opportunities

- T002–T004 can run in parallel after T001.
- T006, T007, and T009–T011 touch independent files and can run in parallel.
- US1 manifest/workflow work can run alongside Docker smoke implementation after Foundational.
- US3 recovery library/tests and US4 inspection/Tailscale tests can run alongside US2 rollback work.
- Documentation and immutable-pin review can run in parallel after implementations stabilize.

---

## Parallel Examples

### User Story 1

```text
Task T015: production-shaped runtime smoke
Task T016: release manifest tests
Task T017: preflight no-mutation tests
Task T025: trusted private workflow template
```

### User Stories 2–4 after US1

```text
Track A: T029–T034 paired rollback
Track B: T035–T044 encrypted backup and restore
Track C: T045–T050 inspection and private ingress
```

---

## Implementation Strategy

### MVP First

1. Complete Setup and Foundational runtime.
2. Complete US1 against disposable resources.
3. Complete US3 predeploy backup before authorizing any live mutation.
4. Complete US2 rollback and US4 inspection.
5. Run all automated gates and only then bootstrap production.

### Incremental Validation

1. Static contracts prove fixed names and trust boundaries.
2. Production-shaped smoke proves container behavior and persistence.
3. Failure injection proves paired rollback.
4. Encrypted round trip proves recovery.
5. Inspection fixtures prove observable failure separation.
6. Live rollout proves private HTTPS authentication and DealFlow coexistence.

## Notes

- `[P]` means different files and no dependency on an incomplete task.
- Never run validation cleanup with project `selfhandler` or `dealflow`.
- Never place a self-hosted runner in `PanyaPrimal/selfHandlerApp` while it is public.
- Never log credentials, `.env` content, recovery authentication keys, age identities, or auth-smoke
  passwords.
- Never use `docker compose down -v`, `migrate:fresh`, `migrate:rollback`, or `db:seed` against the
  production target.
