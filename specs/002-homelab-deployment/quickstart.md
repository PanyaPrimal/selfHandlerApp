# Quickstart: Validate and Operate the Homelab Deployment

This guide proves the deployment contract without exposing or copying production data. Commands are
run from the repository root unless stated otherwise. Secret values are represented only by names.

## 1. Prerequisites

- Docker Engine and Docker Compose v2 with Linux containers
- PHP/Composer versions accepted by `apps/api/composer.lock`
- Node.js/npm versions accepted by `apps/web/package-lock.json`
- Python 3 for deployment contract tests
- Windows PowerShell 5.1 or newer for operational scripts
- checksum-pinned portable Python and `age` binaries for deployment validation and recovery
- access to the tailnet only for live private-route checks

Never copy the production `.env`, age identity, database volume, or private-files volume into a
validation workspace.

## 2. Application quality gates

```powershell
Push-Location apps/api
composer validate --strict
php artisan test
Pop-Location

Push-Location apps/web
npm ci
npm run typecheck
npm run build
Pop-Location

npm run test:e2e
```

Expected: every command exits zero. The E2E harness uses its isolated SQLite database and its own
ports; it does not contact production.

## 3. Deployment contracts

```powershell
python -m unittest discover -s deployment/tests -p "test_*.py" -v
docker compose -f deployment/compose.production.yaml config --quiet
```

Expected: contracts validate the fixed target, manifest schemas, no-secret output rules, production
resource isolation, workflow trust boundary, and destructive-command denylist. Compose resolves only
the expected `web`, `app`, and `db` services and publishes only loopback port 18080.

## 4. Disposable production-shaped smoke

The smoke launcher generates a unique project and port, refuses `selfhandler` and `dealflow`, builds
both runtime images, starts MySQL, runs migrations once, and exercises Nginx, FPM, Laravel, database,
registration, sign-in, an owned-data endpoint, and sign-out.

```powershell
python deployment/tests/production_smoke.py
```

Expected evidence:

- all generated containers, networks, and volumes use the disposable project prefix;
- database and FPM have no published ports;
- web/app run non-root with read-only roots, dropped capabilities, bounded resources, and only declared
  writable mounts;
- `/api/health` returns `status=ok` and the candidate 40-character revision;
- a controlled database row and private file survive an application-pair replacement;
- an intentionally unhealthy candidate restores the previous pair without schema/data deletion;
- cleanup removes only generated disposable resources.

Afterward, prove that the real stacks were untouched:

```powershell
docker ps --filter "label=com.docker.compose.project=dealflow"
docker ps --filter "label=com.docker.compose.project=selfhandler"
docker volume ls --filter "name=dealflow_"
docker volume ls --filter "name=selfhandler_"
```

The validation test itself must never invoke cleanup against either production project.

Measured on 2026-08-10 against the final candidate tree:

- the complete production-shaped run took 105.6 seconds, including immutable image builds;
- the intentionally unhealthy paired replacement rolled back in 8.0 seconds;
- the same authenticated session, controlled database row, and private file remained valid afterward;
- the before/after production snapshots were identical, and validation cleanup left zero generated
  containers, networks, or volumes.

## 5. Release qualification

Wait for the public `CI` workflow triggered by the exact `master` push to finish successfully. Its
deployment-contract job runs on hosted Windows Server 2025, proves Windows PowerShell 5.1, and uses
the hash-locked Python 3.14 test environment. Then start qualification only through the no-input
launcher from a clean public-repository `master` that exactly matches `origin/master`:

```powershell
.\deploy.ps1
```

The launcher sends that reviewed 40-character revision to the trusted private operations repository
through an owner-authenticated `repository_dispatch`. After its protected-state resolver, a no-secret
private hosted Windows job checks out only that exact SHA, proves Windows PowerShell 5.1, and repeats
the full deployment suite; Ubuntu qualification cannot begin unless it succeeds. The private hosted
jobs reject any other event, actor, repository, or revision, recheck that public `master` still equals
the dispatched revision before credential use, run Sections 2–4, publish paired GHCR images, and
validate the generated release manifest against `contracts/release-manifest.schema.json`. There is no
moving-branch manual action and the launcher accepts no infrastructure or revision parameters.

Expected before the homelab job starts:

- source revision is a full default-branch SHA;
- the public workflow run is `push`/`master`, completed successfully, and has that exact head SHA;
- app/web images are referenced by `sha256` digest, never `latest`;
- deployment bundle checksum matches;
- all five quality-evidence fields are `passed`;
- the homelab job does not check out the public repository and executes only the checksum-verified
  deployment bundle produced by the qualified hosted job.

## 6. First homelab bootstrap

