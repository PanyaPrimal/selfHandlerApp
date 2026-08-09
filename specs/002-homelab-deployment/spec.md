# Feature Specification: Homelab Deployment

**Feature ID**: `002-homelab-deployment`

**Created**: 2026-08-09

**Status**: Draft

**Input**: Establish a safe, repeatable deployment and recovery flow for the SelfHandler application
on the owner's homelab, following the proven fixed-target operating model used by the DealFlow pet
project while accounting for SelfHandler's public source repository and personal-data sensitivity.

## Clarifications

### Session 2026-08-09

- Q: How will the production application identify its current user? → A: Deliver a separate
  multi-user email-and-password authentication and registration feature before live homelab rollout.
- Q: Is that application prerequisite now satisfied? → A: Feature `003-multi-user-auth` is
  implemented and passes backend plus desktop/mobile browser acceptance. Live rollout must still
  verify the fixed private HTTPS origin, `selfhandler_session` as a Secure and HttpOnly cookie, the
  matching stateful-domain configuration, and that production seeding is not invoked.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Release SelfHandler Safely (Priority: P1)

As the owner-operator, I deploy one reviewed SelfHandler release to one fixed homelab target through a
single command so I can update the application without choosing infrastructure parameters or risking
the existing production data.

**Why this priority**: A repeatable release path is the minimum useful outcome. Backup, recovery, and
operations depend on having one deterministic production target and release procedure.

**Independent Test**: Start a release for an exact reviewed revision, verify that all pre-release
checks pass, and confirm that the private production URL serves that revision while existing records
remain unchanged.

**Acceptance Scenarios**:

1. **Given** the reviewed default branch is synchronized and production is healthy, **When** the
   operator starts deployment, **Then** the exact reviewed release is installed on the fixed target
   without asking for a host, port, environment, or deployment mode.
2. **Given** any mandatory quality check fails, **When** a release is attempted, **Then** production
   is not changed and the operator can identify the failed gate.
3. **Given** production contains user records and private files, **When** a release succeeds, **Then**
   those records and files remain available and unchanged except for approved schema evolution.
4. **Given** two release attempts overlap, **When** the second attempt starts, **Then** it waits or
   fails safely without interleaving production mutations.

---

### User Story 2 - Recover from a Failed Release (Priority: P2)

As the owner-operator, I want a failed application replacement to return automatically to the last
known healthy release so a bad build does not leave SelfHandler unavailable.

**Why this priority**: The first production release path is unsafe to use repeatedly unless a failed
replacement has a bounded, verified recovery path.

**Independent Test**: Deploy an intentionally unhealthy release to a disposable production-equivalent
stack and verify that the previous application release becomes healthy again without changing the
stored user data.

**Acceptance Scenarios**:

1. **Given** a healthy release is running, **When** its replacement fails startup or health checks,
   **Then** the previous application release is restored automatically.
2. **Given** automatic application rollback succeeds, **When** the operator inspects the result,
   **Then** the failure, attempted release, restored release, and recovery outcome are visible.
3. **Given** automatic rollback cannot restore health, **When** recovery stops, **Then** production is
   not reported as successful and the operator is directed to the preserved recovery artifact.

---

### User Story 3 - Back Up and Restore Personal Data (Priority: P3)

As the owner-operator, I create encrypted, off-host recovery copies of SelfHandler's database and
private files so a disk, host, or schema failure does not permanently destroy personal history.

**Why this priority**: SelfHandler will contain personal health, routine, attachment, and eventually
financial information that cannot be reconstructed reliably after loss.

**Independent Test**: Create a backup from a production-equivalent stack, destroy a disposable copy of
its persistent state, restore it from the encrypted bundle, and verify the known records and files.

**Acceptance Scenarios**:

1. **Given** production is healthy, **When** a release begins, **Then** an encrypted database-and-files
   recovery bundle is stored outside the homelab failure domain before production changes.
2. **Given** no release occurs, **When** the daily backup schedule runs, **Then** a fresh encrypted
   off-host bundle is created without interrupting normal use.
3. **Given** an authenticated recovery bundle and its operator-held decryption identity, **When** a
   restore drill targets an explicitly disposable clean stack, **Then** database records, private
   files, and schema state are recovered and verified.
4. **Given** backup creation, encryption, integrity validation, or off-host transfer fails, **When** a
   deployment is waiting, **Then** production remains unchanged.

---

### User Story 4 - Inspect Production State (Priority: P4)

