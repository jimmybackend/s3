"""Read-only SSM proof of FastDrive auto-shutdown session after GitOps reload."""
from __future__ import annotations
import os
import re
import time
import boto3

SCRIPT=r'''#!/bin/bash
set -euo pipefail
APP=/var/www/arcadecloud-drive
if [ ! -f "$APP/drive/app_bootstrap.php" ]; then
  printf 'ARCADECLOUD_IDLE_PROBE_APP_MISSING=yes\n'
  exit 11
fi
if ! systemctl is-active --quiet arcadecloud-media-worker.service; then
  printf 'ARCADECLOUD_IDLE_PROBE_WORKER_ACTIVE=no\n'
  exit 12
fi
printf 'ARCADECLOUD_IDLE_PROBE_WORKER_ACTIVE=yes\n'
php -d display_errors=0 -r '
  require "/var/www/arcadecloud-drive/drive/app_bootstrap.php";
  $id = (new \\ArcadeCloud\\Drive\\System\\Ec2InstanceIdentityService())->current();
  $iid = (string)($id["instance_id"] ?? "");
  if (!preg_match("/^i-[0-9a-f]{8,17}$/", $iid)) {
     echo "ARCADECLOUD_IDLE_PROBE_IDENTITY=no\\n"; exit(13);
  }
  echo "ARCADECLOUD_IDLE_PROBE_IDENTITY=yes\\n";
  $db = \\ArcadeCloud\\Drive\\Core\\ApplicationKernel::app()->db();
  $stmt = $db->prepare("SELECT Status, IdleSince FROM MediaWorkerNodeSessions
    WHERE InstanceId=? AND Status IN (\x27starting\x27,\x27running\x27,\x27idle\x27,\x27stopping\x27)
    ORDER BY id_ DESC LIMIT 1");
  if (!$stmt) exit(14);
  $stmt->bind_param("s", $iid);
  if (!$stmt->execute()) exit(15);
  $r=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!is_array($r)) {
      echo "ARCADECLOUD_IDLE_PROBE_SESSION=absent\\n";
      echo "ARCADECLOUD_IDLE_PROBE_TIMER=not_armed\\n";
      exit(0);
  }
  echo "ARCADECLOUD_IDLE_PROBE_SESSION=present\\n";
  $status=(string)$r["Status"];
  $statuses=["starting","running","idle","stopping"];
  if (!in_array($status,$statuses,true)) exit(16);
  echo "ARCADECLOUD_IDLE_PROBE_STATUS=".$status."\\n";
  $timer=($status==="idle" && trim((string)($r["IdleSince"]??""))!=="")?"armed":"not_armed";
  echo "ARCADECLOUD_IDLE_PROBE_TIMER=".$timer."\\n";
'
'''
def main():
    region=os.environ.get("ARCADECLOUD_AWS_REGION","")
    instance=os.environ.get("ARCADECLOUD_AWS_LARGE_ID","")
    if not re.fullmatch(r"[a-z]{2}-[a-z]+-\d+",region) or not re.fullmatch(r"i-[0-9a-f]{8,17}",instance):
        raise SystemExit("Missing EC2 settings")
    ssm=boto3.client("ssm",region_name=region)
    sent=ssm.send_command(InstanceIds=[instance],DocumentName="AWS-RunShellScript",
        Parameters={"commands":[SCRIPT],"executionTimeout":["90"]},
        TimeoutSeconds=90,Comment="ArcadeCloud post-deploy read-only idle state health")
    cmd=sent["Command"]["CommandId"]
    print("ARCADECLOUD_IDLE_PROBE_SUBMITTED=yes")
    for _ in range(32):
        try:
            out=ssm.get_command_invocation(CommandId=cmd,InstanceId=instance)
        except ssm.exceptions.InvocationDoesNotExist:
            time.sleep(2);continue
        if out["Status"] in ("Success","Failed","Cancelled","TimedOut"):
            lines=out.get("StandardOutputContent","").splitlines()
            flags=[]
            for line in lines:
                if re.fullmatch(r"ARCADECLOUD_IDLE_PROBE_(?:APP_MISSING|WORKER_ACTIVE|IDENTITY)=(?:yes|no)",line) or re.fullmatch(
                    r"ARCADECLOUD_IDLE_PROBE_(?:SESSION)=(?:present|absent)|ARCADECLOUD_IDLE_PROBE_(?:TIMER)=(?:armed|not_armed)|ARCADECLOUD_IDLE_PROBE_STATUS=(?:starting|running|idle|stopping)",line):
                    print(line)
                    flags.append(line)
            print("ARCADECLOUD_IDLE_PROBE_COMMAND_STATUS="+out["Status"])
            if out["Status"]!="Success":
                raise SystemExit(1)
            if "ARCADECLOUD_IDLE_PROBE_SESSION=absent" in flags:
                raise SystemExit("Auto-off timer has no associated session")
            return
        time.sleep(2)
    raise SystemExit("SSM probe timed out")
if __name__=="__main__":
    main()
