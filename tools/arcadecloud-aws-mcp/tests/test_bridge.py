"""Offline contracts: no AWS call is made by these tests."""
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from bridge import AwsBridge, BridgeError  # noqa: E402

SMALL = "i-0123456789abcdef0"
LARGE = "i-0fedcba9876543210"
COMMAND = "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"


class FakeClient:
    def __init__(self, name, calls):
        self.name = name
        self.calls = calls

    def describe_instances(self, **kwargs):
        self.calls.append((self.name, "describe", kwargs))
        return {"Reservations": [{"Instances": [{
            "InstanceId": kwargs["InstanceIds"][0],
            "InstanceType": "t3.large",
            "State": {"Name": "running"},
        }]}]}

    def get_caller_identity(self):
        self.calls.append((self.name, "identity", {}))
        return {"Account": "123456789012", "Arn": "arn:aws:iam::123456789012:role/test"}

    def start_instances(self, **kwargs):
        self.calls.append((self.name, "start", kwargs))
        return {"ResponseMetadata": {"RequestId": "test-req"}}

    def stop_instances(self, **kwargs):
        self.calls.append((self.name, "stop", kwargs))
        return {"ResponseMetadata": {"RequestId": "test-req"}}

    def send_command(self, **kwargs):
        self.calls.append((self.name, "send", kwargs))
        return {"Command": {"CommandId": COMMAND, "Status": "Pending"}}

    def get_command_invocation(self, **kwargs):
        self.calls.append((self.name, "result", kwargs))
        return {"Status": "Success", "StandardOutputContent": "ok",
                "StandardErrorContent": "", "ResponseCode": 0}


class AwsBridgeTests(unittest.TestCase):
    def setUp(self):
        self.calls = []
        self.env = {
            "ARCADECLOUD_MCP_SMALL_INSTANCE_ID": SMALL,
            "ARCADECLOUD_MCP_LARGE_INSTANCE_ID": LARGE,
            "AWS_REGION": "us-east-1",
        }

    def bridge(self):
        return AwsBridge(
            env=self.env,
            client_factory=lambda service, region: FakeClient(service, self.calls),
        )

    def test_inventory_only_two_allowlisted(self):
        results = self.bridge().inventory()
        self.assertEqual([x["alias"] for x in results], ["small", "large"])
        self.assertEqual(len(self.calls), 2)
        self.assertEqual(self.calls[0][2]["InstanceIds"], [SMALL])
        self.assertEqual(self.calls[1][2]["InstanceIds"], [LARGE])

    def test_unknown_alias_rejected(self):
        with self.assertRaises(BridgeError):
            self.bridge().describe("i-anything")
        self.assertEqual(self.calls, [])

    def test_fastdrive_fallback_for_large(self):
        self.env.pop("ARCADECLOUD_MCP_LARGE_INSTANCE_ID")
        self.env["ARCADECLOUD_FASTDRIVE_INSTANCE_ID"] = LARGE
        self.assertEqual(self.bridge().target("large").instance_id, LARGE)

    def test_invalid_config_denied(self):
        self.env["ARCADECLOUD_MCP_LARGE_INSTANCE_ID"] = "i-not-an-id"
        with self.assertRaises(BridgeError):
            self.bridge().inventory()
        self.assertEqual(self.calls, [])

    def test_identical_targets_denied(self):
        self.env["ARCADECLOUD_MCP_LARGE_INSTANCE_ID"] = SMALL
        with self.assertRaises(BridgeError):
            self.bridge().inventory()

    def test_docker_diagnostics_use_static_script(self):
        output = self.bridge().diagnostics("large", "docker")
        self.assertEqual(output["command_id"], COMMAND)
        service, call, args = self.calls[0]
        self.assertEqual((service, call), ("ssm", "send"))
        self.assertEqual(args["InstanceIds"], [LARGE])
        self.assertEqual(args["DocumentName"], "AWS-RunShellScript")
        self.assertIn("docker stats --no-stream", args["Parameters"]["commands"][0])

    def test_unknown_report_denied(self):
        with self.assertRaises(BridgeError):
            self.bridge().diagnostics("large", "sudo rm -rf /")

    def test_shell_disabled_by_default(self):
        with self.assertRaises(BridgeError):
            self.bridge().shell("large", "echo test", "revisión")
        self.assertEqual(self.calls, [])

    def test_shell_enabled_audited(self):
        self.env["ARCADECLOUD_MCP_ENABLE_SHELL"] = "1"
        self.bridge().shell("large", "echo test", "diagnóstico")
        self.assertEqual(self.calls[0][2]["Comment"], "ArcadeCloud MCP: diagnóstico")
        self.assertEqual(self.calls[0][2]["InstanceIds"], [LARGE])

    def test_small_never_stopped_by_default(self):
        self.env["ARCADECLOUD_MCP_ENABLE_POWER"] = "1"
        with self.assertRaises(BridgeError):
            self.bridge().power("small", "stop")
        self.assertEqual(self.calls, [])

    def test_large_power_requires_opt_in(self):
        with self.assertRaises(BridgeError):
            self.bridge().power("large", "start")
        self.env["ARCADECLOUD_MCP_ENABLE_POWER"] = "1"
        self.bridge().power("large", "start")
        self.assertEqual(self.calls[0][2]["InstanceIds"], [LARGE])

    def test_command_result_bound_to_alias(self):
        self.bridge().command_result("large", COMMAND)
        self.assertEqual(self.calls[0][2]["InstanceId"], LARGE)
        with self.assertRaises(BridgeError):
            self.bridge().command_result("large", "; touch hacked")


if __name__ == "__main__":
    unittest.main()
