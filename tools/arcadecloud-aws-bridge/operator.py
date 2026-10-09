"""Protected ArcadeCloud EC2 operator via GitHub Actions and AWS SSM.

No free-form commands. All SSM scripts are generated from fixed literals.
Never emit raw SSM output to a public-repository workflow log.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import time
from pathlib import Path

INSTANCE_ID = re.compile(r"^i-[0-9a-f]{8,17}$")
REQUEST_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{2,63}$")
REGION = re.compile(r"^[a-z]{2}(?:-gov)?-[a-z]+-\d+$")
OPERATIONS = frozenset({"diagnose", "service", "power-start"})
DIAGNOSTICS = frozenset({
    "memory", "disk", "uptime", "arcadecloud-services", "arcadecloud-timers",
    "nginx-status", "php-fpm-status", "repo-status", "media-worker-status",
    "docker-summary",
})
# Second line of defense remains ArcadeCloud's privileged PHP helper, which
# independently checks the command and service-action allowlist.
SERVICE_ACTIONS = frozenset({
    "media-worker:start", "media-worker:stop", "media-worker:restart",
    "workstation:start", "workstation:stop", "workstation:restart",
    "federation-sync:run-now", "federation-https:run-now",
    "polly-reconcile:run-now", "transcribe-reconcile:run-now",
})
TERMINAL = frozenset({"Success", "Cancelled", "TimedOut", "Failed", "Cancelling"})


def validate_request(request):
    if not isinstance(request, dict) or set(request) != {
        "request_id", "alias", "operation", "argument", "confirm"
    }:
        raise ValueError("Los campos de solicitud no corresponden al contrato")
    rid, alias, op, arg, confirm = (
        request[k] for k in ("request_id", "alias", "operation", "argument", "confirm")
    )
    if not isinstance(rid, str) or not REQUEST_ID.fullmatch(rid):
        raise ValueError("request_id invalido")
    if alias not in ("small", "large") or op not in OPERATIONS or not isinstance(arg, str):
        raise ValueError("Operación o alias no permitido")
    expected = {
        "diagnose": "READ_DIAGNOSTIC",
        "service": "SERVICE_CHANGE",
        "power-start": "POWER_LARGE",
    }[op]
    if confirm != expected:
        raise ValueError("Confirmación incompatible con la operación")
    if op == "diagnose" and arg not in DIAGNOSTICS:
        raise ValueError("Diagnóstico no permitido")
    if op == "service" and arg not in SERVICE_ACTIONS:
        raise ValueError("Acción de servicio no permitida")
    if op == "power-start" and (alias != "large" or arg != ""):
        raise ValueError("Únicamente se permite encender FastDrive")
    return request


def load_request(event, file, alias, operation, argument, confirm, run_id):
    if event == "workflow_dispatch":
        return validate_request({
            "request_id": "manual-" + str(run_id),
            "alias": alias,
            "operation": operation,
            "argument": argument,
            "confirm": confirm,
        })
    if event != "push":
        raise ValueError("Evento no autorizado")
    return validate_request(json.loads(Path(file).read_text(encoding="utf-8")))


def target(alias, env):
    name = "ARCADECLOUD_AWS_SMALL_ID" if alias == "small" else "ARCADECLOUD_AWS_LARGE_ID"
    region = env.get("ARCADECLOUD_AWS_REGION", "")
    instance_id = env.get(name, "")
    if not REGION.fullmatch(region) or not INSTANCE_ID.fullmatch(instance_id):
        raise ValueError("Región o Instance ID faltante/inválido")
    if env.get("ARCADECLOUD_AWS_OPERATOR_ENABLED") != "YES_PROTECTED":
        raise ValueError("El operador requiere habilitación manual explícita")
    return region, instance_id


def script_for(request):
    op, arg = request["operation"], request["argument"]
    if op == "power-start":
        raise ValueError("Power no se ejecuta por SSM")
    helper = "/usr/local/sbin/arcadecloud-drive-admin"
    if op == "diagnose" and arg == "docker-summary":
        # Prints only a numeric count. No container names, env, mounts or logs.
        return (
            "set -eu\n"
            "command -v docker >/dev/null\n"
            "docker info >/dev/null 2>&1\n"
            "count=$(docker ps -q | wc -l)\n"
            "printf 'ARCADECLOUD_DOCKER_COUNT=%s\\n' \"$count\"\n"
        )
    if op == "diagnose":
        return f"set -eu\ntest -x {helper}\n{helper} server-console {arg}\n"
    service, action = arg.split(":", 1)
    return f"set -eu\ntest -x {helper}\n{helper} node-service {service} {action}\n"


def _managed_online(session, instance_id):
    ssm = session.client("ssm")
    response = ssm.describe_instance_information(
        Filters=[{"Key": "InstanceIds", "Values": [instance_id]}]
    )
    nodes = response.get("InstanceInformationList", [])
    return bool(nodes and nodes[0].get("PingStatus") == "Online")


def execute(request, env, session_factory, sleeper=time.sleep):
    region, iid = target(request["alias"], env)
    session = session_factory(region_name=region)
    ec2 = session.client("ec2")
    data = ec2.describe_instances(InstanceIds=[iid])
    instances = [
        item for reservation in data.get("Reservations", [])
        for item in reservation.get("Instances", [])
    ]
    if len(instances) != 1 or instances[0].get("InstanceId") != iid:
        raise RuntimeError("La EC2 configurada no es visible")
    state = instances[0].get("State", {}).get("Name", "unknown")
    result = {
        "request_id": request["request_id"],
        "alias": request["alias"],
        "operation": request["operation"],
        "argument": request["argument"],
        "state_before": state,
    }
    if request["operation"] == "power-start":
        if state == "running":
            return result | {"status": "already_running"}
        if state != "stopped":
            raise RuntimeError("FastDrive no se encuentra en estado stopped")
        ec2.start_instances(InstanceIds=[iid])
        return result | {"status": "start_requested"}
    if state != "running" or not _managed_online(session, iid):
        raise RuntimeError("La EC2 debe estar encendida con SSM Online")
    ssm = session.client("ssm")
    sent = ssm.send_command(
        InstanceIds=[iid],
        DocumentName="AWS-RunShellScript",
        Parameters={"commands": [script_for(request)]},
        Comment="ArcadeCloud GitHub operator " + request["request_id"][:60],
        TimeoutSeconds=60,
        MaxConcurrency="1",
        MaxErrors="0",
    )
    command_id = sent["Command"]["CommandId"]
    result["command_id"] = command_id
    for _ in range(15):
        try:
            invocation = ssm.get_command_invocation(CommandId=command_id, InstanceId=iid)
        except Exception as exc:
            if getattr(exc, "response", {}).get("Error", {}).get("Code") == "InvocationDoesNotExist":
                sleeper(2)
                continue
            raise
        status = invocation.get("Status", "Unknown")
        if status in TERMINAL:
            result.update({"status": status, "exit_code": invocation.get("ResponseCode")})
            # Prevent public workflow logs from ever carrying raw command output.
            if request["operation"] == "diagnose" and request["argument"] == "docker-summary" and status == "Success":
                match = re.fullmatch(
                    r"ARCADECLOUD_DOCKER_COUNT=(\d+)\s*",
                    invocation.get("StandardOutputContent", ""),
                )
                if match:
                    result["docker_running_containers"] = int(match.group(1))
            return result
        sleeper(2)
    return result | {"status": "Pending"}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--event", required=True)
    parser.add_argument("--request-path", default="tools/arcadecloud-aws-bridge/requests/operator.json")
    parser.add_argument("--alias", default="")
    parser.add_argument("--operation", default="")
    parser.add_argument("--argument", default="")
    parser.add_argument("--confirm", default="")
    parser.add_argument("--run-id", default="0")
    args = parser.parse_args()
    request = load_request(
        args.event, args.request_path, args.alias, args.operation,
        args.argument, args.confirm, args.run_id
    )
    import boto3

    result = execute(request, os.environ, boto3.Session)
    print("ARCADECLOUD_OPERATOR_RESULT=" + json.dumps(result, sort_keys=True))
    if result["status"] not in ("Success", "start_requested", "already_running"):
        raise SystemExit(1)


if __name__ == "__main__":
    main()
