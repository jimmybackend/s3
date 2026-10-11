"""Safeguards for sanitized SSM log snapshots."""
import importlib.util
import json
import pathlib
import unittest

root = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("bridge", root / "operator.py")
ops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ops)


class LogSummaryTests(unittest.TestCase):
    def test_fixed_log_commands_only(self):
        for name, family in (("nginx-log-summary", "nginx"), ("php-fpm-log-summary", "php"), ("system-log-summary", "system"), ("db-log-summary", "database"), ("auth-log-summary", "authentication")):
            request = {
                "request_id": "test-log-summary-001",
                "alias": "small",
                "operation": "diagnose",
                "argument": name,
                "confirm": "READ_DIAGNOSTIC",
            }
            self.assertEqual(ops.validate_request(request), request)
            script = ops.script_for(request)
            self.assertIn("ARCADECLOUD_LOG_SUMMARY=", script)
            self.assertNotIn("/etc/shadow", script)
            self.assertNotIn("sudo", script)
            self.assertIn("family = '" + family + "'", script)

    def test_rejects_free_form_log_arguments(self):
        for value in ("nginx-log-summary; cat /etc/passwd", "journalctl -xe", "php-fpm-log-summary\nid"):
            with self.assertRaises(ValueError):
                ops.validate_request({
                    "request_id": "test-log-summary-002",
                    "alias": "small",
                    "operation": "diagnose",
                    "argument": value,
                    "confirm": "READ_DIAGNOSTIC",
                })


if __name__ == "__main__":
    unittest.main()
