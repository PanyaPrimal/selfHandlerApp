# SelfHandler homelab operations

This directory is the public, checksum-verifiable deployment bundle for the one
supported production target. The scripts intentionally do not accept alternate
hosts, Compose projects, ports, volume names, or release refs.

## Fixed production identity

| Item | Fixed value |
| --- | --- |
| Deployment ID | `selfhandler-production` |
| Compose project | `selfhandler` |
| Host root | `C:\Homelab\SelfHandlerApp` |
| Compose definition | `compose.production.yaml` from the checksum-verified qualified bundle only |
| Local origin | `http://127.0.0.1:18080` |
| Public origin | `https://selfhandler.drpanya.uk` |
| Database volume | `selfhandler_mysql_data` |
| Private-files volume | `selfhandler_private_files` |
| Operations lock | `C:\Homelab\SelfHandlerApp\.locks\selfhandler-production.lock` |

Compose and operation scripts always resolve relative to the currently executing
qualified bundle. Deploy uses the freshly checksum-verified staging bundle;
scheduled backup/inspection uses the checksum-verified installed
active-operations pointer. No mutable Compose copy under the host root is a
trusted execution source.

Copy `env.production.example` to `C:\Homelab\SelfHandlerApp\.env` and
`ops-config.example` to `C:\Homelab\SelfHandlerApp\ops\config.env`. Protect both
with the same inheritance-disabled runner/Administrators/SYSTEM ACL used below.
The application `.env`
is passed to Compose; `ops\config.env` and `ops\secrets` are host-only and must
never be mounted into an application container.

Required application secret names are `APP_KEY`, `DB_PASSWORD`, and
`DB_ROOT_PASSWORD`. Required operational names are `AGE_RECIPIENT`,
`AGE_RECIPIENT_FINGERPRINT`, `BACKUP_HMAC_KEY_ID`, `BACKUP_HMAC_KEY_PATH`,
`PROBE_ACCOUNT_EMAIL`, and `PROBE_ACCOUNT_PASSWORD_PATH`. The configured public
recipient fingerprint must equal SHA-256 of the exact recipient text. The HMAC
key and probe password are stored in the
fixed ACL-protected files named by their path settings; changing either path is
rejected. The `age` recipient is public;
the corresponding private identity stays off the homelab and runner.

Generate the recovery secrets first on an operator-controlled machine, not on
the homelab. Keep this recovery kit off-host and offline. The commands below do
not print a secret (the captured age public key is intentionally non-secret):

