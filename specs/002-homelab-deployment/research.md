# Research: Homelab Deployment

## 1. Fixed placement beside DealFlow

**Decision**: Install SelfHandler as Docker Compose project `selfhandler` under
`C:\Homelab\SelfHandlerApp`, binding only `127.0.0.1:18080`. Use project-scoped networks
`selfhandler_app` and `selfhandler_data` and volumes `selfhandler_mysql_data` and
`selfhandler_private_files`. Do not set literal `container_name` values; resolve containers by Compose
project/service labels.

**Rationale**: DealFlow already owns project `dealflow`, loopback port 8000, the public HTTPS 443
Funnel, `dealflow_*` networks/volumes, and `C:\Homelab\DealFlowCRM`. Compose project prefixes give
strong, inspectable isolation while allowing both applications to share one Docker engine.

The shared Docker engine is deliberately an operator trust boundary, not a security boundary between
the two applications: any Docker-daemon administrator can inspect both stacks and mount their volumes.
SelfHandler therefore uses a separate non-administrator Windows runner identity and ACLs for its
host-only HMAC key, probe credential, configuration, and operational state, but complete isolation of
container data would require a separately approved daemon or VM.

**Alternatives considered**: Adding services to DealFlow Compose, sharing DealFlow MySQL, sharing a
network, or using a path under the DealFlow origin were rejected because they couple lifecycle,
credentials, cookies, recovery, and failure domains. A separate VM/daemon would strengthen the
administrator boundary but was rejected for this increment because the approved homelab placement is
beside DealFlow on the existing engine; it remains the next step if mutually untrusted workloads are
introduced.

## 2. Runtime topology

**Decision**: Use three long-running services: an unprivileged Nginx `web` service containing only
the Vue build, a non-root PHP 8.4.24 FPM `app` service containing Laravel and production Composer
dependencies, and MySQL 8.4 `db`. Only Nginx publishes a host port. Nginx serves SPA history fallback
and proxies only `/api/*`, `/sanctum/*`, and health paths to Laravel's fixed `public/index.php`.

**Rationale**: The repository already has separately built Vue and Laravel applications. This keeps
Node out of production, avoids a general PHP-file location in Nginx, and lets readiness exercise the
actual Nginx-to-FPM-to-Laravel-to-MySQL chain.

**Alternatives considered**: A Vite/Node production server was rejected because the output is static.
One container running both Nginx and FPM was rejected because it combines process lifecycles and
weakens health/replacement isolation. FrankenPHP is viable but adds an unneeded runtime change.

## 3. Immutable paired images

**Decision**: Build `web-runtime` and `app-runtime` targets from one multi-stage Dockerfile. Lock npm
and Composer dependencies, pin base images to tested versions/digests, and publish each qualification
attempt as a public-read, write-restricted package version with never-reused tags shaped
`<source-SHA>-<workflow-run-id>-<run-attempt>`.
Deploy only the exact paired digests recorded in the manifest, with the source SHA retained as the OCI
revision. Treat the two digests as one atomic release.

**Rationale**: Multi-stage builds keep toolchains and source-only material out of runtime images.
Digest deployment prevents tag movement and makes release verification and rollback deterministic.
Unique attempt tags make a partial registry push safely retryable without claiming byte-for-byte build
reproducibility or overwriting a prior tag.
Docker documents multi-stage builds at https://docs.docker.com/build/building/multi-stage/.

**Alternatives considered**: `latest`, a bare source-SHA tag reused across attempts, mutable source
bind mounts, building from the current homelab checkout, or independently promoting the two images
were rejected as mutable, not proven byte-reproducible, or non-atomic.

## 4. Runtime configuration and Laravel writable paths

**Decision**: Inject protected configuration only at container runtime. Do not run cache commands
during image build and do not run generic `artisan optimize` because current routes contain Closures.
Seed a writable, UID/GID-owned `bootstrap/cache` tmpfs from non-secret image package metadata, then run
only proven-safe runtime cache commands such as `config:cache` after environment injection. Keep the
root filesystem read-only; mount only the private-file named volume and 64 MiB tmpfs locations for
`/tmp`, `storage/framework`, `storage/logs`, and `bootstrap/cache`. Send application logs to stderr.

**Rationale**: Laravel requires `storage` and `bootstrap/cache` to be writable and recommends cached
production configuration, but caching during the image build could capture build-time configuration
or secrets. Runtime initialization preserves immutable images and secret separation. See
https://laravel.com/docs/12.x/deployment.

**Alternatives considered**: Writable application roots, a host source bind mount, baking `.env` into
the image, generic optimization/route caching, and skipping configuration caching entirely were
rejected.

## 5. Public Caddy ingress without sharing application state

**Decision**: Publish SelfHandler at `https://selfhandler.drpanya.uk` through the existing homelab
Caddy listener on router-forwarded TCP `80`/`443`. Caddy joins only external Docker network
`selfhandler_app` and proxies to `web:8080`; MySQL and PHP-FPM retain no host/WAN ports. The release
runner verifies the public route but does not mutate shared ingress.