Bootstrap is permitted only when project `selfhandler` and its two production volumes do not yet
exist. Before migration, bootstrap starts only MySQL and uploads an encrypted empty baseline. The
protected application runtime file `C:\Homelab\SelfHandlerApp\.env` is created locally from
`deployment/env.production.example`; it is never uploaded or printed and contains only values passed
to Compose/application services. It must provide at least:

```text
APP_KEY
DB_PASSWORD
DB_ROOT_PASSWORD
```

Operational values are separate: the public age recipient and probe-account email live in
`C:\Homelab\SelfHandlerApp\ops\config.env`; the backup HMAC key and probe-account password use
ACL-protected host-only secret files; the age private identity remains off-host; and GHCR uses the
ephemeral trusted-workflow credential/Docker credential store. None is injected into the application
container.

Non-secret fixed values must include production environment/debug settings, the private HTTPS URL,
database session/cache drivers, `selfhandler_session`, Secure/HttpOnly/SameSite cookie settings, and
the exact Sanctum host with port.

Before adding ingress, capture the existing configuration. Add only the HTTPS 8443 Serve listener for
`http://127.0.0.1:18080`; do not reset or replace the existing DealFlow Funnel on 443. Compare status
afterward and prove the DealFlow route still returns healthy.

Bootstrap succeeds only after the pre-migration empty baseline and a second post-authentication
recovery point are both stored off-host and Sections 7–8 pass. It records `previous_release=null`
explicitly.

## 7. Live release verification

On a machine inside the tailnet:

```powershell
$origin = 'https://homelab.tail31a802.ts.net:8443'
Invoke-RestMethod "$origin/api/health"
```

Expected JSON contains only:

```json
{
  "status": "ok",
  "release": "0123456789abcdef0123456789abcdef01234567"
}
```

Verify through the browser using a deliberately created production account:

1. Register or sign in through the real UI; no seeder is run.
2. Confirm the `selfhandler_session` response cookie is `Secure`, `HttpOnly`, path `/`, and SameSite
   Lax, and that state-changing calls use the Sanctum CSRF cookie/header flow.
3. Create or open one owned routine/goal and confirm Today loads.
4. Sign out and confirm the owned-data endpoint returns unauthenticated.
5. Confirm the UI/health revision equals the approved release manifest.

Cookies are host-scoped rather than port-scoped, so the browser may send them to both applications on
this hostname. SelfHandler's distinct session/XSRF names prevent collision with DealFlow; this check
must verify both applications continue authenticating independently.

## 8. Inspect production

Run only on the trusted homelab executor:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File deployment/scripts/inspect-production.ps1
```

Expected: exact paired digests, local/private readiness, database health, expected volume identities,
latest valid backup age, isolation checks, and no alerts. Output may name required secret variables but
must never include their values.

## 9. Backup and disposable restore drill

Create a manual backup through trusted automation, upload it off-host, and obtain the private artifact
out of band. On a controlled drill host with the operator-held age identity:

```powershell
$env:SELFHANDLER_RECOVERY_HMAC_KEY_PATH = 'C:\Recovery\backup-hmac.key'
try {
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File deployment/scripts/restore-production.ps1 `
    -RecoveryMode Drill `
    -BundlePath C:\Recovery\selfhandler-example.age `
    -IdentityPath C:\Recovery\selfhandler-age-identity.txt `
    -DisposableProject selfhandler-drill-a1b2c3d4
}
finally {
  Remove-Item Env:SELFHANDLER_RECOVERY_HMAC_KEY_PATH -ErrorAction SilentlyContinue
}
```

Expected: ciphertext, authenticated manifest, target identity, age, member sizes/checksums, safe paths,
database probes, private-file probes, and schema fingerprint all validate before restore. The drill
restores only generated volumes and verifies 100% of controlled records/files. Production mode is
refused without its separate exact confirmation contract. The drill-side HMAC key and age identity
are both ACL-protected members of the off-host operator recovery kit; neither is copied into the
repository or an Actions artifact.

Measured on 2026-08-10: the encrypted disposable MySQL/private-volume round trip completed in 27.6
seconds, restored one of one controlled database records and one of one controlled private files,
touched zero production projects, and removed its generated resources.

## 10. Failure evidence

A rejected preflight must show zero production mutations. A failed candidate must result in either:

- `rolled_back`, with the previous paired digests healthy through both local and private routes; or
- `recovery_required`, with a validated bundle reference and deterministic manual recovery entry point.

Neither result may delete volumes, run `migrate:rollback`, run a seeder, expose a secret, or report a
failed private route as successful.

Inspection keeps local, private-route, backup, and interrupted-deployment failures distinct. The
exercised stable alert codes are `local_unhealthy`, `private_route_unreachable`, `backup_overdue`,
`deployment_incomplete`, and `pending_release`; a private-route failure must not imply local failure.
