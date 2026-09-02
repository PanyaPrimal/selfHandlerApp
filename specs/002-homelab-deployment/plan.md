# Implementation Plan: Homelab Deployment

**Feature**: `002-homelab-deployment` | **Date**: 2026-08-09 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/002-homelab-deployment/spec.md`

## Summary

Package the Laravel API and Vue SPA as one reviewed release made of two immutable Linux container
images. Deploy that pair beside DealFlow as the fixed Docker Compose project `selfhandler`, with a
dedicated MySQL 8.4 database, dedicated private-file storage, and only an Nginx entry point bound to
`127.0.0.1:18080`. The shared homelab Caddy ingress also joins `selfhandler_app` and exposes the web
service at `https://selfhandler.drpanya.uk`; the existing CRM and pgAdmin sites remain independent.

GitHub-hosted runners execute every checkout and all qualification of public-repository code, tests,
image builds, and registry publishing. A separately trusted private operations workflow on the
homelab runner consumes only an approved manifest, exact image digests, and the checksum-verified
deployment bundle produced from that reviewed revision; it never executes a pull request, fork,
arbitrary ref, or unqualified checkout. Each production mutation is serialized, preceded by a
validated encrypted off-host database-and-files backup, followed by a one-shot migration and full
local/public-route verification. Application rollback swaps the complete previous image pair and
never deletes or automatically rolls back persistent data.

## Technical Context

**Language/Version**: PHP 8.4.24 FPM for Laravel (application constraint `^8.4`), TypeScript `~6.0`, Vue
3.5, Node.js 22 for build only, Windows PowerShell 5.1-compatible operational scripts, and a
checksum-pinned portable Python 3 runtime for deployment validation/recovery helpers

**Primary Dependencies**: Laravel 12.65 and Sanctum 4.x from `composer.lock`; Vue 3.5, Vue Router 5,
Vite 8, and Playwright 1.61 from `package-lock.json`; Nginx unprivileged runtime; Docker Engine with
Compose v2; MySQL 8.4 LTS; `age` for recovery-bundle encryption; GitHub Actions and GHCR for hosted
release production

**Storage**: MySQL 8.4 in the named volume `selfhandler_mysql_data`; Laravel private files in
`selfhandler_private_files`; runtime cache/session data in MySQL; explicitly owned and bounded tmpfs
mounts for Laravel and Nginx temporary paths; protected operational state under
`C:\Homelab\SelfHandlerApp\state`; separate ACL-protected ops configuration under
`C:\Homelab\SelfHandlerApp\ops` that is never passed to the application container

**Testing**: PHPUnit 11 backend suite, `vue-tsc`, Vite production build, Playwright desktop/mobile
flows, Python deployment contract tests, Docker Compose configuration checks, and a disposable
production-shaped MySQL/Nginx/PHP-FPM smoke and rollback/restore drill

**Target Platform**: Linux containers on the existing Windows homelab Docker Desktop/WSL2 engine;
public HTTPS through the existing Caddy ingress; GitHub-hosted Ubuntu runners for
application CI and private qualification, plus hosted Windows Server 2025 for the public deployment
contracts and mandatory Windows PowerShell 5.1 evidence

**Project Type**: Monorepo web application plus fixed-target operational tooling

**Performance Goals**: A normal replacement passes migration and local/public-route verification
within 15 minutes; an intentionally unhealthy replacement restores the previous application within
5 minutes; a controlled recovery drill finishes within 60 minutes; readiness responds within 5
seconds under normal homelab conditions

**Constraints**: One fixed production target; brief maintenance is acceptable; only the dedicated
SelfHandler ingress network is shared with Caddy; no shared DealFlow volumes, database, application
network, state, or secrets; no public-repository
checkout or unqualified code execution on the production runner (only the checksum-verified bundle
from the exact qualified revision may execute there); no `latest` tags; no production seeding;
no destructive schema rollback; secrets are runtime-only; database and PHP-FPM publish no host ports;
images and root filesystems are immutable apart from declared volumes/tmpfs; resource ceilings start
at web `0.25 CPU/128 MiB/64 PIDs`, app `0.75 CPU/512 MiB/128 PIDs`, and database
`0.75 CPU/768 MiB/256 PIDs`, with 10 MiB × 5 JSON logs and 64 MiB application tmpfs mounts

