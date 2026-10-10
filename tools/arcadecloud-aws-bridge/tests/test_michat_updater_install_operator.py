"""Contract for MiChat updater installation through the fixed SSM operator."""
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
        "request_id": "michat-updater-install-contract",
        "alias": alias,
        "operation": "service",
        "argument": "michat:install-updater",
        "confirm": "SERVICE_CHANGE",
    }


class MiChatUpdaterInstallContract(unittest.TestCase):
    def test_install_is_fixed_to_michat_and_detects_real_fpm_pool(self):
        validated = ops.validate_request(request())
        script = ops.script_for(validated)
        self.assertIn("APP=/var/www/michat", script)
        self.assertIn("chat.esforzados.com", script)
        self.assertIn("/usr/sbin/nginx', '-T'", script)
        self.assertIn("install_michat_updater.sh", script)
        self.assertIn("/usr/local/sbin/michat-updater probe", script)
        self.assertIn("MICHAT_UPDATER_OK", script)
        self.assertNotIn("reset --hard", script)
        self.assertNotIn("git clean", script)

    def test_install_is_small_node_only(self):
        with self.assertRaises(ValueError):
            ops.validate_request(request("large"))


if __name__ == "__main__":
    unittest.main()
