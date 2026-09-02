from __future__ import annotations

import json
import os
import unittest
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker
from powershell_test_support import run_powershell


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "deployment" / "scripts" / "inspect-production.ps1"


class InspectionReportTests(unittest.TestCase):
    def report(
        self,
        *,
        public: str = "healthy",
        backup: str = "valid",
        canary: str = "",
        pending: int = 0,
    ) -> dict:
        escaped = str(SCRIPT).replace("'", "''")
        command = f"""
. '{escaped}'
$active = [pscustomobject]@{{source_revision='{'a' * 40}';web_digest='sha256:{'b' * 64}';app_digest='sha256:{'c' * 64}'}}
$local = [pscustomobject]@{{status='healthy';latency_ms=12}}
$public = [pscustomobject]@{{status='{public}';latency_ms=23}}
$backup = [pscustomobject]@{{status='{backup}';age_hours=$(if ('{backup}' -eq 'missing') {{$null}} else {{25.5}});reference=$(if ('{backup}' -eq 'missing') {{$null}} else {{'artifact-selfhandler'}})}}
$runtime = @{{non_root='passed';read_only='passed';ports='passed'}}
$report = New-SelfHandlerHealthReport -ObservedAt '2026-08-09T12:00:00Z' -ActiveRelease $active -LocalReadiness $local -PublicRoute $public -DatabaseStatus 'healthy' -DatabaseVolumeStatus 'present' -PrivateFilesVolumeStatus 'present' -LatestBackup $backup -RuntimeIsolation $runtime -CapacityStatus 'sufficient' -PendingReleaseCount {pending}
$report | ConvertTo-Json -Depth 10 -Compress
"""
        env = os.environ.copy()
        env["SELFHANDLER_TEST_SECRET_CANARY"] = canary
        result = run_powershell(
            command,
            cwd=ROOT,
            capture_output=True,
            text=True,
            env=env,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertNotIn(canary, result.stdout) if canary else None
        return json.loads(result.stdout.strip().splitlines()[-1])

    def test_healthy_report_contains_fixed_identity_and_no_alerts(self) -> None:
        report = self.report()

        self.assertEqual(report["deployment_id"], "selfhandler-production")
        self.assertEqual(report["active_release"]["web_digest"], "sha256:" + "b" * 64)
        self.assertEqual(report["persistent_stores"]["database_volume"]["name"], "selfhandler_mysql_data")
        self.assertEqual(report["alerts"], [])

        schema = json.loads(
            (
                ROOT
                / "specs"
                / "002-homelab-deployment"
                / "contracts"
                / "health-report.schema.json"
            ).read_text(encoding="utf-8")
        )
        Draft202012Validator(schema, format_checker=FormatChecker()).validate(report)

    def test_public_route_failure_is_separate_from_local_health(self) -> None:
        report = self.report(public="unreachable")

        self.assertEqual(report["local_readiness"]["status"], "healthy")
        self.assertEqual(report["public_route"]["status"], "unreachable")
        self.assertIn("public_route_unreachable", report["alerts"])
        self.assertNotIn("local_unhealthy", report["alerts"])

    def test_overdue_backup_has_stable_alert(self) -> None:
        report = self.report(backup="overdue")

        self.assertEqual(report["latest_backup"]["status"], "overdue")
        self.assertIn("backup_overdue", report["alerts"])

    def test_secret_canary_never_appears_in_structured_output(self) -> None:
        report = self.report(canary="SELFHANDLER-SECRET-CANARY-DO-NOT-PRINT")
        self.assertNotIn("CANARY", json.dumps(report))

    def test_memory_capacity_fixture_requires_total_and_free_headroom(self) -> None:
        shared = str(ROOT / "deployment" / "scripts" / "shared.ps1").replace("'", "''")
        command = f"""
. '{shared}'
$healthy = Test-SelfHandlerMemoryCapacity -TotalBytes 8589934592 -UsedBytes 3221225472
$tooSmall = Test-SelfHandlerMemoryCapacity -TotalBytes 3221225472 -UsedBytes 536870912
$tooBusy = Test-SelfHandlerMemoryCapacity -TotalBytes 8589934592 -UsedBytes 7516192768
if (-not $healthy -or $tooSmall -or $tooBusy) {{ exit 19 }}
"""
        result = run_powershell(
            command,
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_runtime_contract_requires_nnp_and_exact_named_volume_mounts(self) -> None:
        shared = str(ROOT / "deployment" / "scripts" / "shared.ps1").replace("'", "''")
        for script_name in ("deploy-production.ps1", "finalize-release.ps1", "inspect-production.ps1"):
            source = (ROOT / "deployment" / "scripts" / script_name).read_text(encoding="utf-8")
            self.assertIn("Test-SelfHandlerNoNewPrivileges", source)
            self.assertIn("Test-SelfHandlerMountContract", source)
        command = f"""
. '{shared}'
function New-Inspection([string]$Service) {{
  $tmpfs = @{{'/tmp'='rw'}}
  $mounts = @()
  if ($Service -eq 'db') {{
    $tmpfs['/run/mysqld'] = 'rw'
    $mounts = @([pscustomobject]@{{Type='volume';Name='selfhandler_mysql_data';Destination='/var/lib/mysql';RW=$true}})
  }} elseif ($Service -eq 'app') {{
    foreach ($path in @('/app/bootstrap/cache','/app/storage/framework','/app/storage/logs')) {{ $tmpfs[$path] = 'rw' }}
    $mounts = @([pscustomobject]@{{Type='volume';Name='selfhandler_private_files';Destination='/app/storage/app/private';RW=$true}})
  }}
  return [pscustomobject]@{{
    HostConfig = [pscustomobject]@{{SecurityOpt=@('no-new-privileges:true');Tmpfs=[pscustomobject]$tmpfs}}
    Mounts = $mounts
  }}
}}
foreach ($service in @('web','app','db')) {{
  $inspection = New-Inspection $service
  if (-not (Test-SelfHandlerNoNewPrivileges -Inspection $inspection)) {{ exit 51 }}
  if (-not (Test-SelfHandlerMountContract -Service $service -Inspection $inspection)) {{ exit 52 }}
}}
$badNnp = New-Inspection 'web'
$badNnp.HostConfig.SecurityOpt = @()
if (Test-SelfHandlerNoNewPrivileges -Inspection $badNnp) {{ exit 53 }}
$badExtra = New-Inspection 'web'
$badExtra.Mounts = @([pscustomobject]@{{Type='bind';Name='';Destination='/host';RW=$true}})
if (Test-SelfHandlerMountContract -Service web -Inspection $badExtra) {{ exit 54 }}
$badSource = New-Inspection 'db'
$badSource.Mounts[0].Name = 'other_mysql_data'
if (Test-SelfHandlerMountContract -Service db -Inspection $badSource) {{ exit 55 }}
"""
        result = run_powershell(
            command,
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_pending_release_is_a_distinct_incomplete_deployment_alert(self) -> None:
        report = self.report(pending=1)
        self.assertIn("pending_release", report["alerts"])
        self.assertIn("deployment_incomplete", report["alerts"])


if __name__ == "__main__":
    unittest.main()
