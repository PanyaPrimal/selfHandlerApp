from __future__ import annotations

import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "deployment" / "scripts" / "configure-private-route.ps1"


class TailscaleRouteTests(unittest.TestCase):
    def test_configuration_is_additive_and_rollback_is_scoped_to_8443(self) -> None:
        source = SCRIPT.read_text(encoding="utf-8")

        self.assertIn("serve status --json", source)
        self.assertIn("funnel status --json", source)
        self.assertIn("--https=8443", source)
        self.assertIn("http://127.0.0.1:18080", source)
        self.assertRegex(source, r"serve\s+--yes\s+--https=8443\s+off")
        self.assertNotIn("serve reset", source)
        self.assertNotIn("set-config --all", source)
        self.assertNotIn("funnel --https=443 off", source)
        self.assertIn('[ValidateSet("Verify", "Apply")]', source)
        self.assertIn("Assert-WindowsAdministrator", source)

        apply_branch = source.index('if ($Mode -eq "Apply")')
        administrator_gate = source.index("Assert-WindowsAdministrator", apply_branch)
        mutation = source.index("tailscale serve --bg --yes --https=8443", administrator_gate)
        self.assertLess(apply_branch, administrator_gate)
        self.assertLess(administrator_gate, mutation)

    def test_deploy_verifies_admin_route_between_local_and_private_gates(self) -> None:
        deploy_source = (
            ROOT / "deployment" / "scripts" / "deploy-production.ps1"
        ).read_text(encoding="utf-8")
        local_gate = deploy_source.index('if ($local.status -ne "healthy")')
        route_setup = deploy_source.index(
            "Invoke-ConfigureSelfHandlerPrivateRoute -Mode Verify -LockAlreadyHeld",
            local_gate,
        )
        private_gate = deploy_source.index(
            "Test-SelfHandlerReadiness -Scope Private",
            route_setup,
        )
        self.assertLess(local_gate, route_setup)
        self.assertLess(route_setup, private_gate)

        route_source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("[switch]$LockAlreadyHeld", route_source)
        self.assertIn("if (-not $LockAlreadyHeld)", route_source)

    def test_auth_smoke_sends_stateful_origin_and_referer_headers(self) -> None:
        source = (
            ROOT / "deployment" / "scripts" / "auth-smoke.ps1"
        ).read_text(encoding="utf-8")
        self.assertIn("Origin = $originText", source)
        self.assertIn('Referer = ($originText + "/")', source)
        for endpoint in ("/api/auth/login", "/api/auth/register", "/api/auth/logout"):
            endpoint_at = source.index(endpoint)
            invocation_end = source.index("-UseBasicParsing", endpoint_at)
            self.assertIn("-Headers $headers", source[endpoint_at:invocation_end])
        empty_user_gate = source.index("Assert-BootstrapUserTableEmpty")
        register_body = source.index("$registerBody")
        self.assertLess(empty_user_gate, register_body)

    def test_fixture_comparison_preserves_funnel_and_accepts_only_expected_serve_delta(self) -> None:
        escaped = str(SCRIPT).replace("'", "''")
        before_serve = '{"TCP":{"443":{"HTTPS":true}},"Web":{"homelab.tail31a802.ts.net:443":{"Handlers":{"/":{"Proxy":"http://127.0.0.1:3000"}}}},"AllowFunnel":{"homelab.tail31a802.ts.net:443":true}}'
        after_serve = '{"TCP":{"443":{"HTTPS":true},"8443":{"HTTPS":true}},"Web":{"homelab.tail31a802.ts.net:443":{"Handlers":{"/":{"Proxy":"http://127.0.0.1:3000"}}},"homelab.tail31a802.ts.net:8443":{"Handlers":{"/":{"Proxy":"http://127.0.0.1:18080"}}}},"AllowFunnel":{"homelab.tail31a802.ts.net:443":true}}'
        command = (
            f". '{escaped}'; "
            f"Assert-SelfHandlerTailscaleDelta -BeforeServeJson '{before_serve}' "
            f"-AfterServeJson '{after_serve}' -BeforeFunnelJson '{before_serve}' -AfterFunnelJson '{after_serve}'; "
            "'passed'"
        )
        result = subprocess.run(
            ["powershell", "-NoLogo", "-NoProfile", "-NonInteractive", "-Command", command],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("passed", result.stdout)

    def test_fixture_comparison_rejects_dealflow_funnel_change(self) -> None:
        escaped = str(SCRIPT).replace("'", "''")
        before_serve = '{}'
        after_serve = '{"TCP":{"8443":{"HTTPS":true}},"Web":{"homelab.tail31a802.ts.net:8443":{"Handlers":{"/":{"Proxy":"http://127.0.0.1:18080"}}}}}'
        command = (
            f". '{escaped}'; "
            f"Assert-SelfHandlerTailscaleDelta -BeforeServeJson '{before_serve}' "
            f"-AfterServeJson '{after_serve}' -BeforeFunnelJson '{{\"AllowFunnel\":{{\"homelab.tail31a802.ts.net:443\":true}}}}' "
            "-AfterFunnelJson '{\"AllowFunnel\":{}}';"
        )
        result = subprocess.run(
            ["powershell", "-NoLogo", "-NoProfile", "-NonInteractive", "-Command", command],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("DealFlow", result.stdout + result.stderr)

    def test_fixture_comparison_rejects_dropped_existing_serve_route(self) -> None:
        escaped = str(SCRIPT).replace("'", "''")
        before_serve = '{"TCP":{"443":{"HTTPS":true}},"Web":{"homelab.tail31a802.ts.net:443":{"Handlers":{"/":{"Proxy":"http://127.0.0.1:3000"}}}},"AllowFunnel":{"homelab.tail31a802.ts.net:443":true}}'
        after_serve = '{"TCP":{"8443":{"HTTPS":true}},"Web":{"homelab.tail31a802.ts.net:8443":{"Handlers":{"/":{"Proxy":"http://127.0.0.1:18080"}}}},"AllowFunnel":{"homelab.tail31a802.ts.net:443":true}}'
        command = (
            f". '{escaped}'; "
            f"Assert-SelfHandlerTailscaleDelta -BeforeServeJson '{before_serve}' "
            f"-AfterServeJson '{after_serve}' -BeforeFunnelJson '{before_serve}' -AfterFunnelJson '{after_serve}';"
        )
        result = subprocess.run(
            ["powershell", "-NoLogo", "-NoProfile", "-NonInteractive", "-Command", command],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Serve configuration", result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
