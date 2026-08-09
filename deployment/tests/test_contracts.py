from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

import yaml
from jsonschema import Draft202012Validator


REPO_ROOT = Path(__file__).resolve().parents[2]
CONTRACTS = REPO_ROOT / "specs" / "002-homelab-deployment" / "contracts"
PUBLIC_WORKFLOWS = REPO_ROOT / ".github" / "workflows"
PRIVATE_WORKFLOWS = REPO_ROOT / "deployment" / "private-ops" / ".github" / "workflows"
ACTION_PIN = re.compile(r"^[^\s]+@[0-9a-f]{40}$")


class JsonSchemaContractTests(unittest.TestCase):
    def test_all_operational_schemas_are_valid_draft_2020_12(self) -> None:
        expected = {
            "release-manifest.schema.json",
            "release-record.schema.json",
            "recovery-manifest.schema.json",
            "health-report.schema.json",
        }
        self.assertTrue(expected.issubset({path.name for path in CONTRACTS.glob("*.json")}))
        for name in sorted(expected):
            with self.subTest(schema=name):
                Draft202012Validator.check_schema(json.loads((CONTRACTS / name).read_text(encoding="utf-8")))

    def test_release_manifest_schema_fixes_target_and_canonical_repositories(self) -> None:
        schema = json.loads((CONTRACTS / "release-manifest.schema.json").read_text(encoding="utf-8"))
        properties = schema["properties"]
        self.assertEqual("selfhandler-production", properties["deployment_id"]["const"])
        self.assertEqual("PanyaPrimal/selfHandlerApp", properties["source_repository"]["const"])
        workflow = properties["workflow_identity"]["properties"]
        self.assertEqual("PanyaPrimal/selfhandler-ops", workflow["repository"]["const"])
        self.assertEqual("repository_dispatch", workflow["event"]["const"])


class FixedTargetContractTests(unittest.TestCase):
    def test_production_compose_has_isolated_fixed_identity(self) -> None:
        compose_path = REPO_ROOT / "deployment" / "compose.production.yaml"
        compose = yaml.safe_load(compose_path.read_text(encoding="utf-8"))
        self.assertEqual("selfhandler", compose["name"])
        self.assertEqual({"web", "app", "db"}, set(compose["services"]))
        self.assertEqual({"app", "data"}, set(compose["networks"]))
        self.assertTrue(compose["networks"]["data"]["internal"])
        self.assertEqual({"mysql_data", "private_files"}, set(compose["volumes"]))

        web_ports = compose["services"]["web"].get("ports", [])
        self.assertEqual(["127.0.0.1:18080:8080"], web_ports)
        self.assertFalse(compose["services"]["app"].get("ports"))
        self.assertFalse(compose["services"]["db"].get("ports"))
        self.assertNotIn("dealflow", compose_path.read_text(encoding="utf-8").lower())

    def test_disposable_override_cannot_name_production_or_dealflow_resources(self) -> None:
        override = (REPO_ROOT / "deployment" / "compose.validation.yaml").read_text(encoding="utf-8").lower()
        forbidden = (
            "name: selfhandler\n",
            "container_name:",
            "selfhandler_mysql_data",
            "selfhandler_private_files",
            "dealflow",
            "127.0.0.1:18080",
        )
        for value in forbidden:
            with self.subTest(value=value):
                self.assertNotIn(value, override)

    def test_routine_implementation_contains_no_destructive_or_seeding_commands(self) -> None:
        forbidden = (
            "docker compose down -v",
            "migrate:fresh",
            "migrate:rollback",
            "db:seed",
            "tailscale serve reset",
        )
        implementation_files = [REPO_ROOT / "deploy.ps1", REPO_ROOT / "deployment" / "compose.production.yaml"]
        implementation_files.extend((REPO_ROOT / "deployment" / "scripts").glob("*.ps1"))
        for path in implementation_files:
            source = path.read_text(encoding="utf-8").lower()
            for command in forbidden:
                with self.subTest(path=path.name, command=command):
                    self.assertNotIn(command, source)


