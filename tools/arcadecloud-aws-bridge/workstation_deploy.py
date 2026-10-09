"""Deploy ONLY the versioned ArcadeCloud PDF/RAR workstation image via SSM.

Public GitHub action logs receive only a fixed set of stage/status booleans.
No free-form remote commands, secrets, raw docker output or host logs.
"""
from __future__ import annotations
import json
import os
import re
import time
from pathlib import Path
import boto3

REGION = re.compile(r"^[a-z]{2}-[a-z]+-\d+$")
IID = re.compile(r"^i-[0-9a-f]{8,17}$")
REQUEST = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{2,63}$")
SCRIPT = r'''#!/bin/bash
set -euo pipefail
ROOT=/var/www/arcadecloud-drive
LOG=/var/log/arcadecloud-workstation-package-build.log
flag() { printf 'ARCADECLOUD_DEPLOY_%s=%s\n' "$1" "$2"; }
flag STARTED yes
if ! command -v git >/dev/null || ! command -v docker >/dev/null; then
  flag PREREQUISITES no
  exit 11
fi
if [ ! -d "$ROOT/.git" ]; then
  flag REPOSITORY no
  exit 12
fi
if ! git -c safe.directory="$ROOT" -C "$ROOT" branch --show-current | grep -qx main; then
  flag MAIN_BRANCH no
  exit 13
fi
if [ -n "$(git -c safe.directory="$ROOT" -C "$ROOT" status --porcelain -uno)" ]; then
  flag LOCAL_CHANGES yes
  exit 14
fi
if [ "$(systemctl is-active arcadecloud-workstation.service 2>/dev/null || true)" = active ] ||
   [ "$(docker inspect -f '{{.State.Running}}' arcadecloud-workstation 2>/dev/null || true)" = true ]; then
  flag ACTIVE_SESSION_POSSIBLE yes
  exit 15
fi
if ! git -c safe.directory="$ROOT" -C "$ROOT" pull --ff-only origin main >/dev/null 2>&1; then
  flag FAST_FORWARD_FAILED yes
  exit 16
fi
flag REPO_UPDATED yes
# Keep previous image through a zero-copy tag until replacement is tested.
if docker image inspect arcadecloud/workstation:phase1 >/dev/null 2>&1; then
  docker tag arcadecloud/workstation:phase1 arcadecloud/workstation:before-pdf-rar-20261008
fi
# Never use install_workstation_node.sh here: that would reconcile Guacamole DB.
umask 077
if ! docker build -t arcadecloud/workstation:phase1 "$ROOT/drive/docker/workstation" >"$LOG" 2>&1; then
  flag BUILD_FAILED yes
  exit 17
fi
flag BUILD_COMPLETED yes
if ! docker run --rm --network none --memory 512m --pids-limit 128 --entrypoint /bin/bash \
  arcadecloud/workstation:phase1 -ec '
    for tool in atril rar unrar zip unzip file-roller; do command -v "$tool" >/dev/null; done
    tmp=$(mktemp -d)
    trap "rm -rf \"$tmp\"" EXIT
    cd "$tmp"
    printf "arcadecloud-test\n" >test.txt
    zip -q test.zip test.txt
    unzip -tqq test.zip
    rar a -idq test.rar test.txt >/dev/null
    unrar t -inul test.rar >/dev/null
  ' >/dev/null 2>&1; then
  flag ARCHIVE_TEST_FAILED yes
  exit 18
fi
flag PDF_ZIP_RAR_VERIFIED yes
for name in arcadecloud-guacamole arcadecloud-guacd arcadecloud-guac-db; do
  if [ "$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null || true)" != true ]; then
    flag GUACAMOLE_COMPONENT_RUNNING no
    exit 19
  fi
done
if ! docker port arcadecloud-guacamole 8080/tcp 2>/dev/null | grep -qx '127.0.0.1:8085'; then
  flag PRIVATE_GUAC_PORT no
  exit 20
fi
if [ "$(systemctl is-enabled arcadecloud-workstation.service 2>/dev/null || true)" != disabled ]; then
  flag WORKSTATION_ON_DEMAND no
  exit 21
fi
# Apply resource settings only to the stopped Workstation unit. Do not
# touch Guacamole services, environment secrets, launch broker or active sessions.
UNIT=/etc/systemd/system/arcadecloud-workstation.service
OLD_LIMITS='--memory=5g --cpus=3 --shm-size=512m'
NEW_LIMITS='--memory=5632m --cpus=3.25 --shm-size=1g'
if [ ! -f "$UNIT" ]; then
  flag WORKSTATION_UNIT_MISSING yes
  exit 22
fi
if grep -Fq -- "$OLD_LIMITS" "$UNIT"; then
  if [ "$(systemctl is-active arcadecloud-workstation.service 2>/dev/null || true)" = active ]; then
    flag ACTIVE_SESSION_POSSIBLE yes
    exit 23
  fi
  cp -a -- "$UNIT" "${UNIT}.before-pdf-rar-20261008"
  # Only this fixed resource segment is changed. Atomic replacement
  # preserves ownership, mode and all existing env paths/ports.
  TMP_UNIT="$(mktemp /etc/systemd/system/.arcadecloud-workstation.XXXXXXXX)"
  sed "s@--memory=5g --cpus=3 --shm-size=512m@--memory=5632m --cpus=3.25 --shm-size=1g@" "$UNIT" > "$TMP_UNIT"
  chown --reference="$UNIT" "$TMP_UNIT"
  chmod --reference="$UNIT" "$TMP_UNIT"
  mv -f -- "$TMP_UNIT" "$UNIT"
  systemctl daemon-reload
elif ! grep -Fq -- "$NEW_LIMITS" "$UNIT"; then
  flag UNKNOWN_WORKSTATION_LIMITS yes
  exit 24
fi
if ! grep -Fq -- "$NEW_LIMITS" "$UNIT"; then
  flag LIMITS_FAILED yes
  exit 25
fi
if [ "$(systemctl is-active arcadecloud-workstation.service 2>/dev/null || true)" = active ]; then
  flag UNEXPECTED_WORKSTATION_START yes
  exit 26
fi
flag RESOURCE_LIMITS_APPLIED yes
flag GUACAMOLE_UNCHANGED yes
flag WORKSTATION_ON_DEMAND yes
flag SUCCESS yes
'''
def main():
    data = json.loads(Path("tools/arcadecloud-aws-bridge/requests/workstation-deploy.json").read_text(encoding="utf8"))
    if not isinstance(data,dict) or set(data)!={"request_id","action"}:
        raise SystemExit("Invalid operation contract")
    if not isinstance(data["request_id"],str) or not REQUEST.fullmatch(data["request_id"]):
        raise SystemExit("Invalid request ID")
    if data["action"] != "build-pdf-zip-rar":
        raise SystemExit("Invalid operation")
    region=os.environ.get("ARCADECLOUD_AWS_REGION","")
    iid=os.environ.get("ARCADECLOUD_AWS_LARGE_ID","")
    if not REGION.fullmatch(region) or not IID.fullmatch(iid):
        raise SystemExit("Bad configured EC2")
    ssm=boto3.client("ssm",region_name=region)
    sent=ssm.send_command(
        InstanceIds=[iid], DocumentName="AWS-RunShellScript",
        Parameters={"commands":[SCRIPT],"executionTimeout":["2400"]},
        TimeoutSeconds=120, Comment="ArcadeCloud Workstation PDF and archive image build")
    cid=sent["Command"]["CommandId"]
    print("ARCADECLOUD_DEPLOY_SUBMITTED=yes")
    for _ in range(150):
        try:
            result=ssm.get_command_invocation(CommandId=cid,InstanceId=iid)
        except ssm.exceptions.InvocationDoesNotExist:
            time.sleep(8)
            continue
        status=result.get("Status")
        if status in ("Success","Failed","Cancelled","TimedOut"):
            stdout=result.get("StandardOutputContent","")
            for line in stdout.splitlines():
                if re.fullmatch(r"ARCADECLOUD_DEPLOY_[A-Z_]+=(yes|no)",line):
                    print(line)
            print("ARCADECLOUD_DEPLOY_COMMAND_STATUS="+status)
            if status!="Success" or "ARCADECLOUD_DEPLOY_SUCCESS=yes" not in stdout:
                raise SystemExit(1)
            return
        time.sleep(8)
    raise SystemExit("Build is still running: check SSM command execution in AWS")
if __name__ == "__main__":
    main()
