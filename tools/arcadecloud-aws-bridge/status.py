"""Minimal read-only EC2 bridge for GitHub Actions.

The repository is public: print no credentials, IPs, tags, SSM output or
environment variables. Only read EC2 state and SSM connectivity.
"""
from __future__ import annotations

import argparse
import json
import os
import re
from pathlib import Path

INSTANCE_ID = re.compile(r"^i-[0-9a-f]{8,17}$")
REGION = re.compile(r"^[a-z]{2}(?:-gov)?-[a-z]+-\d+$")
REQUEST_ID = re.compile(r"^[a-zA-Z0-9][a-zA-Z0-9._-]{2,63}$")
ALIASES = frozenset({"small", "large"})
REPORTS = frozenset({"status"})


def validate_request(payload):
    """Reject unknown keys, free-form commands, IDs and arbitrary targets."""
    if not isinstance(payload, dict):
        raise ValueError("Request must be an object")
    if set(payload) != {"request_id", "alias", "report"}:
        raise ValueError("Request keys must be exactly request_id, alias, report")
    if payload["alias"] not in ALIASES:
        raise ValueError("Only small or large aliases are accepted")
    if payload["report"] not in REPORTS:
        raise ValueError("Only the status report is enabled")
    request_id = payload["request_id"]
    if not isinstance(request_id, str) or not REQUEST_ID.fullmatch(request_id):
        raise ValueError("Invalid request_id")
    return payload


def load_request(event_name, request_path, manual_alias, manual_report, run_id):
    if event_name == "workflow_dispatch":
        return validate_request({
            "request_id": "manual-" + str(run_id),
            "alias": manual_alias,
            "report": manual_report,
        })
    if event_name != "push":
        raise ValueError("Event not supported")
    return validate_request(json.loads(Path(request_path).read_text(encoding="utf-8")))


def validate_config(alias, env):
    region = env.get("ARCADECLOUD_AWS_REGION", "").strip()
    name = "ARCADECLOUD_AWS_SMALL_ID" if alias == "small" else "ARCADECLOUD_AWS_LARGE_ID"
    instance_id = env.get(name, "").strip()
    if not REGION.fullmatch(region):
        raise ValueError("Missing/invalid ARCADECLOUD_AWS_REGION")
    if not INSTANCE_ID.fullmatch(instance_id):
        raise ValueError(f"Missing/invalid {name}")
    return region, instance_id


def get_status(request, env, session_factory):
    region, instance_id = validate_config(request["alias"], env)
    session = session_factory(region_name=region)
    ec2 = session.client("ec2")
    response = ec2.describe_instances(InstanceIds=[instance_id])
    instances = [
        i for reservation in response.get("Reservations", [])
        for i in reservation.get("Instances", [])
    ]
    if len(instances) != 1 or instances[0].get("InstanceId") != instance_id:
        raise ValueError("Configured instance is not visible to this AWS identity")
    instance = instances[0]
    ssm = session.client("ssm")
    ping = ssm.describe_instance_information(
        Filters=[{"Key": "InstanceIds", "Values": [instance_id]}]
    ).get("InstanceInformationList", [])
    ssm_status = ping[0].get("PingStatus", "Unknown") if ping else "NotRegistered"
    return {
        "request_id": request["request_id"],
        "alias": request["alias"],
        "report": "status",
        "region": region,
        "state": instance.get("State", {}).get("Name", "unknown"),
        "type": instance.get("InstanceType", "unknown"),
        "ssm": ssm_status,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--event", required=True)
    parser.add_argument("--request-path", default="tools/arcadecloud-aws-bridge/requests/current.json")
    parser.add_argument("--alias", default="")
    parser.add_argument("--report", default="")
    parser.add_argument("--run-id", default="00000")
    args = parser.parse_args()
    request = load_request(
        args.event, args.request_path, args.alias, args.report, args.run_id
    )
    # boto3 credentials are sourced from GitHub OIDC temporary STS role, NOT
    # from any EC2 host's ~/.aws/credentials.
    import boto3
    result = get_status(request, os.environ, boto3.Session)
    print("ARCADECLOUD_AWS_STATUS_JSON=" + json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    main()
