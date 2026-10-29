#!/usr/bin/env python3
"""Prueba del sink virtual y aislamiento XRDP sin un servidor real de audio."""
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / "docker/workstation/arcadecloud-audio-ensure-sink"
ENTRY = (ROOT / "docker/workstation/entrypoint.sh").read_text()
DOCKERFILE = (ROOT / "docker/workstation/Dockerfile").read_text()

assert "RDP_DISPLAY" in ENTRY
assert 'if [ "$RDP_DISPLAY" -ge 10 ]; then' in ENTRY
assert '/usr/local/bin/arcadecloud-audio-ensure-sink' in ENTRY
assert "COPY arcadecloud-audio-ensure-sink" in DOCKERFILE
assert "arcadecloud-audio-ensure-sink" in DOCKERFILE

with tempfile.TemporaryDirectory() as td:
    root = Path(td)
    state = root / "sinks"
    calls = root / "calls"
    pactl = root / "pactl"
    pactl.write_text("""#!/bin/sh
echo "$@" >> "$AUDIO_TEST_CALLS"
case "$1:$2:$3" in
    info::) exit 0 ;;
    list:short:sinks) cat "$AUDIO_TEST_STATE"; exit 0 ;;
    load-module:*) printf '1\\tarcadecloud-web\\tPipeWire\\n' >> "$AUDIO_TEST_STATE"; echo 7; exit 0 ;;
    set-default-sink:*) exit 0 ;;
    *) echo "Unexpected: $*" >&2; exit 2 ;;
esac
""")
    pactl.chmod(0o755)
    state.write_text("")
    calls.write_text("")
    env = {**os.environ, "PATH": f"{td}:{os.environ['PATH']}",
           "AUDIO_TEST_CALLS": str(calls), "AUDIO_TEST_STATE": str(state),
           "DISPLAY": ":1"}
    subprocess.run(["bash", str(HELPER)], check=True, env=env,
                   capture_output=True, text=True, timeout=12)
    log = calls.read_text()
    assert "load-module module-null-sink" in log
    assert "set-default-sink arcadecloud-web" in log

    calls.write_text("")
    subprocess.run(["bash", str(HELPER)], check=True, env=env,
                   capture_output=True, text=True, timeout=12)
    assert "load-module" not in calls.read_text(), "Idempotencia del módulo"

    state.write_text("1\tarcadecloud-web\tPipeWire\n2\txrdp-sink\tPipeWire\n")
    calls.write_text("")
    env["DISPLAY"] = ":10"
    subprocess.run(["bash", str(HELPER)], check=True, env=env,
                   capture_output=True, text=True, timeout=12)
    log = calls.read_text()
    assert "set-default-sink" not in log, "No alterar salida Guacamole"
    assert "load-module" not in log, "No duplicar virtual sink"

print("OK: noVNC crea salida independiente, XRDP conserva la suya.")