**Trusted administrator boundary**: SelfHandler and DealFlow share one Docker daemon, so daemon
administrators can inspect both applications and their Docker-managed data. A dedicated SelfHandler
Windows runner identity isolates host-only configuration, recovery authentication material, and state;
it does not claim isolation from another Docker administrator. A separate daemon/VM is outside this
increment.

**Scale/Scope**: One homelab installation, two long-running application images plus MySQL, a small
number of users, one daily encrypted backup, one fixed public origin, and append-only release history

## Constitution Check

*GATE: Passed before research and re-checked after Phase 1 design.*

- **Specifications Before Implementation**: PASS. [spec.md](spec.md) defines four prioritized,
  independently testable operational journeys, explicit failure behavior, and measurable deployment,
  rollback, recovery, and inspection outcomes.
- **Vision and Delivery Sources**: PASS. This increment changes only delivery infrastructure and
  links to the existing privacy and user-ownership rules. It does not redefine domain behavior from
  `docs/design/`.
- **Thin Vertical Slices**: PASS. The design adds only the three runtime services, release pair,
  backup/recovery path, and inspection needed for one usable installation. Redis, workers,
  orchestration, HA, fleet management, and external observability remain excluded.
- **Deterministic Core**: PASS. Deployment, validation, migration, backup, rollback, and recovery are
  deterministic scripts with no AI dependency.
- **User-Owned Data and Privacy**: PASS. Database and private files are isolated in dedicated named
  volumes, backups are encrypted before leaving protected staging, the HTTPS route is terminated by Caddy,
  and secret values are excluded from artifacts and reports.
- **Contracts and Tests**: PASS. Operational command contracts, health and manifest schemas, static
  contract tests, production-shaped smoke tests, failure injection, and live auth/cookie checks move
  with the runtime configuration.
- **Branch Governance**: PASS. Work remains on the current `master` branch selected by the user. No
  branch is created, switched, merged, or deleted by Spec Kit or deployment automation.

### Post-Design Re-check

PASS. Phase 1 introduces two application runtime images and one MySQL service because the existing
Vue/Laravel boundary requires static serving plus PHP-FPM and persistence. Each service has a current
consumer and an independently testable responsibility. A private operations trust boundary is
required by FR-018 because the source repository is public; it is not a generalized fleet platform.

## Architecture and Release Boundaries

```text
Public Internet -> Caddy :443 -> selfhandler_app network
https://selfhandler.drpanya.uk
                    |
                    v
  selfhandler_web (Nginx :8080, plus host loopback 127.0.0.1:18080)
                    |
          selfhandler_app network
                    |
  selfhandler_app (PHP 8.4.24 FPM :9000, no host port)
                    |
         selfhandler_data network (internal)
                    |
  selfhandler_db (MySQL 8.4 :3306, no host port)

Persistent: selfhandler_mysql_data + selfhandler_private_files
Separate existing stack: dealflow_* resources behind the same Caddy 80/443 listener
```

The release unit is the tuple `(source SHA, web digest, app digest, schema fingerprint)`. Web and app
images are promoted and rolled back only as a pair. The candidate app image runs migrations once from
an explicitly invoked one-shot container after backup validation; migrations never run in every
application entrypoint.

## Project Structure

### Documentation (this feature)

```text
specs/002-homelab-deployment/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── health.openapi.yaml
│   ├── operations.md
│   ├── health-report.schema.json
│   ├── recovery-manifest.schema.json
│   ├── release-manifest.schema.json
│   └── release-record.schema.json
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
.github/workflows/
└── ci.yml

apps/api/
├── app/Http/Controllers/HealthController.php
├── bootstrap/app.php
├── config/app.php
├── routes/api.php
└── tests/Feature/HealthTest.php

deployment/
├── compose.production.yaml
├── compose.validation.yaml
├── docker/
│   ├── Dockerfile
│   ├── app-entrypoint.sh
│   ├── nginx.conf
│   └── php-fpm.conf
├── env.production.example
├── ops-config.example
├── README.md
├── recovery.py
├── release_manifest.py
├── scripts/
│   ├── backup-production.ps1
│   ├── auth-smoke.ps1
│   ├── deploy-production.ps1
│   ├── finalize-release.ps1
│   ├── inspect-production.ps1
│   ├── recovery-drill.ps1
│   ├── restore-production.ps1
│   └── shared.ps1
├── tests/
│   ├── README.md
│   ├── requirements.txt
│   ├── recovery_smoke.py
│   ├── test_backup.py
│   ├── test_contracts.py
│   ├── test_deploy.py
│   ├── test_inspect.py
│   ├── test_recovery.py
│   ├── test_release.py
│   ├── test_public_ingress.py
│   └── production_smoke.py
└── private-ops/
    ├── .github/
    │   └── workflows/
    │       ├── backup-selfhandler.yml
    │       ├── deploy-selfhandler.yml
    │       └── inspect-selfhandler.yml
    └── README.md

deploy.ps1
.dockerignore
```

