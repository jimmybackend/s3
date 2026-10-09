"""AWS control bridge for exactly two configured ArcadeCloud EC2 instances.

No AWS keys, instance IDs, or web-facing PHP authorization are embedded here.
This module is separate from ec2.php and never imports Drive user sessions.
"""
from __future__ import annotations

import os
import re
from dataclasses import dataclass
from typing import Callable, Mapping

INSTANCE_ID = re.compile(r"^i-[0-9a-f]{8,17}$")
REGION = re.compile(r"^[a-z]{2}(?:-gov)?-[a-z]+-\d+$")
COMMAND_ID = re.compile(r"^[0-9a-f]{8}-[0-9a-f-]{27,}$", re.I)

DIAGNOSTICS = {
    "host": "set -eu; uname -a; uptime; nproc; free -h; df -h",
    "docker": (
        "set -eu; command -v docker >/dev/null || { echo 'Docker no instalado'; exit 1; }; "
        "docker ps --format '{{.Names}} | {{.Image}} | {{.Status}}'; "
        "docker stats --no-stream --format '{{.Name}} | {{.CPUPerc}} | {{.MemUsage}} | {{.MemPerc}}'"
    ),
    "services": (
        "set -eu; systemctl --no-pager --plain list-units --type=service "
        "'arcadecloud*' || true"
    ),
}


class BridgeError(Exception):
    """Safe-to-display configuration or policy error."""


@dataclass(frozen=True)
class Target:
    alias: str
    instance_id: str
    region: str
    always_on: bool