As the owner-operator, I inspect health, release history, backup freshness, and externally reachable
status without exposing secrets so I can identify operational problems before data or availability is
lost.

**Why this priority**: Observable state reduces recovery time and prevents an apparently successful
workflow from hiding a broken private route or stale backup.

**Independent Test**: Inspect a running production-equivalent stack and confirm that the report names
the active release, health state, persistent resources, latest backup time, and any actionable alert
without printing secret values.

**Acceptance Scenarios**:

1. **Given** production is healthy, **When** the operator inspects it, **Then** local and private-route
   health, active release identity, and backup freshness are reported.
2. **Given** the local application is healthy but the private route is unavailable, **When** inspection
   runs, **Then** the route failure is reported separately from application health.
3. **Given** protected configuration exists, **When** inspection or deployment logs are produced,
   **Then** secret names may be reported but secret values are not exposed.

### Edge Cases

- The production target has insufficient space for a new release, rollback artifacts, and backup
  staging at the same time.
- The container runtime, private network agent, off-host destination, or release registry is
  unavailable.
- The release identifier or immutable artifact already exists and would otherwise be overwritten.
- A schema change succeeds but the replacement application fails, making application-only rollback
  unsafe without a compatibility guarantee.
- The host loses power during backup creation, schema migration, service replacement, or release
  history persistence.
- The database is healthy but private-file backup or restore integrity checks fail.
- A recovery bundle is truncated, altered, too old, belongs to another deployment, or cannot be
  decrypted by the operator-held identity.
- The private remote-access route conflicts with an existing DealFlow route on the same homelab.
- The first production deployment has no previous release available for automatic rollback.
- A request to delete persistent resources is issued against the real production target.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The release flow MUST target exactly one owner-approved production installation and MUST
  NOT accept an arbitrary host, deployment identifier, port, or operating mode from the operator.
- **FR-002**: The operator MUST be able to start the complete release flow through one documented
  command or one input-free manual action.
- **FR-003**: A release MUST identify one exact reviewed source revision and immutable application
  artifacts; an existing release identifier MUST NOT be overwritten.
- **FR-004**: Mandatory backend, frontend, end-to-end, deployment, and production-equivalent smoke
  checks MUST pass before any production mutation.
- **FR-005**: A failed mandatory check, unavailable dependency, unhealthy existing production state,
  or insufficient capacity MUST stop the release before production mutation.
- **FR-006**: Every production deployment MUST create and validate a complete pre-deployment recovery
  bundle before replacing services or applying schema changes.
- **FR-007**: Recovery bundles MUST include all authoritative database content, private files, source
  release identity, schema state, creation time, deployment identity, integrity metadata, and artifact
  sizes.
- **FR-008**: Recovery bundles MUST be encrypted for an operator-controlled identity before leaving
  protected staging, and plaintext recovery data MUST be removed after success or failure.
- **FR-009**: At least one validated recovery bundle for each deployment MUST be stored outside the
  homelab failure domain before production mutation begins.
- **FR-010**: Successful deployment MUST preserve production database and private-file storage across
  application replacements.
- **FR-011**: Approved schema changes MUST run exactly once per release and MUST be compatible with
  application rollback, or the release MUST stop before the incompatible mutation.
- **FR-012**: The release flow MUST verify application startup, database connectivity, local health,
  and private-route health before declaring success.
- **FR-013**: If replacement startup or verification fails, the flow MUST attempt automatic rollback
  to the complete previous application release without deleting persistent data.
- **FR-014**: If automatic rollback cannot restore health, the flow MUST fail visibly, preserve the
  recovery bundle, and provide a deterministic manual recovery entry point.
- **FR-015**: Production MUST expose only the application entry point required by the private access
  route; the database and internal application runtime MUST NOT publish externally reachable ports.
- **FR-016**: Production services MUST run with least privilege, bounded resources, bounded log
  retention, and only the writable paths required for persistent or temporary operation.
- **FR-017**: Production secrets MUST remain outside the source repository and immutable release
  artifacts and MUST NOT appear in command output, health responses, release history, or backup
  manifests.
- **FR-018**: Untrusted public-repository contributions MUST NOT be able to execute code on the
  homelab production executor or access its deployment credentials.
- **FR-019**: Only one production mutation or backup operation that requires a consistent snapshot MAY
  run at a time.
- **FR-020**: The system MUST create an encrypted off-host backup at least once per calendar day and
  report when the latest valid backup exceeds the expected recovery-point window.
- **FR-021**: Release history MUST record the source revision, immutable artifact identities, previous
  release, schema state, backup reference, operator or automation identity, start and completion time,
  and final outcome.
