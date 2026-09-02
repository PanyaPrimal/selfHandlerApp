from __future__ import annotations

import re
import unittest
from pathlib import Path

import yaml


REPO_ROOT = Path(__file__).resolve().parents[2]
DEPLOY_SCRIPT = REPO_ROOT / "deployment" / "scripts" / "deploy-production.ps1"
PRIVATE_DEPLOY = (
    REPO_ROOT
    / "deployment"
    / "private-ops"
    / ".github"
    / "workflows"
    / "deploy-selfhandler.yml"
)


class FixedLauncherTests(unittest.TestCase):
    def test_launcher_has_no_inputs_and_dispatches_only_the_private_workflow(self) -> None:
        source = (REPO_ROOT / "deploy.ps1").read_text(encoding="utf-8")
        compact = re.sub(r"\s+", " ", source)
        self.assertRegex(compact, r"param\(\s*\)")
        self.assertIn("PanyaPrimal/selfhandler-ops", source)
        self.assertIn("deploy-selfhandler.yml", source)
        self.assertIn("repos/$OperationsRepository/dispatches", source)
        self.assertIn("event_type=deploy-selfhandler", source)
        self.assertIn("client_payload[request_id]=$requestId", source)
        self.assertIn("client_payload[source_repository]=$PublicRepository", source)
        self.assertIn("client_payload[source_revision]=$remoteSha", source)
        self.assertIn('Deploy SelfHandler $remoteSha request $requestId', source)
        self.assertIn("displayTitle", source)
        self.assertIn("$script:GitHubCliRoot", source)
        self.assertIn("Join-Path $extractRoot 'bin\\gh.exe'", source)
        self.assertIn("function ConvertFrom-JsonArray", source)
        self.assertIn("$Json.Trim() -eq '[]'", source)
        self.assertIn("Remove-Item -LiteralPath $cliRoot -Recurse -Force", source)
        self.assertIn("--event', 'repository_dispatch", source)
        self.assertIn("'run', 'rerun'", source)
        self.assertIn("'--failed'", source)
        self.assertIn("single crash-safe retry", source)
        self.assertNotRegex(source, r"(?i)\$(?:target|host|port|mode|profile|sourceRef)\b")


class DeploymentOrchestrationContractTests(unittest.TestCase):
    @staticmethod
    def _invocation_offset(source: str, command: str) -> int:
        matches = []
        for match in re.finditer(rf"\b{re.escape(command)}\b", source):
            line_start = source.rfind("\n", 0, match.start()) + 1
            prefix = source[line_start : match.start()]
            if re.search(r"\bfunction\s*$", prefix, re.IGNORECASE):
                continue
            matches.append(match)
        if not matches:
            raise AssertionError(f"No invocation of {command} was found")
        return matches[-1].start()

    def test_preflight_precedes_every_production_mutation(self) -> None:
        source = DEPLOY_SCRIPT.read_text(encoding="utf-8")
        preflight = self._invocation_offset(source, "Invoke-DeploymentPreflight")
        migration = self._invocation_offset(source, "Invoke-CandidateMigration")
        replacement = self._invocation_offset(source, "Invoke-PairedReplacement")
        health = self._invocation_offset(source, "Test-ReleaseHealth")
        self.assertLess(preflight, migration)
        self.assertLess(migration, replacement)
        self.assertLess(replacement, health)

    def test_all_preflight_failures_have_stable_no_mutation_codes(self) -> None:
        source = DEPLOY_SCRIPT.read_text(encoding="utf-8")
        for code in (
            "dependency_unavailable",
            "capacity_insufficient",
            "duplicate_release",
            "revision_mismatch",
            "current_health_failed",
            "backup_not_off_host",
        ):
            with self.subTest(code=code):
                self.assertIn(code, source)
        self.assertIn("rejected", source)
        self.assertIn("Complete-ReleaseRecord", source)

    def test_migration_and_replacement_failures_are_distinct(self) -> None:
        source = DEPLOY_SCRIPT.read_text(encoding="utf-8")
        self.assertIn("migration_failed", source)
        self.assertIn("failed_before_replace", source)
        self.assertIn("replacement_failed", source)
        self.assertIn("Invoke-PairedRollback", source)

    def test_bundle_execution_uses_only_preverified_local_images(self) -> None:
        source = DEPLOY_SCRIPT.read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?im)^\s*&?\s*docker\s+pull\b")
        self.assertRegex(source, r"(?im)docker\s+image\s+inspect")
        self.assertGreaterEqual(source.count("--pull never"), 2)

    def test_rollback_records_terminal_success_or_manual_recovery_evidence(self) -> None:
        source = DEPLOY_SCRIPT.read_text(encoding="utf-8")
        rollback = self._invocation_offset(source, "Invoke-PairedRollback")
        completion = self._invocation_offset(source, "Complete-ReleaseRecord")
        self.assertLess(rollback, completion)
        for outcome in ("rolled_back", "recovery_required"):
            with self.subTest(outcome=outcome):
                self.assertIn(outcome, source)
        self.assertIn("rollback_failed", source)
        self.assertIn("BackupReference", source)
        self.assertNotIn("migrate:rollback", source.lower())


