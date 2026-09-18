from __future__ import annotations

import configparser
import re
import shlex
import unittest
from pathlib import Path

import yaml


ROOT = Path(__file__).resolve().parents[2]


class BackgroundRuntimeContractTests(unittest.TestCase):
    def setUp(self) -> None:
        self.config = configparser.ConfigParser(interpolation=None)
        self.config.read(ROOT / "deployment/docker/supervisord.conf", encoding="utf-8")
        self.compose = yaml.safe_load((ROOT / "deployment/compose.production.yaml").read_text())

    def test_all_background_writers_share_the_existing_application_lifecycle(self) -> None:
        programs = {section for section in self.config.sections() if section.startswith("program:")}
        self.assertEqual({"program:fpm", "program:scheduler", "program:queue"}, programs)
        self.assertEqual({"db", "app", "web"}, set(self.compose["services"]))
        self.assertEqual("82:82", self.compose["services"]["app"]["user"])
        for name in programs:
            with self.subTest(program=name):
                program = self.config[name]
                self.assertTrue(program.getboolean("autostart"))
                self.assertTrue(program.getboolean("autorestart"))
                self.assertTrue(program.getboolean("stopasgroup"))
                self.assertTrue(program.getboolean("killasgroup"))
                self.assertEqual("/app", program["directory"])
                self.assertLessEqual(program.getint("startretries"), 5)
        self.assertIn("schedule:work", shlex.split(self.config["program:scheduler"]["command"]))
        self.assertIn("queue:work", shlex.split(self.config["program:queue"]["command"]))
        self.assertIn("database", shlex.split(self.config["program:queue"]["command"]))

    def test_job_timeout_precedes_retry_and_container_shutdown_allows_drain(self) -> None:
        command = shlex.split(self.config["program:queue"]["command"])
        timeout = int(next(arg.split("=", 1)[1] for arg in command if arg.startswith("--timeout=")))
        queue_source = (ROOT / "apps/api/config/queue.php").read_text()
        retry = int(re.search(r"env\('DB_QUEUE_RETRY_AFTER', (\d+)\)", queue_source).group(1))
        drain = self.config["program:queue"].getint("stopwaitsecs")
        grace = int(self.compose["services"]["app"]["stop_grace_period"].removesuffix("s"))
        self.assertLess(timeout, retry)
        self.assertLess(timeout, drain)
        self.assertLess(drain, grace)
        dockerfile = (ROOT / "deployment/docker/Dockerfile").read_text()
        self.assertRegex(dockerfile, r"docker-php-ext-install[^\n]+\bpcntl\b")

    def test_control_socket_is_private_and_health_includes_background_processes(self) -> None:
        self.assertNotIn("inet_http_server", self.config)
        self.assertEqual("0700", self.config["unix_http_server"]["chmod"])
        self.assertEqual(
            ["CMD", "/usr/local/bin/app-healthcheck"],
            self.compose["services"]["app"]["healthcheck"]["test"],
        )
        health = (ROOT / "deployment/docker/app-healthcheck.sh").read_text()
        for name in ("fpm", "scheduler", "queue"):
            self.assertIn('"' + name + '"', health)
        self.assertIn("RUNNING", health)
        self.assertIn("9000", health)
        dockerfile = (ROOT / "deployment/docker/Dockerfile").read_text()
        self.assertIn('CMD ["/usr/bin/supervisord", "-c", "/etc/selfhandler/supervisord.conf"]', dockerfile)


if __name__ == "__main__":
    unittest.main()