- **FR-022**: Operators MUST be able to inspect production health, release identity, storage identity,
  backup freshness, and private-route reachability without exposing protected values.
- **FR-023**: A recovery procedure MUST validate bundle identity, age, sizes, integrity metadata,
  database contents, private-file archive contents, and target identity before overwriting any target
  state.
- **FR-024**: A destructive restore MUST require an explicit target and confirmation and MUST refuse
  the real production target during automated drills unless the operator separately authorizes a real
  recovery operation.
- **FR-025**: Deployment and recovery validation MUST use disposable resources whose names and storage
  are provably distinct from the real SelfHandler and DealFlow production resources.

### Scope Boundaries

This feature includes one production installation, immutable release packaging, a private application
entry point, deployment checks, persistent storage, schema application, health verification,
application rollback, encrypted backup, restore drills, inspection, and operational documentation.

Feature `003-multi-user-auth` satisfies the application behavior prerequisite for live rollout.
Deployment validation includes a smoke check that an existing account can sign in, reach an
owned-data endpoint, and sign out, plus a check of the production HTTPS session-cookie and stateful-
origin configuration. The authentication behavior and screens remain owned by feature 003.

It excludes automatic deployment on every push, public internet exposure, high availability,
multi-host orchestration, horizontal scaling, fleet/profile management, zero-downtime schema migration,
production data cloning into development, native mobile packaging, application authentication screens,
Redis, queue workers, scheduled product jobs, external observability platforms, and deployment of
modules outside an approved product feature.

### Key Entities

- **Production Target**: The single owner-approved homelab installation with a fixed identity, private
  entry point, persistent stores, protected configuration, and current health state.
- **Release**: One immutable, reviewed application version with source identity, artifact identities,
  schema state, predecessor, timestamps, and outcome.
- **Recovery Bundle**: An encrypted, integrity-described package containing authoritative database and
  private-file state for one production target at a point in time.
- **Release Record**: Append-only operational evidence connecting a release attempt to its checks,
  backup, previous version, migration state, operator, and final result.
- **Health Report**: A non-secret view of local application, database, private-route, backup-freshness,
  capacity, and runtime-isolation status.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An operator can start a reviewed production release with one action and without entering
  infrastructure choices; 100% of successful releases identify the exact reviewed revision.
- **SC-002**: A failed pre-release check results in zero production service, schema, or persistent-data
  mutations in every tested failure scenario.
- **SC-003**: 100% of production replacement attempts have a validated encrypted off-host recovery
  bundle completed before the first production mutation.
- **SC-004**: A healthy replacement becomes available through the private production route within 15
  minutes after production mutation begins under normal homelab conditions.
- **SC-005**: An intentionally unhealthy replacement returns to the previous healthy application
  release within 5 minutes in every application-rollback acceptance test.
- **SC-006**: A successful release preserves 100% of a controlled set of existing database records and
  private files.
- **SC-007**: A clean disposable target can be restored from an encrypted recovery bundle with 100% of
  controlled records and files verified within 60 minutes.
- **SC-008**: The latest valid off-host backup is no more than 24 hours old during normal operation,
  and an overdue backup produces an explicit alert in the next inspection.
- **SC-009**: Production inspection reports the exact active release, local health, private-route
  health, backup freshness, and persistent-store identities while exposing zero protected values.
- **SC-010**: Automated deployment and recovery tests create, mutate, and remove zero real SelfHandler
  or DealFlow production containers, networks, or persistent stores.

## Assumptions

- SelfHandler remains a public portfolio source repository; production execution and secrets are
  isolated behind an owner-controlled trusted boundary.
- Multi-user email-and-password authentication and registration are delivered by feature
  `003-multi-user-auth`; this deployment feature MUST NOT substitute a development-only user, invoke
  a default account seeder, or weaken the production session environment during rollout.
- There is one owner-operator and one production homelab installation during this increment.
- Production begins with a fresh authoritative database; importing the current local prototype
  database is outside this feature.
- The homelab provides a container runtime, sufficient persistent disk, and an existing private remote
  access mechanism. The exact route is fixed during planning without exposing the application to the
  public internet.
- The operator retains the recovery decryption identity outside both the repository and homelab
  failure domain.
- Brief maintenance during a release is acceptable; high availability and zero-downtime migration are
  outside this increment.
- Product cache, session, and queue needs may use the primary database where appropriate; a separate
  cache or queue service requires a future feature that demonstrates current need.