**Rationale**: One domain with per-project subdomains provides stable HTTPS without a tunnel or
project-specific WAN port. A separate hostname avoids SPA fallback, cookie, CSRF, and route-prefix
collisions while keeping project data networks and volumes isolated.

**Alternatives considered**: Reusing the CRM root path, publishing Nginx directly on the LAN/WAN,
and retaining a Tailscale-only production origin were rejected because they create routing/security
coupling or do not meet the public-address requirement.

## 6. Public-repository trust boundary

**Decision**: Execute all SelfHandler public-repository checkout, tests, Docker builds, and artifact
creation on GitHub-hosted runners. A private owner-controlled operations workflow is the only workflow
eligible for the homelab runner. The no-input local launcher verifies a clean, synchronized public
`master` and sends its exact 40-character revision through an owner-authenticated repository dispatch.
Public deployment contracts run on hosted Windows Server 2025 and explicitly prove Windows PowerShell
5.1. After the private workflow authenticates the request and its homelab resolver reads protected
state, a no-secret hosted Windows job checks out only the exact dispatched SHA and repeats the full
contract suite under native Windows PowerShell 5.1. Every fresh qualification depends on that success.
The private workflow rejects any other actor, repository, event, or revision, rechecks that `master`
still equals the approved revision before credential use and deployment, and never checks out public
code on the homelab. It executes only a checksum-verified deployment bundle while pulling exact image
digests and validates canonical GitHub run metadata, same-run manifest identity, and OCI revision
labels. The runtime packages are public only for anonymous digest pulls; publication remains limited
to the private workflow and the images contain no runtime secrets. GitHub build-provenance
attestations are excluded because the selected GitHub Free plan does not provide them for user-owned
private repositories.

**Rationale**: GitHub warns that self-hosted runners should almost never be attached to public
repositories because untrusted code can persistently compromise the runner and its network. See
https://docs.github.com/en/actions/reference/security/secure-use. Repeating the exact-SHA contracts in
the private workflow makes native Windows PowerShell evidence mandatory without adding a cross-repo
PAT or exposing production/registry credentials to public source or the persistent runner. Public
read access to secret-free runtime images lets the persistent runner pull exact digests anonymously.
The boundary directly satisfies FR-018.

**Alternatives considered**: A self-hosted job in the public repository, private-environment reviewer
gates unavailable on standard personal plans, resolving a moving branch after authorization, and
checking out a user-supplied ref on the homelab were rejected. Environment approval alone would not
turn a persistent runner into an isolated sandbox.

## 7. Health model

**Decision**: Keep Laravel `/up` as boot/liveness and add `GET /api/health` as non-secret readiness.
Readiness executes `SELECT 1` and returns only `status` and the configured release identity. Nginx's
healthcheck calls readiness so it covers Nginx, FPM, Laravel, and MySQL. MySQL retains its own native
healthcheck.

**Rationale**: Laravel's built-in health endpoint proves framework boot but does not prove database
connectivity or which release is running. Laravel supports extending health checks, but a dedicated
stable JSON contract is easier to verify without exposing exception details.

**Alternatives considered**: Checking only container state, only `/up`, returning full diagnostics
publicly, or checking MySQL directly from the deployment host were rejected as incomplete or too
revealing.

## 8. Migration and rollback contract

**Decision**: After backup and before starting the replacement, run `php artisan migrate --force`
exactly once in a one-shot container made from the candidate app digest. Every deployment migration
must follow expand/contract rules and leave the previous application release functional through the
rollback window. CI migrates a disposable database and tests both candidate and previous application
images against the resulting forward schema.

**Rationale**: Laravel's migrations table supplies once-per-database semantics. Running migrations in
every FPM entrypoint creates restart races. Application rollback cannot safely imply destructive data
rollback. See https://laravel.com/docs/12.x/migrations.

**Alternatives considered**: Entrypoint migrations, concurrent migrations, `migrate:rollback` during
automatic recovery, and accepting incompatible rename/drop migrations were rejected.

## 9. Persistent data and backup bundle

**Decision**: Use Docker named volumes rather than Windows bind mounts for MySQL and private files.
Before each mutation and once daily, create a consistent MySQL dump and read-only private-file archive,
record target/release/schema/time/sizes/checksums in a manifest, authenticate the manifest with an
operator secret, package it, encrypt it with an operator-controlled `age` recipient, remove plaintext
in `finally`, and upload the ciphertext plus non-secret reference outside the homelab failure domain.
The exact plaintext tar contains `manifest.json`, `manifest.hmac`, `database.sql`, and
`private-files.tar`. `manifest.hmac` is HMAC-SHA256 over the exact UTF-8 `manifest.json` bytes and is
checked in constant time before payload extraction. The manifest allowlists exactly the two payload
members and their sizes/checksums. Current application code has no private-file mutation endpoint, so
the volume is stable during backup; a future attachment feature must add atomic file-finalization and
update this consistency contract before rollout.