class WorkflowTrustContractTests(unittest.TestCase):
    @staticmethod
    def _workflow(path: Path) -> dict:
        result = yaml.safe_load(path.read_text(encoding="utf-8"))
        if not isinstance(result, dict):
            raise AssertionError(f"Workflow is not a mapping: {path}")
        return result

    def test_public_repository_workflows_use_hosted_runners_only(self) -> None:
        paths = sorted(PUBLIC_WORKFLOWS.glob("*.yml"))
        self.assertTrue(paths)
        for path in paths:
            workflow = self._workflow(path)
            for job_name, job in workflow.get("jobs", {}).items():
                with self.subTest(path=path.name, job=job_name):
                    runs_on = str(job.get("runs-on", ""))
                    self.assertNotIn("self-hosted", runs_on.lower())
                    if path.name == "ci.yml" and job_name == "deployment":
                        self.assertEqual("windows-2025", runs_on)
                        setup = next(
                            step
                            for step in job["steps"]
                            if step.get("name") == "Set up the hash-locked Windows test runtime"
                        )
                        self.assertEqual(
                            "actions/setup-python@ece7cb06caefa5fff74198d8649806c4678c61a1",
                            setup["uses"],
                        )
                        self.assertEqual("3.14.3", setup["with"]["python-version"])
                        evidence = next(
                            step
                            for step in job["steps"]
                            if step.get("name") == "Prove Windows PowerShell 5.1 is the contract shell"
                        )
                        self.assertEqual("powershell", evidence["shell"])
                        self.assertIn("PSEdition -ne 'Desktop'", evidence["run"])
                        self.assertIn("PSVersion.Minor -ne 1", evidence["run"])
                        contracts = next(
                            step
                            for step in job["steps"]
                            if step.get("name") == "Run deployment contract tests"
                        )
                        self.assertEqual("powershell", contracts["shell"])
                    else:
                        self.assertRegex(runs_on, r"^ubuntu-[0-9]{2}\.[0-9]{2}$")

    def test_public_workflows_have_minimal_permissions_and_sha_pinned_actions(self) -> None:
        for path in sorted(PUBLIC_WORKFLOWS.glob("*.yml")):
            workflow = self._workflow(path)
            permissions = workflow.get("permissions", {})
            self.assertNotEqual("write-all", permissions)
            self.assertNotEqual("write", permissions.get("contents"))
            for job in workflow.get("jobs", {}).values():
                for step in job.get("steps", []):
                    use = step.get("uses")
                    if not use:
                        continue
                    with self.subTest(path=path.name, use=use):
                        self.assertRegex(use, ACTION_PIN)

    def test_public_repository_exposes_only_read_only_ci(self) -> None:
        paths = sorted(path.name for path in PUBLIC_WORKFLOWS.glob("*.yml"))
        self.assertEqual(["ci.yml"], paths)
        source = (PUBLIC_WORKFLOWS / "ci.yml").read_text(encoding="utf-8")
        self.assertNotIn("packages: write", source)
        self.assertNotIn("id-token: write", source)
        self.assertNotIn("attestations: write", source)
        self.assertNotIn("docker/login-action", source)
        self.assertNotIn("attest-build-provenance", source)

    def test_homelab_jobs_never_checkout_public_source(self) -> None:
        for path in sorted(PRIVATE_WORKFLOWS.glob("*.yml")):
            workflow = self._workflow(path)
            for job_name, job in workflow.get("jobs", {}).items():
                runs_on = str(job.get("runs-on", ""))
                if "self-hosted" not in runs_on.lower():
                    continue
                source = json.dumps(job, sort_keys=True).lower()
                with self.subTest(path=path.name, job=job_name):
                    self.assertNotIn("actions/checkout", source)
                    self.assertNotIn("repository:", source)

    def test_private_workflow_step_actions_are_sha_pinned(self) -> None:
        for path in sorted(PRIVATE_WORKFLOWS.glob("*.yml")):
            workflow = self._workflow(path)
            for job_name, job in workflow.get("jobs", {}).items():
                for step in job.get("steps", []):
                    use = step.get("uses")
                    if not use:
                        continue
                    with self.subTest(path=path.name, job=job_name, use=use):
                        self.assertRegex(use, ACTION_PIN)

    def test_private_deploy_has_no_inputs_and_requires_exact_winps_contracts(self) -> None:
        path = PRIVATE_WORKFLOWS / "deploy-selfhandler.yml"
        text = path.read_text(encoding="utf-8")
        self.assertNotRegex(text, r"(?m)^\s{4,}inputs:\s*$")
        for forbidden in ("target", "host", "port", "mode", "source_ref", "source-revision"):
            self.assertNotRegex(text.lower(), rf"(?m)^\s+{re.escape(forbidden)}:\s*$")
        workflow = self._workflow(path)
        request = workflow["jobs"]["request"]
        windows = workflow["jobs"]["windows-contracts"]
        qualify = workflow["jobs"]["qualify"]
        self.assertEqual(
            ["Bind the owner request to canonical public master"],
            [step["name"] for step in request["steps"]],
        )
        self.assertEqual(["request", "resolve-active"], windows["needs"])
        self.assertIn("needs.resolve-active.outputs.resume_release != 'true'", windows["if"])
        self.assertEqual("windows-2025", windows["runs-on"])
        self.assertEqual({"contents": "read"}, windows["permissions"])
        checkout = windows["steps"][0]
        self.assertEqual(
            "actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683",
            checkout["uses"],
        )
        self.assertEqual("PanyaPrimal/selfHandlerApp", checkout["with"]["repository"])
        self.assertEqual("${{ needs.request.outputs.source_revision }}", checkout["with"]["ref"])
        setup = windows["steps"][1]
        self.assertEqual(
            "actions/setup-python@ece7cb06caefa5fff74198d8649806c4678c61a1",
            setup["uses"],
        )
        self.assertEqual("3.14.3", setup["with"]["python-version"])
        evidence = windows["steps"][2]
        self.assertEqual("powershell", evidence["shell"])
        self.assertIn("git rev-parse HEAD", evidence["run"])
        self.assertIn("PSEdition -ne 'Desktop'", evidence["run"])
        contracts = windows["steps"][-1]
        self.assertEqual("powershell", contracts["shell"])
        self.assertIn("python -m unittest discover", contracts["run"])
        self.assertEqual(["request", "resolve-active", "windows-contracts"], qualify["needs"])
        self.assertIn("needs.windows-contracts.result == 'success'", qualify["if"])
        self.assertNotIn("PUBLIC_CI_READ_TOKEN", text)

    def test_deployment_validation_dependencies_are_fully_hash_locked(self) -> None:
        requirements = (REPO_ROOT / "deployment" / "tests" / "requirements.txt").read_text(
            encoding="utf-8"
        )
        for package in (
            "jsonschema==4.25.1",
            "PyYAML==6.0.3",
            "attrs==26.1.0",
            "jsonschema-specifications==2025.9.1",
            "referencing==0.37.0",
            "rpds-py==2026.6.3",
        ):
            with self.subTest(package=package):
                self.assertIn(package, requirements)
        self.assertGreaterEqual(requirements.count("--hash=sha256:"), 10)
        workflow_sources = [path.read_text(encoding="utf-8") for path in PUBLIC_WORKFLOWS.glob("*.yml")]
        workflow_sources.append(
            (PRIVATE_WORKFLOWS / "deploy-selfhandler.yml").read_text(encoding="utf-8")
        )
        installs = [line for source in workflow_sources for line in source.splitlines() if "pip install" in line]
        self.assertTrue(installs)
        self.assertTrue(all("--require-hashes" in line for line in installs))

    def test_public_ci_and_private_qualification_reject_dependency_advisories(self) -> None:
        ci = (PUBLIC_WORKFLOWS / "ci.yml").read_text(encoding="utf-8")
        private_release = (PRIVATE_WORKFLOWS / "deploy-selfhandler.yml").read_text(encoding="utf-8")
        for name, source in (
            ("ci", ci),
            ("private_release", private_release),
        ):
            with self.subTest(workflow=name):
                self.assertIn("composer --working-dir=apps/api audit --locked --no-interaction", source)
                self.assertIn("apps/api/vendor/bin/pint --test", source)
                self.assertIn("npm --prefix apps/web audit --audit-level=high", source)


if __name__ == "__main__":
    unittest.main()
