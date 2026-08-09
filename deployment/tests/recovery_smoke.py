from __future__ import annotations

import argparse
import json
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path

REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
if str(REPOSITORY_ROOT) not in sys.path:
    sys.path.insert(0, str(REPOSITORY_ROOT))

from deployment import recovery


MYSQL_IMAGE = "mysql:8.4.11@sha256:b3b90af2a6552ae30c266fdb7d5dd55f3afb72404bb78d37fe8a23eb857fd3fb"
SMOKE_LABEL = "selfhandler.recovery-smoke"


def _run(command: list[str], *, input_bytes: bytes | None = None, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    try:
        result = subprocess.run(
            command,
            input=input_bytes,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=300,
        )
    except subprocess.TimeoutExpired as exc:
        raise RuntimeError("Disposable recovery command exceeded the 300-second bound.") from exc
    if check and result.returncode != 0:
        detail = result.stderr.decode("utf-8", errors="replace").strip()
        detail = re.sub(r"(?i)(password|secret|token)(\s*[:=]\s*)\S+", r"\1\2[REDACTED]", detail)
        raise RuntimeError(
            f"Disposable recovery command failed (exit {result.returncode}): {detail[:500]}"
        )
    return result


def _docker(docker: str, *arguments: str, input_bytes: bytes | None = None, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return _run([docker, *arguments], input_bytes=input_bytes, check=check)


def _production_snapshot(docker: str) -> tuple[tuple[str, ...], tuple[str, ...]]:
    containers: list[str] = []
    for project in ("selfhandler", "dealflow"):
        result = _docker(
            docker,
            "ps",
            "-a",
            "--filter",
            f"label=com.docker.compose.project={project}",
            "--format",
            "{{.ID}}:{{.Names}}",
        )
        containers.extend(result.stdout.decode("utf-8").splitlines())
    volumes = _docker(docker, "volume", "ls", "--format", "{{.Name}}").stdout.decode("utf-8").splitlines()
    protected_volumes = [
        value for value in volumes if value.startswith("selfhandler_") or value.startswith("dealflow_")
    ]
    return tuple(sorted(containers)), tuple(sorted(protected_volumes))


def _wait_mysql(docker: str, container: str) -> None:
    deadline = time.monotonic() + 120
    while time.monotonic() < deadline:
        result = _docker(
            docker,
            "exec",
            container,
            "sh",
            "-c",
            'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; mysql --batch --skip-column-names -uroot -e "SELECT 1" >/dev/null',
            check=False,
        )
        if result.returncode == 0:
            return
        time.sleep(1)
    raise RuntimeError("Disposable MySQL did not become ready.")


def _assert_label_then_remove_container(docker: str, container: str, smoke_id: str) -> None:
    observed = _docker(
        docker,
        "inspect",
        "--format",
        '{{ index .Config.Labels "selfhandler.recovery-smoke" }}',
        container,
    ).stdout.decode("utf-8").strip()
    if observed != smoke_id:
        raise RuntimeError("Refusing cleanup of a container outside the generated recovery smoke.")
    _docker(docker, "rm", "--force", container)


def _assert_label_then_remove_volume(docker: str, volume: str, smoke_id: str) -> None:
    observed = _docker(
        docker,
        "volume",
        "inspect",
        "--format",
        '{{ index .Labels "selfhandler.recovery-smoke" }}',
        volume,
    ).stdout.decode("utf-8").strip()
    if observed != smoke_id:
        raise RuntimeError("Refusing cleanup of a volume outside the generated recovery smoke.")
    _docker(docker, "volume", "rm", volume)


def _start_mysql(
    docker: str,
    *,
    container: str,
    volume: str,
    env_file: Path,
    smoke_id: str,
) -> None:
    _docker(docker, "volume", "create", "--label", f"{SMOKE_LABEL}={smoke_id}", volume)
    _docker(
        docker,
        "run",
        "-d",
        "--name",
        container,
        "--label",
        f"{SMOKE_LABEL}={smoke_id}",
        "--network",
        "none",
        "--env-file",
        str(env_file),
        "--mount",
        f"type=volume,source={volume},target=/var/lib/mysql",
        MYSQL_IMAGE,
        "--skip-name-resolve",
    )
    _wait_mysql(docker, container)


def run_smoke(age: str, age_keygen: str, docker: str) -> dict[str, object]:
    started = time.monotonic()
    smoke_id = uuid.uuid4().hex[:12]
    prefix = f"selfhandler-recovery-smoke-{smoke_id}"
    source_container = f"{prefix}-source-db"
    restored_container = f"{prefix}-restored-db"
    source_database_volume = f"{prefix}-source-mysql"
    restored_database_volume = f"{prefix}-restored-mysql"
    source_private_volume = f"{prefix}-source-private"
    restored_private_volume = f"{prefix}-restored-private"
    containers: list[str] = []
    volumes: list[str] = []
    before_production = _production_snapshot(docker)

    with tempfile.TemporaryDirectory(prefix="selfhandler-recovery-smoke-") as temporary:
        root = Path(temporary)
        mysql_env = root / "mysql.env"
        mysql_env.write_text(
            f"MYSQL_ROOT_PASSWORD={secrets.token_urlsafe(36)}\nMYSQL_DATABASE=selfhandler\n",
            encoding="utf-8",
        )
        try:
            _docker(docker, "image", "inspect", MYSQL_IMAGE)
            _start_mysql(
                docker,
                container=source_container,
                volume=source_database_volume,
                env_file=mysql_env,
                smoke_id=smoke_id,
            )
            containers.append(source_container)
            volumes.append(source_database_volume)
            _docker(
                docker,
                "exec",
                "-i",
                source_container,
                "sh",
                "-c",
                'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot "$MYSQL_DATABASE"',
                input_bytes=b"CREATE TABLE probes (id BIGINT PRIMARY KEY, value VARCHAR(64) NOT NULL); INSERT INTO probes VALUES (1, 'controlled');\n",
            )
            database = root / "database.sql"
            database.write_bytes(
                _docker(
                    docker,
                    "exec",
                    source_container,
                    "sh",
                    "-c",
                    'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysqldump --single-transaction -uroot "$MYSQL_DATABASE"',
                ).stdout
            )

            _docker(docker, "volume", "create", "--label", f"{SMOKE_LABEL}={smoke_id}", source_private_volume)
            volumes.append(source_private_volume)
            _docker(
                docker,
                "run",
                "--rm",
                "--network",
                "none",
                "--label",
                f"{SMOKE_LABEL}={smoke_id}",
                "--mount",
                f"type=volume,source={source_private_volume},target=/data",
                "--entrypoint",
                "/bin/sh",
                MYSQL_IMAGE,
                "-c",
                "mkdir -p /data/fixtures; printf 'controlled private file' > /data/fixtures/probe.txt; chmod 0600 /data/fixtures/probe.txt",
            )
            private_archive = root / "private-files.tar"
            private_archive.write_bytes(
                _docker(
                    docker,
                    "run",
                    "--rm",
                    "--network",
                    "none",
                    "--label",
                    f"{SMOKE_LABEL}={smoke_id}",
                    "--mount",
                    f"type=volume,source={source_private_volume},target=/data,readonly",
                    "--entrypoint",
                    "/bin/sh",
                    MYSQL_IMAGE,
                    "-c",
                    "exec tar -C /data -cf - .",
                ).stdout
            )

            identity = root / "identity.txt"
            keygen = _run([age_keygen, "-o", str(identity)])
            recipient = next(
                line.split(":", 1)[1].strip()
                for line in keygen.stderr.decode("utf-8").splitlines()
                if line.lower().startswith("public key:")
            )
            key = b"recovery-smoke-hmac-key-material-32-bytes-minimum"
            manifest = recovery.build_recovery_manifest(
                database_path=database,
                private_files_path=private_archive,
                source_release={
                    "source_revision": "a" * 40,
                    "web_digest": "sha256:" + "b" * 64,
                    "app_digest": "sha256:" + "c" * 64,
                },
                schema_fingerprint="d" * 64,
                encryption_recipient_fingerprint=recovery.recipient_fingerprint(recipient),
                key_id="smoke-v1",
                backup_reason="manual",
                database_controlled_count=1,
                private_controlled_count=1,
                created_at=datetime.now(timezone.utc),
            )
            plaintext = root / "bundle.tar"
            ciphertext = root / "bundle.tar.age"
            decrypted = root / "decrypted.tar"
            recovery.write_plaintext_bundle(plaintext, manifest, key, database, private_archive)
            recovery.validate_plaintext_bundle(plaintext, key, max_age_hours=24)
            _run([age, "--encrypt", "--recipient", recipient, "--output", str(ciphertext), str(plaintext)])
            plaintext.unlink()
            _run([age, "--decrypt", "--identity", str(identity), "--output", str(decrypted), str(ciphertext)])
            restored = root / "restored"
            validated = recovery.extract_validated_bundle(
                decrypted,
                restored,
                key,
                max_age_hours=24,
            )

            _assert_label_then_remove_container(docker, source_container, smoke_id)
            containers.remove(source_container)
            _start_mysql(
                docker,
                container=restored_container,
                volume=restored_database_volume,
                env_file=mysql_env,
                smoke_id=smoke_id,
            )
            containers.append(restored_container)
            volumes.append(restored_database_volume)
            _docker(
                docker,
                "exec",
                "-i",
                restored_container,
                "sh",
                "-c",
                'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot "$MYSQL_DATABASE"',
                input_bytes=(restored / "database.sql").read_bytes(),
            )
            database_probe = _docker(
                docker,
                "exec",
                restored_container,
                "sh",
                "-c",
                'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*), MIN(value) FROM probes;"',
            ).stdout.decode("utf-8").strip()
            if database_probe != "1\tcontrolled":
                raise RuntimeError("Restored disposable MySQL controlled record did not match.")

            _docker(docker, "volume", "create", "--label", f"{SMOKE_LABEL}={smoke_id}", restored_private_volume)
            volumes.append(restored_private_volume)
            _docker(
                docker,
                "run",
                "--rm",
                "-i",
                "--network",
                "none",
                "--label",
                f"{SMOKE_LABEL}={smoke_id}",
                "--mount",
                f"type=volume,source={restored_private_volume},target=/data",
                "--entrypoint",
                "/bin/sh",
                MYSQL_IMAGE,
                "-c",
                "exec tar -C /data -xf -",
                input_bytes=(restored / "private-files.tar").read_bytes(),
            )
            private_probe = _docker(
                docker,
                "run",
                "--rm",
                "--network",
                "none",
                "--label",
                f"{SMOKE_LABEL}={smoke_id}",
                "--mount",
                f"type=volume,source={restored_private_volume},target=/data,readonly",
                "--entrypoint",
                "/bin/sh",
                MYSQL_IMAGE,
                "-c",
                "printf '%s\\t%s' \"$(find /data -type f | wc -l | tr -d ' ')\" \"$(cat /data/fixtures/probe.txt)\"",
            ).stdout.decode("utf-8")
            if private_probe != "1\tcontrolled private file":
                raise RuntimeError("Restored disposable private-volume controlled file did not match.")

            result = {
                "status": "passed",
                "bundle_id": validated.manifest["bundle_id"],
                "database_controlled_count": 1,
                "private_file_count": validated.private_file_count,
                "disposable_project": prefix,
                "duration_seconds": round(time.monotonic() - started, 3),
                "production_projects_touched": 0,
            }
        finally:
            for container in reversed(containers):
                _assert_label_then_remove_container(docker, container, smoke_id)
            for volume in reversed(volumes):
                _assert_label_then_remove_volume(docker, volume, smoke_id)

    if _production_snapshot(docker) != before_production:
        raise RuntimeError("Production container or volume identities changed during recovery smoke.")
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description="Run an encrypted disposable SelfHandler recovery round trip.")
    parser.add_argument("--age", default=shutil.which("age"))
    parser.add_argument("--age-keygen", default=shutil.which("age-keygen"))
    parser.add_argument("--docker", default=shutil.which("docker"))
    args = parser.parse_args()
    if not args.age or not args.age_keygen or not args.docker:
        raise RuntimeError("age, age-keygen, and Docker are required for the recovery smoke.")
    print(json.dumps(run_smoke(args.age, args.age_keygen, args.docker), sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
