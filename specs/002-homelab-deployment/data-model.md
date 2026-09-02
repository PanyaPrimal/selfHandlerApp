# Operational Data Model: Homelab Deployment

This feature adds no product-domain tables. Its entities are runtime resources and append-only
operational records. Product data remains in the Laravel schema and continues to be user-owned.

## Production Target

The target is compiled into trusted operations tooling rather than supplied by an operator.

| Field | Value / rule |
|---|---|
| `deployment_id` | Constant `selfhandler-production` |
| `compose_project` | Constant `selfhandler` |
| `root` | Constant `C:\Homelab\SelfHandlerApp` |
| `local_origin` | Constant `http://127.0.0.1:18080` |
| `public_origin` | Constant `https://selfhandler.drpanya.uk` |
| `web_service` | `web`; the only published service |
| `app_service` | `app`; internal PHP-FPM runtime |
| `database_service` | `db`; internal MySQL runtime |
| `database_volume` | `selfhandler_mysql_data` |
| `private_files_volume` | `selfhandler_private_files` |
| `app_network` | `selfhandler_app` |
| `data_network` | `selfhandler_data`, marked internal |
| `protected_env` | `C:\Homelab\SelfHandlerApp\.env` |
| `ops_config` | `C:\Homelab\SelfHandlerApp\ops\config.env`, never passed to Compose |
| `ops_secret_root` | ACL-protected host-only files under `C:\Homelab\SelfHandlerApp\ops\secrets` |
| `state_root` | `C:\Homelab\SelfHandlerApp\state` |

Validation rejects any effective Compose project, volume, network, published port, or deployment ID
that differs from these constants. Validation also rejects any resource labeled as project `dealflow`.

## Release Manifest

One immutable hosted-CI output, validated by
[release-manifest.schema.json](contracts/release-manifest.schema.json).

| Field | Rule |
|---|---|
| `schema_version` | Fixed supported manifest format version |
| `deployment_id` | Must be `selfhandler-production` |
| `source_repository` | Must be the canonical public repository |
| `source_revision` | Full 40-character commit SHA on the reviewed default branch |
| `workflow_run_id` | Positive GitHub Actions run identifier |
| `created_at` | UTC RFC 3339 timestamp |
| `web_image` | GHCR repository plus immutable `sha256` digest |
| `app_image` | GHCR repository plus immutable `sha256` digest |
| `deployment_bundle` | Artifact name, byte size, and SHA-256 checksum |
| `schema_fingerprint` | SHA-256 over sorted production migration identities/content |
| `quality_evidence` | Required named hosted checks and their successful run identity |
| `workflow_identity` | Canonical workflow repository/ref/event/run/attempt verified through GitHub metadata |
| `attestations` | GitHub build-provenance identities for both immutable image subjects |
| `oci_revision` | Exact revision label read back from each pulled image and equal to `source_revision` |

The tuple `(source_revision, web_image.digest, app_image.digest)` is unique. Existing release
identities are never overwritten.

## Release Record

An append-only attempt record stored under the protected production state root.

| Field | Rule |
|---|---|
| `attempt_id` | Unique run-derived identifier |
| `deployment_id` | Fixed target identity |
| `source_revision` | Candidate full SHA |
| `web_digest` / `app_digest` | Candidate immutable pair |
| `previous_release` | Previous paired digests, or `null` only for bootstrap |
| `schema_before` / `schema_after` | Migration-state fingerprints |
| `backup_reference` | Validated off-host artifact reference; bootstrap records its initial recovery point |
| `actor` | Trusted automation/operator identity, never a credential |
| `started_at` / `completed_at` | UTC timestamps; completion is required for terminal states |
| `checks` | Named non-secret gate outcomes |
| `outcome` | State below |
| `restored_release` | Populated when application rollback was attempted |
| `failure_code` | Stable non-secret code; no exception dump or secret value |

### Release state transitions

```text
validating -> qualified
  -> rejected
qualified -> backing_up -> rejected
          -> deploying -> migrating -> failed_before_replace
                       -> replacing -> verifying -> awaiting_completion
                                                   -> completion_validated -> succeeded
                                    \-> rolling_back -> rolled_back
                                                     \-> recovery_required
```

`succeeded`, `rejected`, `failed_before_replace`, `rolled_back`, and `recovery_required` are terminal.
`qualified`, `deploying`, `awaiting_completion`, and `completion_validated` are crash-resumable journal
states rather than canonical terminal Release Records. Only the trusted finalizer may convert
`completion_validated` to `succeeded` and update the active-release pointer.
`rolled_back` leaves the prior release active.

## Prepared Release Journal

Before any fallible backup or production mutation, the trusted workflow copies the verified bundle,
manifest, and trust metadata into an ACL-protected immutable directory keyed by source revision and
writes `prepared-release.json` with state `qualified`. Its manifest, bundle, trust-metadata hashes,
original private workflow run/attempt, workflow revision, actor, and attempt ID must all match. A new
GitHub attempt can adopt this journal without republishing or relying on undocumented previous-job
outputs. An unrelated source revision ignores an orphan preparation only while no production pending
journal exists.

## Pending Release Journal

A verified candidate is persisted under the protected state root before the hosted workflow performs
post-deployment operations. It is found by immutable release identity rather than GitHub run number so
an exact-SHA retry can resume safely after cancellation or runner loss.