class PrivateWorkflowOrderingTests(unittest.TestCase):
    def test_homelab_job_consumes_same_run_or_exact_protected_resume_artifact(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        workflow = yaml.safe_load(source)
        deploy = workflow["jobs"]["deploy"]
        self.assertEqual(
            ["request", "resolve-active", "qualify", "publish", "package"],
            deploy["needs"],
        )
        self.assertNotIn("actions/checkout", str(deploy).lower())
        self.assertIn("metadata.workflow_run.id", source)
        self.assertIn("GITHUB_RUN_ID", source)
        self.assertIn("prepared-release.json", source)
        self.assertIn("resume_release", source)
        self.assertIn("trust-metadata.json", source)
        self.assertIn("deployment_bundle.sha256", source)
        self.assertIn("Get-FileHash -Algorithm SHA256", source)

    def test_off_host_backup_upload_is_a_hard_gate_before_deployment(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        backup = source.index("Create and validate the required pre-mutation recovery point")
        upload = source.index("Upload encrypted recovery point outside the homelab failure domain")
        deploy = source.index("Deploy or resume only from immutable off-host recovery evidence")
        self.assertLess(backup, upload)
        self.assertLess(upload, deploy)
        self.assertIn("steps.backup-upload.outputs.artifact-id", source)
        self.assertIn("-BackupReference", source)

    def test_uploaded_backups_use_the_shared_valid_evidence_record(self) -> None:
        deploy_source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        scheduled_source = (
            PRIVATE_DEPLOY.parent / "backup-selfhandler.yml"
        ).read_text(encoding="utf-8")
        self.assertIn("finalize-release.ps1", deploy_source)
        self.assertIn("CompletionBackupReference", deploy_source)
        self.assertIn("Bind-OffHostBackupReference", scheduled_source)
        self.assertIn("completion_validated", deploy_source)
        self.assertIn("pending-backups", scheduled_source)
        self.assertNotIn("retention_days = 30", deploy_source)
        self.assertNotIn("retention_days = 30", scheduled_source)

    def test_inspection_validates_the_qualified_health_report_contract(self) -> None:
        inspect_source = (
            PRIVATE_DEPLOY.parent / "inspect-selfhandler.yml"
        ).read_text(encoding="utf-8")
        validation = inspect_source.index("health-report.schema.json")
        publication = inspect_source.index("Publish the non-secret inspection report")
        self.assertLess(validation, publication)
        self.assertIn("https://json-schema.org/draft/2020-12/schema", inspect_source)
        self.assertIn("Assert-ExactProperties", inspect_source)
        self.assertIn("additionalProperties", inspect_source)

    def test_attestations_are_verified_with_a_checksum_pinned_portable_cli(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        self.assertIn("gh_2.97.0_windows_amd64.zip", source)
        self.assertIn("35d7fe05c4dd1411ffda1e73dfc7c6f44b75c936ca51fa6595c657fdc0350cec", source)
        self.assertEqual(2, source.count("attestation verify"))
        self.assertIn("PanyaPrimal/selfhandler-ops", source)
        self.assertIn("--signer-workflow", source)
        self.assertIn("PanyaPrimal/selfhandler-ops/.github/workflows/deploy-selfhandler.yml", source)
        self.assertIn("--signer-digest", source)
        self.assertIn(
            "WORKFLOW_REVISION: ${{ needs.resolve-active.outputs.resume_workflow_sha || github.workflow_sha }}",
            source,
        )
        self.assertIn("--deny-self-hosted-runners", source)

    def test_hosted_release_has_explicit_privilege_separation(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        workflow = yaml.safe_load(source)
        qualify = workflow["jobs"]["qualify"]
        publish = workflow["jobs"]["publish"]
        package = workflow["jobs"]["package"]
        self.assertEqual("read", qualify["permissions"]["packages"])
        self.assertNotIn("id-token", qualify["permissions"])
        self.assertEqual("write", publish["permissions"]["packages"])
        self.assertEqual("write", publish["permissions"]["attestations"])
        self.assertEqual({"contents": "read"}, package["permissions"])
        self.assertNotRegex(source, r"(?m)^\s+uses:\s+[^\s]+@(?:master|main)\s*$")

    def test_authenticated_dispatch_binds_the_exact_qualified_revision(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        workflow = yaml.safe_load(source)
        request = workflow["jobs"]["request"]
        publish = workflow["jobs"]["publish"]
        deploy = workflow["jobs"]["deploy"]
        self.assertEqual("ubuntu-24.04", request["runs-on"])
        self.assertEqual("request", workflow["jobs"]["resolve-active"]["needs"])
        self.assertNotIn("environment", publish)
        self.assertNotIn("environment", deploy)
        self.assertIn("github.event.client_payload.source_revision", source)
        self.assertIn("github.event.client_payload.source_repository", source)
        self.assertIn("github.event.client_payload.request_id", source)
        self.assertIn("github.event.sender.login", source)
        self.assertIn("request_id,source_repository,source_revision", source)
        self.assertIn("^[0-9a-f]{32}$", source)
        self.assertGreaterEqual(source.count("git ls-remote https://github.com/PanyaPrimal/selfHandlerApp.git refs/heads/master"), 3)
        steps = publish["steps"]
        verify = next(index for index, step in enumerate(steps) if step["name"] == "Verify source identity before registry authentication")
        login = next(index for index, step in enumerate(steps) if step["name"] == "Log in to GHCR only after every public build has completed")
        self.assertLess(verify, login)
        self.assertIn("git ls-remote", steps[verify]["run"])
        self.assertIn('test "$current_master" = "$SOURCE_REVISION"', steps[verify]["run"])
        self.assertEqual(
            "Reconfirm canonical master immediately before registry authentication",
            steps[login - 1]["name"],
        )
        self.assertIn("git ls-remote", steps[login - 1]["run"])
        self.assertEqual(
            "Reconfirm the authenticated canonical revision before homelab execution",
            deploy["steps"][0]["name"],
        )

    def test_no_public_script_runs_while_publish_registry_credential_is_active(self) -> None:
        workflow = yaml.safe_load(PRIVATE_DEPLOY.read_text(encoding="utf-8"))
        steps = workflow["jobs"]["publish"]["steps"]
        login = next(index for index, step in enumerate(steps) if step["name"] == "Log in to GHCR only after every public build has completed")
        logout = next(index for index, step in enumerate(steps) if step["name"] == "Clear every persisted registry credential")
        self.assertLess(login, logout)
        build_steps = [
            (index, step)
            for index, step in enumerate(steps)
            if "docker/build-push-action@" in str(step.get("uses", ""))
        ]
        self.assertEqual(2, len(build_steps))
        for index, step in build_steps:
            with self.subTest(step=step["name"]):
                self.assertLess(index, login)
                self.assertTrue(step["with"]["load"])
                self.assertFalse(step["with"]["push"])
                self.assertEqual("", step["with"]["github-token"])
        active_steps = steps[login + 1 : logout]
        for step in active_steps:
            command = str(step.get("run", "")).lower()
            with self.subTest(step=step["name"]):
                self.assertNotRegex(command, r"\b(?:python|php|composer|npm|npx)\b")
                self.assertNotIn("deployment/", command)
                self.assertNotIn("docker/build-push-action@", str(step.get("uses", "")))
        self.assertEqual(
            "Refuse overwrite of the unique qualification image pair",
            active_steps[0]["name"],
        )

    def test_publish_uses_unique_attempt_tags_and_never_adopts_existing_images(self) -> None:
        workflow = yaml.safe_load(PRIVATE_DEPLOY.read_text(encoding="utf-8"))
        publish = workflow["jobs"]["publish"]
        self.assertIn("github.run_id", publish["env"]["QUALIFICATION_TAG"])
        self.assertIn("github.run_attempt", publish["env"]["QUALIFICATION_TAG"])
        refuse = next(
            item
            for item in publish["steps"]
            if item["name"] == "Refuse overwrite of the unique qualification image pair"
        )
        self.assertIn("assert_absent", refuse["run"])
        self.assertIn("Registry absence could not be established safely", refuse["run"])
        self.assertIn("already exists and cannot be overwritten", refuse["run"])
        push = next(
            item
            for item in publish["steps"]
            if item["name"] == "Push only the already-built unique qualification image pair"
        )
        self.assertIn('docker push "$image:$QUALIFICATION_TAG"', push["run"])
        self.assertNotIn("docker pull", push["run"])
        self.assertNotIn("remote_id", push["run"])

    def test_prepared_and_pending_release_resume_preserve_original_run_attempt(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        workflow = yaml.safe_load(source)
        resolve = workflow["jobs"]["resolve-active"]
        qualify = workflow["jobs"]["qualify"]
        deploy = workflow["jobs"]["deploy"]
        self.assertIn("resume_release", resolve["outputs"])
        self.assertIn("resume_prepared", resolve["outputs"])
        self.assertIn("resume_pending", resolve["outputs"])
        self.assertIn("prepared-release.json", source)
        self.assertIn("state = 'qualified'", source)
        self.assertIn("manifestRunAttempt", source)
        self.assertIn("Manifest qualification attempt is newer", source)
        self.assertIn("workflow_run_attempt = [string]$env:TRUSTED_WORKFLOW_RUN_ATTEMPT", source)
        stage_start = source.index("Stage the immutable qualified operations bundle")
        first_bundle = source.index("Reconfirm canonical master immediately before first public bundle execution")
        self.assertLess(source.index("prepared-release.json", stage_start), first_bundle)
        self.assertIn("needs.resolve-active.outputs.resume_release != 'true'", qualify["if"])
        self.assertIn("needs.resolve-active.outputs.resume_release == 'true'", deploy["if"])
        self.assertIn("Reconcile a same-run crash journal", source)
        self.assertIn("steps.journal.outputs.pending != 'true'", source)
        resolve_source = resolve["steps"][0]["run"]
        self.assertIn(
            "Join-Path 'C:\\Homelab\\SelfHandlerApp\\state\\qualified-bundles' $effectiveRevision",
            resolve_source,
        )
        self.assertIn(
            "$effectiveRevision = if ($preparedMode) { $env:REQUESTED_REVISION } else { [string]$pending.source_revision }",
            resolve_source,
        )
        self.assertIn("$pendingRecords.Count -gt 1", resolve_source)
        self.assertNotIn("Get-ChildItem -LiteralPath $qualifiedRoot", resolve_source)

    def test_finalization_is_last_and_follows_durable_ops_and_completion_backup(self) -> None:
        workflow = yaml.safe_load(PRIVATE_DEPLOY.read_text(encoding="utf-8"))
        steps = workflow["jobs"]["deploy"]["steps"]
        names = [step["name"] for step in steps]
        activate = names.index("Activate the original checksum-qualified operations identity for finalization")
        select = names.index("Select the immutable completion recovery reference")
        recheck = names.index("Reconfirm canonical master immediately before release finalization")
        finalize = names.index("Finalize active release only after operations and recovery evidence are durable")
        retry_current = names.index("Require a fresh dispatch after completing an older pending release")
        self.assertLess(activate, select)
        self.assertLess(select, recheck)
        self.assertEqual(finalize, len(steps) - 2)
        self.assertEqual(retry_current, len(steps) - 1)
        self.assertEqual(recheck, finalize - 1)
        self.assertIn("-CompletionBackupReference", steps[finalize]["run"])
        self.assertIn("schema_version = 2", steps[activate]["run"])
        self.assertNotIn("deployment\\scripts", steps[retry_current]["run"])

    def test_terminal_success_is_adopted_after_ack_crash_without_new_mutation(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        workflow = yaml.safe_load(source)
        resolve_source = workflow["jobs"]["resolve-active"]["steps"][0]["run"]
        deploy_steps = workflow["jobs"]["deploy"]["steps"]
        self.assertIn("resume_complete", workflow["jobs"]["resolve-active"]["outputs"])
        self.assertIn("Canonical terminal release evidence", resolve_source)
        self.assertIn("$terminal.backup_reference", resolve_source)
        self.assertIn("$activeState.web_digest", resolve_source)
        for name in (
            "Create and validate the required pre-mutation recovery point",
            "Upload encrypted recovery point outside the homelab failure domain",
            "Activate the original checksum-qualified operations identity for finalization",
        ):
            step = next(item for item in deploy_steps if item["name"] == name)
            self.assertIn("env.ALREADY_COMPLETE != 'true'", step["if"])
        deploy = next(
            item
            for item in deploy_steps
            if item["name"] == "Deploy or resume only from immutable off-host recovery evidence"
        )
        self.assertNotIn("if", deploy)
        self.assertIn("TERMINAL_BACKUP_REFERENCE", deploy["env"])
        self.assertIn("$env:ALREADY_COMPLETE -eq 'true'", deploy["run"])
        completion = next(
            item
            for item in deploy_steps
            if item["name"] == "Select the immutable completion recovery reference"
        )
        self.assertIn("TERMINAL_COMPLETION_REFERENCE", completion["env"])
        self.assertIn("$env:ALREADY_COMPLETE -eq 'true'", completion["run"])
        self.assertEqual(
            "Finalize active release only after operations and recovery evidence are durable",
            deploy_steps[-2]["name"],
        )

    def test_new_master_dispatch_completes_one_older_pending_release_then_requires_retry(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        workflow = yaml.safe_load(source)
        resolve = workflow["jobs"]["resolve-active"]
        deploy = workflow["jobs"]["deploy"]
        resolve_source = resolve["steps"][0]["run"]
        self.assertIn("resume_source_revision", resolve["outputs"])
        self.assertIn("resume_requested_mismatch", resolve["outputs"])
        self.assertIn("$pendingRecords.Count -gt 1", resolve_source)
        self.assertIn("[string]$pending.source_revision", resolve_source)
        self.assertIn("$requestedMismatch = $effectiveRevision -ne $env:REQUESTED_REVISION", resolve_source)
        self.assertEqual(
            "${{ needs.resolve-active.outputs.resume_source_revision || needs.request.outputs.source_revision }}",
            deploy["env"]["SOURCE_REVISION"],
        )
        self.assertEqual(
            "${{ needs.resolve-active.outputs.resume_requested_mismatch }}",
            deploy["env"]["RESUMED_DIFFERENT_REVISION"],
        )
        canonical_checks = [
            step
            for step in deploy["steps"]
            if step["name"].startswith("Reconfirm canonical master")
        ]
        self.assertGreaterEqual(len(canonical_checks), 3)
        for step in canonical_checks:
            with self.subTest(step=step["name"]):
                self.assertIn("REQUESTED_SOURCE_REVISION", step["run"])
        signal = deploy["steps"][-1]
        self.assertEqual(
            "Require a fresh dispatch after completing an older pending release",
            signal["name"],
        )
        self.assertEqual("env.RESUMED_DIFFERENT_REVISION == 'true'", signal["if"])
        self.assertIn("pending_release_completed_retry_current", signal["run"])
        self.assertIn("JOURNAL_COMPLETE", source)
        self.assertIn("JOURNAL_COMPLETION_REFERENCE", source)

    def test_bundle_execution_has_adjacent_canonical_master_rechecks(self) -> None:
        workflow = yaml.safe_load(PRIVATE_DEPLOY.read_text(encoding="utf-8"))
        steps = workflow["jobs"]["deploy"]["steps"]
        names = [step["name"] for step in steps]
        backup = names.index("Create and validate the required pre-mutation recovery point")
        deploy = names.index("Deploy or resume only from immutable off-host recovery evidence")
        finalize = names.index("Finalize active release only after operations and recovery evidence are durable")
        self.assertEqual(
            "Reconfirm canonical master immediately before first public bundle execution",
            names[backup - 1],
        )
        self.assertEqual(
            "Reconfirm canonical master immediately before deployment or resume",
            names[deploy - 1],
        )
        self.assertEqual(
            "Reconfirm canonical master immediately before release finalization",
            names[finalize - 1],
        )

    def test_private_free_plan_workflows_do_not_use_environments(self) -> None:
        for path in sorted(PRIVATE_DEPLOY.parent.glob("*.yml")):
            with self.subTest(path=path.name):
                workflow = yaml.safe_load(path.read_text(encoding="utf-8"))
                for job in workflow.get("jobs", {}).values():
                    self.assertNotIn("environment", job)

    def test_private_runner_has_a_dedicated_account_and_documents_shared_daemon_risk(self) -> None:
        source = (PRIVATE_DEPLOY.parents[2] / "README.md").read_text(encoding="utf-8")
        self.assertIn("dedicated non-administrator SelfHandler Windows account", source)
        self.assertIn("Never reuse the DealFlow OS account", source)
        self.assertIn("shared-daemon residual risk", source)
        self.assertIn("separate Docker daemon or VM", source)
        self.assertIn("probe-account-password.txt", source)

    def test_installed_and_resumed_operations_require_protected_acl_and_signer_record(self) -> None:
        deploy_source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        for marker in (
            "AreAccessRulesProtected",
            "WindowsIdentity]::GetCurrent().User.Value",
            "S-1-5-32-544",
            "S-1-5-18",
            "untrusted owner",
            "trust_metadata_sha256",
            "workflow_sha",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, deploy_source)
        for name in ("backup-selfhandler.yml", "inspect-selfhandler.yml"):
            source = (PRIVATE_DEPLOY.parent / name).read_text(encoding="utf-8")
            with self.subTest(workflow=name):
                self.assertIn("Assert-ProtectedOperationsPath", source)
                self.assertIn("trust_metadata_sha256", source)
                self.assertIn("workflow_sha", source)

    def test_previous_image_credential_is_cleared_before_compatibility_smoke(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        preload = source.index("Preload the exact previous image and clear the registry credential")
        logout = source.index("docker logout ghcr.io", preload)
        compatibility = source.index("Prove previous application compatibility with the forward schema")
        checkout = source.index("Check out only the exact canonical public revision")
        self.assertLess(preload, logout)
        self.assertLess(logout, checkout)
        self.assertLess(logout, compatibility)
        compatibility_source = source[compatibility : source.index("Record bootstrap compatibility evidence", compatibility)]
        self.assertIn("--previous-app-preloaded", compatibility_source)
        self.assertIn("--forward-schema-only", compatibility_source)

    def test_recovery_and_bootstrap_evidence_are_real_hard_gates(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        self.assertIn("python deployment/tests/recovery_smoke.py", source)
        self.assertIn("bdc69c09cbdd6cf8b1f333d372a1f58247b3a33146406333e30c0f26e8f51377", source)
        for value in ("SELFHANDLER_APP_IMAGE", "SELFHANDLER_WEB_IMAGE", "APP_RELEASE_SHA"):
            with self.subTest(value=value):
                self.assertGreaterEqual(source.count(value), 2)

    def test_private_tokens_and_registry_login_never_reach_bundle_scripts(self) -> None:
        source = PRIVATE_DEPLOY.read_text(encoding="utf-8")
        workflow = yaml.safe_load(source)
        qualify = workflow["jobs"]["qualify"]
        deploy = workflow["jobs"]["deploy"]
        self.assertNotIn("secrets", qualify)
        self.assertNotIn("GITHUB_TOKEN", deploy.get("env", {}))
        self.assertNotIn("GH_TOKEN", deploy.get("env", {}))
        token_steps = []
        for step in deploy["steps"]:
            step_env = step.get("env", {})
            if "GITHUB_TOKEN" in step_env or "GH_TOKEN" in step_env:
                token_steps.append(step["name"])
        self.assertEqual(
            [
                "Download the same-run artifact without a source checkout",
                "Provision checksum-pinned portable gh and verify provenance plus OCI labels",
            ],
            token_steps,
        )
        logout = source.index("docker logout ghcr.io")
        bundle_execution = source.index("Create and validate the required pre-mutation recovery point")
        self.assertLess(logout, bundle_execution)


if __name__ == "__main__":
    unittest.main()
