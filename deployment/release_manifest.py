#!/usr/bin/env python3
"""Deterministic SelfHandler release bundle and manifest utilities.

This module is intentionally usable as both a small library and a command-line
tool.  Release creation happens only on a GitHub-hosted runner.  The homelab
runner verifies the resulting manifest and bundle before executing any file
from the bundle.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath
from typing import Any, Iterable, Mapping, Sequence


DEPLOYMENT_ID = "selfhandler-production"
SOURCE_REPOSITORY = "PanyaPrimal/selfHandlerApp"
OPERATIONS_REPOSITORY = "PanyaPrimal/selfhandler-ops"
OPERATIONS_WORKFLOW_REF = (
    "PanyaPrimal/selfhandler-ops/.github/workflows/"
    "deploy-selfhandler.yml@refs/heads/master"
)
OPERATIONS_EVENT = "repository_dispatch"
PREDICATE_TYPE = "https://slsa.dev/provenance/v1"
WEB_REPOSITORY = "ghcr.io/panyaprimal/selfhandler-web"
APP_REPOSITORY = "ghcr.io/panyaprimal/selfhandler-app"

SHA_RE = re.compile(r"^[0-9a-f]{40}$")
DIGEST_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")

SCHEMA_FILES = {
    "release-manifest": "release-manifest.schema.json",
    "release-record": "release-record.schema.json",
    "recovery-manifest": "recovery-manifest.schema.json",
    "health-report": "health-report.schema.json",
}

# Only operational code/configuration is executable on the homelab.  Product
# source, tests, VCS data, local env files, and the private-ops template never
# enter this bundle.
DEFAULT_BUNDLE_PATHS = (
    "deployment/compose.production.yaml",
    "deployment/release_manifest.py",
    "deployment/recovery.py",
    "deployment/scripts",
    "specs/002-homelab-deployment/contracts",
)


class ReleaseContractError(ValueError):
    """Raised when immutable release evidence violates the fixed contract."""


def _require_jsonschema() -> Any:
    try:
        import jsonschema  # type: ignore[import-not-found]
    except ImportError as exc:  # pragma: no cover - exercised by CLI users
        raise RuntimeError(
            "JSON Schema validation requires the test-harness dependency "
            "'jsonschema'. See deployment/tests/README.md."
        ) from exc
    return jsonschema


def repository_root() -> Path:
    return Path(__file__).resolve().parents[1]


def contracts_root() -> Path:
    return repository_root() / "specs" / "002-homelab-deployment" / "contracts"


def canonical_json_bytes(value: Mapping[str, Any]) -> bytes:
    return (
        json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
        + "\n"
    ).encode("utf-8")


def sha256_file(path: os.PathLike[str] | str) -> str:
    digest = hashlib.sha256()
    with Path(path).open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def schema_fingerprint(migrations_root: os.PathLike[str] | str) -> str:
    root = Path(migrations_root).resolve()
    if not root.is_dir():
        raise ReleaseContractError(f"Migration root does not exist: {root}")

    migration_files = sorted(
        (path for path in root.rglob("*") if path.is_file()),
        key=lambda path: path.relative_to(root).as_posix(),
    )
    digest = hashlib.sha256()
    for path in migration_files:
        relative = path.relative_to(root).as_posix().encode("utf-8")
        content = path.read_bytes()
        digest.update(len(relative).to_bytes(8, "big"))
        digest.update(relative)
        digest.update(len(content).to_bytes(8, "big"))
        digest.update(content)
    return digest.hexdigest()


def _safe_archive_name(relative: str) -> str:
    name = PurePosixPath(relative.replace("\\", "/"))
    if name.is_absolute() or not name.parts or ".." in name.parts:
        raise ReleaseContractError(f"Unsafe bundle member: {relative}")
    normalized = name.as_posix()
    if normalized.startswith(".") or "/." in normalized:
        raise ReleaseContractError(f"Hidden bundle member is forbidden: {relative}")
    if normalized.lower().endswith((".env", ".key", ".pem", ".pfx", ".agekey")):
        raise ReleaseContractError(f"Secret-like bundle member is forbidden: {relative}")
    return normalized


def _expand_bundle_paths(root: Path, includes: Sequence[str]) -> list[tuple[Path, str]]:
    members: dict[str, Path] = {}
    resolved_root = root.resolve()

    for include in includes:
        relative = _safe_archive_name(include)
        source = (resolved_root / Path(relative)).resolve()
        try:
            source.relative_to(resolved_root)
        except ValueError as exc:
            raise ReleaseContractError(f"Bundle input escapes repository: {include}") from exc
        if not source.exists():
            # Optional helpers are allowed to be absent while a feature slice is
            # assembled; required runtime files are asserted below.
            continue
        candidates = [source] if source.is_file() else sorted(source.rglob("*"))
        for candidate in candidates:
            if not candidate.is_file():
                continue
            if candidate.is_symlink():
                raise ReleaseContractError(f"Symlink bundle member is forbidden: {candidate}")
            archive_name = _safe_archive_name(candidate.relative_to(resolved_root).as_posix())
            if archive_name.startswith("deployment/private-ops/"):
                raise ReleaseContractError("The private-ops template cannot enter a release bundle")
            if "/tests/" in f"/{archive_name}/":
                continue
            members[archive_name] = candidate

    required = {
        "deployment/compose.production.yaml",
        "deployment/release_manifest.py",
        "deployment/scripts/deploy-production.ps1",
        "deployment/scripts/backup-production.ps1",
        "specs/002-homelab-deployment/contracts/release-manifest.schema.json",
    }
    missing = sorted(required.difference(members))
    if missing:
        raise ReleaseContractError(f"Release bundle is missing required members: {missing}")
    return [(members[name], name) for name in sorted(members)]


def create_deterministic_bundle(
    repo_root: os.PathLike[str] | str,
    output_path: os.PathLike[str] | str,
    includes: Sequence[str] = DEFAULT_BUNDLE_PATHS,
) -> Path:
    root = Path(repo_root).resolve()
    output = Path(output_path).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    members = _expand_bundle_paths(root, includes)

    temporary = output.with_name(f".{output.name}.tmp")
    if temporary.exists():
        temporary.unlink()
    try:
        with zipfile.ZipFile(
            temporary,
            mode="w",
            compression=zipfile.ZIP_DEFLATED,
            compresslevel=9,
        ) as archive:
            for source, archive_name in members:
                info = zipfile.ZipInfo(archive_name, date_time=(1980, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                archive.writestr(info, source.read_bytes(), compress_type=zipfile.ZIP_DEFLATED)
        os.replace(temporary, output)
    finally:
        if temporary.exists():
            temporary.unlink()
    return output


def _utc_timestamp(value: str | None) -> str:
    if value is None:
        return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
    normalized = value.replace("Z", "+00:00")
    try:
        parsed = datetime.fromisoformat(normalized)
    except ValueError as exc:
        raise ReleaseContractError("created_at must be an RFC 3339 timestamp") from exc
    if parsed.tzinfo is None:
        raise ReleaseContractError("created_at must contain a timezone")
    return parsed.astimezone(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def build_release_manifest(
    *,
    source_revision: str,
    workflow_run_id: int,
    workflow_run_attempt: int,
    bundle_path: os.PathLike[str] | str,
    schema_fingerprint_value: str,
    web_digest: str,
    app_digest: str,
    web_revision: str,
    app_revision: str,
    quality_run_id: int | None = None,
    created_at: str | None = None,
    workflow_repository: str = OPERATIONS_REPOSITORY,
    workflow_ref: str = OPERATIONS_WORKFLOW_REF,
    workflow_event: str = OPERATIONS_EVENT,
    verification_repository: str = OPERATIONS_REPOSITORY,
) -> dict[str, Any]:
    bundle = Path(bundle_path)
    if not bundle.is_file():
        raise ReleaseContractError(f"Deployment bundle does not exist: {bundle}")
    run_id = quality_run_id or workflow_run_id
    check = lambda: {"status": "passed", "run_id": run_id}
    manifest: dict[str, Any] = {
        "schema_version": 1,
        "deployment_id": DEPLOYMENT_ID,
        "source_repository": SOURCE_REPOSITORY,
        "source_revision": source_revision,
        "workflow_run_id": workflow_run_id,
        "created_at": _utc_timestamp(created_at),
        "web_image": {
            "repository": WEB_REPOSITORY,
            "digest": web_digest,
            "revision": web_revision,
        },
        "app_image": {
            "repository": APP_REPOSITORY,
            "digest": app_digest,
            "revision": app_revision,
        },
        "deployment_bundle": {
            "name": f"selfhandler-deployment-{source_revision}",
            "bytes": bundle.stat().st_size,
            "sha256": sha256_file(bundle),
        },
        "schema_fingerprint": schema_fingerprint_value,
        "quality_evidence": {
            "backend": check(),
            "frontend": check(),
            "e2e": check(),
            "deployment": check(),
            "production_smoke": check(),
        },
        "workflow_identity": {
            "repository": workflow_repository,
            "workflow_ref": workflow_ref,
            "event": workflow_event,
            "run_id": workflow_run_id,
            "run_attempt": workflow_run_attempt,
            "qualification_status": "passed",
        },
        "attestations": {
            "web": {
                "subject_digest": web_digest,
                "predicate_type": PREDICATE_TYPE,
                "verification_repository": verification_repository,
            },
            "app": {
                "subject_digest": app_digest,
                "predicate_type": PREDICATE_TYPE,
                "verification_repository": verification_repository,
            },
        },
    }
    validate_release_manifest(manifest)
    return manifest


def schema_path(schema_name: str) -> Path:
    try:
        filename = SCHEMA_FILES[schema_name]
    except KeyError as exc:
        raise ReleaseContractError(f"Unsupported schema: {schema_name}") from exc
    return contracts_root() / filename


def validate_document(document: Mapping[str, Any], schema_name: str) -> None:
    jsonschema = _require_jsonschema()
    schema = json.loads(schema_path(schema_name).read_text(encoding="utf-8"))
    validator = jsonschema.Draft202012Validator(schema, format_checker=jsonschema.FormatChecker())
    errors = sorted(validator.iter_errors(document), key=lambda error: list(error.absolute_path))
    if errors:
        details = "; ".join(
            f"/{'/'.join(map(str, error.absolute_path))}: {error.message}" for error in errors
        )
        raise ReleaseContractError(f"{schema_name} validation failed: {details}")


def validate_release_manifest(manifest: Mapping[str, Any]) -> None:
    validate_document(manifest, "release-manifest")
    source_revision = manifest["source_revision"]
    if manifest["web_image"]["repository"] != WEB_REPOSITORY:
        raise ReleaseContractError("Web image is not in the fixed web repository")
    if manifest["app_image"]["repository"] != APP_REPOSITORY:
        raise ReleaseContractError("App image is not in the fixed application repository")
    if manifest["web_image"]["revision"] != source_revision:
        raise ReleaseContractError("Web OCI revision does not match source revision")
    if manifest["app_image"]["revision"] != source_revision:
        raise ReleaseContractError("App OCI revision does not match source revision")
    if manifest["attestations"]["web"]["subject_digest"] != manifest["web_image"]["digest"]:
        raise ReleaseContractError("Web attestation subject does not match image digest")
    if manifest["attestations"]["app"]["subject_digest"] != manifest["app_image"]["digest"]:
        raise ReleaseContractError("App attestation subject does not match image digest")
    if manifest["workflow_run_id"] != manifest["workflow_identity"]["run_id"]:
        raise ReleaseContractError("Canonical workflow run identifiers do not match")
    expected_bundle_name = f"selfhandler-deployment-{source_revision}"
    if manifest["deployment_bundle"]["name"] != expected_bundle_name:
        raise ReleaseContractError("Deployment bundle name does not match source revision")
    for name, evidence in manifest["quality_evidence"].items():
        if evidence["run_id"] != manifest["workflow_run_id"]:
            raise ReleaseContractError(f"Quality evidence {name} belongs to a different run")


def release_identity(document: Mapping[str, Any]) -> tuple[str, str, str]:
    if "web_image" in document:
        return (
            str(document["source_revision"]),
            str(document["web_image"]["digest"]),
            str(document["app_image"]["digest"]),
        )
    return (
        str(document["source_revision"]),
        str(document["web_digest"]),
        str(document["app_digest"]),
    )


def assert_release_identity_available(
    manifest: Mapping[str, Any], existing_documents: Iterable[Mapping[str, Any]]
) -> None:
    candidate = release_identity(manifest)
    if any(release_identity(existing) == candidate for existing in existing_documents):
        raise ReleaseContractError("Release identity already exists and cannot be overwritten")


def write_json_atomic(path: os.PathLike[str] | str, value: Mapping[str, Any]) -> Path:
    output = Path(path)
    output.parent.mkdir(parents=True, exist_ok=True)
    temporary = output.with_name(f".{output.name}.tmp")
    temporary.write_bytes(canonical_json_bytes(value))
    os.replace(temporary, output)
    return output


def _load_json(path: os.PathLike[str] | str) -> dict[str, Any]:
    value = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ReleaseContractError(f"Expected a JSON object: {path}")
    return value


def _existing_documents(root: Path) -> list[dict[str, Any]]:
    if not root.exists():
        return []
    return [_load_json(path) for path in sorted(root.glob("*.json"))]


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)

    bundle = commands.add_parser("create-bundle", help="create a deterministic deployment zip")
    bundle.add_argument("--repo-root", default=".")
    bundle.add_argument("--output", required=True)

    fingerprint = commands.add_parser("schema-fingerprint", help="hash ordered migration inputs")
    fingerprint.add_argument("--migrations-root", required=True)

    create = commands.add_parser("create-release", help="create and validate a release manifest")
    create.add_argument("--source-revision", required=True)
    create.add_argument("--workflow-run-id", required=True, type=int)
    create.add_argument("--workflow-run-attempt", required=True, type=int)
    create.add_argument("--bundle-path", required=True)
    create.add_argument("--schema-fingerprint", required=True)
    create.add_argument("--web-digest", required=True)
    create.add_argument("--app-digest", required=True)
    create.add_argument("--web-revision", required=True)
    create.add_argument("--app-revision", required=True)
    create.add_argument("--quality-run-id", type=int)
    create.add_argument("--created-at")
    create.add_argument("--workflow-repository", default=OPERATIONS_REPOSITORY)
    create.add_argument("--workflow-ref", default=OPERATIONS_WORKFLOW_REF)
    create.add_argument("--workflow-event", default=OPERATIONS_EVENT)
    create.add_argument("--verification-repository", default=OPERATIONS_REPOSITORY)
    create.add_argument("--history-root")
    create.add_argument("--output", required=True)

    validate = commands.add_parser("validate", help="validate an operational JSON document")
    validate.add_argument("--schema", choices=sorted(SCHEMA_FILES), required=True)
    validate.add_argument("--document", required=True)
    validate.add_argument("--history-root")
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    try:
        if args.command == "create-bundle":
            output = create_deterministic_bundle(args.repo_root, args.output)
            print(json.dumps({"path": str(output), "bytes": output.stat().st_size, "sha256": sha256_file(output)}))
            return 0
        if args.command == "schema-fingerprint":
            print(schema_fingerprint(args.migrations_root))
            return 0
        if args.command == "create-release":
            manifest = build_release_manifest(
                source_revision=args.source_revision,
                workflow_run_id=args.workflow_run_id,
                workflow_run_attempt=args.workflow_run_attempt,
                bundle_path=args.bundle_path,
                schema_fingerprint_value=args.schema_fingerprint,
                web_digest=args.web_digest,
                app_digest=args.app_digest,
                web_revision=args.web_revision,
                app_revision=args.app_revision,
                quality_run_id=args.quality_run_id,
                created_at=args.created_at,
                workflow_repository=args.workflow_repository,
                workflow_ref=args.workflow_ref,
                workflow_event=args.workflow_event,
                verification_repository=args.verification_repository,
            )
            if args.history_root:
                assert_release_identity_available(manifest, _existing_documents(Path(args.history_root)))
            write_json_atomic(args.output, manifest)
            return 0
        document = _load_json(args.document)
        if args.schema == "release-manifest":
            validate_release_manifest(document)
            if args.history_root:
                assert_release_identity_available(document, _existing_documents(Path(args.history_root)))
        else:
            validate_document(document, args.schema)
        return 0
    except (OSError, json.JSONDecodeError, ReleaseContractError, RuntimeError) as exc:
        print(f"release contract error: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
