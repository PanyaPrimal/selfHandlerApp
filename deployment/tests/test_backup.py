from __future__ import annotations

import re
import tempfile
import unittest
from pathlib import Path

from powershell_test_support import (
    WINDOWS_POWERSHELL_51_AVAILABLE,
    powershell_literal,
    run_powershell,
)


ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / "deployment" / "scripts"


class BackupScriptContractTests(unittest.TestCase):
    def test_backup_interface_is_fixed_target_and_emits_expected_result_fields(self) -> None:
        source = (SCRIPTS / "backup-production.ps1").read_text(encoding="utf-8")

        self.assertRegex(source, r"\[ValidateSet\([^\]]*bootstrap-baseline[^\]]*\)\]")
        self.assertIn("[string]$OutputDirectory", source)
        for forbidden in ("Host", "Port", "ComposeProject", "DeploymentId", "Volume"):
            self.assertNotRegex(source, rf"\[\w+\]\${forbidden}\b")
        for field in ("CiphertextPath", "Sha256", "BundleId"):
            self.assertIn(field, source)
        self.assertIn("ConvertTo-Json -Compress", source)

    def test_plaintext_validation_precedes_encryption_and_cleanup_is_guaranteed(self) -> None:
        source = (SCRIPTS / "backup-production.ps1").read_text(encoding="utf-8")

        validation = source.index("validate --bundle")
        encryption = source.index("--encrypt")
        ciphertext_hash = source.index("Get-FileHash", encryption)
        cleanup = source.rindex("finally")
        self.assertLess(validation, encryption)
        self.assertLess(encryption, ciphertext_hash)
        self.assertLess(ciphertext_hash, cleanup)
        self.assertIn("Remove-SensitivePath", source[cleanup:])
        self.assertNotIn("--decrypt", source)
        self.assertNotIn("AGE-SECRET-KEY-", source)

    def test_database_count_and_schema_are_derived_from_exact_dump_import(self) -> None:
        source = (SCRIPTS / "backup-production.ps1").read_text(encoding="utf-8")
        dump_start = source.index('$dumpScript =')
        exact_import = source.index("Get-DatabaseSnapshotEvidence", dump_start)
        manifest_create = source.index('$createArguments = @(', exact_import)
        live_window = source[dump_start:manifest_create]
        self.assertLess(dump_start, exact_import)
        self.assertNotIn("SELECT COUNT(*) FROM users;", live_window)
        self.assertIn("$snapshotEvidence.controlled_count", live_window)
        self.assertIn("$snapshotEvidence.schema_fingerprint", live_window)
        self.assertIn('Arguments @("exec", "-i", $container', source)
        self.assertNotIn("/tmp/selfhandler-backup-", source)

    def test_bootstrap_baseline_proves_database_empty_before_dump(self) -> None:
        source = (SCRIPTS / "backup-production.ps1").read_text(encoding="utf-8")
        port_probe = source.index("Assert-BootstrapLoopbackPortAvailable")
        bootstrap_mutation = source.index("Invoke-SelfHandlerCompose up --detach")
        empty_schema_probe = source.index(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();"
        )
        database_dump = source.index("mysqldump")
        self.assertLess(port_probe, bootstrap_mutation)
        self.assertLess(empty_schema_probe, database_dump)
        self.assertIn("bootstrap-baseline requires an empty database schema", source)
        self.assertIn('private store ownership or mode is not 82:82:0750', source)

    def test_compose_detach_flag_cannot_bind_as_powershell_debug_parameter(self) -> None:
        for script_name in (
            "backup-production.ps1",
            "deploy-production.ps1",
            "restore-production.ps1",
        ):
            source = (SCRIPTS / script_name).read_text(encoding="utf-8")
            self.assertNotIn("Invoke-SelfHandlerCompose up -d ", source)
            self.assertIn("Invoke-SelfHandlerCompose up --detach ", source)

    def test_compose_wrapper_accepts_progress_on_stderr_when_exit_code_is_zero(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            compose_path = root / "compose.yaml"
            environment_path = root / ".env"
            compose_path.write_text("services: {}\n", encoding="utf-8")
            environment_path.write_text("APP_ENV=production\n", encoding="utf-8")
            shared = (SCRIPTS / "shared.ps1").as_posix().replace("'", "''")
            compose_literal = compose_path.as_posix().replace("'", "''")
            environment_literal = environment_path.as_posix().replace("'", "''")
            result = run_powershell(
                f"""
$ErrorActionPreference = 'Stop'
. '{shared}'
$script:SelfHandlerComposePath = '{compose_literal}'
$script:SelfHandlerEnvironmentPath = '{environment_literal}'
function Assert-ProtectedSecretFile {{ param([string]$Path) return $Path }}
function docker {{
    Write-Error 'Container selfhandler-db-1 Running'
    $global:LASTEXITCODE = 0
}}
Invoke-SelfHandlerCompose up --detach db
Write-Output 'compose-wrapper-ok'
""",
                capture_output=True,
                text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("compose-wrapper-ok", result.stdout)

    def test_bootstrap_deploy_rechecks_fixed_loopback_port_before_migration(self) -> None:
        source = (SCRIPTS / "deploy-production.ps1").read_text(encoding="utf-8")
        bootstrap = source.index("if ($bootstrap)")
        port_probe = source.index("Assert-BootstrapLoopbackPortAvailable", bootstrap)
        migration = source.index("Invoke-CandidateMigration", port_probe)
        self.assertLess(port_probe, migration)
        self.assertIn('"local_port_conflict"', source)

    def test_terminal_success_and_active_pointer_are_finalizer_only_and_compensated(self) -> None:
        deploy = (SCRIPTS / "deploy-production.ps1").read_text(encoding="utf-8")
        source = (SCRIPTS / "finalize-release.ps1").read_text(encoding="utf-8")
        pending = deploy.index("Save-PendingRelease -Record $pendingRecord")
        deploy_output = deploy.index("ConvertTo-Json", pending)
        self.assertLess(pending, deploy_output)
        self.assertNotIn('Outcome "succeeded"', deploy)
        self.assertNotIn("Set-ActiveRelease -Release $candidateRelease", deploy)

        completion_backup = source.index("Resolve-CompletionBackup")
        candidate_pointer = source.index("Set-ActiveRelease -Release $candidate", completion_backup)
        success_record = source.index('-Outcome "succeeded"', candidate_pointer)
        compensation = source.index(
            "Restore-FinalizerActivePointer -PreviousRelease $pending.previous_release",
            success_record,
        )
        self.assertLess(completion_backup, candidate_pointer)
        self.assertLess(candidate_pointer, success_record)
        self.assertLess(success_record, compensation)

    def test_pending_release_resume_and_bootstrap_completion_source_are_explicit(self) -> None:
        deploy = (SCRIPTS / "deploy-production.ps1").read_text(encoding="utf-8")
        backup = (SCRIPTS / "backup-production.ps1").read_text(encoding="utf-8")
        finalizer = (SCRIPTS / "finalize-release.ps1").read_text(encoding="utf-8")
        self.assertIn("Get-PendingRelease -AttemptId $AttemptId -AllowMissing", deploy)
        self.assertIn("Resume = $true", deploy)
        journal = deploy.index("Save-PendingRelease -Record $pendingRecord")
        migration = deploy.index("Invoke-CandidateMigration", journal)
        replacement = deploy.index("Invoke-PairedReplacement", migration)
        self.assertLess(journal, migration)
        self.assertLess(journal, replacement)
        self.assertIn('state = "deploying"', deploy)
        self.assertIn("Set-PendingReleaseDeploymentState", deploy)
        self.assertIn("awaiting_completion", deploy)
        self.assertIn("bundle_sha256", deploy)
        self.assertIn("manifest_sha256", deploy)
        self.assertIn("predeploy_backup_reference", deploy)
        self.assertIn("bootstrap completion backup requires exactly one pending release", backup)
        self.assertIn("$activeRelease = [pscustomobject][ordered]", backup)
        for parameter in (
            "ReleaseManifestPath",
            "CompletionBackupReference",
            "Actor",
            "AttemptId",
        ):
            self.assertRegex(finalizer, rf"\[string\]\${parameter}\b")
        self.assertIn("Assert-QualifiedOperationsPointer", finalizer)
        self.assertIn("Set-PendingReleaseCompletion", finalizer)
        self.assertIn("Resolve-DeploymentAttemptId", deploy)
        self.assertIn("Resolve-FinalizerAttemptId", finalizer)
        self.assertIn('[string]$record.outcome -eq "succeeded"', deploy)
        self.assertIn("Write-Output ($existingTerminal | ConvertTo-Json", deploy)
        for marker in (
            "workflow_repository",
            "workflow_ref",
            "workflow_run_id",
            "workflow_run_attempt",
        ):
            self.assertIn(marker, deploy)

    def test_noncompletion_backups_refuse_any_pending_release_before_store_access(self) -> None:
        source = (SCRIPTS / "backup-production.ps1").read_text(encoding="utf-8")
        guard = source.index('throw "backup_refused_pending_release"')
        bootstrap_initialization = source.index("Initialize-EmptyBootstrapStores", guard)
        dump = source.index("mysqldump", guard)
        self.assertLess(guard, bootstrap_initialization)
        self.assertLess(guard, dump)
        self.assertIn('$Reason -ne "bootstrap"', source[:guard])

    def test_operations_pointer_requires_v2_trust_record_and_acl_paths(self) -> None:
        finalizer = (SCRIPTS / "finalize-release.ps1").read_text(encoding="utf-8")
        shared = (SCRIPTS / "shared.ps1").read_text(encoding="utf-8")
        for marker in (
            'schema_version -ne 2',
            "trust-metadata.json",
            "trust_metadata_sha256",
            "workflow_sha",
            "manifest_sha256",
            "Assert-TrustedIntegrityPath",
        ):
            self.assertIn(marker, finalizer)
        self.assertIn("Assert-SelfHandlerStateRootIntegrity", shared)
        self.assertIn("Integrity-protected $Context grants access", shared)

    def test_documented_age_generation_writes_a_validated_bare_recipient(self) -> None:
        readme = (ROOT / "deployment" / "README.md").read_text(encoding="utf-8")
        self.assertIn("^Public key: age1", readme)
        self.assertIn("-replace '^Public key: '", readme)
        self.assertIn("age-public-recipient.txt", readme)
        self.assertIn("never the `Public key:` label", readme)

    def test_production_secret_files_and_env_fail_closed_on_acl(self) -> None:
        shared = (SCRIPTS / "shared.ps1").read_text(encoding="utf-8")
        backup = (SCRIPTS / "backup-production.ps1").read_text(encoding="utf-8")
        auth = (SCRIPTS / "auth-smoke.ps1").read_text(encoding="utf-8")
        for marker in (
            "AreAccessRulesProtected",
            "S-1-5-32-544",
            "S-1-5-18",
            "ReparsePoint",
            "GetAccessControl",
        ):
            self.assertIn(marker, shared)
        self.assertIn(
            "Assert-ProtectedSecretFile -Path $script:SelfHandlerEnvironmentPath",
            shared,
        )
        self.assertIn(
            "Assert-ProtectedSecretFile -Path $script:SelfHandlerOpsConfigPath",
            backup,
        )
        self.assertIn("Executable overrides are forbidden", backup)
        self.assertIn("Assert-ProtectedSecretFile -Path $hmacKeyPath", backup)
        self.assertIn("Assert-ProtectedSecretFile -Path $passwordPath", auth)

    def test_sensitive_directory_cleanup_runs_after_failure(self) -> None:
        shared = SCRIPTS / "shared.ps1"
        with tempfile.TemporaryDirectory() as temporary:
            target = Path(temporary) / "plaintext"
            escaped_shared = str(shared).replace("'", "''")
            escaped_target = str(target).replace("'", "''")
            command = (
                f". '{escaped_shared}'; "
                f"try {{ Invoke-WithSensitiveDirectory -Path '{escaped_target}' -Action {{ param($p) "
                "[IO.File]::WriteAllText((Join-Path $p 'secret.txt'), 'canary'); throw 'expected' } } "
                "catch { }; "
                f"if (Test-Path -LiteralPath '{escaped_target}') {{ exit 9 }}"
            )
            result = run_powershell(
                command,
                capture_output=True,
                text=True,
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    @unittest.skipUnless(WINDOWS_POWERSHELL_51_AVAILABLE, "Windows PowerShell 5.1 is unavailable")
    def test_native_redirect_closes_exact_stdin_before_waiting_for_child_exit(self) -> None:
        shared = str(SCRIPTS / "shared.ps1").replace("'", "''")
        with tempfile.TemporaryDirectory() as temporary:
            input_path = Path(temporary) / "input.bin"
            output_path = Path(temporary) / "output.txt"
            error_path = Path(temporary) / "error.txt"
            input_path.write_bytes(b"sentinel")
            command = f"""
. '{shared}'
$child = '$value = [Console]::In.ReadToEnd(); [Console]::Out.Write($value.Length)'
$exitCode = Invoke-NativeProcessRedirected `
  -FilePath '{powershell_literal()}' `
  -Arguments @('-NoLogo', '-NoProfile', '-NonInteractive', '-Command', $child) `
  -StandardInputPath '{str(input_path).replace("'", "''")}' `
  -StandardOutputPath '{str(output_path).replace("'", "''")}' `
  -StandardErrorPath '{str(error_path).replace("'", "''")}'
if ($exitCode -ne 0 -or [IO.File]::ReadAllText('{str(output_path).replace("'", "''")}') -ne '8') {{ exit 31 }}
"""
            result = run_powershell(
                command,
                cwd=ROOT,
                capture_output=True,
                text=True,
                timeout=15,
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    @unittest.skipUnless(WINDOWS_POWERSHELL_51_AVAILABLE, "Windows PowerShell 5.1 is unavailable")
    def test_acl_validator_rejects_untrusted_owner_and_parent_writer(self) -> None:
        shared = str(SCRIPTS / "shared.ps1").replace("'", "''")
        command = f"""
. '{shared}'
$runner = [Security.Principal.WindowsIdentity]::GetCurrent().User
$administrators = New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')
$system = New-Object Security.Principal.SecurityIdentifier('S-1-5-18')
$users = New-Object Security.Principal.SecurityIdentifier('S-1-5-32-545')
function New-TestAcl([Security.Principal.SecurityIdentifier]$Owner, [switch]$UntrustedWriter) {{
  $rules = @()
  foreach ($sid in @($runner, $administrators, $system)) {{
    $rules += [pscustomobject]@{{
      IsInherited = $false
      IdentityReference = $sid
      AccessControlType = [Security.AccessControl.AccessControlType]::Allow
    }}
  }}
  if ($UntrustedWriter) {{
    $rules += [pscustomobject]@{{
      IsInherited = $false
      IdentityReference = $users
      AccessControlType = [Security.AccessControl.AccessControlType]::Allow
    }}
  }}
  return [pscustomobject]@{{Owner=$Owner.Value;AreAccessRulesProtected=$true;Access=$rules}}
}}
Assert-TrustedWindowsAcl -Acl (New-TestAcl -Owner $runner) -Context file
$ownerRejected = $false
try {{ Assert-TrustedWindowsAcl -Acl (New-TestAcl -Owner $users) -Context file }} catch {{ $ownerRejected = $true }}
$writerRejected = $false
try {{ Assert-TrustedWindowsAcl -Acl (New-TestAcl -Owner $runner -UntrustedWriter) -Context directory }} catch {{ $writerRejected = $true }}
if (-not $ownerRejected -or -not $writerRejected) {{ exit 17 }}
"""
        result = run_powershell(
            command,
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_loopback_probe_rejects_an_existing_listener(self) -> None:
        shared = str(SCRIPTS / "shared.ps1").replace("'", "''")
        command = f"""
. '{shared}'
$held = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Parse('127.0.0.1'), 0)
$held.ExclusiveAddressUse = $true
$held.Start()
try {{
  $port = ([Net.IPEndPoint]$held.LocalEndpoint).Port
  if (Test-LoopbackPortAvailable -Port $port) {{ exit 21 }}
}} finally {{
  $held.Stop()
}}
if (-not (Test-LoopbackPortAvailable -Port $port)) {{ exit 22 }}
"""
        result = run_powershell(
            command,
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    @unittest.skipUnless(WINDOWS_POWERSHELL_51_AVAILABLE, "Windows PowerShell 5.1 is unavailable")
    def test_atomic_state_json_protects_child_directory_and_file_acls(self) -> None:
        shared = str(SCRIPTS / "shared.ps1").replace("'", "''")
        with tempfile.TemporaryDirectory() as temporary:
            state_file = Path(temporary) / "pending-releases" / "attempt-12345678.json"
            escaped_state_file = str(state_file).replace("'", "''")
            command = f"""
. '{shared}'
Write-AtomicJson -Path '{escaped_state_file}' -Value ([pscustomobject]@{{schema_version=1}})
$directory = Split-Path -Parent '{escaped_state_file}'
Assert-TrustedIntegrityPath -Path $directory -Type directory -RequireProtectedAcl | Out-Null
Assert-TrustedIntegrityPath -Path '{escaped_state_file}' -Type file -RequireProtectedAcl | Out-Null
"""
            result = run_powershell(
                command,
                cwd=ROOT,
                capture_output=True,
                text=True,
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    @unittest.skipUnless(WINDOWS_POWERSHELL_51_AVAILABLE, "Windows PowerShell 5.1 is unavailable")
    def test_lock_requires_a_preprotected_parent_and_protects_the_lock_file(self) -> None:
        shared = str(SCRIPTS / "shared.ps1").replace("'", "''")
        with tempfile.TemporaryDirectory() as temporary:
            protected = Path(temporary) / "protected-locks"
            unsafe = Path(temporary) / "unsafe-locks"
            protected.mkdir()
            unsafe.mkdir()
            lock_path = protected / "selfhandler-production.lock"
            unsafe_lock = unsafe / "selfhandler-production.lock"
            command = f"""
. '{shared}'
Protect-TrustedIntegrityPathAcl -Path '{str(Path(temporary)).replace("'", "''")}' -Directory
Protect-TrustedIntegrityPathAcl -Path '{str(protected).replace("'", "''")}' -Directory
$unsafeRejected = $false
try {{ Enter-SelfHandlerProductionLock -Path '{str(unsafe_lock).replace("'", "''")}' | Out-Null }} catch {{ $unsafeRejected = $true }}
$first = Enter-SelfHandlerProductionLock -Path '{str(lock_path).replace("'", "''")}'
try {{
  Assert-TrustedIntegrityPath -Path '{str(lock_path).replace("'", "''")}' -Type file -RequireProtectedAcl | Out-Null
  $secondRejected = $false
  try {{ Enter-SelfHandlerProductionLock -Path '{str(lock_path).replace("'", "''")}' | Out-Null }} catch {{ $secondRejected = $true }}
  if (-not $unsafeRejected -or -not $secondRejected) {{ exit 41 }}
}} finally {{ Exit-SelfHandlerProductionLock -Lock $first }}
"""
            result = run_powershell(
                command,
                cwd=ROOT,
                capture_output=True,
                text=True,
            )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
