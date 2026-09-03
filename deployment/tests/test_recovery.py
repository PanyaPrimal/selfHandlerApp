from __future__ import annotations

import hashlib
import hmac
import io
import json
import tarfile
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

from deployment import recovery
from powershell_test_support import WINDOWS_POWERSHELL_51_AVAILABLE, run_powershell


SHA = "1" * 40
DIGEST = "sha256:" + "2" * 64
SCHEMA = "3" * 64
HMAC_KEY = b"test-only-hmac-key-material-that-is-at-least-32-bytes"


class RecoveryContractTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.database = self.root / "database.sql"
        self.database.write_bytes(b"-- MySQL dump\nCREATE TABLE example (id bigint);\n")
        self.private_archive = self.root / "private-files.tar"
        with tarfile.open(self.private_archive, "w") as archive:
            payload = b"private fixture"
            member = tarfile.TarInfo("attachments/probe.txt")
            member.size = len(payload)
            member.mode = 0o600
            archive.addfile(member, io.BytesIO(payload))

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def source_release(self) -> dict[str, str]:
        return {
            "source_revision": SHA,
            "web_digest": DIGEST,
            "app_digest": DIGEST,
        }

    def manifest(self, **overrides: object) -> dict[str, object]:
        values: dict[str, object] = {
            "database_path": self.database,
            "private_files_path": self.private_archive,
            "source_release": self.source_release(),
            "schema_fingerprint": SCHEMA,
            "encryption_recipient_fingerprint": "recipient-0123456789abcdef",
            "key_id": "homelab-v1",
            "backup_reason": "predeploy",
            "database_controlled_count": 1,
            "private_controlled_count": 1,
            "created_at": datetime(2026, 8, 9, 12, tzinfo=timezone.utc),
            "bundle_id": "selfhandler-20260809T120000Z-01234567",
        }
        values.update(overrides)
        return recovery.build_recovery_manifest(**values)

    def bundle(self, manifest: dict[str, object] | None = None) -> Path:
        target = self.root / "recovery.tar"
        recovery.write_plaintext_bundle(
            target,
            manifest or self.manifest(),
            HMAC_KEY,
            self.database,
            self.private_archive,
        )
        return target

    @staticmethod
    def rewrite_bundle(source: Path, destination: Path, changes: dict[str, bytes]) -> None:
        with tarfile.open(source, "r") as archive:
            payloads = {
                member.name: archive.extractfile(member).read()  # type: ignore[union-attr]
                for member in archive.getmembers()
            }
        payloads.update(changes)
        with tarfile.open(destination, "w", format=tarfile.PAX_FORMAT) as archive:
            for name in recovery.BUNDLE_MEMBERS:
                payload = payloads[name]
                member = tarfile.TarInfo(name)
                member.size = len(payload)
                member.mode = 0o600
                member.mtime = 0
                archive.addfile(member, io.BytesIO(payload))

    def test_bundle_has_exact_four_regular_members_and_exact_hmac_sidecar(self) -> None:
        bundle = self.bundle()

        with tarfile.open(bundle, "r") as archive:
            members = archive.getmembers()
            self.assertEqual([item.name for item in members], list(recovery.BUNDLE_MEMBERS))
            self.assertTrue(all(item.isfile() for item in members))
            manifest_bytes = archive.extractfile("manifest.json").read()  # type: ignore[union-attr]
            sidecar = archive.extractfile("manifest.hmac").read()  # type: ignore[union-attr]

        expected = hmac.new(HMAC_KEY, manifest_bytes, hashlib.sha256).hexdigest().encode("ascii")
        self.assertEqual(sidecar, expected)
        validated = recovery.validate_plaintext_bundle(bundle, HMAC_KEY)
        self.assertEqual(validated.manifest["bundle_id"], "selfhandler-20260809T120000Z-01234567")

    def test_manifest_hmac_is_checked_with_constant_time_compare_before_payloads(self) -> None:
        bundle = self.bundle()
        tampered = self.root / "tampered-manifest.tar"
        with tarfile.open(bundle, "r") as archive:
            manifest = json.loads(archive.extractfile("manifest.json").read())  # type: ignore[union-attr]
        manifest["deployment_id"] = "another-deployment"
        changed = recovery.serialize_manifest(manifest)
        self.rewrite_bundle(bundle, tampered, {"manifest.json": changed})

        with patch("deployment.recovery.hmac.compare_digest", wraps=hmac.compare_digest) as compare:
            with self.assertRaisesRegex(recovery.RecoveryValidationError, "authentication"):
                recovery.validate_plaintext_bundle(tampered, HMAC_KEY)
        compare.assert_called_once()

    def test_payload_size_and_checksum_tampering_are_rejected(self) -> None:
        bundle = self.bundle()
        tampered = self.root / "tampered-payload.tar"
        self.rewrite_bundle(bundle, tampered, {"database.sql": b"-- changed after signing\n"})

        with self.assertRaisesRegex(recovery.RecoveryValidationError, "database.sql.*(size|checksum)"):
            recovery.validate_plaintext_bundle(tampered, HMAC_KEY)

    def test_extra_duplicate_traversal_and_link_members_are_rejected(self) -> None:
        bundle = self.bundle()
        extra = self.root / "extra.tar"
        with tarfile.open(bundle, "r") as source, tarfile.open(extra, "w") as target:
            for item in source.getmembers():
                target.addfile(item, source.extractfile(item))
            member = tarfile.TarInfo("unexpected.txt")
            member.size = 1
            target.addfile(member, io.BytesIO(b"x"))
        with self.assertRaisesRegex(recovery.RecoveryValidationError, "exactly"):
            recovery.validate_plaintext_bundle(extra, HMAC_KEY)

        for unsafe_name, member_type in (("../escape", tarfile.REGTYPE), ("payload-link", tarfile.SYMTYPE)):
            unsafe = self.root / f"unsafe-{member_type!s}.tar"
            with tarfile.open(unsafe, "w") as archive:
                member = tarfile.TarInfo(unsafe_name)
                member.type = member_type
                if member_type == tarfile.SYMTYPE:
                    member.linkname = "manifest.json"
                else:
                    member.size = 1
                archive.addfile(member, None if member_type == tarfile.SYMTYPE else io.BytesIO(b"x"))
            with self.assertRaises(recovery.RecoveryValidationError):
                recovery.validate_plaintext_bundle(unsafe, HMAC_KEY)

    def test_private_file_archive_rejects_traversal_links_and_devices(self) -> None:
        cases = [
            ("../escape.txt", tarfile.REGTYPE),
            ("absolute", tarfile.SYMTYPE),
            ("device", tarfile.CHRTYPE),
        ]
        for index, (name, member_type) in enumerate(cases):
            path = self.root / f"unsafe-private-{index}.tar"
            with tarfile.open(path, "w") as archive:
                member = tarfile.TarInfo(name)
                member.type = member_type
                if member_type == tarfile.SYMTYPE:
                    member.linkname = "/etc/passwd"
                elif member_type == tarfile.REGTYPE:
                    member.size = 1
                archive.addfile(member, io.BytesIO(b"x") if member.size else None)
            with self.subTest(name=name, member_type=member_type):
                with self.assertRaisesRegex(recovery.RecoveryValidationError, "private-files"):
                    recovery.validate_private_files_archive(path)

    def test_wrong_target_and_stale_bundle_are_rejected_after_valid_authentication(self) -> None:
        bundle = self.bundle()
        with tarfile.open(bundle, "r") as archive:
            manifest = json.loads(archive.extractfile("manifest.json").read())  # type: ignore[union-attr]
        manifest["deployment_id"] = "wrong-target"
        changed = recovery.serialize_manifest(manifest)
        wrong_target = self.root / "wrong-target.tar"
        self.rewrite_bundle(
            bundle,
            wrong_target,
            {
                "manifest.json": changed,
                "manifest.hmac": recovery.manifest_hmac(changed, HMAC_KEY),
            },
        )
        with self.assertRaisesRegex(recovery.RecoveryValidationError, "deployment"):
            recovery.validate_plaintext_bundle(wrong_target, HMAC_KEY)

        stale = self.bundle(
            self.manifest(created_at=datetime(2026, 8, 8, 10, tzinfo=timezone.utc))
        )
        with self.assertRaisesRegex(recovery.RecoveryValidationError, "older"):
            recovery.validate_plaintext_bundle(
                stale,
                HMAC_KEY,
                max_age_hours=24,
                now=datetime(2026, 8, 9, 12, tzinfo=timezone.utc),
            )

    def test_null_source_release_is_allowed_only_for_bootstrap_baseline(self) -> None:
        empty_private = self.root / "empty-private.tar"
        with tarfile.open(empty_private, "w", format=tarfile.PAX_FORMAT):
            pass
        baseline = self.manifest(
            private_files_path=empty_private,
            source_release=None,
            backup_reason="bootstrap-baseline",
            schema_fingerprint=recovery.EMPTY_SCHEMA_FINGERPRINT,
            database_controlled_count=0,
            private_controlled_count=0,
        )
        recovery.validate_manifest(baseline)

        invalid = dict(baseline)
        invalid["backup_reason"] = "predeploy"
        with self.assertRaisesRegex(recovery.RecoveryValidationError, "source_release"):
            recovery.validate_manifest(invalid)

        for field, value in (
            ("schema_fingerprint", "0" * 64),
            ("database", {**baseline["database"], "controlled_count": 1}),
            ("private_files", {**baseline["private_files"], "controlled_count": 1}),
        ):
            invalid_baseline = dict(baseline)
            invalid_baseline[field] = value
            with self.assertRaisesRegex(recovery.RecoveryValidationError, "bootstrap-baseline"):
                recovery.validate_manifest(invalid_baseline)

    def test_safe_extraction_occurs_only_after_validation(self) -> None:
        bundle = self.bundle()
        destination = self.root / "extracted"

        validated = recovery.extract_validated_bundle(bundle, destination, HMAC_KEY)

        self.assertEqual((destination / "database.sql").read_bytes(), self.database.read_bytes())
        self.assertEqual(
            (destination / "private-files.tar").read_bytes(), self.private_archive.read_bytes()
        )
        self.assertEqual(validated.private_file_count, 1)


