import importlib.util
import pathlib
import unittest

root = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("operator_module", root / "operator.py")
ops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ops)

class LargeDockerTests(unittest.TestCase):
    def test_large_diagnostics_only(self):
        request = dict(request_id="docker-safe-001", alias="large", operation="diagnose",
                       argument="docker-health-large", confirm="READ_DIAGNOSTIC")
        self.assertEqual(ops.validate_request(request), request)
        script = ops.script_for(request)
        self.assertIn("docker', 'ps', '-a'", script)
        self.assertNotIn("docker exec", script)
        self.assertNotIn("docker restart", script)
        self.assertNotIn("inspect", script)

    def test_small_not_authorized(self):
        request = dict(request_id="docker-safe-001", alias="small", operation="diagnose",
                       argument="docker-health-large", confirm="READ_DIAGNOSTIC")
        with self.assertRaises(ValueError):
            ops.validate_request(request)

    def test_arbitrary_commands_rejected(self):
        for command in ("docker exec", "docker-health-large; id", "docker logs"):
            request = dict(request_id="docker-safe-001", alias="large", operation="diagnose",
                           argument=command, confirm="READ_DIAGNOSTIC")
            with self.assertRaises(ValueError):
                ops.validate_request(request)

if __name__ == "__main__":
    unittest.main()
