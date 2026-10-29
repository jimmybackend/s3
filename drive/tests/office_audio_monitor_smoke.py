#!/usr/bin/env python3
"""Audio Office: elegir monitor con entradas activas sin perder fallback."""
from importlib.machinery import SourceFileLoader
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

root = Path(__file__).resolve().parents[1]
module = SourceFileLoader(
    "arcadecloud_office_audio",
    str(root / "docker/workstation/arcadecloud-audio-server.py"),
).load_module()

def returned(args, **kwargs):
    cmd = " ".join(args)
    if cmd == "pactl list short sinks":
        return SimpleNamespace(stdout="95\txrdp-sink\tPipeWire\n100\tfallback-sink\tPipeWire\n")
    if cmd == "pactl list short sink-inputs":
        return SimpleNamespace(stdout="215\t100\t214\tPipeWire\n")
    if cmd == "pactl get-default-sink":
        return SimpleNamespace(stdout="xrdp-sink\n")
    raise AssertionError(cmd)

with patch.object(module, "run", side_effect=returned):
    assert module.active_audio_monitor({}) == "fallback-sink.monitor"

def without_active(args, **kwargs):
    if args == ["pactl", "list", "short", "sink-inputs"]:
        return SimpleNamespace(stdout="")
    return returned(args, **kwargs)

with patch.object(module, "run", side_effect=without_active):
    assert module.active_audio_monitor({}) == "xrdp-sink.monitor"

with patch.object(module, "run", side_effect=OSError("offline")):
    assert module.active_audio_monitor({}) == "xrdp-sink.monitor"

def with_web_sink(args, **kwargs):
    if args == ["pactl", "list", "short", "sinks"]:
        return SimpleNamespace(stdout="95\txrdp-sink\tPipeWire\n103\tarcadecloud-web\tPipeWire\n")
    return returned(args, **kwargs)

# Si Guacamole tiene una entrada activa, noVNC no debe capturarla.
with patch.object(module, "run", side_effect=with_web_sink):
    assert module.active_audio_monitor({}) == "arcadecloud-web.monitor"

print("OK: audio KDE/XFCE captura el sink activo con fallback")