The public repository owns build inputs and tested operational contracts. Its CI workflow performs
read-only validation, runs deployment contracts on hosted Windows Server 2025 under Windows
PowerShell 5.1, and never receives production or registry credentials. The private operations
repository owns the owner-authenticated exact-SHA dispatch workflow. After its read-only host-state
resolver, a no-secret hosted Windows job checks out only the authenticated public SHA and repeats the
full contract suite under native Windows PowerShell 5.1; qualification requires that success. Later
hosted jobs qualify the revision, publish and attest the paired images, and package the deployment
bundle; the self-hosted job invokes that attested/checksum-verified bundle. Fixed protected runtime
`.env` and separate host-only ops secrets remain only on the homelab.
GHCR uses an ephemeral workflow credential; the backup HMAC key is an ACL-protected host file; the age
recipient is non-secret ops configuration; and the age identity remains off-host. None is injected
into the app container or copied into release artifacts.

## Deployment Sequence

1. Public `push` CI proves the exact `master` SHA, including the full deployment contract suite on
   hosted Windows PowerShell 5.1. The no-input launcher then proves local `master` is clean and
   synchronized and sends its exact SHA plus a fresh correlation identifier to the private operations
   repository. After authenticating the request and resolving protected host state, a private
   no-secret hosted Windows job checks out only that exact revision and repeats every deployment
   contract under native Windows PowerShell 5.1. Qualification depends on its success, then the
   unprivileged Ubuntu job repeats backend, frontend, E2E, deployment-contract, image-build, and
   disposable production-shaped checks.
2. A separate hosted publish job builds before receiving registry credentials, rechecks that public
   `master` still equals the qualified SHA immediately before registry login, publishes the paired
   images by SHA, produces GitHub build provenance, and logs out. An unprivileged packaging job then
   records immutable digests, OCI revision labels, workflow identity, attestations, and deployment
   bundle checksum in a release manifest.
3. The trusted private workflow accepts no host/port/project inputs, acquires the exclusive
   `C:\Homelab\.locks\selfhandler-production.lock` used by every SelfHandler deploy/backup/restore,
   validates actor/revision/manifest, and checks Docker, Caddy-backed public readiness, disk, memory, current health,
   and resource isolation. Before fallible host operations it writes an ACL-protected immutable
   prepared-release journal containing the exact bundle, manifest, private-workflow identity, and
   checksums so another run can resume without trusting previous job outputs.
4. Before the first mutation it creates database and private-file snapshots, authenticates the
   manifest, encrypts the bundle with the operator-controlled `age` recipient, removes plaintext, and
   completes off-host upload.
5. It pulls exact digests, verifies the candidate pair, stops only SelfHandler web/app for the allowed
   maintenance window, runs the candidate app migration container once, and replaces the pair while
   preserving both named volumes.
6. It verifies container health, database readiness, local readiness, public HTTPS readiness,
   release identity, runtime hardening, port isolation, and a real session-auth smoke without seeding.
   A visible probe account is configured as private ops credentials; bootstrap registers it only when
   the database has no users, routine releases only log it in, and bootstrap creates a second encrypted
   recovery point after account verification. The first release remains pending until that recovery
   point is uploaded and immutably bound; only the trusted finalizer may then write the active-release
   pointer and terminal `succeeded` record. An exact-SHA retry resumes this finalization instead of
   republishing or reapplying the release.
7. On application failure it restores the complete previous image pair, verifies health, records the
   failed and restored releases, and leaves the encrypted recovery bundle available. It never runs an
   automatic destructive database rollback.

Bootstrap first proves the new volumes are empty, starts only the database, and uploads a validated
encrypted empty baseline (no active release and an empty schema fingerprint) before the first
migration. This satisfies the pre-mutation recovery boundary despite there being no predecessor. The
post-auth bootstrap recovery point then becomes the first normal restorable application state.

## Complexity Tracking

No constitution violation requires an exception. The separate trusted operations boundary and two
application images are the minimum designs that satisfy, respectively, the public-repository security
requirement and the existing Vue-static/PHP-FPM runtime boundary.
