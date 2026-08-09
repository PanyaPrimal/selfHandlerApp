"""Safe recovery-bundle primitives for the fixed SelfHandler production target.

The plaintext tar format is deliberately small and closed: ``manifest.json``,
``manifest.hmac``, ``database.sql``, and ``private-files.tar``.  The manifest
MAC is verified before either payload is read or extracted.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import io
import json
import os
import re
import secrets
import shutil
import tarfile
import tempfile
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path, PurePosixPath
from typing import BinaryIO, Mapping, Sequence


DEPLOYMENT_ID = "selfhandler-production"
EMPTY_SCHEMA_FINGERPRINT = hashlib.sha256(b"").hexdigest()
BUNDLE_MEMBERS = ("manifest.json", "manifest.hmac", "database.sql", "private-files.tar")
PAYLOAD_MEMBERS = ("database.sql", "private-files.tar")
BACKUP_REASONS = {
    "predeploy",
    "scheduled",
    "manual",
    "pre-restore",
    "bootstrap-baseline",
    "bootstrap",
}

_SHA_RE = re.compile(r"^[0-9a-f]{40}$")
_SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
_DIGEST_RE = re.compile(r"^sha256:[0-9a-f]{64}$")
_BUNDLE_ID_RE = re.compile(r"^selfhandler-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8,64}$")
_KEY_ID_RE = re.compile(r"^[A-Za-z0-9._-]{1,64}$")
_MAX_MANIFEST_BYTES = 1024 * 1024
_MAX_HMAC_BYTES = 256


class RecoveryValidationError(ValueError):
    """Raised when recovery data is unsafe, unauthenticated, or out of contract."""


@dataclass(frozen=True)
class ValidatedRecoveryBundle:
    path: Path
    manifest: dict[str, object]
    manifest_sha256: str
    private_file_count: int


def _require(condition: bool, message: str) -> None:
    if not condition:
        raise RecoveryValidationError(message)


def _exact_keys(value: Mapping[str, object], expected: set[str], context: str) -> None:
    actual = set(value)
    _require(actual == expected, f"{context} fields are invalid: {sorted(actual ^ expected)}")


def _parse_datetime(value: object, context: str) -> datetime:
    _require(isinstance(value, str), f"{context} must be an RFC 3339 timestamp.")
    normalized = value[:-1] + "+00:00" if value.endswith("Z") else value
    try:
        parsed = datetime.fromisoformat(normalized)
    except ValueError as exc:
        raise RecoveryValidationError(f"{context} must be an RFC 3339 timestamp.") from exc
    _require(parsed.tzinfo is not None, f"{context} must include a timezone.")
    return parsed.astimezone(timezone.utc)


def _timestamp(value: datetime) -> str:
    _require(value.tzinfo is not None, "created_at must include a timezone.")
    return value.astimezone(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def recipient_fingerprint(recipient: str) -> str:
    normalized = recipient.strip()
    _require(normalized.startswith("age1") and len(normalized) >= 24, "The age recipient is invalid.")
    return hashlib.sha256(normalized.encode("utf-8")).hexdigest()


def serialize_manifest(manifest: Mapping[str, object]) -> bytes:
    """Return deterministic UTF-8 bytes; these exact bytes are authenticated."""

    return json.dumps(
        manifest,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")


def manifest_hmac(manifest_bytes: bytes, key: bytes) -> bytes:
    _require(len(key) >= 32, "The recovery HMAC key must contain at least 32 bytes.")
    return hmac.new(key, manifest_bytes, hashlib.sha256).hexdigest().encode("ascii")


def read_hmac_key(path: Path) -> bytes:
    _require(path.is_file(), "The configured recovery HMAC key file is missing.")
    key = path.read_bytes().rstrip(b"\r\n")
    _require(len(key) >= 32, "The configured recovery HMAC key is too short.")
    return key


def _validate_source_release(value: object) -> None:
    _require(isinstance(value, dict), "source_release must be an object.")
    _exact_keys(value, {"source_revision", "web_digest", "app_digest"}, "source_release")
    _require(bool(_SHA_RE.fullmatch(str(value["source_revision"]))), "source_revision is invalid.")
    for field in ("web_digest", "app_digest"):
        _require(bool(_DIGEST_RE.fullmatch(str(value[field]))), f"source_release.{field} is invalid.")


def _validate_summary(value: object, *, database: bool) -> None:
    context = "database" if database else "private_files"
    _require(isinstance(value, dict), f"{context} must be an object.")
    required = {"path", "bytes", "sha256", "controlled_count"}
    allowed = required | ({"logical_name"} if database else set())
    _require(required <= set(value) <= allowed, f"{context} fields are invalid.")
    expected_path = "database.sql" if database else "private-files.tar"
    _require(value["path"] == expected_path, f"{context}.path is invalid.")
    if database:
        _require(value.get("logical_name") == "selfhandler", "database.logical_name is invalid.")
    _require(isinstance(value["bytes"], int) and value["bytes"] >= 0, f"{context}.bytes is invalid.")
    _require(bool(_SHA256_RE.fullmatch(str(value["sha256"]))), f"{context}.sha256 is invalid.")
    _require(
        isinstance(value["controlled_count"], int) and value["controlled_count"] >= 0,
        f"{context}.controlled_count is invalid.",
    )


def validate_manifest(manifest: object) -> dict[str, object]:
    _require(isinstance(manifest, dict), "Recovery manifest must be a JSON object.")
    expected = {
        "schema_version",
        "bundle_id",
        "deployment_id",
        "created_at",
        "source_release",
        "schema_fingerprint",
        "database",
        "private_files",
        "members",
        "encryption_recipient_fingerprint",
        "manifest_authentication",
        "backup_reason",
    }
    _exact_keys(manifest, expected, "manifest")
    _require(manifest["schema_version"] == 1, "Unsupported recovery manifest schema version.")
    _require(bool(_BUNDLE_ID_RE.fullmatch(str(manifest["bundle_id"]))), "bundle_id is invalid.")
    _require(manifest["deployment_id"] == DEPLOYMENT_ID, "Recovery deployment identity is invalid.")
    _parse_datetime(manifest["created_at"], "created_at")
    reason = manifest["backup_reason"]
    _require(reason in BACKUP_REASONS, "backup_reason is invalid.")
    if reason == "bootstrap-baseline":
        _require(manifest["source_release"] is None, "bootstrap-baseline source_release must be null.")
    else:
        _require(manifest["source_release"] is not None, "source_release is required for this backup reason.")
        _validate_source_release(manifest["source_release"])
    _require(
        bool(_SHA256_RE.fullmatch(str(manifest["schema_fingerprint"]))),
        "schema_fingerprint is invalid.",
    )
    _validate_summary(manifest["database"], database=True)
    _validate_summary(manifest["private_files"], database=False)
    if reason == "bootstrap-baseline":
        _require(
            manifest["schema_fingerprint"] == EMPTY_SCHEMA_FINGERPRINT,
            "bootstrap-baseline schema_fingerprint must describe an empty schema.",
        )
        _require(
            manifest["database"]["controlled_count"] == 0,  # type: ignore[index]
            "bootstrap-baseline database controlled_count must be zero.",
        )
        _require(
            manifest["private_files"]["controlled_count"] == 0,  # type: ignore[index]
            "bootstrap-baseline private_files controlled_count must be zero.",
        )
    fingerprint = manifest["encryption_recipient_fingerprint"]
    _require(isinstance(fingerprint, str) and 8 <= len(fingerprint) <= 128, "Recipient fingerprint is invalid.")

    authentication = manifest["manifest_authentication"]
    _require(isinstance(authentication, dict), "manifest_authentication must be an object.")
    _exact_keys(authentication, {"algorithm", "key_id", "sidecar"}, "manifest_authentication")
    _require(authentication["algorithm"] == "HMAC-SHA256", "Manifest authentication algorithm is invalid.")
    _require(bool(_KEY_ID_RE.fullmatch(str(authentication["key_id"]))), "Manifest key_id is invalid.")
    _require(authentication["sidecar"] == "manifest.hmac", "Manifest sidecar is invalid.")

    members = manifest["members"]
    _require(isinstance(members, list) and len(members) == 2, "Manifest must allowlist exactly two payload members.")
    summaries: dict[str, dict[str, object]] = {}
    for item in members:
        _require(isinstance(item, dict), "Manifest member must be an object.")
        _exact_keys(item, {"path", "type", "bytes", "sha256"}, "manifest member")
        path = item["path"]
        _require(path in PAYLOAD_MEMBERS and path not in summaries, "Manifest payload allowlist is invalid.")
        _require(item["type"] == "file", "Manifest payload type is invalid.")
        _require(isinstance(item["bytes"], int) and item["bytes"] >= 0, "Manifest payload size is invalid.")
        _require(bool(_SHA256_RE.fullmatch(str(item["sha256"]))), "Manifest payload checksum is invalid.")
        summaries[str(path)] = item
    _require(set(summaries) == set(PAYLOAD_MEMBERS), "Manifest payload allowlist is incomplete.")
    for path, summary_name in (("database.sql", "database"), ("private-files.tar", "private_files")):
        summary = manifest[summary_name]
        _require(isinstance(summary, dict), f"{summary_name} summary is invalid.")
        _require(
            summary["bytes"] == summaries[path]["bytes"] and summary["sha256"] == summaries[path]["sha256"],
            f"{path} member metadata does not match its summary.",
        )
    return manifest


def _safe_member_name(name: str, context: str) -> None:
    _require(bool(name) and "\\" not in name and "\x00" not in name, f"Unsafe {context} member path.")
    path = PurePosixPath(name)
    _require(not path.is_absolute(), f"Unsafe {context} absolute member path.")
    _require(all(part not in {"", ".", ".."} for part in path.parts), f"Unsafe {context} traversal path.")


def validate_private_files_archive(source: Path | BinaryIO) -> int:
    """Validate a private-file tar and return its regular-file count."""

    try:
        if isinstance(source, Path):
            archive_context = tarfile.open(source, "r:*")
        else:
            archive_context = tarfile.open(fileobj=source, mode="r|*")
        with archive_context as archive:
            count = 0
            for member in archive:
                normalized_name = member.name
                if normalized_name in {".", "./"}:
                    _require(member.isdir(), "Unsafe private-files archive root member type.")
                    continue
                while normalized_name.startswith("./"):
                    normalized_name = normalized_name[2:]
                _safe_member_name(normalized_name, "private-files")
                _require(member.isfile() or member.isdir(), "Unsafe private-files archive member type.")
                if member.isfile():
                    count += 1
            return count
    except (tarfile.TarError, OSError) as exc:
        raise RecoveryValidationError("The private-files archive is invalid.") from exc


def build_recovery_manifest(
    *,
    database_path: Path,
    private_files_path: Path,
    source_release: Mapping[str, str] | None,
    schema_fingerprint: str,
    encryption_recipient_fingerprint: str,
    key_id: str,
    backup_reason: str,
    database_controlled_count: int,
    private_controlled_count: int,
    created_at: datetime | None = None,
    bundle_id: str | None = None,
) -> dict[str, object]:
    _require(database_path.is_file(), "database.sql is missing.")
    _require(private_files_path.is_file(), "private-files.tar is missing.")
    actual_private_count = validate_private_files_archive(private_files_path)
    _require(
        actual_private_count == private_controlled_count,
        "private_files controlled_count does not match the archive.",
    )
    created = created_at or datetime.now(timezone.utc)
    timestamp = created.astimezone(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    selected_bundle_id = bundle_id or f"selfhandler-{timestamp}-{secrets.token_hex(4)}"
    database_summary = {
        "path": "database.sql",
        "logical_name": "selfhandler",
        "bytes": database_path.stat().st_size,
        "sha256": sha256_file(database_path),
        "controlled_count": database_controlled_count,
    }
    private_summary = {
        "path": "private-files.tar",
        "bytes": private_files_path.stat().st_size,
        "sha256": sha256_file(private_files_path),
        "controlled_count": private_controlled_count,
    }
    manifest: dict[str, object] = {
        "schema_version": 1,
        "bundle_id": selected_bundle_id,
        "deployment_id": DEPLOYMENT_ID,
        "created_at": _timestamp(created),
        "source_release": dict(source_release) if source_release is not None else None,
        "schema_fingerprint": schema_fingerprint,
        "database": database_summary,
        "private_files": private_summary,
        "members": [
            {
                "path": "database.sql",
                "type": "file",
                "bytes": database_summary["bytes"],
                "sha256": database_summary["sha256"],
            },
            {
                "path": "private-files.tar",
                "type": "file",
                "bytes": private_summary["bytes"],
                "sha256": private_summary["sha256"],
            },
        ],
        "encryption_recipient_fingerprint": encryption_recipient_fingerprint,
        "manifest_authentication": {
            "algorithm": "HMAC-SHA256",
            "key_id": key_id,
            "sidecar": "manifest.hmac",
        },
        "backup_reason": backup_reason,
    }
    return validate_manifest(manifest)


def _tar_info(name: str, size: int) -> tarfile.TarInfo:
    info = tarfile.TarInfo(name)
    info.size = size
    info.mode = 0o600
    info.mtime = 0
    info.uid = 0
    info.gid = 0
    info.uname = ""
    info.gname = ""
    return info


def write_plaintext_bundle(
    target: Path,
    manifest: Mapping[str, object],
    key: bytes,
    database_path: Path,
    private_files_path: Path,
) -> None:
    validated = validate_manifest(dict(manifest))
    manifest_bytes = serialize_manifest(validated)
    sidecar = manifest_hmac(manifest_bytes, key)
    _require(database_path.stat().st_size == validated["database"]["bytes"], "database.sql size changed.")  # type: ignore[index]
    _require(sha256_file(database_path) == validated["database"]["sha256"], "database.sql checksum changed.")  # type: ignore[index]
    _require(private_files_path.stat().st_size == validated["private_files"]["bytes"], "private-files.tar size changed.")  # type: ignore[index]
    _require(sha256_file(private_files_path) == validated["private_files"]["sha256"], "private-files.tar checksum changed.")  # type: ignore[index]
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = target.with_name(f".{target.name}.{secrets.token_hex(8)}.partial")
    try:
        with tarfile.open(temporary, "w", format=tarfile.PAX_FORMAT) as archive:
            for name, value in (("manifest.json", manifest_bytes), ("manifest.hmac", sidecar)):
                archive.addfile(_tar_info(name, len(value)), io.BytesIO(value))
            for name, path in (("database.sql", database_path), ("private-files.tar", private_files_path)):
                with path.open("rb") as stream:
                    archive.addfile(_tar_info(name, path.stat().st_size), stream)
        os.replace(temporary, target)
    finally:
        temporary.unlink(missing_ok=True)


def _open_exact_bundle(path: Path) -> tuple[tarfile.TarFile, dict[str, tarfile.TarInfo]]:
    _require(path.is_file(), "Recovery plaintext bundle was not found.")
    try:
        archive = tarfile.open(path, "r:*")
    except (tarfile.TarError, OSError) as exc:
        raise RecoveryValidationError("Recovery plaintext bundle is not a valid tar archive.") from exc
    members = archive.getmembers()
    names = [member.name for member in members]
    try:
        _require(names == list(BUNDLE_MEMBERS), "Recovery tar must contain exactly the four allowlisted members.")
        _require(len(set(names)) == len(names), "Recovery tar contains duplicate members.")
        for member in members:
            _safe_member_name(member.name, "recovery")
            _require(member.isfile(), "Recovery tar members must be regular files.")
    except Exception:
        archive.close()
        raise
    return archive, dict(zip(names, members, strict=True))


def _read_bounded(archive: tarfile.TarFile, member: tarfile.TarInfo, limit: int, context: str) -> bytes:
    _require(member.size <= limit, f"{context} exceeds the allowed size.")
    stream = archive.extractfile(member)
    _require(stream is not None, f"{context} could not be read.")
    data = stream.read(limit + 1)
    _require(len(data) == member.size and len(data) <= limit, f"{context} size is invalid.")
    return data


def _verify_payload(
    archive: tarfile.TarFile,
    member: tarfile.TarInfo,
    expected: Mapping[str, object],
) -> None:
    _require(member.size == expected["bytes"], f"{member.name} size does not match the manifest.")
    stream = archive.extractfile(member)
    _require(stream is not None, f"{member.name} could not be read.")
    digest = hashlib.sha256()
    size = 0
    for chunk in iter(lambda: stream.read(1024 * 1024), b""):
        digest.update(chunk)
        size += len(chunk)
    _require(size == expected["bytes"], f"{member.name} size does not match the manifest.")
    _require(digest.hexdigest() == expected["sha256"], f"{member.name} checksum does not match the manifest.")


def validate_plaintext_bundle(
    path: Path,
    key: bytes,
    *,
    expected_deployment_id: str = DEPLOYMENT_ID,
    max_age_hours: float | None = None,
    now: datetime | None = None,
) -> ValidatedRecoveryBundle:
    archive, members = _open_exact_bundle(path)
    try:
        manifest_bytes = _read_bounded(archive, members["manifest.json"], _MAX_MANIFEST_BYTES, "manifest.json")
        sidecar = _read_bounded(archive, members["manifest.hmac"], _MAX_HMAC_BYTES, "manifest.hmac")
        expected_hmac = manifest_hmac(manifest_bytes, key)
        _require(
            len(sidecar) == 64 and hmac.compare_digest(sidecar, expected_hmac),
            "Recovery manifest authentication failed.",
        )
        try:
            manifest_object = json.loads(manifest_bytes.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise RecoveryValidationError("Recovery manifest JSON is invalid.") from exc
        manifest = validate_manifest(manifest_object)
        _require(manifest["deployment_id"] == expected_deployment_id, "Recovery bundle belongs to another deployment.")
        created_at = _parse_datetime(manifest["created_at"], "created_at")
        observed = (now or datetime.now(timezone.utc)).astimezone(timezone.utc)
        _require(created_at <= observed + timedelta(minutes=5), "Recovery bundle timestamp is in the future.")
        if max_age_hours is not None:
            age_hours = (observed - created_at).total_seconds() / 3600
            _require(age_hours <= max_age_hours, "Recovery bundle is older than the allowed recovery window.")
        summaries = {item["path"]: item for item in manifest["members"]}  # type: ignore[index]
        for name in PAYLOAD_MEMBERS:
            _verify_payload(archive, members[name], summaries[name])
    finally:
        archive.close()

    # Re-open only after authentication and payload checksum validation.
    with tarfile.open(path, "r:*") as archive:
        private_stream = archive.extractfile("private-files.tar")
        _require(private_stream is not None, "private-files.tar could not be read.")
        private_count = validate_private_files_archive(private_stream)
        if manifest["backup_reason"] == "bootstrap-baseline":
            baseline_stream = archive.extractfile("private-files.tar")
            _require(baseline_stream is not None, "private-files.tar could not be read.")
            with tarfile.open(fileobj=baseline_stream, mode="r:*") as baseline_archive:
                _require(
                    len(baseline_archive.getmembers()) == 0,
                    "bootstrap-baseline private-files.tar must contain no entries.",
                )
    _require(
        private_count == manifest["private_files"]["controlled_count"],  # type: ignore[index]
        "private-files.tar controlled file count does not match the manifest.",
    )
    return ValidatedRecoveryBundle(
        path=path.resolve(),
        manifest=manifest,
        manifest_sha256=hashlib.sha256(manifest_bytes).hexdigest(),
        private_file_count=private_count,
    )


def extract_validated_bundle(
    path: Path,
    destination: Path,
    key: bytes,
    *,
    expected_deployment_id: str = DEPLOYMENT_ID,
    max_age_hours: float | None = None,
) -> ValidatedRecoveryBundle:
    validated = validate_plaintext_bundle(
        path,
        key,
        expected_deployment_id=expected_deployment_id,
        max_age_hours=max_age_hours,
    )
    if destination.exists():
        _require(not any(destination.iterdir()), "Recovery extraction destination must be empty.")
    destination.parent.mkdir(parents=True, exist_ok=True)
    staging = Path(tempfile.mkdtemp(prefix=f".{destination.name}-", dir=destination.parent))
    try:
        with tarfile.open(path, "r:*") as archive:
            for name in PAYLOAD_MEMBERS:
                source = archive.extractfile(name)
                _require(source is not None, f"{name} could not be extracted.")
                with (staging / name).open("xb") as target:
                    shutil.copyfileobj(source, target, length=1024 * 1024)
        if destination.exists():
            destination.rmdir()
        os.replace(staging, destination)
    finally:
        if staging.exists():
            shutil.rmtree(staging, ignore_errors=True)
    return validated


def _source_release_from_file(path: Path | None) -> Mapping[str, str] | None:
    if path is None:
        return None
    value = json.loads(path.read_text(encoding="utf-8"))
    _validate_source_release(value)
    return value


def _build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Create and validate SelfHandler recovery bundles.")
    subparsers = parser.add_subparsers(dest="command", required=True)

    create = subparsers.add_parser("create")
    create.add_argument("--database", type=Path, required=True)
    create.add_argument("--private-archive", type=Path, required=True)
    create.add_argument("--output", type=Path, required=True)
    create.add_argument("--hmac-key-file", type=Path, required=True)
    create.add_argument("--key-id", required=True)
    create.add_argument("--recipient-fingerprint", required=True)
    create.add_argument("--reason", choices=sorted(BACKUP_REASONS), required=True)
    create.add_argument("--schema-fingerprint", required=True)
    create.add_argument("--source-release-json", type=Path)
    create.add_argument("--database-count", type=int, default=0)
    create.add_argument("--private-count", type=int, required=True)
    create.add_argument("--bundle-id")

    validate = subparsers.add_parser("validate")
    validate.add_argument("--bundle", type=Path, required=True)
    validate.add_argument("--hmac-key-file", type=Path, required=True)
    validate.add_argument("--max-age-hours", type=float)
    validate.add_argument("--extract-to", type=Path)

    private = subparsers.add_parser("validate-private")
    private.add_argument("--archive", type=Path, required=True)

    empty_private = subparsers.add_parser("create-empty-private")
    empty_private.add_argument("--output", type=Path, required=True)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    args = _build_parser().parse_args(argv)
    if args.command == "create":
        source_release = _source_release_from_file(args.source_release_json)
        if args.reason != "bootstrap-baseline" and source_release is None:
            raise RecoveryValidationError("--source-release-json is required for this backup reason.")
        manifest = build_recovery_manifest(
            database_path=args.database,
            private_files_path=args.private_archive,
            source_release=source_release,
            schema_fingerprint=args.schema_fingerprint,
            encryption_recipient_fingerprint=args.recipient_fingerprint,
            key_id=args.key_id,
            backup_reason=args.reason,
            database_controlled_count=args.database_count,
            private_controlled_count=args.private_count,
            bundle_id=args.bundle_id,
        )
        write_plaintext_bundle(
            args.output,
            manifest,
            read_hmac_key(args.hmac_key_file),
            args.database,
            args.private_archive,
        )
        print(json.dumps({"bundle_id": manifest["bundle_id"], "plaintext_validated": True}, separators=(",", ":")))
        return 0
    if args.command == "validate":
        key = read_hmac_key(args.hmac_key_file)
        if args.extract_to:
            result = extract_validated_bundle(
                args.bundle,
                args.extract_to,
                key,
                max_age_hours=args.max_age_hours,
            )
        else:
            result = validate_plaintext_bundle(args.bundle, key, max_age_hours=args.max_age_hours)
        print(
            json.dumps(
                {
                    "bundle_id": result.manifest["bundle_id"],
                    "manifest_sha256": result.manifest_sha256,
                    "private_file_count": result.private_file_count,
                    "validated": True,
                },
                separators=(",", ":"),
            )
        )
        return 0
    if args.command == "validate-private":
        count = validate_private_files_archive(args.archive)
        print(json.dumps({"private_file_count": count, "validated": True}, separators=(",", ":")))
        return 0
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with tarfile.open(args.output, "w", format=tarfile.PAX_FORMAT):
        pass
    print(json.dumps({"private_file_count": 0, "created": True}, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