class AwsBridge:
    def __init__(
        self,
        env: Mapping[str, str] | None = None,
        client_factory: Callable[[str, str], object] | None = None,
    ):
        self.env = dict(os.environ if env is None else env)
        self.client_factory = client_factory or self._aws_client

    def _aws_client(self, service: str, region: str):
        # Same names as Config::getAwsControlClientConfig() in Config-s3.php.
        # Otherwise boto3 uses standard AWS SDK provider chain (instance IAM role).
        import boto3

        key = self.env.get("AWS_CONTROL_ACCESS_KEY_ID", "").strip()
        secret = self.env.get("AWS_CONTROL_SECRET_ACCESS_KEY", "").strip()
        if bool(key) != bool(secret):
            raise BridgeError("AWS_CONTROL_ACCESS_KEY_ID y AWS_CONTROL_SECRET_ACCESS_KEY deben coexistir")
        opts = {"region_name": region}
        if key:
            opts["aws_access_key_id"] = key
            opts["aws_secret_access_key"] = secret
            token = self.env.get("AWS_CONTROL_SESSION_TOKEN", "").strip()
            if token:
                opts["aws_session_token"] = token
        else:
            # Honor standard AWS_* environment variables and the EC2 instance profile.
            # No secrets are returned by any MCP tool.
            for name in ("AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY", "AWS_SESSION_TOKEN"):
                value = self.env.get(name)
                if value:
                    os.environ.setdefault(name, value)
        return boto3.client(service, **opts)

    def targets(self) -> dict[str, Target]:
        default_region = (
            self.env.get("AWS_REGION", "")
            or self.env.get("AWS_DEFAULT_REGION", "")
        )
        configs = (
            ("small", "ARCADECLOUD_MCP_SMALL_INSTANCE_ID",
             "ARCADECLOUD_MCP_SMALL_REGION", True),
            ("large", "ARCADECLOUD_MCP_LARGE_INSTANCE_ID",
             "ARCADECLOUD_MCP_LARGE_REGION", False),
        )
        result = {}
        for alias, id_name, region_name, always_on in configs:
            instance = self.env.get(id_name, "").strip()
            if alias == "large" and not instance:
                instance = self.env.get("ARCADECLOUD_FASTDRIVE_INSTANCE_ID", "").strip()
            region = self.env.get(region_name, "").strip()
            if alias == "large" and not region:
                region = self.env.get("ARCADECLOUD_FASTDRIVE_REGION", "").strip()
            region = region or default_region
            if not instance:
                continue
            if not INSTANCE_ID.fullmatch(instance):
                raise BridgeError(f"Instance ID inválido en {id_name}")
            if not REGION.fullmatch(region):
                raise BridgeError(f"Región inválida o ausente para {alias}")
            result[alias] = Target(alias, instance, region, always_on)
        if not result:
            raise BridgeError("Configura los Instance ID permitidos de small y large")
        if len({(t.region, t.instance_id) for t in result.values()}) != len(result):
            raise BridgeError("Los alias small/large no pueden apuntar a la misma EC2")
        return result

    def target(self, alias: str) -> Target:
        targets = self.targets()
        if alias not in ("small", "large") or alias not in targets:
            raise BridgeError("Alias desconocido/no configurado; usa small o large")
        return targets[alias]

    def _enabled(self, name: str):
        if self.env.get(name, "").strip() != "1":
            raise BridgeError(f"Operación deshabilitada; habilita explícitamente {name}=1")

    def _describe(self, t: Target) -> dict:
        result = self.client_factory("ec2", t.region).describe_instances(
            InstanceIds=[t.instance_id]
        )
        instances = [
            instance
            for reservation in result.get("Reservations", [])
            for instance in reservation.get("Instances", [])
        ]
        if not instances:
            raise BridgeError(f"EC2 {t.alias} no encontrada o sin permiso de consulta")
        instance = instances[0]
        return {
            "alias": t.alias, "instance_id": t.instance_id, "region": t.region,
            "always_on": t.always_on,
            "state": instance.get("State", {}).get("Name", "unknown"),
            "type": instance.get("InstanceType"),
            "private_ip": instance.get("PrivateIpAddress"),
            "public_ip": instance.get("PublicIpAddress"),
        }

    def inventory(self) -> list[dict]:
        return [self._describe(t) for t in self.targets().values()]

    def describe(self, alias: str) -> dict:
        return self._describe(self.target(alias))

    def identity(self, alias: str) -> dict:
        t = self.target(alias)
        sts = self.client_factory("sts", t.region).get_caller_identity()
        return {"account": sts.get("Account"), "principal_arn": sts.get("Arn")}

    def power(self, alias: str, action: str) -> dict:
        self._enabled("ARCADECLOUD_MCP_ENABLE_POWER")
        t = self.target(alias)
        if action not in ("start", "stop"):
            raise BridgeError("Acción inválida: start o stop")
        if action == "stop" and t.always_on:
            self._enabled("ARCADECLOUD_MCP_ALLOW_STOP_SMALL")
        ec2 = self.client_factory("ec2", t.region)
        if action == "start":
            response = ec2.start_instances(InstanceIds=[t.instance_id])
        else:
            response = ec2.stop_instances(InstanceIds=[t.instance_id])
        return {
            "alias": alias, "instance_id": t.instance_id,
            "action": action, "request_id": response.get("ResponseMetadata", {}).get("RequestId"),
        }

    def _send(self, t: Target, command: str, comment: str) -> dict:
        ssm = self.client_factory("ssm", t.region)
        result = ssm.send_command(
            InstanceIds=[t.instance_id],
            DocumentName="AWS-RunShellScript",
            Parameters={"commands": [command]},
            Comment=comment,
            TimeoutSeconds=60,
        )
        return {"alias": t.alias, "instance_id": t.instance_id,
                "command_id": result["Command"]["CommandId"],
                "status": result["Command"].get("Status", "Pending")}

    def diagnostics(self, alias: str, report: str) -> dict:
        t = self.target(alias)
        if report not in DIAGNOSTICS:
            raise BridgeError("Reporte inválido: host, docker o services")
        return self._send(t, DIAGNOSTICS[report], f"ArcadeCloud MCP diagnostic {report}")

    def shell(self, alias: str, command: str, reason: str) -> dict:
        self._enabled("ARCADECLOUD_MCP_ENABLE_SHELL")
        t = self.target(alias)
        if not command.strip() or len(command) > 2048:
            raise BridgeError("Comando vacío o mayor a 2048 caracteres")
        if not reason.strip() or len(reason) > 120:
            raise BridgeError("Indica una razón de hasta 120 caracteres para auditoría")
        # The complete command is passed as data to SSM, never interpolated into
        # a local shell. SSM runs the user-requested script on the selected host.
        return self._send(t, command, "ArcadeCloud MCP: " + reason.strip())

    def command_result(self, alias: str, command_id: str) -> dict:
        t = self.target(alias)
        if not COMMAND_ID.fullmatch(command_id):
            raise BridgeError("Command ID inválido")
        result = self.client_factory("ssm", t.region).get_command_invocation(
            CommandId=command_id, InstanceId=t.instance_id
        )
        return {
            "alias": t.alias,
            "command_id": command_id,
            "status": result.get("Status"),
            "response_code": result.get("ResponseCode"),
            "stdout": result.get("StandardOutputContent", "")[:12000],
            "stderr": result.get("StandardErrorContent", "")[:4000],
            "truncated": len(result.get("StandardOutputContent", "")) > 12000
            or len(result.get("StandardErrorContent", "")) > 4000,
        }
