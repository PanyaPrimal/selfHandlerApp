"""Disposable production-shaped runtime smoke for SelfHandler.

The launcher generates every Compose identity, refuses production project names,
and removes only the resources carrying that generated project label.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


ROOT = Path(__file__).resolve().parents[2]
PRODUCTION_COMPOSE = ROOT / "deployment" / "compose.production.yaml"
VALIDATION_COMPOSE = ROOT / "deployment" / "compose.validation.yaml"
PROJECT_PATTERN = re.compile(r"\Aselfhandler-validation-[0-9a-f]{12}\Z")
RELEASE = hashlib.sha1(b"selfhandler-production-smoke").hexdigest()
EXPECTED_PRODUCTION_VOLUMES = {"selfhandler_mysql_data", "selfhandler_private_files"}
EXPECTED_PRODUCTION_NETWORKS = {"selfhandler_app", "selfhandler_data"}


class SmokeFailure(RuntimeError):
    pass


def _redact(value: str, redactions: set[str]) -> str:
    for redaction in redactions:
        if redaction:
            value = value.replace(redaction, "[REDACTED]")
    return value


def _run(
    command: list[str],
    *,
    env: dict[str, str] | None = None,
    timeout: int = 300,
    redactions: set[str] | None = None,
) -> str:
    completed = subprocess.run(
        command,
        cwd=ROOT,
        env=env,
        text=True,
        encoding="utf-8",
        errors="replace",
        capture_output=True,
        timeout=timeout,
        check=False,
    )
    if completed.returncode != 0:
        safe_output = _redact(
            "\n".join(part for part in (completed.stdout, completed.stderr) if part),
            redactions or set(),
        )
        raise SmokeFailure(
            f"Command failed with exit {completed.returncode}: "
            f"{' '.join(command[:4])}\n{safe_output[-6000:]}"
        )
    return completed.stdout.strip()


def _compose_args(env_file: Path) -> list[str]:
    return [
        "docker",
        "compose",
        "--env-file",
        str(env_file),
        "-f",
        str(PRODUCTION_COMPOSE),
        "-f",
        str(VALIDATION_COMPOSE),
    ]


def _free_loopback_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as listener:
        listener.bind(("127.0.0.1", 0))
        return int(listener.getsockname()[1])


def _write_env(path: Path, project: str, port: int) -> tuple[set[str], dict[str, str]]:
    database_password = secrets.token_hex(24)
    root_password = secrets.token_hex(24)
    app_key = "base64:" + base64.b64encode(secrets.token_bytes(32)).decode("ascii")
    values = {
        "SELFHANDLER_APP_IMAGE": f"{project}-app:local",
        "SELFHANDLER_WEB_IMAGE": f"{project}-web:local",
        "SELFHANDLER_VALIDATION_PROJECT": project,
        "SELFHANDLER_VALIDATION_TAG": "local",
        "SELFHANDLER_VALIDATION_PORT": str(port),
        "APP_KEY": app_key,
        "APP_RELEASE_SHA": RELEASE,
        "DB_DATABASE": "selfhandler_validation",
        "DB_USERNAME": "selfhandler_validation",
        "DB_PASSWORD": database_password,
        "DB_ROOT_PASSWORD": root_password,
    }
    path.write_text(
        "".join(f"{name}={value}\n" for name, value in values.items()),
        encoding="utf-8",
    )
    return {database_password, root_password, app_key}, values


def _write_previous_app_env(path: Path, values: dict[str, str], port: int) -> None:
    app_values = {
        "APP_NAME": "SelfHandler",
        "APP_ENV": "production",
        "APP_DEBUG": "false",
        "APP_URL": f"http://127.0.0.1:{port}",
        "APP_KEY": values["APP_KEY"],
        "APP_RELEASE_SHA": RELEASE,
        "LOG_CHANNEL": "stderr",
        "LOG_LEVEL": "warning",
        "DB_CONNECTION": "mysql",
        "DB_HOST": "db",
        "DB_PORT": "3306",
        "DB_DATABASE": values["DB_DATABASE"],
        "DB_USERNAME": values["DB_USERNAME"],
        "DB_PASSWORD": values["DB_PASSWORD"],
        "CACHE_STORE": "database",
        "SESSION_DRIVER": "database",
        "SESSION_CONNECTION": "mysql",
        "SESSION_ENCRYPT": "true",
        "SESSION_COOKIE": "selfhandler_session",
        "SESSION_SECURE_COOKIE": "false",
        "SESSION_HTTP_ONLY": "true",
        "SESSION_SAME_SITE": "lax",
        "QUEUE_CONNECTION": "database",
        "SANCTUM_STATEFUL_DOMAINS": f"127.0.0.1:{port}",
        "FILESYSTEM_DISK": "local",
    }
    path.write_text(
        "".join(f"{name}={value}\n" for name, value in app_values.items()),
        encoding="utf-8",
    )


def _validate_digest_reference(image: str) -> None:
    if not re.fullmatch(
        r"[a-z0-9]+(?:[._/-][a-z0-9]+)*(?::[A-Za-z0-9._-]+)?@sha256:[0-9a-f]{64}",
        image,
    ):
        raise SmokeFailure("Previous app image must be an exact repository@sha256 digest")


def _assert_previous_app_forward_schema(
    image: str,
    project: str,
    app_env_file: Path,
    env: dict[str, str],
    redactions: set[str],
    *,
    preloaded: bool,
) -> None:
    _validate_digest_reference(image)
    if preloaded:
        _run(["docker", "image", "inspect", image], env=env, timeout=30, redactions=redactions)
    else:
        _run(["docker", "pull", image], env=env, timeout=300, redactions=redactions)
    runtime = [
        "docker",
        "run",
        "--rm",
        "--network",
        f"{project}_data",
        "--read-only",
        "--user",
        "82:82",
        "--cap-drop",
        "ALL",
        "--security-opt",
        "no-new-privileges:true",
        "--memory",
        "512m",
        "--cpus",
        "0.75",
        "--pids-limit",
        "128",
        "--tmpfs",
        "/tmp:rw,nosuid,noexec,size=64m,uid=82,gid=82,mode=1770",
        "--tmpfs",
        "/app/bootstrap/cache:rw,nosuid,noexec,size=16m,uid=82,gid=82,mode=0750",
        "--tmpfs",
        "/app/storage/framework:rw,nosuid,noexec,size=64m,uid=82,gid=82,mode=0750",
        "--tmpfs",
        "/app/storage/logs:rw,nosuid,noexec,size=16m,uid=82,gid=82,mode=0750",
        "--env-file",
        str(app_env_file),
        image,
    ]
    _run(
        runtime + ["php", "artisan", "migrate:status", "--no-ansi"],
        env=env,
        timeout=120,
        redactions=redactions,
    )
    probe = (
        "require '/app/vendor/autoload.php'; "
        "$app = require '/app/bootstrap/app.php'; "
        "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "
        "Illuminate\\Support\\Facades\\DB::select('SELECT 1'); echo 'database-ok';"
    )
    result = _run(
        runtime + ["php", "-r", probe],
        env=env,
        timeout=120,
        redactions=redactions,
    )
    if not result.endswith("database-ok"):
        raise SmokeFailure("Previous app did not complete the forward-schema database probe")


def _request_json(
    opener: urllib.request.OpenerDirector,
    origin: str,
    path: str,
    *,
    method: str = "GET",
    body: dict[str, object] | None = None,
    csrf_token: str | None = None,
    expected_status: int = 200,
) -> dict[str, object] | None:
    headers = {
        "Accept": "application/json",
        "Origin": origin,
        "Referer": origin + "/",
    }
    data = None
    if body is not None:
        data = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    if csrf_token is not None:
        headers["X-XSRF-TOKEN"] = csrf_token
    request = urllib.request.Request(
        origin + path,
        data=data,
        headers=headers,
        method=method,
    )
    try:
        with opener.open(request, timeout=10) as response:
            status = response.status
            payload = response.read()
    except urllib.error.HTTPError as error:
        status = error.code
        payload = error.read()
    if status != expected_status:
        raise SmokeFailure(
            f"{method} {path} returned {status}, expected {expected_status}: "
            f"{payload[:500]!r}"
        )
    if not payload:
        return None
    parsed = json.loads(payload.decode("utf-8"))
    if not isinstance(parsed, dict):
        raise SmokeFailure(f"{method} {path} did not return a JSON object")
    return parsed


def _wait_for_readiness(origin: str, timeout: int = 150) -> dict[str, object]:
    opener = urllib.request.build_opener()
    deadline = time.monotonic() + timeout
    last_error = "not attempted"
    while time.monotonic() < deadline:
        try:
            payload = _request_json(opener, origin, "/api/health")
            if payload == {"status": "ok", "release": RELEASE}:
                return payload
            last_error = repr(payload)
        except (OSError, ValueError, SmokeFailure) as error:
            last_error = str(error)
        time.sleep(2)
    raise SmokeFailure(f"Readiness did not pass within {timeout}s: {last_error}")


def _csrf_token(jar: http.cookiejar.CookieJar) -> str:
    for cookie in jar:
        if cookie.name == "XSRF-TOKEN":
            return urllib.parse.unquote(cookie.value)
    raise SmokeFailure("Sanctum did not issue XSRF-TOKEN")


def _assert_controlled_state(
    opener: urllib.request.OpenerDirector,
    origin: str,
    compose: list[str],
    env: dict[str, str],
    redactions: set[str],
    routine_id: object,
    expected_private_hash: str,
) -> None:
    routines = _request_json(opener, origin, "/api/routines")
    ids = {item.get("id") for item in (routines or {}).get("data", [])}
    if routine_id not in ids:
        raise SmokeFailure("Controlled database row did not survive replacement")
    actual_private_hash = _run(
        compose
        + [
            "exec",
            "-T",
            "app",
            "php",
            "-r",
            "$hash = hash_file('sha256', '/app/storage/app/private/runtime-smoke.txt'); "
            "if ($hash === false) { fwrite(STDERR, 'private hash failed'); exit(1); } echo $hash;",
        ],
        env=env,
        redactions=redactions,
    )
    if actual_private_hash != expected_private_hash:
        raise SmokeFailure(
            "Controlled private file did not survive replacement: "
            f"expected={expected_private_hash!r}, actual={actual_private_hash!r}"
        )


def _assert_auth_and_persistence(
    compose: list[str],
    origin: str,
    env: dict[str, str],
    redactions: set[str],
) -> tuple[urllib.request.OpenerDirector, http.cookiejar.CookieJar, object, str]:
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    _request_json(opener, origin, "/sanctum/csrf-cookie", expected_status=204)
    csrf = _csrf_token(jar)

    invite_output = _run(
        compose
        + [
            "exec",
            "-T",
            "app",
            "php",
            "artisan",
            "invite:create",
            "--note=disposable-production-smoke",
            "--no-ansi",
        ],
        env=env,
        redactions=redactions,
    )
    invite_match = re.search(r"\b[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){2}\b", invite_output)
    if invite_match is None:
        raise SmokeFailure("The disposable runtime did not create a registration invite")
    invite_code = invite_match.group(0)
    redactions.add(invite_code)

    email = f"runtime-smoke-{uuid.uuid4().hex[:12]}@example.test"
    password = "runtime smoke password 2026"
    registered = _request_json(
        opener,
        origin,
        "/api/auth/register",
        method="POST",
        csrf_token=csrf,
        expected_status=201,
        body={
            "name": "Runtime Smoke",
            "email": email,
            "password": password,
            "password_confirmation": password,
            "invite_code": invite_code,
        },
    )
    if not registered or registered.get("data", {}).get("email") != email:
        raise SmokeFailure("Registration did not return the controlled account")

    routine = _request_json(
        opener,
        origin,
        "/api/routines",
        method="POST",
        csrf_token=_csrf_token(jar),
        expected_status=201,
        body={"name": "Runtime persistence probe", "schedule_type": "daily"},
    )
    routine_id = (routine or {}).get("data", {}).get("id")
    if not routine_id:
        raise SmokeFailure("Controlled routine was not created")

    private_payload = "selfhandler-private-volume-smoke"
    _run(
        compose
        + [
            "exec",
            "-T",
            "app",
            "php",
            "-r",
            "$path = '/app/storage/app/private/runtime-smoke.txt'; "
            f"$payload = '{private_payload}'; "
            "$bytes = file_put_contents($path, $payload); "
            "if ($bytes !== strlen($payload)) { fwrite(STDERR, 'private write failed'); exit(1); }",
        ],
        env=env,
        redactions=redactions,
    )
    before_hash = _run(
        compose
        + [
            "exec",
            "-T",
            "app",
            "php",
            "-r",
            "$hash = hash_file('sha256', '/app/storage/app/private/runtime-smoke.txt'); "
            "if ($hash === false) { fwrite(STDERR, 'private hash failed'); exit(1); } echo $hash;",
        ],
        env=env,
        redactions=redactions,
    )

    _run(
        compose + ["up", "-d", "--force-recreate", "app", "web"],
        env=env,
        timeout=180,
        redactions=redactions,
    )
    _wait_for_readiness(origin)

    expected_hash = hashlib.sha256(private_payload.encode("utf-8")).hexdigest()
    if before_hash != expected_hash:
        raise SmokeFailure("Controlled private file hash did not match its payload")
    _assert_controlled_state(
        opener,
        origin,
        compose,
        env,
        redactions,
        routine_id,
        expected_hash,
    )
    return opener, jar, routine_id, expected_hash


def _assert_unhealthy_replacement_and_rollback(
    compose: list[str],
    project: str,
    origin: str,
    env: dict[str, str],
    redactions: set[str],
    temp_root: Path,
    opener: urllib.request.OpenerDirector,
    routine_id: object,
    expected_private_hash: str,
) -> float:
    if not PROJECT_PATTERN.fullmatch(project):
        raise SmokeFailure("Failure injection refused a non-disposable project")
    healthy_images = {
        "app": f"{project}-app:local",
        "web": f"{project}-web:local",
    }
    unhealthy_images = {
        "app": f"{project}-app:unhealthy",
        "web": f"{project}-web:unhealthy",
    }
    for service in ("app", "web"):
        dockerfile = temp_root / f"Dockerfile.{service}.unhealthy"
        dockerfile.write_text(
            "ARG BASE_IMAGE\n"
            "FROM ${BASE_IMAGE}\n"
            f'LABEL selfhandler.validation.candidate="unhealthy-{service}"\n'
            + ('CMD ["sh", "-c", "exit 42"]\n' if service == "web" else ""),
            encoding="utf-8",
        )
        _run(
            [
                "docker",
                "build",
                "--pull=false",
                "--network=none",
                "--build-arg",
                f"BASE_IMAGE={healthy_images[service]}",
                "--tag",
                unhealthy_images[service],
                "--file",
                str(dockerfile),
                str(temp_root),
            ],
            env=env,
            timeout=180,
            redactions=redactions,
        )
        healthy_id = _run(
            ["docker", "image", "inspect", "--format", "{{.Id}}", healthy_images[service]],
            env=env,
        )
        unhealthy_id = _run(
            ["docker", "image", "inspect", "--format", "{{.Id}}", unhealthy_images[service]],
            env=env,
        )
        if healthy_id == unhealthy_id:
            raise SmokeFailure(f"Failure injection did not create a distinct {service} image")

    unhealthy_override = temp_root / "unhealthy-web.yaml"
    unhealthy_override.write_text(
        "services:\n"
        "  app:\n"
        f'    image: "{unhealthy_images["app"]}"\n'
        "  web:\n"
        f'    image: "{unhealthy_images["web"]}"\n',
        encoding="utf-8",
    )
    unhealthy_compose = compose + ["-f", str(unhealthy_override)]
    _run(
        unhealthy_compose
        + ["up", "-d", "--force-recreate", "--no-build", "--pull", "never", "app", "web"],
        env=env,
        timeout=180,
        redactions=redactions,
    )

    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        web_id = _run(unhealthy_compose + ["ps", "-aq", "web"], env=env)
        if web_id:
            state = _container_inspect(web_id).get("State") or {}
            if not state.get("Running"):
                break
        time.sleep(1)
    else:
        raise SmokeFailure("Intentionally unhealthy web replacement did not fail")

    for service in ("app", "web"):
        container_id = _run(unhealthy_compose + ["ps", "-aq", service], env=env)
        inspected = _container_inspect(container_id)
        if inspected.get("Config", {}).get("Image") != unhealthy_images[service]:
            raise SmokeFailure(f"Unhealthy replacement did not install the {service} candidate")

    try:
        _request_json(urllib.request.build_opener(), origin, "/api/health")
    except (OSError, ValueError, SmokeFailure):
        pass
    else:
        raise SmokeFailure("Intentionally unhealthy replacement still served readiness")

    rollback_started = time.monotonic()
    _run(
        compose
        + ["up", "-d", "--force-recreate", "--no-build", "--pull", "never", "app", "web"],
        env=env,
        timeout=180,
        redactions=redactions,
    )
    _wait_for_readiness(origin)
    rollback_seconds = time.monotonic() - rollback_started
    if rollback_seconds >= 300:
        raise SmokeFailure(
            f"Paired rollback exceeded the five-minute bound: {rollback_seconds:.1f}s"
        )
    for service in ("app", "web"):
        container_id = _run(compose + ["ps", "-q", service], env=env)
        inspected = _container_inspect(container_id)
        if inspected.get("Config", {}).get("Image") != healthy_images[service]:
            raise SmokeFailure(f"Paired rollback did not restore the healthy {service} image")
    _assert_controlled_state(
        opener,
        origin,
        compose,
        env,
        redactions,
        routine_id,
        expected_private_hash,
    )
    return rollback_seconds


def _logout_and_assert_protected(
    opener: urllib.request.OpenerDirector,
    jar: http.cookiejar.CookieJar,
    origin: str,
) -> None:
    csrf = _csrf_token(jar)
    _request_json(
        opener,
        origin,
        "/api/auth/logout",
        method="POST",
        csrf_token=csrf,
        expected_status=204,
    )
    _request_json(opener, origin, "/api/routines", expected_status=401)


def _container_inspect(container_id: str) -> dict[str, object]:
    output = _run(["docker", "inspect", container_id])
    parsed = json.loads(output)
    if not isinstance(parsed, list) or len(parsed) != 1:
        raise SmokeFailure(f"Unexpected inspect result for {container_id}")
    return parsed[0]


def _assert_isolation(compose: list[str], project: str, port: int, env: dict[str, str]) -> None:
    expected_users = {"web": "101:101", "app": "82:82", "db": "999:999"}
    for service, expected_user in expected_users.items():
        container_id = _run(compose + ["ps", "-q", service], env=env)
        if not container_id:
            raise SmokeFailure(f"Service {service} has no container")
        inspected = _container_inspect(container_id)
        config = inspected["Config"]
        host = inspected["HostConfig"]
        labels = config.get("Labels") or {}
        if labels.get("com.docker.compose.project") != project:
            raise SmokeFailure(f"Service {service} escaped disposable project")
        if config.get("User") != expected_user:
            raise SmokeFailure(f"Service {service} does not run as {expected_user}")
        if not host.get("ReadonlyRootfs"):
            raise SmokeFailure(f"Service {service} root filesystem is writable")
        if "no-new-privileges:true" not in (host.get("SecurityOpt") or []):
            raise SmokeFailure(f"Service {service} lacks no-new-privileges")
        if "ALL" not in (host.get("CapDrop") or []):
            raise SmokeFailure(f"Service {service} does not drop all capabilities")
        if not host.get("Memory") or not host.get("NanoCpus") or not host.get("PidsLimit"):
            raise SmokeFailure(f"Service {service} lacks resource ceilings")

        bindings = host.get("PortBindings") or {}
        published = [binding for values in bindings.values() for binding in (values or [])]
        if service != "web" and published:
            raise SmokeFailure(f"Service {service} publishes a host port")
        if service == "web":
            if len(published) != 1:
                raise SmokeFailure("Web must publish exactly one port")
            binding = published[0]
            if binding.get("HostIp") != "127.0.0.1" or binding.get("HostPort") != str(port):
                raise SmokeFailure("Web port is not the generated loopback binding")

        network_names = set((inspected["NetworkSettings"].get("Networks") or {}).keys())
        expected_networks = {
            "web": {f"{project}_app"},
            "app": {f"{project}_app", f"{project}_data"},
            "db": {f"{project}_data"},
        }[service]
        if network_names != expected_networks:
            raise SmokeFailure(
                f"Service {service} networks {network_names} != {expected_networks}"
            )

    volume_names = set(
        _run(
            ["docker", "volume", "ls", "-q", "--filter", f"label=com.docker.compose.project={project}"]
        ).splitlines()
    )
    expected_volumes = {f"{project}_mysql_data", f"{project}_private_files"}
    if volume_names != expected_volumes:
        raise SmokeFailure(f"Disposable volumes {volume_names} != {expected_volumes}")


def _production_snapshot() -> dict[str, list[str]]:
    containers: list[str] = []
    for project in ("dealflow", "selfhandler"):
        output = _run(
            ["docker", "ps", "-aq", "--filter", f"label=com.docker.compose.project={project}"]
        )
        containers.extend(output.splitlines())
    volumes = set(_run(["docker", "volume", "ls", "-q"]).splitlines())
    networks = set(_run(["docker", "network", "ls", "--format", "{{.Name}}"]).splitlines())
    return {
        "containers": sorted(containers),
        "volumes": sorted(volumes & EXPECTED_PRODUCTION_VOLUMES),
        "networks": sorted(networks & EXPECTED_PRODUCTION_NETWORKS),
    }


def _remove_validation_images(project: str, env: dict[str, str], redactions: set[str]) -> None:
    if not PROJECT_PATTERN.fullmatch(project):
        raise SmokeFailure("Image cleanup refused a non-disposable project")
    for service in ("app", "web"):
        for tag in ("local", "unhealthy"):
            reference = f"{project}-{service}:{tag}"
            inspected = subprocess.run(
                ["docker", "image", "inspect", reference],
                cwd=ROOT,
                env=env,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                timeout=30,
                check=False,
            )
            if inspected.returncode == 0:
                _run(
                    ["docker", "image", "rm", reference],
                    env=env,
                    timeout=60,
                    redactions=redactions,
                )


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--previous-app-image",
        help="Exact previous app repository@sha256 digest to test against the candidate schema.",
    )
    parser.add_argument(
        "--forward-schema-only",
        action="store_true",
        help="Stop after candidate migration and previous-app compatibility checks.",
    )
    parser.add_argument(
        "--previous-app-preloaded",
        action="store_true",
        help="Require the exact previous image locally and forbid registry access from the smoke.",
    )
    args = parser.parse_args()
    if args.forward_schema_only and not args.previous_app_image:
        parser.error("--forward-schema-only requires --previous-app-image")
    if args.previous_app_preloaded and not args.previous_app_image:
        parser.error("--previous-app-preloaded requires --previous-app-image")
    return args


def main() -> int:
    args = _parse_args()
    project = f"selfhandler-validation-{uuid.uuid4().hex[:12]}"
    if not PROJECT_PATTERN.fullmatch(project) or project in {"selfhandler", "dealflow"}:
        raise SmokeFailure("Generated project failed the disposable identity guard")

    _run(["docker", "version", "--format", "{{.Server.Version}}"], timeout=20)
    _run(["docker", "compose", "version"], timeout=20)
    before = _production_snapshot()
    port = _free_loopback_port()

    with tempfile.TemporaryDirectory(prefix="selfhandler-smoke-") as temp_root:
        env_file = Path(temp_root) / "validation.env"
        redactions, values = _write_env(env_file, project, port)
        previous_app_env_file = Path(temp_root) / "previous-app.env"
        _write_previous_app_env(previous_app_env_file, values, port)
        compose = _compose_args(env_file)
        env = os.environ.copy()
        env.update(
            {
                "SELFHANDLER_VALIDATION_PROJECT": project,
                "SELFHANDLER_VALIDATION_PORT": str(port),
            }
        )

        try:
            print(f"[smoke] building disposable pair for {project}", flush=True)
            _run(
                compose + ["build", "app", "web"],
                env=env,
                timeout=900,
                redactions=redactions,
            )
            _run(
                compose + ["up", "-d", "--wait", "--wait-timeout", "120", "db"],
                env=env,
                timeout=180,
                redactions=redactions,
            )
            _run(
                compose + ["run", "--rm", "--no-deps", "app", "php", "artisan", "migrate", "--force"],
                env=env,
                timeout=180,
                redactions=redactions,
            )
            if args.previous_app_image:
                _assert_previous_app_forward_schema(
                    args.previous_app_image,
                    project,
                    previous_app_env_file,
                    env,
                    redactions,
                    preloaded=args.previous_app_preloaded,
                )
                print("[smoke] previous app accepts the candidate forward schema", flush=True)
            if not args.forward_schema_only:
                _run(
                    compose + ["up", "-d", "app", "web"],
                    env=env,
                    timeout=180,
                    redactions=redactions,
                )
                origin = f"http://127.0.0.1:{port}"
                _wait_for_readiness(origin)
                opener, jar, routine_id, private_hash = _assert_auth_and_persistence(
                    compose,
                    origin,
                    env,
                    redactions,
                )
                rollback_seconds = _assert_unhealthy_replacement_and_rollback(
                    compose,
                    project,
                    origin,
                    env,
                    redactions,
                    Path(temp_root),
                    opener,
                    routine_id,
                    private_hash,
                )
                _logout_and_assert_protected(opener, jar, origin)
                _assert_isolation(compose, project, port, env)
                print(
                    "[smoke] readiness, auth, persistence, isolation, and paired "
                    f"rollback passed in {rollback_seconds:.1f}s",
                    flush=True,
                )
        finally:
            if not PROJECT_PATTERN.fullmatch(project):
                raise SmokeFailure("Cleanup refused a non-disposable project")
            _run(
                compose + ["down", "--volumes", "--remove-orphans", "--timeout", "15"],
                env=env,
                timeout=180,
                redactions=redactions,
            )
            _remove_validation_images(project, env, redactions)

    after = _production_snapshot()
    if after != before:
        raise SmokeFailure(
            f"Production resource snapshot changed: before={before!r}, after={after!r}"
        )
    print("[smoke] production SelfHandler and DealFlow resources were untouched", flush=True)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (SmokeFailure, subprocess.TimeoutExpired) as error:
        print(f"[smoke] FAILED: {error}", file=sys.stderr)
        raise SystemExit(1)
