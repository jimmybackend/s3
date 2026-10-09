"""Read-only host sizing for the always-on-demand ArcadeCloud Workstation.

The GitHub public log contains only numeric/boolean sizing metrics.
"""
from __future__ import annotations
import os
import re
import time
import boto3

SCRIPT = r'''#!/bin/bash
set -u
num() { printf 'ARC_SIZE_%s=%s\n' "$1" "$2"; }
mem_total=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)
mem_avail=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
swap_total=$(awk '/^SwapTotal:/ {print $2}' /proc/meminfo)
swap_free=$(awk '/^SwapFree:/ {print $2}' /proc/meminfo)
num MEM_TOTAL_KIB "${mem_total:-0}"
num MEM_AVAILABLE_KIB "${mem_avail:-0}"
num SWAP_TOTAL_KIB "${swap_total:-0}"
num SWAP_FREE_KIB "${swap_free:-0}"
num NPROC "$(nproc)"
load=$(cut -d' ' -f1 /proc/loadavg)
num LOAD_1MIN_X100 "$(awk -v v="$load" 'BEGIN {printf "%.0f", v*100}')"
for item in root:/ workstation:/var/lib/arcadecloud-office docker:/var/lib/docker; do
  label=${item%%:*}; target=${item#*:}
  if [ -d "$target" ]; then
    set -- $(df -Pk "$target" | tail -n 1 | awk '{print $2,$3,$4}')
    num "${label^^}_DISK_TOTAL_KIB" "${1:-0}"
    num "${label^^}_DISK_USED_KIB" "${2:-0}"
    num "${label^^}_DISK_FREE_KIB" "${3:-0}"
  fi
done
for service in nginx docker php-fpm-drive arcadecloud-media-worker arcadecloud-workstation; do
  label=$(printf '%s' "$service" | tr '[:lower:]-' '[:upper:]_')
  if systemctl is-active --quiet "$service.service"; then num "${label}_ACTIVE" 1; else num "${label}_ACTIVE" 0; fi
done
if command -v docker >/dev/null 2>&1; then
  containers=$(docker ps -q 2>/dev/null | wc -l)
  num RUNNING_CONTAINERS "$containers"
  if docker image inspect arcadecloud/workstation:phase1 >/dev/null 2>&1; then
    sz=$(docker image inspect --format '{{.Size}}' arcadecloud/workstation:phase1 2>/dev/null || echo 0)
    num WORKSTATION_IMAGE_BYTES "$sz"
  fi
fi
if [ -d /var/lib/arcadecloud-office/home/arcade ]; then
  num PERSISTENT_HOME_KIB "$(du -sk /var/lib/arcadecloud-office/home/arcade 2>/dev/null | awk '{print $1}')"
fi
num FINISHED 1
'''
FIELDS = frozenset({
  "MEM_TOTAL_KIB","MEM_AVAILABLE_KIB","SWAP_TOTAL_KIB","SWAP_FREE_KIB",
  "NPROC","LOAD_1MIN_X100",
  "ROOT_DISK_TOTAL_KIB","ROOT_DISK_USED_KIB","ROOT_DISK_FREE_KIB",
  "WORKSTATION_DISK_TOTAL_KIB","WORKSTATION_DISK_USED_KIB","WORKSTATION_DISK_FREE_KIB",
  "DOCKER_DISK_TOTAL_KIB","DOCKER_DISK_USED_KIB","DOCKER_DISK_FREE_KIB",
  "NGINX_ACTIVE","DOCKER_ACTIVE","PHP_FPM_DRIVE_ACTIVE",
  "ARCADECLOUD_MEDIA_WORKER_ACTIVE","ARCADECLOUD_WORKSTATION_ACTIVE",
  "RUNNING_CONTAINERS","WORKSTATION_IMAGE_BYTES","PERSISTENT_HOME_KIB","FINISHED"
})
def main():
  reg=os.environ.get("ARCADECLOUD_AWS_REGION","")
  iid=os.environ.get("ARCADECLOUD_AWS_LARGE_ID","")
  if not re.fullmatch(r"[a-z]{2}-[a-z]+-\d+",reg) or not re.fullmatch(r"i-[0-9a-f]{8,17}",iid):
    raise SystemExit("Invalid EC2 configuration")
  ssm=boto3.client("ssm",region_name=reg)
  response=ssm.send_command(
    InstanceIds=[iid],DocumentName="AWS-RunShellScript",
    Parameters={"commands":[SCRIPT]},
    Comment="ArcadeCloud safe host resources sizing",
    TimeoutSeconds=90
  )
  cid=response["Command"]["CommandId"]
  for _ in range(25):
    try:
      out=ssm.get_command_invocation(CommandId=cid,InstanceId=iid)
    except ssm.exceptions.InvocationDoesNotExist:
      time.sleep(2);continue
    status=out["Status"]
    if status in {"Success","Failed","Cancelled","TimedOut"}:
      data={}
      for line in out.get("StandardOutputContent","").splitlines():
        m=re.fullmatch(r"ARC_SIZE_([A-Z0-9_]+)=([0-9]+)",line)
        if m and m.group(1) in FIELDS:
          data[m.group(1)] = int(m.group(2))
      for key,value in sorted(data.items()):
        print("ARC_SIZE_"+key+"="+str(value))
      if status!="Success" or data.get("FINISHED")!=1:
        raise SystemExit("Remote diagnostics failed")
      return
    time.sleep(2)
  raise SystemExit("Remote sizing timed out")
if __name__=="__main__":
  main()
