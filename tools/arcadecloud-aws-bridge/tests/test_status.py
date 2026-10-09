"""Offline tests. No AWS network access or secrets required."""
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "bridge_status", Path(__file__).resolve().parents[1] / "status.py"
)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

SMALL = "i-0123456789abcdef0"
LARGE = "i-0fedcba9876543210"


class FakeEC2:
    def describe_instances(self, **kwargs):
        return {"Reservations": [{"Instances": [{
            "InstanceId": kwargs["InstanceIds"][0],
            "InstanceType": "t3.large",
            "State": {"Name": "running"}
        }]}]}


class FakeSSM:
    def describe_instance_information(self, **kwargs):
        return {"InstanceInformationList": [{
            "InstanceId": kwargs["Filters"][0]["Values"][0],
            "PingStatus": "Online",
        }]}


class FakeSession:
    def __init__(self, **kwargs):
        assert kwargs == {"region_name": "us-east-1"}
        self.called = []

    def client(self, service):
        self.called.append(service)
        return {"ec2": FakeEC2(), "ssm": FakeSSM()}[service]


class StatusTests(unittest.TestCase):
    def setUp(self):
        self.env = {
            "ARCADECLOUD_AWS_REGION": "us-east-1",
            "ARCADECLOUD_AWS_SMALL_ID": SMALL,
            "ARCADECLOUD_AWS_LARGE_ID": LARGE,
        }

    def test_status_only_and_no_sensitive_data(self):
        request = mod.validate_request({
            "request_id": "my-request-001", "alias": "large", "report": "status"
        })
        data = mod.get_status(request, self.env, FakeSession)
        self.assertEqual(data["state"], "running")
        self.assertEqual(data["type"], "t3.large")
        self.assertEqual(data["ssm"], "Online")
        self.assertNotIn("InstanceId", json.dumps(data))
        self.assertNotIn(LARGE, json.dumps(data))

    def test_small_alias(self):
        data = mod.get_status(mod.validate_request({
            "request_id": "my-request-002", "alias": "small", "report": "status"
        }), self.env, FakeSession)
        self.assertEqual(data["alias"], "small")

    def test_no_unknown_operation_or_freeform_command(self):
        for payload in [
            {"request_id": "req-001", "alias": "large", "report": "execute"},
            {"request_id": "req-001", "alias": "large", "report": "status", "command": "id"},
            {"request_id": "req-001", "alias": "i-123", "report": "status"},
            {"request_id": "../", "alias": "large", "report": "status"},
        ]:
            with self.assertRaises(ValueError):
                mod.validate_request(payload)

    def test_valid_json_request_file(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / "request.json"
            file.write_text(json.dumps({
                "request_id": "first-query", "alias": "large", "report": "status"
            }), encoding="utf-8")
            data = mod.load_request("push", file, "", "", "123")
            self.assertEqual(data["alias"], "large")

    def test_manual_request(self):
        data = mod.load_request("workflow_dispatch", "unused", "small", "status", "123")
        self.assertEqual(data["request_id"], "manual-123")

    def test_missing_or_malformed_configuration(self):
        with self.assertRaises(ValueError):
            mod.validate_config("large", {})
        env = dict(self.env)
        env["ARCADECLOUD_AWS_LARGE_ID"] = "invalid"
        with self.assertRaises(ValueError):
            mod.validate_config("large", env)


if __name__ == "__main__":
    unittest.main()
