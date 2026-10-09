"""Git-first FastDrive deployment through SSM with restricted, fixed commands.

This deployment never pushes a server checkout to GitHub. Code must be reviewed
and merged on main first, and FastDrive only fast-forwards to that exact commit.
"""
from __future__ import annotations
import json
import os
import re
import time
from pathlib import Path

import boto3

REQUEST_FILE=Path("tools/arcadecloud-aws-bridge/requests/fastdrive-gitops.json")
SCRIPT=r'''#!/bin/bash
set -euo pipefail
APP=/var/www/arcadecloud-drive
flag() { printf 'ARCADECLOUD_GITOPS_%s=%s\n' "$1" "$2"; }
if [ ! -d "$APP/.git" ]; then flag REPO_MISSING yes; exit 11; fi
if [ "$(git -c safe.directory="$APP" -C "$APP" branch --show-current)" != main ]; then
  flag BRANCH_MISMATCH yes; exit 12
fi
if [ -n "$(git -c safe.directory="$APP" -C "$APP" status --porcelain -uno)" ]; then
  flag LOCAL_TRACKED_CHANGES yes; exit 13
fi
# Untracked local source files may be precious. Never overwrite or silently
# ignore a local-only .php/.js/.sh/.py/.json/.yml working file.
if git -c safe.directory="$APP" -C "$APP" ls-files --others --exclude-standard |
   grep -Eq '^(drive/|tools/|\.github/).+\.(php|js|sh|py|json|yml|yaml)$'; then
  flag LOCAL_UNTRACKED_CODE yes; exit 14
fi
flag CLEAN_PRECHECK yes
if ! git -c safe.directory="$APP" -C "$APP" fetch --quiet origin main; then
  flag FETCH_FAILED yes; exit 15
fi
if ! git -c safe.directory="$APP" -C "$APP" merge --ff-only --quiet origin/main; then
  flag FAST_FORWARD_FAILED yes; exit 16
fi
flag FAST_FORWARD_OK yes
if [ "$(git -c safe.directory="$APP" -C "$APP" rev-parse HEAD)" != "EXPECTED_COMMIT_SHA" ]; then
  flag COMMIT_MISMATCH yes; exit 17
fi
flag EXACT_COMMIT yes
for file in drive/src/Media/MediaWorkerNodeService.php \
            drive/tests/idle_stop_office_regression.php \
            drive/bin/fastdrive_safe_worker_reload.php; do
  if ! php -l "$APP/$file" >/dev/null 2>&1; then
    flag PHP_SYNTAX_ERROR yes
    exit 18
  fi
done
flag PHP_SYNTAX_OK yes
if ! php "$APP/drive/bin/fastdrive_safe_worker_reload.php"; then
  flag WORKER_RELOAD_ERROR yes
  exit 19
fi
if [ "$(systemctl is-active arcadecloud-media-worker.service 2>/dev/null || true)" != active ]; then
  flag MEDIA_WORKER_INACTIVE yes
  exit 20
fi
for name in arcadecloud-guacamole arcadecloud-guacd arcadecloud-guac-db; do
  if [ "$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null || true)" != true ]; then
    flag GUACAMOLE_COMPONENT_FAILED yes
    exit 21
  fi
done
if ! docker port arcadecloud-guacamole 8080/tcp 2>/dev/null | grep -qx '127.0.0.1:8085'; then
  flag GUAC_PORT_CHANGED yes
  exit 22
fi
flag GUACAMOLE_HEALTHY yes
flag SUCCESS yes
'''
def main():
    request=json.loads(REQUEST_FILE.read_text(encoding="utf-8"))
    if not isinstance(request,dict) or set(request)!={"request_id","action"}:
        raise SystemExit("Invalid fixed request")
    if not isinstance(request["request_id"],str) or not re.fullmatch(r"[A-Za-z0-9_.-]{8,72}",request["request_id"]):
        raise SystemExit("Invalid request ID")
    if request["action"]!="fastdrive-code-sync":
        raise SystemExit("Unknown operation")
    region=os.environ.get("ARCADECLOUD_AWS_REGION","")
    iid=os.environ.get("ARCADECLOUD_AWS_LARGE_ID","")
    sha=os.environ.get("EXPECTED_GIT_SHA","")
    if not re.fullmatch(r"[a-z]{2}-[a-z]+-\d+",region) or not re.fullmatch(r"i-[0-9a-f]{8,17}",iid):
        raise SystemExit("Invalid EC2 parameters")
    if not re.fullmatch(r"[0-9a-f]{40}",sha):
        raise SystemExit("Missing validated main SHA")
    client=boto3.client("ssm",region_name=region)
    resp=client.send_command(InstanceIds=[iid],DocumentName="AWS-RunShellScript",
        Parameters={"commands":[SCRIPT.replace("EXPECTED_COMMIT_SHA",sha)],"executionTimeout":["300"]},
        Comment="ArcadeCloud GitOps fixed main sync",TimeoutSeconds=120)
    cid=resp["Command"]["CommandId"]
    print("ARCADECLOUD_GITOPS_SUBMITTED=yes")
    for _ in range(80):
        try:
            result=client.get_command_invocation(CommandId=cid,InstanceId=iid)
        except client.exceptions.InvocationDoesNotExist:
            time.sleep(4)
            continue
        if result["Status"] in ("Success","Failed","Cancelled","TimedOut"):
            lines=result.get("StandardOutputContent","").splitlines()
            for line in lines:
                if re.fullmatch(r"ARCADECLOUD_GITOPS_[A-Z_]+=(yes|no)",line) or re.fullmatch(
                    r"ARCADECLOUD_WORKER_RELOAD=(success|failed_safe|deferred_busy_tasks|deferred_office_active|deferred_local_process)",line):
                    print(line)
            status=result["Status"]
            print("ARCADECLOUD_GITOPS_COMMAND_STATUS="+status)
            if status!="Success" or "ARCADECLOUD_GITOPS_SUCCESS=yes" not in lines:
                raise SystemExit(1)
            return
        time.sleep(4)
    raise SystemExit("SSM code sync timed out, check AWS execution")
if __name__=="__main__":
    main()
