from __future__ import annotations

import json
import unittest
from pathlib import Path

import yaml


ROOT = Path(__file__).resolve().parents[2]
SCRIPTS = ROOT / "deployment" / "scripts"


class PublicIngressTests(unittest.TestCase):
    def test_fixed_origin_is_the_drpanya_https_hostname(self) -> None:
        shared = (SCRIPTS / "shared.ps1").read_text(encoding="utf-8")
        env_example = (ROOT / "deployment" / "env.production.example").read_text(
            encoding="utf-8"
        )
        ops_example = (ROOT / "deployment" / "ops-config.example").read_text(
            encoding="utf-8"
        )

        self.assertIn(
            '$script:SelfHandlerPublicOrigin = "https://selfhandler.drpanya.uk"',
            shared,
        )
        self.assertIn("APP_URL=https://selfhandler.drpanya.uk", env_example)
        self.assertIn("SANCTUM_STATEFUL_DOMAINS=selfhandler.drpanya.uk", env_example)
        self.assertIn("PUBLIC_ORIGIN=https://selfhandler.drpanya.uk", ops_example)

    def test_release_checks_local_then_public_readiness_without_tailscale(self) -> None:
        deploy = (SCRIPTS / "deploy-production.ps1").read_text(encoding="utf-8")
        local_gate = deploy.index('if ($local.status -ne "healthy")')
        public_gate = deploy.index(
            "Test-SelfHandlerReadiness -Scope Public", local_gate
        )

        self.assertLess(local_gate, public_gate)
        self.assertNotIn("configure-private-route", deploy)
        self.assertNotIn('"tailscale"', deploy)
        self.assertFalse((SCRIPTS / "configure-private-route.ps1").exists())

    def test_auth_smoke_sends_public_stateful_origin_and_referer_headers(self) -> None:
        source = (SCRIPTS / "auth-smoke.ps1").read_text(encoding="utf-8")

        self.assertIn("[Uri]$script:SelfHandlerPublicOrigin", source)
        self.assertIn("Origin = $originText", source)
        self.assertIn('Referer = ($originText + "/")', source)
        self.assertIn("function New-BootstrapInvitation", source)
        self.assertIn("invite:create", source)
        self.assertIn("invite_code = $inviteCode", source)
        for endpoint in ("/api/auth/login", "/api/auth/register", "/api/auth/logout"):
            endpoint_at = source.index(endpoint)
            invocation_end = source.index("-UseBasicParsing", endpoint_at)
            self.assertIn("-Headers $headers", source[endpoint_at:invocation_end])

    def test_production_compose_keeps_databases_private_and_web_loopback_only(self) -> None:
        compose_path = ROOT / "deployment" / "compose.production.yaml"
        compose = yaml.safe_load(compose_path.read_text(encoding="utf-8"))

        self.assertEqual(
            "https://selfhandler.drpanya.uk",
            compose["services"]["app"]["environment"]["APP_URL"],
        )
        self.assertEqual(
            "selfhandler.drpanya.uk",
            compose["services"]["app"]["environment"]["SANCTUM_STATEFUL_DOMAINS"],
        )
        self.assertEqual(["127.0.0.1:18080:8080"], compose["services"]["web"]["ports"])
        self.assertFalse(compose["services"]["app"].get("ports"))
        self.assertFalse(compose["services"]["db"].get("ports"))
        self.assertTrue(compose["networks"]["app"]["external"])
        self.assertEqual(
            "${GOOGLE_CALENDAR_CLIENT_ID:-}",
            compose["services"]["app"]["environment"]["GOOGLE_CALENDAR_CLIENT_ID"],
        )
        self.assertEqual(
            "${GOOGLE_CALENDAR_CLIENT_SECRET:-}",
            compose["services"]["app"]["environment"]["GOOGLE_CALENDAR_CLIENT_SECRET"],
        )

    def test_health_contract_reports_public_route(self) -> None:
        schema = json.loads(
            (
                ROOT
                / "specs"
                / "002-homelab-deployment"
                / "contracts"
                / "health-report.schema.json"
            ).read_text(encoding="utf-8")
        )

        self.assertIn("public_route", schema["required"])
        self.assertIn("public_route", schema["properties"])
        self.assertNotIn("private_route", schema["properties"])

    def test_operator_docs_define_shared_caddy_ingress(self) -> None:
        docs = (ROOT / "deployment" / "README.md").read_text(encoding="utf-8")

        self.assertIn("https://selfhandler.drpanya.uk", docs)
        self.assertIn("selfhandler_app", docs)
        self.assertIn("reverse_proxy web:8080", docs)
        self.assertNotIn("homelab.tail31a802.ts.net", docs)
        self.assertNotIn("Tailscale Serve", docs)


if __name__ == "__main__":
    unittest.main()