The homelab validates manifest authentication and payloads before encryption, then validates the age
process result plus ciphertext size/checksum. It cannot decrypt its own routine backup because the age
identity deliberately remains off-host. Decrypt-to-validate is therefore an identity-backed recovery
drill responsibility, not a routine backup-runner capability.

**Rationale**: Named volumes avoid Windows filesystem semantics for MySQL. Database plus private
files are jointly authoritative. Encryption and an authenticated manifest support confidentiality,
tamper detection, and deterministic target validation.

**Alternatives considered**: Database-only backup, unencrypted GitHub artifacts, backup after
migration, a backup kept only on the Docker host, or treating successful archive creation as proof of
restore were rejected.

## 10. Recovery and drills

**Decision**: Restore validates deployment identity, bundle authentication, ciphertext and member
checksums, sizes, age, safe archive paths, database contents, and schema metadata before mutation. A
drill may target only a generated disposable Compose project. Real production restore requires an
explicit recovery mode and exact confirmation string and preserves a pre-restore safety bundle.
Drills reject backups older than the 24-hour RPO target. Production and bootstrap-reset recovery may
use an authenticated backup up to the fixed 720-hour retention boundary, but 24–720-hour bundles
require a separate exact stale-recovery confirmation and audit evidence; older or future-dated bundles
are refused.

**Rationale**: Backup existence is not recoverability. Explicit identity and confirmation prevent a
test or wrong-customer bundle from overwriting production. Disposable names and labels prove DealFlow
and SelfHandler production resources remain untouched.

**Alternatives considered**: Periodic backup without restore testing, restore directly into
production for validation, and accepting arbitrary archive paths were rejected.

## 11. Serialization and host capacity

**Decision**: Use `cancel-in-progress: false` in workflow concurrency and the host-side exclusive lock
`C:\Homelab\SelfHandlerApp\.locks\selfhandler-production.lock` for every SelfHandler deploy, backup, and restore.
Before staging, verify Docker and public-ingress availability,
free disk for backup plus both release pairs, bounded host memory/CPU, and healthy current production.
SelfHandler services start with web `0.25 CPU/128 MiB/64 PIDs`, app `0.75 CPU/512 MiB/128 PIDs`,
database `0.75 CPU/768 MiB/256 PIDs`, 10 MiB × 5 JSON logs, and 64 MiB tmpfs mounts. Backup runs daily,
retains encrypted workflow artifacts for 30 days, and alerts when the latest validated copy is older
than 24 hours.

**Rationale**: GitHub concurrency is repository-scoped. The host lock remains effective across all
SelfHandler workflows and interactive recovery. DealFlow stays resource-isolated; claiming a shared
cross-application lock would be false until DealFlow explicitly adopts the same protocol. Docker
Compose supports health dependencies, read-only filesystems, tmpfs, and resource limits; see
https://docs.docker.com/reference/compose-file/services/.

**Alternatives considered**: Relying only on workflow concurrency, allowing deployment during backup,
and unbounded containers/logs were rejected.

## 12. First deployment and release history

**Decision**: Treat bootstrap separately from routine replacement. Bootstrap requires empty newly
created production volumes, creates and uploads an encrypted empty baseline after starting only MySQL
but before the first migration, and records no previous release. After migration and probe-account
authentication it creates the first normal encrypted recovery point. Later releases require a healthy
current release and validated pre-deployment backup. Append release attempts atomically with source
SHA, paired digests, schema fingerprint,
previous release, backup reference, actor, timestamps, checks, and outcome.

**Rationale**: A first release cannot automatically roll back to a nonexistent predecessor, while
pretending it can would violate the observable recovery contract. Append-only evidence makes failed
and restored attempts inspectable.

**Alternatives considered**: Fabricating a previous release, requiring a backup of nonexistent data,
overwriting one `current.json` without history, and declaring bootstrap success before auth/private
route verification were rejected.

## 13. Production authentication settings

**Decision**: Configure `APP_ENV=production`, `APP_DEBUG=false`, the exact public HTTPS `APP_URL`,
database-backed session/cache, `SESSION_COOKIE=selfhandler_session`, Secure and HttpOnly cookies,
SameSite Lax, and the exact host-with-port in `SANCTUM_STATEFUL_DOMAINS`. A visible probe account
email/password lives only in the private ops secret store: bootstrap registers it only if the user
table is empty, routine deploys log in, and a new backup is required after first account verification.
The smoke signs in, reaches an owned-data endpoint, and signs out. No seeder runs.

Nginx forwards the original Host and HTTPS scheme. Laravel trusts proxy headers because PHP-FPM is
unpublished and reachable solely from the isolated `selfhandler_app` network. `SESSION_DOMAIN=null`
remains host-only, although browser cookies are not port-isolated; the unique `selfhandler_session` and
Laravel XSRF cookie names avoid collision with DealFlow on the same hostname.

**Rationale**: Feature 003 is the production identity source. A local environment, insecure cookie,
wrong stateful origin, or default seeder would produce a deployment that appears healthy but cannot be
used safely.

**Alternatives considered**: `APP_ENV=local`, disabling CSRF, issuing bearer tokens, creating a hidden
default user, and running `db:seed` were rejected.
