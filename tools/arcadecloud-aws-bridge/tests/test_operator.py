"""Operator contract tests: pure fakes, no AWS credentials, no live EC2."""
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location(
    "arcade_operator", Path(__file__).resolve().parents[1] / "operator.py"
)
ops = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ops)

SMALL = "i-097146ee51c7f7026"
LARGE = "i-01b1f1077d7471070"
ENV = {
    "ARCADECLOUD_AWS_REGION": "us-east-1",
    "ARCADECLOUD_AWS_SMALL_ID": SMALL,
    "ARCADECLOUD_AWS_LARGE_ID": LARGE,
    "ARCADECLOUD_AWS_OPERATOR_ENABLED": "YES_PROTECTED",
}
COMMAND_ID = "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"


def req(alias="small", operation="diagnose", argument="memory", confirm="READ_DIAGNOSTIC"):
    return {
        "request_id": "test-operator-0001", "alias": alias,
        "operation": operation, "argument": argument, "confirm": confirm
    }


class EC2:
    def __init__(self, calls):
        self.calls = calls

    def describe_instances(self, **kwargs):
        self.calls.append(("ec2.describe", kwargs))
        return {"Reservations": [{"Instances": [{
            "InstanceId": kwargs["InstanceIds"][0],
            "State": {"Name": "stopped" if kwargs["InstanceIds"][0] == LARGE else "running"},
        }]}]}

    def start_instances(self, **kwargs):
        self.calls.append(("ec2.start", kwargs))
        return {"StartingInstances": []}


class SSM:
    def __init__(self, calls, output):
        self.calls = calls
        self.output = output

    def describe_instance_information(self, **kwargs):
        self.calls.append(("ssm.describe", kwargs))
        return {"InstanceInformationList": [{"PingStatus": "Online"}]}

    def send_command(self, **kwargs):
        self.calls.append(("ssm.send", kwargs))
        return {"Command": {"CommandId": COMMAND_ID}}

    def get_command_invocation(self, **kwargs):
        self.calls.append(("ssm.result", kwargs))
        return {
            "Status": "Success", "ResponseCode": 0,
            "StandardOutputContent": self.output,
            "StandardErrorContent": "SUPER_SECRET_FAILURE_CONTEXT",
        }


class Session:
    def __init__(self, calls, output, **kwargs):
        assert kwargs == {"region_name": "us-east-1"}
        self.calls = calls
        self.output = output

    def client(self, kind):
        return {"ec2": EC2(self.calls), "ssm": SSM(self.calls, self.output)}[kind]


class OperatorTests(unittest.TestCase):
    def setUp(self):
        self.calls = []

    def go(self, request, env=None, output="TOP_SECRET_VALUE"):
        return ops.execute(
            ops.validate_request(request),
            ENV if env is None else env,
            lambda **kw: Session(self.calls, output, **kw),
            sleeper=lambda _: None,
        )

    def test_diagnostics_use_existing_helper_and_never_expose_output(self):
        report = self.go(req())
        self.assertEqual(report["status"], "Success")
        self.assertNotIn("TOP_SECRET_VALUE", json.dumps(report))
        self.assertNotIn("SUPER_SECRET_FAILURE_CONTEXT", json.dumps(report))
        sent = next(k for kind, k in self.calls if kind == "ssm.send")
        self.assertEqual(sent["InstanceIds"], [SMALL])
        self.assertEqual(sent["DocumentName"], "AWS-RunShellScript")
        self.assertIn("arcadecloud-drive-admin server-console memory", sent["Parameters"]["commands"][0])

    def test_docker_summary_is_only_numeric(self):
        report = self.go(req(argument="docker-summary"), output="ARCADECLOUD_DOCKER_COUNT=3\n")
        self.assertEqual(report["docker_running_containers"], 3)
        self.assertNotIn("StandardOutputContent", report)

    def test_services_only_whitelisted_actions(self):
        r = req(operation="service", argument="media-worker:restart", confirm="SERVICE_CHANGE")
        report = self.go(r)
        self.assertEqual(report["status"], "Success")
        sent = next(k for kind, k in self.calls if kind == "ssm.send")
        self.assertIn("node-service media-worker restart", sent["Parameters"]["commands"][0])

    def test_reject_arbitrary_commands_and_critical_services(self):
        attempts = [
            req(argument="cat /etc/shadow"),
            req(operation="service", argument="nginx:stop", confirm="SERVICE_CHANGE"),
            req(operation="service", argument="media-worker:destroy", confirm="SERVICE_CHANGE"),
            req(alias="i-random"),
            req(operation="power-start", alias="small", argument="", confirm="POWER_LARGE"),
            req(operation="power-start", alias="large", argument="", confirm="READ_DIAGNOSTIC"),
            req() | {"shell": "echo hello"},
        ]
        for p in attempts:
            with self.subTest(p=p), self.assertRaises(ValueError):
                ops.validate_request(p)

    def test_no_operator_flag_fail_closed(self):
        env = dict(ENV)
        env.pop("ARCADECLOUD_AWS_OPERATOR_ENABLED")
        with self.assertRaises(ValueError):
            self.go(req(), env=env)
        self.assertEqual(self.calls, [])

    def test_only_large_instance_can_be_started(self):
        r = req(alias="large", operation="power-start", argument="", confirm="POWER_LARGE")
        output = self.go(r)
        self.assertEqual(output["status"], "start_requested")
        self.assertEqual(self.calls[-1], ("ec2.start", {"InstanceIds": [LARGE]}))
        self.assertFalse(any(kind.startswith("ssm") for kind, _ in self.calls))

    def test_push_request_with_confirmation(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "operator.json"
            path.write_text(json.dumps(req()), encoding="utf-8")
            self.assertEqual(ops.load_request("push", path, "", "", "", "", "22")["alias"], "small")
            with self.assertRaises(ValueError):
                ops.load_request("other", path, "", "", "", "", "22")

    def test_no_stop_no_terminate_no_run_instances(self):
        self.assertEqual(ops.OPERATIONS, {"diagnose", "service", "power-start"})


if __name__ == "__main__":
    unittest.main()
