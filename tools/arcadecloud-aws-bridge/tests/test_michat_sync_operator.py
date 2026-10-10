"""Contract for the fixed MiChat GitOps action."""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "arcade_operator", Path(__file__).resolve().parents[1] / "operator.py"
)
ops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ops)


def request(alias="small"):
    return {
        "request_id": "michat-sync-contract-001",
        "alias": alias,
        "operation": "service",
        "argument": "michat:sync-main",
        "confirm": "SERVICE_CHANGE",
    }


class MiChatSyncContract(unittest.TestCase):
    def test_fixed_safe_gitops(self):
        validated = ops.validate_request(request())
        script = ops.script_for(validated)
        self.assertIn("APP=/var/www/michat", script)
        self.assertIn("git status --porcelain=v1", script)
        self.assertIn("git fetch --quiet origin main", script)
        self.assertIn("git merge --ff-only origin/main", script)
        self.assertIn("jimmybackend/michat", script)
        self.assertNotIn("reset --hard", script)
        self.assertNotIn("git clean", script)
        self.assertNotIn("stash", script)
        self.assertNotIn("systemctl restart", script)

    def test_large_node_is_rejected(self):
        with self.assertRaises(ValueError):
            ops.validate_request(request("large"))


if __name__ == "__main__":
    unittest.main()