class RestoreScriptSafetyTests(unittest.TestCase):
    def test_production_restore_fail_closed_checks_precede_mutation(self) -> None:
        root = Path(__file__).resolve().parents[2]
        source = (root / "deployment" / "scripts" / "restore-production.ps1").read_text(
            encoding="utf-8"
        )
        baseline_rejection = source.index("$null -eq $validatedSourceRelease")
        local_image_check = source.index(
            "Assert-LocalRecoveryImage -Reference $validatedAppImage"
        )
        safety_backup_check = source.index(
            "$validatedSafetyBackup = Get-ValidatedPreRestoreSafetyBackup"
        )
        semantic_preflight = source.index(
            "$productionRestorePreflight = Invoke-DisposableProductionRestorePreflight"
        )
        stop = source.index("Invoke-SelfHandlerCompose stop web app")
        destructive_restore_call = source.index(
            "Restore-DatabasePayload -Container $databaseContainer",
            stop,
        )
        self.assertLess(baseline_rejection, stop)
        self.assertLess(local_image_check, stop)
        self.assertLess(safety_backup_check, stop)
        self.assertLess(semantic_preflight, stop)
        self.assertLess(stop, destructive_restore_call)
        self.assertIn('$maximumAgeHours = $(if ($RecoveryMode -eq "Drill") { 24 } else { 720 })', source)
        self.assertIn("--max-age-hours $maximumAgeHours", source)

    def test_restore_uses_hardened_root_helper_and_checks_controlled_counts(self) -> None:
        root = Path(__file__).resolve().parents[2]
        source = (root / "deployment" / "scripts" / "restore-production.ps1").read_text(
            encoding="utf-8"
        )
        self.assertIn('--user "0:0" --read-only --cap-drop ALL', source)
        self.assertIn("--network none", source)
        self.assertIn("chown -R 82:82 /target", source)
        self.assertIn("Assert-RestoredControlledCounts", source)
        self.assertIn("SELECT COUNT(*) FROM users", source)
        self.assertIn("Invoke-NativeProcessRedirected", source)
        self.assertIn('-Arguments @("exec", "-i", $Container', source)
        self.assertIn("[IO.File]::Delete($importErrorPath)", source)
        self.assertNotIn("/tmp/selfhandler-restore-", source)
        self.assertIn('-Arguments @("exec", "-i", $helper', source)
        self.assertIn("tar -C /target -xf -", source)
        self.assertNotIn("/tmp/private-files.tar", source)

    def test_bootstrap_reset_is_separate_confirmed_empty_only_recovery(self) -> None:
        root = Path(__file__).resolve().parents[2]
        source = (root / "deployment" / "scripts" / "restore-production.ps1").read_text(
            encoding="utf-8"
        )
        self.assertIn('ValidateSet("Drill", "Production", "BootstrapReset")', source)
        self.assertIn(
            'RESET selfhandler-production TO EMPTY BOOTSTRAP BASELINE',
            source,
        )
        eligibility = source.index('$RecoveryMode -eq "BootstrapReset"')
        semantic_preflight = source.index(
            "$productionRestorePreflight = Invoke-DisposableProductionRestorePreflight",
            eligibility,
        )
        reset_branch = source.index("Exact-confirmed recovery for a failed first deployment")
        reset_stop = source.index("Invoke-SelfHandlerCompose stop web app", reset_branch)
        self.assertLess(semantic_preflight, reset_stop)
        reset_section = source[reset_branch:]
        self.assertIn("Invoke-SelfHandlerCompose rm -f -s web app", reset_section)
        self.assertIn("Assert-RestoredDatabaseSchemaEmpty", reset_section)
        self.assertIn("active-release.json", reset_section)
        self.assertNotIn("up --detach --no-build --pull never app web", reset_section)
        history_guard = source.index("function Assert-BootstrapResetHistoryEligible")
        history_guard_end = source.index("function Wait-DisposableRestoreDatabaseReady")
        history_section = source[history_guard:history_guard_end]
        self.assertIn('Join-Path $script:SelfHandlerStateRoot "releases"', history_section)
        self.assertIn("malformed canonical release history", history_section)
        self.assertIn("failed first-bootstrap attempt", history_section)
        self.assertNotIn("release-history.json", history_section)

    def test_production_restore_refuses_pending_release_before_plaintext_or_mutation(self) -> None:
        root = Path(__file__).resolve().parents[2]
        source = (root / "deployment" / "scripts" / "restore-production.ps1").read_text(
            encoding="utf-8"
        )
        guard = source.index('throw "restore_refused_pending_release"')
        decrypt = source.index("--decrypt", guard)
        stop = source.index("Invoke-SelfHandlerCompose stop", guard)
        self.assertLess(guard, decrypt)
        self.assertLess(guard, stop)
        self.assertIn('$RecoveryMode -ne "Drill"', source[:guard])

    def test_stale_recovery_requires_exact_confirmation_and_never_exceeds_retention(self) -> None:
        root = Path(__file__).resolve().parents[2]
        shared = str(root / "deployment" / "scripts" / "shared.ps1").replace("'", "''")
        command = f"""
. '{shared}'
$noConfirmationRejected = $false
try {{ Assert-SelfHandlerRecoveryAgePolicy -RecoveryMode Production -AgeHours 25 -StaleConfirmation $null }} catch {{ $noConfirmationRejected = $_.Exception.Message -eq 'stale_recovery_confirmation_required' }}
Assert-SelfHandlerRecoveryAgePolicy -RecoveryMode Production -AgeHours 25 -StaleConfirmation 'RESTORE selfhandler-production BACKUP OLDER THAN 24 HOURS'
$beyondRetentionRejected = $false
try {{ Assert-SelfHandlerRecoveryAgePolicy -RecoveryMode Production -AgeHours 721 -StaleConfirmation 'RESTORE selfhandler-production BACKUP OLDER THAN 24 HOURS' }} catch {{ $beyondRetentionRejected = $_.Exception.Message -eq 'recovery_backup_beyond_retention' }}
$drillStaleRejected = $false
try {{ Assert-SelfHandlerRecoveryAgePolicy -RecoveryMode Drill -AgeHours 25 -StaleConfirmation $null }} catch {{ $drillStaleRejected = $_.Exception.Message -eq 'recovery_backup_too_old' }}
if (-not $noConfirmationRejected -or -not $beyondRetentionRejected -or -not $drillStaleRejected) {{ exit 31 }}
"""
        result = run_powershell(
            command,
            cwd=root,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