| Field | Rule |
|---|---|
| `state` | `deploying`, `awaiting_completion`, or `completion_validated` |
| `attempt_id` | Original attempt identity retained as evidence, not the resume lookup key |
| `source_revision` / `web_digest` / `app_digest` | Exact installed candidate tuple |
| `manifest_sha256` / `bundle_sha256` | Must match the revalidated qualified artifacts |
| `predeploy_backup_reference` | Immutable already-bound recovery reference used before mutation |
| `previous_release` | Exact prior pair, or `null` only for bootstrap |
| `schema_before` / `schema_after` | Verified migration state |
| `bootstrap` | Determines whether a second post-auth recovery point is mandatory |
| `actor` / request evidence | Must match the trusted owner-authenticated request contract |

`deploying` is durable before the first migration/replacement mutation. `awaiting_completion` means
the exact candidate pair, schema, runtime isolation, local/public health, and authentication passed.
`completion_validated` means the required completion recovery point is also uploaded and bound.

For bootstrap, finalization validates the staged operations pointer and the uploaded/bound post-auth
recovery point against this exact candidate. It then updates the active-release pointer and appends the
terminal `succeeded` record atomically, with compensation if either persistence step fails. The pending
journal is removed only after terminal state is durable. A retry with any mismatched revision, digest,
manifest, bundle, actor, backup, or target fails closed. If canonical `master` advances while an older
pending release needs recovery, the next owner-authenticated no-input run completes that original
trusted journal first and reports that the current revision must be launched again.

## Recovery Manifest

The plaintext manifest exists only inside protected staging and the encrypted bundle. Its contract is
[recovery-manifest.schema.json](contracts/recovery-manifest.schema.json).

| Field | Rule |
|---|---|
| `schema_version` | Supported recovery format version |
| `bundle_id` | Unique, non-reusable identifier |
| `deployment_id` | Exact production target identity |
| `created_at` | UTC RFC 3339 timestamp |
| `source_release` | Source SHA and paired image digests active at snapshot time; `null` only for `bootstrap-baseline` |
| `schema_fingerprint` | Migration state represented by the dump |
| `database` | Logical database name, `database.sql`, bytes, SHA-256, controlled probe counts |
| `private_files` | Archive member path, bytes, SHA-256, controlled file count |
| `members` | Exactly `database.sql` and `private-files.tar`, each with bytes and SHA-256 |
| `encryption_recipient_fingerprint` | Public recipient fingerprint only, never a private identity |
| `manifest_authentication` | `HMAC-SHA256`, key identifier, and sidecar path `manifest.hmac`; never the key |
| `backup_reason` | `predeploy`, `scheduled`, `manual`, `pre-restore`, `bootstrap-baseline`, or `bootstrap` |

The encrypted tar contains exactly `manifest.json`, `manifest.hmac`, `database.sql`, and
`private-files.tar`. `manifest.hmac` is lowercase hexadecimal HMAC-SHA256 over the exact UTF-8 bytes
of `manifest.json`. Restore selects the ACL-protected host key by `key_id`, verifies the MAC in constant
time before extracting either payload, then verifies the two allowlisted payload checksums/sizes.

The authenticated manifest and member checksums are verified before extraction. Archive members must
be regular files with normalized relative paths; links, devices, absolute paths, and traversal are
rejected.

## Recovery Bundle

| Field | Rule |
|---|---|
| `ciphertext_path` | Protected staging path ending in `.age` until upload completes |
| `ciphertext_bytes` | Positive and equal to the uploaded artifact metadata |
| `ciphertext_sha256` | Verified before restore |
| `off_host_reference` | Immutable private artifact identifier and retention timestamp |
| `validated_at` | Set after plaintext manifest auth/member validation, successful encryption, and ciphertext size/checksum validation; identity-backed decrypt validation is recorded separately by a recovery drill |
| `expires_at` | Retention metadata; expiration cannot remove the latest required valid bundle |

Plaintext bundle state has only transient `creating` and `validating` phases and must reach `removed`
on both success and failure. A `bootstrap-baseline` bundle has `source_release=null`, an empty schema
fingerprint, an empty database dump, and an empty private archive; it is uploaded before first migration.

## Health Report

An inspection result is generated on demand, validated by
[health-report.schema.json](contracts/health-report.schema.json), and is not authoritative product state.

| Field | Rule |
|---|---|
| `deployment_id` | Fixed non-secret identity |
| `observed_at` | UTC timestamp |
| `active_release` | Source SHA and actual paired container digests |
| `local_readiness` | Status and bounded latency only |
| `public_route` | Status and bounded latency only |
| `database` | Healthy/unhealthy; no server variables or credentials |
| `persistent_stores` | Exact expected Docker volume names and existence status |
| `latest_backup` | Age, validation status, and overdue alert |
| `runtime_isolation` | Non-root, read-only, dropped caps, port, network, and resource-bound checks |
| `capacity` | Non-sensitive free-space/headroom status rather than full host inventory |
| `alerts` | Stable codes with operator action; no secret values |

## Relationships and invariants

- A Production Target has zero or one active Release and many append-only Release Records.
- Every routine deployment Release Record references exactly one validated pre-deployment Recovery
  Bundle completed before its first mutation.
- A Recovery Bundle belongs to exactly one Production Target and snapshots exactly one active Release
  plus its schema and persistent stores.
- A successful or rolled-back Release Record must match the image digests observed in the final Health
  Report.
- Deleting or recreating either production volume is never a release operation.
- Disposable validation and drill targets use generated project/volume names and can never satisfy the
  production target identity check.