```powershell
$trustedAccount = "$env:USERDOMAIN\$env:USERNAME"
$secretRoot = 'C:\RecoveryKit\SelfHandler'
New-Item -ItemType Directory -Path $secretRoot -Force | Out-Null
icacls.exe $secretRoot /inheritance:r /grant:r `
  "${trustedAccount}:(OI)(CI)(M)" '*S-1-5-32-544:(OI)(CI)(F)' `
  '*S-1-5-18:(OI)(CI)(F)' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Recovery-kit directory ACL setup failed' }
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
try {
  $hmac = New-Object byte[] 64
  $probe = New-Object byte[] 48
  $rng.GetBytes($hmac)
  $rng.GetBytes($probe)
  [IO.File]::WriteAllBytes((Join-Path $secretRoot 'backup-hmac.key'), $hmac)
  [IO.File]::WriteAllText(
    (Join-Path $secretRoot 'probe-account-password.txt'),
    [Convert]::ToBase64String($probe),
    (New-Object Text.UTF8Encoding($false))
  )
} finally { $rng.Dispose() }
$ageOutput = @(& age-keygen.exe -o (Join-Path $secretRoot 'selfhandler-age-identity.txt') 2>&1)
if ($LASTEXITCODE -ne 0) { throw 'age identity generation failed' }
$recipientLines = @($ageOutput | ForEach-Object { [string]$_ } | Where-Object { $_ -match '^Public key: age1[0-9a-z]{20,100}$' })
if ($recipientLines.Count -ne 1) { throw 'age public recipient output is invalid' }
$ageRecipient = ($recipientLines[0] -replace '^Public key: ', '')
if ($ageRecipient -notmatch '^age1[0-9a-z]{20,100}$') { throw 'age recipient is invalid' }
[IO.File]::WriteAllText(
  (Join-Path $secretRoot 'age-public-recipient.txt'),
  $ageRecipient,
  (New-Object Text.UTF8Encoding($false))
)
foreach ($path in @(
  (Join-Path $secretRoot 'backup-hmac.key'),
  (Join-Path $secretRoot 'probe-account-password.txt'),
  (Join-Path $secretRoot 'selfhandler-age-identity.txt'),
  (Join-Path $secretRoot 'age-public-recipient.txt')
)) {
  icacls.exe $path /inheritance:r /grant:r `
    "${trustedAccount}:(R)" '*S-1-5-32-544:(F)' '*S-1-5-18:(F)' | Out-Null
  if ($LASTEXITCODE -ne 0) { throw "Secret ACL setup failed for $path" }
}
```

Copy only `backup-hmac.key` and `probe-account-password.txt` to the homelab's
fixed `C:\Homelab\SelfHandlerApp\ops\secrets` paths through the RDP/admin setup
channel, then replace the operator allow entry with the actual non-admin runner
account. Use a dedicated SelfHandler Windows account, runner root, and service
registered only to `PanyaPrimal/selfhandler-ops`; do not share the DealFlow OS
identity or grant its SID access to SelfHandler state/secrets. Add only the
SelfHandler account to `docker-users`. Never copy the age identity to the
homelab. Put the public recipient from
`age-public-recipient.txt` in `ops\config.env` exactly as the bare `age1...`
text (never the `Public key:` label). Set `AGE_RECIPIENT_FINGERPRINT` to the
lowercase SHA-256 of those exact UTF-8 recipient bytes; backup recomputes and
compares it before reading production stores.

Both non-admin runner accounts remain effectively Docker-engine administrators
when they share one Docker Desktop daemon. Separate Windows SIDs and filesystem
ACLs prevent ordinary file/service crossover, but the shared daemon is a
documented residual boundary: strong cross-repository runtime isolation requires
a separate Docker daemon or VM for SelfHandler.

During the one-time admin setup, disable inheritance and grant allow entries
only to that runner, Administrators, and SYSTEM on
`C:\Homelab\SelfHandlerApp`, its `ops` directory, and `ops\secrets` (runner
Modify; Administrators/SYSTEM Full Control). Apply the same three explicit
identities to `.env`, `ops\config.env`, `backup-hmac.key`, and
`probe-account-password.txt`. The scripts validate both each file and its
immediate parent directory, including owner, reparse status, disabled
inheritance, and absence of any other allow SID; a protected file inside a
writable/replacable directory is rejected.

Pre-create `C:\Homelab\SelfHandlerApp\state` with the same three identities,
trusted owner, no reparse point, and inheritance disabled. The workflow-created
`qualified-bundles` tree, `active-operations.json`, immutable bundle/manifest,
and `trust-metadata.json` may inherit only those trusted entries. Scripts reject
an untrusted owner/allow SID, reparse point, missing trusted identity, path
escape, or checksum mismatch before reading release state or selecting code.

Also pre-create `C:\Homelab\SelfHandlerApp\.locks` with inheritance disabled, no reparse point,
trusted owner, and allow entries only for the dedicated SelfHandler runner,
Administrators, and SYSTEM. `C:\Homelab` itself must not grant any other SID
write/create/delete-child/change-permission/take-ownership rights; another
runner may receive access only on its own deeper project directory. In
particular, the DealFlow SID gets no write/delete access to `.locks`. Operations
never auto-create this directory. The first acquisition creates the fixed
`selfhandler-production.lock` with the same protected ACL and every acquisition
rechecks parent, file owner/ACL, and reparse status before relying on exclusive
`FileShare.None` locking.

Every operation fails closed unless inheritance is disabled and the only allow
entries are the current executor, BUILTIN\Administrators, and SYSTEM, with all
three explicitly present. Preserve protected off-host copies of
`backup-hmac.key`, the age identity, `probe-account-password.txt`, the production
`.env`/`APP_KEY` and database credentials, and non-secret ops config. These are
one recovery kit: the age identity alone can decrypt but cannot authenticate a
bundle, and a restored host also needs runtime configuration and the probe
credential. Re-ACL temporary recovery-host copies to that operator account and
securely remove them after the drill. The homelab `.env` is checked by every
Compose operation with the same fail-closed ACL/reparse/size policy.
`ops\config.env` is integrity-sensitive too: backup and auth check the same ACL,
and executable override keys are forbidden; the checksum-verified Python and
`age` executables must already be on PATH.

Scripts may log these names and safe identifiers, but never their values. Keep
GitHub tokens, Docker registry credentials, production `.env` contents, HMAC key,
probe password, and `age` identity out of release artifacts and job logs.

## Trusted-runner boundary

The public bundle does not establish trust in a release. The owner-authenticated
private repository accepts only the fixed `repository_dispatch`, verifies the
owner sender plus canonical public repository, rechecks that public `master`
equals the exact dispatched 40-character SHA, and then verifies hosted run
identity/metadata, the deployment-bundle checksum, release manifest, and both
same-run image digest declarations. It pulls the exact digest-qualified app and
web images anonymously from public read-only runtime packages, verifies their OCI source revision,
and executes this bundle without a registry credential. Package publication remains restricted to
the private operations workflow. This free-plan path does not depend on GitHub attestations,
which are unavailable for user-owned private repositories.

`deploy-production.ps1` requires those exact images to exist locally. It uses
`docker image inspect` and Compose `--pull never`; it neither pulls nor logs in.
Do not pass `GITHUB_TOKEN`, registry credentials, or persisted Docker credentials
to a deployment-bundle process.

The internal deployment interface is:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File deployment\scripts\deploy-production.ps1 `
  -ReleaseManifestPath C:\staging\release-manifest.json `
  -BackupReference "github-artifact:<immutable-id>:<bundle-id>" `
  -Actor private-runner `
  -AttemptId gh-12345678-1
```

