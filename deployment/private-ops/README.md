# Private SelfHandler operations repository template

Copy this directory's contents to the root of the **private** repository
`PanyaPrimal/selfhandler-ops`. Never attach a self-hosted runner to the public
`PanyaPrimal/selfHandlerApp` repository.

The operator entry point is the input-free developer command `./deploy.ps1`. It requires a clean local
public `master` synchronized with `origin/master` and sends that exact 40-character revision through
an authenticated `repository_dispatch` payload. The private workflow validates the owner sender,
canonical repository, exact three-field payload (`request_id`, repository, revision), and current
public `master`. After the protected-state resolver, a no-secret hosted Windows job checks out only
that authenticated SHA, proves Windows PowerShell 5.1, and runs the complete deployment contract
suite; qualification requires its success. The generated request id only correlates the launcher with
its unique workflow run; it is not a deployment choice. Its
read-only hosted qualification job checks out only that authenticated revision and runs every
application/deployment gate. A separate privileged hosted job rechecks `master`, then
builds and loads both images before registry authentication. Only trusted inline push/readback steps
and pinned attestation actions run while its GHCR credential is active. A final unprivileged hosted
job reproduces the qualified bundle, creates the manifest, and
uploads it in the same private workflow run. No mutable public reusable workflow participates in this
path. The homelab job checks out no repository. It downloads only that run's artifact, validates
canonical run metadata and the bundle SHA-256, verifies both image attestations against the exact
private workflow revision plus OCI revision labels, uploads an encrypted recovery point, then invokes
the fixed-target deployment entry point. Before mutation it atomically installs a protected
`prepared-release.json` with the bundle and original signer/run identity. Deployment journals
`deploying`, `awaiting_completion`, and `completion_validated`; only the finalizer can write the active
release and terminal `succeeded` record after the required off-host completion backup is durable.
The same command automatically retries failed jobs once, and a later dispatch adopts either the
protected prepared release for its requested SHA or the one globally pending journal without
republishing or trusting the new run. If public `master` has advanced, the owner-authenticated
dispatch finishes the older release under its original immutable trust record, then fails with
`pending_release_completed_retry_current`; run `./deploy.ps1` again to qualify and deploy the now
current SHA.

## Repository controls

- Visibility is private; default branch is `master`; the repository is owner-only with no
  collaborators. Default workflow permissions are read-only and the Actions policy allows only the
  reviewed SHA-pinned actions used by these templates.
- Workflow permissions permit the hosted qualification job to write owner GHCR packages and
  attestations. Other jobs use the narrower permissions declared in each workflow.
- Only the owner credential used by `deploy.ps1` may create the `deploy-selfhandler`
  `repository_dispatch`. The workflow rejects another sender, repository, event type, mutable ref, or
  malformed/moved revision. No production secret is carried in the dispatch payload.
- GitHub Free does not provide enforceable required-reviewer protection for this private repository.
  That is an explicit residual risk: the owner pushes workflow/pin changes only from a clean reviewed
  template and re-runs the contract suite; the workflow independently verifies the authenticated
  dispatch and exact public revision before every privileged/host phase.
- GitHub Environments are not used because deployment protection for a private repository is not
  available on the selected GitHub Free plan. No cross-repository PAT or repository secret is needed;
  production credentials remain host files and registry operations use the automatic job token only
  in their dedicated steps.
- Artifact retention is seven days for release bundles, 30 days for encrypted backups, and seven
  days for non-secret inspection reports.
- `deploy-selfhandler.yml`, `backup-selfhandler.yml`, and any future restore workflow share the
  repository concurrency group `selfhandler-production-operations`; host scripts additionally use
  `C:\Homelab\.locks\selfhandler-production.lock` because GitHub concurrency is repository-scoped.

## One-time runner bootstrap

