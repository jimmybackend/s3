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
    "michat:sync-main", "michat:install-updater",
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
    if op == "service" and arg in {"michat:sync-main", "michat:install-updater"} and alias != "small":
        raise ValueError("MiChat sólo se administra en la EC2 pequeña")
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
    if op == "service" and arg == "michat:install-updater":
        return """set -euo pipefail
APP=/var/www/michat
INSTALLER="$APP/michat/bin/install_michat_updater.sh"
test -d "$APP/.git" || { printf 'MICHAT_UPDATER_BLOCKED=missing-repo\\n'; exit 77; }
test -f "$INSTALLER" || { printf 'MICHAT_UPDATER_BLOCKED=missing-installer\\n'; exit 77; }
PHP_USER="$(python3 - <<'PY'
import glob
import pwd
import re
import subprocess
import sys

proc = subprocess.run(
    ['/usr/sbin/nginx', '-T'],
    stdout=subprocess.PIPE,
    stderr=subprocess.STDOUT,
    text=True,
    check=False,
)
if proc.returncode != 0:
    sys.exit(21)

source = re.sub(r'(?m)#.*$', '', proc.stdout)

def iter_blocks(pattern):
    for match in re.finditer(pattern, source):
        depth = 1
        pos = match.end()
        start = pos
        while pos < len(source) and depth:
            char = source[pos]
            if char == '{':
                depth += 1
            elif char == '}':
                depth -= 1
            pos += 1
        if depth == 0:
            yield match, source[start:pos - 1]

passes = []
for _, block in iter_blocks(r'\\bserver\\s*\\{'):
    names = []
    for raw in re.findall(r'\\bserver_name\\s+([^;]+);', block):
        names.extend(raw.split())
    if 'chat.esforzados.com' not in names and 'www.chat.esforzados.com' not in names:
        continue
    passes.extend(re.findall(r'\\bfastcgi_pass\\s+([^;\\s]+)\\s*;', block))

passes = sorted(set(passes))
if len(passes) != 1:
    sys.exit(22)
endpoint = passes[0]

if not endpoint.startswith('unix:') and ':' not in endpoint:
    resolved = []
    pattern = r'\\bupstream\\s+' + re.escape(endpoint) + r'\\s*\\{'
    for _, block in iter_blocks(pattern):
        resolved.extend(re.findall(r'\\bserver\\s+([^;\\s]+)\\s*;', block))
    resolved = sorted(set(resolved))
    if len(resolved) != 1:
        sys.exit(23)
    endpoint = resolved[0]

def normalize(value):
    value = value.strip()
    if value.startswith('unix:'):
        value = value[5:]
    return value.replace('localhost:', '127.0.0.1:')

target = normalize(endpoint)
paths = set()
for pattern in (
    '/etc/php-fpm.conf',
    '/etc/php-fpm.d/*.conf',
    '/etc/php-fpm-drive.conf',
    '/etc/php-fpm-drive.d/*.conf',
    '/etc/php/*/fpm/pool.d/*.conf',
):
    paths.update(glob.glob(pattern))

users = set()
for path in sorted(paths):
    try:
        data = open(path, encoding='utf-8', errors='replace').read()
    except OSError:
        continue
    data = re.sub(r'(?m)^\\s*[;#].*$', '', data)
    sections = re.split(r'(?m)^\\s*\\[[^]]+\\]\\s*$', data)
    for section in sections:
        listen_match = re.search(r'(?m)^\\s*listen\\s*=\\s*([^;#\\r\\n]+)', section)
        user_match = re.search(r'(?m)^\\s*user\\s*=\\s*([^;#\\r\\n]+)', section)
        if not listen_match or not user_match:
            continue
        if normalize(listen_match.group(1)) != target:
            continue
        user = user_match.group(1).strip()
        try:
            pwd.getpwnam(user)
        except KeyError:
            continue
        if user != 'root':
            users.add(user)

if len(users) != 1:
    sys.exit(24)
print(next(iter(users)))
PY
)" || { printf 'MICHAT_UPDATER_BLOCKED=php-user-detection\\n'; exit 77; }
printf '%s' "$PHP_USER" | grep -Eq '^[A-Za-z_][A-Za-z0-9_.-]{0,31}$' || { printf 'MICHAT_UPDATER_BLOCKED=php-user-format\\n'; exit 77; }
id "$PHP_USER" >/dev/null 2>&1 || { printf 'MICHAT_UPDATER_BLOCKED=php-user-missing\\n'; exit 77; }
/usr/bin/bash "$INSTALLER" "$PHP_USER" >/dev/null
test -x /usr/local/sbin/michat-updater || { printf 'MICHAT_UPDATER_BLOCKED=helper-missing\\n'; exit 70; }
/usr/sbin/runuser -u "$PHP_USER" -- /usr/bin/sudo -n /usr/local/sbin/michat-updater probe >/dev/null
printf 'MICHAT_UPDATER_OK php_user=%s helper=installed\\n' "$PHP_USER"
"""
    if op == "service" and arg == "michat:sync-main":
        return """set -euo pipefail
APP=/var/www/michat
test -d "$APP/.git" || { printf 'MICHAT_SYNC_BLOCKED=missing-repo\\n'; exit 77; }
repo_user="$(stat -c %U "$APP/.git")"
id "$repo_user" >/dev/null || { printf 'MICHAT_SYNC_BLOCKED=repo-user\\n'; exit 77; }
g() { /usr/sbin/runuser -u "$repo_user" -- /usr/bin/git -C "$APP" "$@"; }
origin="$(g remote get-url origin)"
case "$origin" in
  https://github.com/jimmybackend/michat.git|https://github.com/jimmybackend/michat|git@github.com:jimmybackend/michat.git|ssh://git@github.com/jimmybackend/michat.git) ;;
  *) printf 'MICHAT_SYNC_BLOCKED=origin\\n'; exit 77 ;;
esac
branch="$(g rev-parse --abbrev-ref HEAD)"
test "$branch" = "main" || { printf 'MICHAT_SYNC_BLOCKED=branch\\n'; exit 77; }
before="$(g rev-parse HEAD)"
dirty_count="$(g status --porcelain=v1 --untracked-files=all | wc -l | tr -d ' ')"
printf 'MICHAT_SYNC_PRE before=%s dirty=%s\\n' "$before" "$dirty_count"
g fetch --quiet origin main
ahead="$(g rev-list --count origin/main..HEAD)"
test "$ahead" = "0" || { printf 'MICHAT_SYNC_BLOCKED=local-commits\\n'; exit 77; }
g merge-base --is-ancestor HEAD origin/main || { printf 'MICHAT_SYNC_BLOCKED=diverged\\n'; exit 77; }
if ! g merge --ff-only origin/main >/dev/null 2>&1; then
  printf 'MICHAT_SYNC_BLOCKED=merge\\n'
  exit 77
fi
after="$(g rev-parse HEAD)"
target="$(g rev-parse origin/main)"
test "$after" = "$target" || { printf 'MICHAT_SYNC_BLOCKED=head-mismatch\\n'; exit 70; }
worker_enabled="$(systemctl is-enabled michat-task-worker.service 2>/dev/null || true)"
worker_active="$(systemctl is-active michat-task-worker.service 2>/dev/null || true)"
printf 'MICHAT_SYNC_OK before=%s after=%s dirty=%s worker_enabled=%s worker_active=%s\\n' "$before" "$after" "$dirty_count" "$worker_enabled" "$worker_active"
"""
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
            output = invocation.get("StandardOutputContent", "")
            if request["operation"] == "diagnose" and request["argument"] == "docker-summary" and status == "Success":
                match = re.fullmatch(r"ARCADECLOUD_DOCKER_COUNT=(\d+)\s*", output)
                if match:
                    result["docker_running_containers"] = int(match.group(1))
            if request["operation"] == "service" and request["argument"] == "michat:install-updater":
                ok = re.search(
                    r"^MICHAT_UPDATER_OK php_user=([A-Za-z_][A-Za-z0-9_.-]{0,31}) helper=installed$",
                    output, re.MULTILINE,
                )
                blocked = re.search(r"^MICHAT_UPDATER_BLOCKED=([a-z-]+)$", output, re.MULTILINE)
                if ok:
                    result["php_user"] = ok.group(1)
                    result["updater_helper"] = "installed"
                if blocked:
                    result["blocked_reason"] = blocked.group(1)
            if request["operation"] == "service" and request["argument"] == "michat:sync-main":
                pre = re.search(r"^MICHAT_SYNC_PRE before=([0-9a-f]{40}) dirty=(\d+)$", output, re.MULTILINE)
                ok = re.search(
                    r"^MICHAT_SYNC_OK before=([0-9a-f]{40}) after=([0-9a-f]{40}) dirty=(\d+) worker_enabled=([a-z-]+) worker_active=([a-z-]+)$",
                    output, re.MULTILINE,
                )
                blocked = re.search(r"^MICHAT_SYNC_BLOCKED=([a-z-]+)$", output, re.MULTILINE)
                if pre:
                    result["michat_before"] = pre.group(1)
                    result["michat_dirty_count"] = int(pre.group(2))
                if ok:
                    result["michat_before"] = ok.group(1)
                    result["michat_after"] = ok.group(2)
                    result["michat_dirty_count"] = int(ok.group(3))
                    result["worker_enabled"] = ok.group(4)
                    result["worker_active"] = ok.group(5)
                if blocked:
                    result["blocked_reason"] = blocked.group(1)
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