There are no host, project, port, mode, or image-ref parameters. Release records
are append-only under `state\releases`. Before the first migration, deploy
atomically writes `state\pending-releases\<attempt>.json` with the exact
manifest/bundle hashes, candidate pair, previous release, already-bound backup,
actor, original private workflow run/attempt identity, and state `deploying`.
An interrupted exact attempt—even when GitHub assigns a new run-attempt
identifier—adopts exactly one journal only when source, paired digests, manifest
bytes, bundle hash, original backup, actor, and workflow evidence all match. It
then safely replays the explicit migration container and paired
replacement; it never republishes images or invents a new release identity.
After local/public/authentication/isolation gates, deploy changes the journal
to `awaiting_completion` and exits successfully, but does **not** write a
terminal release record or change `active-release.json`.

Trusted automation then installs the same checksum-qualified operations bundle,
atomically selects it with `state\active-operations.json`, and—for bootstrap—
creates and uploads the first authenticated normal backup. It completes the
release through the fixed finalizer interface:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File deployment\scripts\finalize-release.ps1 `
  -ReleaseManifestPath C:\staging\release-manifest.json `
  -CompletionBackupReference "github-artifact:<immutable-id>:<bundle-id>" `
  -Actor private-runner `
  -AttemptId gh-12345678-1
```

The finalizer rechecks the installed bundle/manifest checksums, exact local
images and running pair, schema, isolation, local/public health, and real
session authentication. The version-2 `active-operations.json` pointer and its
exact-hash `trust-metadata.json` bind the original attempt/actor, manifest and
bundle hashes, fixed private workflow repository/ref, immutable workflow SHA,
and original run identity inside the ACL-protected state tree. Routine releases reuse the validated predeploy backup;
bootstrap requires the new `bootstrap` backup. It journals that immutable
completion reference as `completion_validated` before changing the active
pointer, then writes `succeeded`. Thus a host interruption on either side of the
pointer update is resumable, while `succeeded` and the candidate active pointer
cannot precede the required off-host bind. A repeated exact attempt/finalizer is
idempotent; a different manifest, actor, attempt, backup, or staged checksum is
rejected.

Candidate migrations use an explicit `artisan migrate --force` one-shot
container with `--pull never`; a journal resume may safely invoke the
framework's idempotent migration command again. Seeders, entrypoint migrations,
and `migrate:rollback` are prohibited. App and web are replaced and, when
needed, rolled back as one digest pair while the named data volumes remain
attached. Before an initial baseline or migration, the fixed loopback port is
also bind-probed; an existing listener fails with `local_port_conflict` before
database mutation.

## Backup

The fixed-target backup interface used by private workflows is:

```powershell
$result = powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File deployment\scripts\backup-production.ps1 `
  -Reason predeploy `
  -OutputDirectory C:\staging\selfhandler-backups