Create a dedicated non-administrator SelfHandler Windows account. Install its runner under a separate
root and Windows service, and register it only to `PanyaPrimal/selfhandler-ops` with the SelfHandler
label set. Never reuse the DealFlow OS account, runner root/service, or a registration attached to a
public/other-owner repository: SelfHandler host-only HMAC, probe credential, and state ACLs allow only
the dedicated SelfHandler SID, Administrators, and SYSTEM. Read/write access to
`C:\Homelab\SelfHandlerApp` is required; interactive desktop and unrelated homelab directories are
not. The SelfHandler host lock serializes only SelfHandler operations; DealFlow has not adopted it.

Both service accounts currently require membership in `docker-users` on the same Docker Engine. That
makes each account daemon-administrator: either can inspect or mount the other stack's containers and
volumes despite filesystem ACLs. This is a documented shared-daemon residual risk, not absolute
cross-stack secrecy. Strict isolation requires a separate Docker daemon or VM for SelfHandler.

The reviewed runner archive is:

```text
version: 2.336.0
url: https://github.com/actions/runner/releases/download/v2.336.0/actions-runner-win-x64-2.336.0.zip
sha256: d59123a43003e357b0805b5d0f611d0bd2f65ab67d51bd070dd4e7a0f685c162
install root: C:\Homelab\Runners\SelfHandler
labels: self-hosted,Windows,X64,homelab,selfhandler
repository: PanyaPrimal/selfhandler-ops
```

Download the archive to a new staging directory, run `Get-FileHash -Algorithm SHA256`, compare the
lowercase digest exactly, and only then expand it. Obtain the one-time registration token from the
private repository's runner settings and register the service under the dedicated account. Never put
the token in a script, shell history, issue, artifact, or repository file. Verify the resulting runner
shows all five labels and is scoped only to `selfhandler-ops` before enabling its workflows.

The workflows do not assume a preinstalled Python, `age`, or GitHub CLI. They provision these
official portable archives in the runner's temporary directory and execute them only after comparing
their immutable SHA-256 values:

| Tool | Archive | SHA-256 |
|---|---|---|
| Python 3.12.10 embedded x64 | `python-3.12.10-embed-amd64.zip` | `4acbed6dd1c744b0376e3b1cf57ce906f9dc9e95e68824584c8099a63025a3c3` |
| age 1.3.1 Windows amd64 | `age-v1.3.1-windows-amd64.zip` | `c56e8ce22f7e80cb85ad946cc82d198767b056366201d3e1a2b93d865be38154` |
| GitHub CLI 2.97.0 Windows amd64 | `gh_2.97.0_windows_amd64.zip` | `35d7fe05c4dd1411ffda1e73dfc7c6f44b75c936ca51fa6595c657fdc0350cec` |

Updating any tool, GitHub Action, or workflow trust reference is a reviewed release change: verify
the upstream release/signature, replace both version and checksum/pin, and run the deployment contract
suite before merge.

## Fixed host configuration

Create these ACL-protected locations as the dedicated runner account; application containers receive
only the production `.env`, never the `ops` tree:

```text
C:\Homelab\SelfHandlerApp\.env
C:\Homelab\SelfHandlerApp\ops\config.env
C:\Homelab\SelfHandlerApp\ops\secrets\backup-hmac.key
C:\Homelab\SelfHandlerApp\ops\secrets\probe-account-password.txt
C:\Homelab\SelfHandlerApp\state
C:\Homelab\SelfHandlerApp\staging
C:\Homelab\.locks
```

`ops\config.env` contains non-application operational values such as the age recipient, its
fingerprint, key id/path, and probe-account references. The backup HMAC key is a random host-only file
readable by the runner account. The age decryption identity remains outside both GitHub and the homelab. Probe-account
credentials live only in private operations secrets and are never injected into application
containers or release/backup artifacts.

The required application settings include the public HTTPS origin, production mode, database-backed
sessions/cache, `SESSION_COOKIE=selfhandler_session`, Secure/HttpOnly/Lax cookie settings, and the
exact `selfhandler.drpanya.uk` Sanctum stateful domain. Production seeding is forbidden. Caddy is
attached to external Docker network `selfhandler_app` and proxies `selfhandler.drpanya.uk` to
`web:8080`; the runner verifies this route but does not mutate shared ingress.

