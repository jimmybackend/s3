import importlib.util
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("operator_bridge", ROOT / "operator.py")
operator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(operator)


class FixedTerminalProbes(unittest.TestCase):
    def test_only_fixed_readonly_probes(self):
        for arg, marker in [
            ("terminal-kernel", "uname -srm"),
            ("terminal-load", "cat /proc/loadavg"),
            ("terminal-root-usage", "df -P /"),
        ]:
            request = operator.validate_request({
                "request_id": "terminal-test-001",
                "alias": "small",
                "operation": "diagnose",
                "argument": arg,
                "confirm": "READ_DIAGNOSTIC",
            })
            script = operator.script_for(request)
            self.assertIn(marker, script)
            self.assertNotIn("sudo", script)
            self.assertNotIn("eval", script)

    def test_rejects_injection(self):
        for arg in ("terminal-kernel; id", "$(id)", "bash", "terminal-kernel\nwhoami"):
            with self.assertRaises(ValueError):
                operator.validate_request({
                    "request_id": "terminal-test-002",
                    "alias": "small",
                    "operation": "diagnose",
                    "argument": arg,
                    "confirm": "READ_DIAGNOSTIC",
                })


if __name__ == "__main__":
    unittest.main()