```

Allowed reasons are `predeploy`, `scheduled`, `manual`, `pre-restore`,
`bootstrap-baseline`, and `bootstrap`. All diagnostics go to the host stream. The
last standard-output line is a compact JSON object containing only
`CiphertextPath`, `Sha256`, and `BundleId`. Private automation must upload the
`.age` file, obtain an immutable off-host reference, and bind that reference to
the same bundle before deployment continues. A host-only or mutable path is not
a recovery point.

Except for the exact `bootstrap` completion snapshot, backup refuses to run
while any deployment journal is pending. This prevents a scheduled, manual,
predeploy, or pre-restore job from labeling candidate data with the still-prior
active pointer. Production restore and BootstrapReset also refuse pending state;
finish or explicitly terminalize the exact attempt first.

Before encryption, the backup code validates an authenticated plaintext tar
containing exactly these four regular members in order:

1. `manifest.json`
2. `manifest.hmac`
3. `database.sql`
4. `private-files.tar`

`manifest.hmac` is lowercase HMAC-SHA256 over the exact UTF-8 bytes of
`manifest.json`. The validator authenticates the manifest with a constant-time
comparison before trusting payload metadata, then checks target identity,
allowed reason/null-release rules, age, sizes, hashes, and nested archive paths.
The routine job validates the `age` process, non-empty ciphertext, and ciphertext
SHA-256, then removes every plaintext staging file in `finally`. It cannot
decrypt its own output because the private identity is intentionally off-host.
Decryption proof belongs to the recovery drill.

Bootstrap has two recovery points: an encrypted empty baseline before migration,
and a new encrypted backup after the real probe account has been created and
verified. Bootstrap is not complete until both are stored off-host and the
private/authentication gates pass. While `active-release.json` intentionally
remains absent, `-Reason bootstrap` derives its source only from exactly one
`awaiting_completion` journal and verifies that journal against the running pair
and schema. The finalizer binds this backup before it can make the candidate
active or terminally successful.

## Inspection and public ingress

Run inspection only from the trusted homelab executor:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File deployment\scripts\inspect-production.ps1
```

The final line is a secret-safe structured health report. It distinguishes local
readiness from public-route readiness and reports actual image digests, database
and volume state, runtime isolation, capacity, release history, and latest valid
backup age. Alert codes are stable automation inputs; do not treat an unhealthy
public route as a healthy deployment merely because loopback succeeds.
`pending_release` and `deployment_incomplete` explicitly report an unfinished
two-phase release; the actual pair versus still-active pointer is also reported
as a failed `release_pair_matches` isolation check where applicable.

Public ingress is shared with the other `drpanya.uk` services and is configured once by an
administrator before the first release. Pre-create Docker network `selfhandler_app`, attach Caddy to
it as an external network, and add this site to `C:\Homelab\Ingress\Caddyfile`:

```caddyfile
selfhandler.drpanya.uk {
    reverse_proxy web:8080
}
```

Validate and reload Caddy after updating its Compose definition. The route may return `502` until the
first SelfHandler web container starts. The router continues to forward only shared TCP `80` and
`443` to `192.168.1.9`; do not expose loopback TCP `18080`, PHP-FPM, or MySQL `3306` on the WAN.
Deployments verify `https://selfhandler.drpanya.uk/api/health` and real cookie/CSRF authentication
after local readiness. They do not mutate Caddy, router, DNS, or Tailscale state.

Google Calendar is optional. To enable it, store `GOOGLE_CALENDAR_CLIENT_ID` and
`GOOGLE_CALENDAR_CLIENT_SECRET` only in the protected production `.env`, and register this exact Google
Cloud Web application redirect URI:

```text
https://selfhandler.drpanya.uk/api/integrations/calendars/google/callback
```

If those two values are empty, the rest of SelfHandler remains available and the Google Calendar provider
reports itself as unconfigured.

## Recovery and break glass

Obtain the encrypted artifact and operator-held `age` identity through separate,
controlled channels. Copy the recovery-kit HMAC key and age identity into an
ACL-protected temporary directory on the controlled recovery host, then point
the validator at the off-host HMAC copy. A disposable drill is the normal path:

```powershell
$env:SELFHANDLER_RECOVERY_HMAC_KEY_PATH = 'C:\RecoveryKit\SelfHandler\backup-hmac.key'
powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File deployment\scripts\recovery-drill.ps1 `
  -BundlePath C:\Recovery\selfhandler-backup.age `
  -IdentityPath C:\RecoveryKit\SelfHandler\selfhandler-age-identity.txt
```

The drill creates a generated `selfhandler-drill-<id>` project and generated
volumes, authenticates and validates the complete bundle before extraction,
restores database/private files, verifies schema and application probes, and
removes only resources carrying that exact generated project label. Production
project/container/network/volume names are denied. Routine drills enforce the
24-hour freshness window and never accept a stale override.

A real restore is break glass. First create and upload a `pre-restore` backup,
then require two explicit target confirmations and its immutable off-host
reference:

```powershell
$env:SELFHANDLER_RECOVERY_HMAC_KEY_PATH = 'C:\RecoveryKit\SelfHandler\backup-hmac.key'
powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File deployment\scripts\restore-production.ps1 `
  -RecoveryMode Production `
  -BundlePath C:\Recovery\selfhandler-backup.age `
  -IdentityPath C:\RecoveryKit\SelfHandler\selfhandler-age-identity.txt `
  -Target selfhandler-production `
  -ConfirmTarget selfhandler-production `
  -SafetyBackupReference "offhost:<immutable-id>:<bundle-id>"
```

`-OriginalHostUnavailable` is the exceptional path when a safety backup is
physically impossible; record the incident and operator authorization before
using it. Production restore validates ciphertext/decryption, HMAC, exact
members, hashes, target, age, paths, source release, and schema before mutating
production. It restores clean database/private-file contents and starts the exact
source image pair. Never improvise volume deletion, partial service rollback, or
schema rollback outside this contract.

The 24-hour target is an inspection/RPO alert, while encrypted artifacts are
retained for at most 30 days. A Production or BootstrapReset bundle older than
24 hours but no older than 720 hours therefore requires the additional exact
operator phrase below; the script records only a non-secret stale-authorization
code. A missing/different phrase is rejected, and any bundle older than 720
hours (or more than five minutes in the future) is always rejected:

```powershell
-ConfirmStaleBackup 'RESTORE selfhandler-production BACKUP OLDER THAN 24 HOURS'
```

If the first-ever deploy fails after migration and there is no previous active
release, normal production restore correctly refuses the null-release baseline.
Use the separate exact-confirmed reset path only after its disposable import,
empty-schema, empty-private-store, HMAC, age, and target checks pass:

```powershell
$env:SELFHANDLER_RECOVERY_HMAC_KEY_PATH = 'C:\RecoveryKit\SelfHandler\backup-hmac.key'
powershell.exe -NoProfile -ExecutionPolicy Bypass `
  -File deployment\scripts\restore-production.ps1 `
  -RecoveryMode BootstrapReset `
  -BundlePath C:\Recovery\selfhandler-bootstrap-baseline.age `
  -IdentityPath C:\RecoveryKit\SelfHandler\selfhandler-age-identity.txt `
  -Target selfhandler-production `
  -ConfirmTarget selfhandler-production `
  -ConfirmBootstrapReset 'RESET selfhandler-production TO EMPTY BOOTSTRAP BASELINE'
```

`BootstrapReset` scans every canonical `state\releases\*.json` record and is
refused on malformed evidence or after any active/successful/rolled-back release.
Existing app/web containers must match exactly one recorded first-bootstrap
`recovery_required` pair. It then removes only those failed-bootstrap containers, restores both stores to the authenticated
empty baseline, proves zero schema objects/controlled records/files, leaves the
database running, keeps `active-release.json` absent, and never starts a dummy or
zero-digest application image. After any drill/restore, clear
`SELFHANDLER_RECOVERY_HMAC_KEY_PATH` and securely remove temporary kit copies.

If automatic paired rollback cannot return both images to health, the release
attempt ends as `recovery_required` and preserves the validated backup reference.
Stop automated releases, retain state/history and logs, run inspection, acquire
the referenced artifact and off-host identity, complete a disposable drill, and
only then enter the confirmed production restore path.