## Workflow contract

- Application qualification, image builds, bundle creation, and their source checkouts run on
  `ubuntu-24.04` GitHub-hosted runners.
- Public deployment contracts run separately on hosted `windows-2025`, pin Python 3.14.3 setup by
  action SHA, and fail unless their command shell is Windows PowerShell 5.1. Every fresh private
  qualification also runs a no-secret `windows-2025` job against the authenticated exact SHA and
  cannot begin until that complete native Windows suite succeeds.
- The deploy workflow has no host, port, project, ref, target, profile, or mode operator inputs. The
  input-free launcher derives the revision only from its clean, synchronized public `master`; the
  private workflow accepts it only as an authenticated fixed-shape dispatch payload and repeatedly
  verifies it is still canonical public `master`.
- A homelab job may run only inline private-workflow checks, immutable-SHA official actions, and code
  extracted from a SHA-256-verified qualified bundle. It never uses `actions/checkout`.
- Image references use `ghcr.io/panyaprimal/selfhandler-web@sha256:...` and
  `ghcr.io/panyaprimal/selfhandler-app@sha256:...`; tags are qualification identities only and are
  never deployed. Each publish uses a never-reused `<source-sha>-<private-run-id>-<run-attempt>` tag,
  so a crash after only one image push leaves a harmless orphan and the retry cannot overwrite it.
- Migration/replacement cannot start until `actions/upload-artifact` returns the immutable off-host
  artifact id/url for the validated encrypted pre-mutation backup.
- The protected prepared-release record is written atomically with the exact bundle, manifest, and
  original workflow signer identity before the first backup/deploy script runs. A rerun may consume a
  manifest from an earlier attempt of the same run; it must retain that original run attempt. A fresh
  dispatch of the same still-canonical SHA adopts the prepared record even when no pending deployment
  journal exists. A prepared record has caused no production mutation and is revision-scoped, so an
  older prepared SHA does not block qualification/staging of a newer authenticated canonical SHA;
  a real pending journal remains the global completion gate.
- After a successful deployment, the exact bundle and manifest remain under
  `C:\Homelab\SelfHandlerApp\state\qualified-bundles\<source-sha>` and selected through the atomic
  schema-v2 `active-operations.json` pointer. Scheduled backup and inspection recheck owner, protected
  ACL, safe path type, manifest/trust hashes, original private signer, and bundle checksum before every
  execution.
- Advancing public `master` while a release is pending does not strand the older release. The next
  owner-authenticated dispatch still validates the current requested `master`, discovers exactly one
  ACL-protected pending journal, and completes that original immutable release without qualifying or
  publishing from the newer source. It then emits `pending_release_completed_retry_current` and
  intentionally fails without further mutation. Run the same input-free launcher again to deploy the
  current SHA; no force-reset, mutable ref, or recovery input is accepted.
- The daily backup workflow runs at 02:17 UTC, keeps ciphertext for 30 days, and writes only non-secret
  freshness/reference evidence to `state\latest-backup.json`.

The developer-side `deploy.ps1` sends the authenticated exact-revision dispatch and waits for its
matching run. It accepts no parameters and does not perform a direct developer-to-homelab deployment.
It also uses the pinned
GitHub CLI archive above: the archive is cached only under the repository's ignored `_tmp` tree,
verified on every launch, and expanded to a new temporary directory. If `GH_TOKEN` is absent, the
launcher reads the existing GitHub credential through `git credential fill`, keeps it process-scoped,
never prints it, and clears/restores the environment in `finally`. Prefer setting a dedicated
fine-grained `GH_TOKEN` limited to `PanyaPrimal/selfhandler-ops` with Metadata read, Actions read, and
Contents write (required for `repository_dispatch`). The GCM fallback intentionally exposes the
developer's broader GitHub credential only to the clean, synchronized, exact-master launcher process;
that broader credential is a documented workstation residual.
