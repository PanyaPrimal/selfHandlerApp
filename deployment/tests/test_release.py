from __future__ import annotations

import copy
import importlib.util
import json
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = REPO_ROOT / "deployment" / "release_manifest.py"
SPEC = importlib.util.spec_from_file_location("release_manifest", MODULE_PATH)
if SPEC is None or SPEC.loader is None:  # pragma: no cover
    raise RuntimeError(f"Cannot load {MODULE_PATH}")
release_manifest = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = release_manifest
SPEC.loader.exec_module(release_manifest)


SOURCE_SHA = "a" * 40
WEB_DIGEST = "sha256:" + "b" * 64
APP_DIGEST = "sha256:" + "c" * 64
SCHEMA_SHA = "d" * 64


class ReleaseManifestTests(unittest.TestCase):
    def _bundle(self, root: Path) -> Path:
        bundle = root / "selfhandler-deployment.zip"
        bundle.write_bytes(b"qualified-deployment-bundle")
        return bundle

    def _manifest(self, root: Path) -> dict:
        return release_manifest.build_release_manifest(
            source_revision=SOURCE_SHA,
            workflow_run_id=123456,
            workflow_run_attempt=2,
            bundle_path=self._bundle(root),
            schema_fingerprint_value=SCHEMA_SHA,
            web_digest=WEB_DIGEST,
            app_digest=APP_DIGEST,
            web_revision=SOURCE_SHA,
            app_revision=SOURCE_SHA,
            created_at="2026-08-09T20:00:00Z",
        )

    def test_release_manifest_contains_canonical_workflow_and_quality_evidence(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            manifest = self._manifest(Path(directory))

        self.assertEqual("PanyaPrimal/selfhandler-ops", manifest["workflow_identity"]["repository"])
        self.assertEqual("repository_dispatch", manifest["workflow_identity"]["event"])
        self.assertEqual(123456, manifest["workflow_run_id"])
        self.assertTrue(all(item["status"] == "passed" for item in manifest["quality_evidence"].values()))
        release_manifest.validate_release_manifest(manifest)

    def test_manifest_generation_is_canonical_for_fixed_inputs(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            first = self._manifest(root)
            second = self._manifest(root)

        self.assertEqual(
            release_manifest.canonical_json_bytes(first),
            release_manifest.canonical_json_bytes(second),
        )

    def test_mismatched_oci_revision_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(release_manifest.ReleaseContractError, "OCI revision"):
                release_manifest.build_release_manifest(
                    source_revision=SOURCE_SHA,
                    workflow_run_id=1,
                    workflow_run_attempt=1,
                    bundle_path=self._bundle(Path(directory)),
                    schema_fingerprint_value=SCHEMA_SHA,
                    web_digest=WEB_DIGEST,
                    app_digest=APP_DIGEST,
                    web_revision="e" * 40,
                    app_revision=SOURCE_SHA,
                    created_at="2026-08-09T20:00:00Z",
                )

    def test_image_integrity_subject_must_equal_the_image_digest(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            manifest = self._manifest(Path(directory))
        manifest["image_integrity"]["web"]["subject_digest"] = "sha256:" + "e" * 64

        with self.assertRaisesRegex(release_manifest.ReleaseContractError, "integrity subject"):
            release_manifest.validate_release_manifest(manifest)

    def test_web_and_app_repositories_cannot_swap_roles(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            manifest = self._manifest(Path(directory))
        swapped = copy.deepcopy(manifest)
        swapped["web_image"]["repository"] = release_manifest.APP_REPOSITORY
        swapped["app_image"]["repository"] = release_manifest.WEB_REPOSITORY

        with self.assertRaisesRegex(release_manifest.ReleaseContractError, "fixed web repository"):
            release_manifest.validate_release_manifest(swapped)

    def test_noncanonical_workflow_metadata_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            manifest = self._manifest(Path(directory))
        manifest["workflow_identity"]["repository"] = "PanyaPrimal/selfHandlerApp"

        with self.assertRaises(release_manifest.ReleaseContractError):
            release_manifest.validate_release_manifest(manifest)

    def test_existing_release_identity_cannot_be_overwritten(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            manifest = self._manifest(Path(directory))
        existing_record = {
            "source_revision": SOURCE_SHA,
            "web_digest": WEB_DIGEST,
            "app_digest": APP_DIGEST,
        }

        with self.assertRaisesRegex(release_manifest.ReleaseContractError, "already exists"):
            release_manifest.assert_release_identity_available(manifest, [existing_record])

    def test_schema_fingerprint_is_path_and_content_sensitive(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "b.php").write_text("second\n", encoding="utf-8")
            (root / "a.php").write_text("first\n", encoding="utf-8")
            first = release_manifest.schema_fingerprint(root)
            (root / "a.php").write_text("changed\n", encoding="utf-8")
            second = release_manifest.schema_fingerprint(root)

        self.assertRegex(first, r"^[0-9a-f]{64}$")
        self.assertNotEqual(first, second)

    def test_deployment_bundle_is_byte_deterministic_and_allowlisted(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            required = {
                "deployment/compose.production.yaml": "name: selfhandler\n",
                "deployment/release_manifest.py": "# manifest\n",
                "deployment/scripts/deploy-production.ps1": "# deploy\n",
                "deployment/scripts/backup-production.ps1": "# backup\n",
                "deployment/tests/not-in-bundle.py": "raise SystemExit\n",
                "specs/002-homelab-deployment/contracts/release-manifest.schema.json": "{}\n",
            }
            for relative, content in required.items():
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(content, encoding="utf-8")
            first = root / "first.zip"
            second = root / "second.zip"
            includes = (
                "deployment/compose.production.yaml",
                "deployment/release_manifest.py",
                "deployment/scripts",
                "specs/002-homelab-deployment/contracts",
            )
            release_manifest.create_deterministic_bundle(root, first, includes)
            release_manifest.create_deterministic_bundle(root, second, includes)
            with zipfile.ZipFile(first) as archive:
                names = archive.namelist()

            self.assertEqual(first.read_bytes(), second.read_bytes())
            self.assertIn("deployment/scripts/deploy-production.ps1", names)
            self.assertNotIn("deployment/tests/not-in-bundle.py", names)

    def test_atomic_json_uses_canonical_encoding(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "manifest.json"
            release_manifest.write_json_atomic(output, {"z": 1, "a": "тест"})
            parsed = json.loads(output.read_text(encoding="utf-8"))

        self.assertEqual({"a": "тест", "z": 1}, parsed)


if __name__ == "__main__":
    unittest.main()
