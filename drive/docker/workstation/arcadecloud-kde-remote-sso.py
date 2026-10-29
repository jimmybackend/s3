#!/usr/bin/env python3
"""KDE noVNC legacy: rely on the authenticated Office gateway, not PAM lock.

Only runs inside the CURRENT single-user Workstation when the server has
explicitly enabled ARCADECLOUD_OFFICE_LEGACY_SSO=1. This is not a substitute
for isolated per-user containers, and must not be used in untrusted desktops.
Preserves unrelated KDE configuration, wallpaper, Dock and Chrome profiles.
"""
from __future__ import annotations

import os
import re
import shutil
import stat
import tempfile
from pathlib import Path

REQUIRED = {
    "Autolock": "false",
    "LockOnResume": "false",
    "LockOnStart": "false",
    "RequirePassword": "false",
}
GROUP = re.compile(r"^\s*\[[^\]]+\]\s*$")
KEY = re.compile(r"^\s*([A-Za-z][A-Za-z0-9]*)\s*=")


def update(text: str) -> str:
    lines = text.splitlines(keepends=True)
    section_start = None
    section_end = len(lines)
    for i, line in enumerate(lines):
        if line.strip() == "[Daemon]":
            section_start = i
            break
    if section_start is not None:
        for j in range(section_start + 1, len(lines)):
            if GROUP.match(lines[j]):
                section_end = j
                break
        prefix = lines[: section_start + 1]
        section = lines[section_start + 1 : section_end]
        suffix = lines[section_end:]
    else:
        if lines and not lines[-1].endswith("\n"):
            lines[-1] += "\n"
        prefix = lines + ["[Daemon]\n"]
        section = []
        suffix = []

    emitted = set()
    output = []
    for line in section:
        match = KEY.match(line)
        key = match.group(1) if match else ""
        if key in REQUIRED:
            if key not in emitted:
                output.append(f"{key}={REQUIRED[key]}\n")
                emitted.add(key)
        else:
            output.append(line)
    for key, value in REQUIRED.items():
        if key not in emitted:
            output.append(f"{key}={value}\n")
    if output and not output[-1].endswith("\n"):
        output[-1] += "\n"
    return "".join(prefix + output + suffix)


def main() -> int:
    if (
        os.environ.get("ARCADECLOUD_OFFICE_LEGACY_SSO") != "1"
        or os.environ.get("ARCADECLOUD_SESSION_TRANSPORT") != "novnc"
        or os.environ.get("ARCADECLOUD_DESKTOP") != "plasma"
    ):
        print("ArcadeCloud: noVNC legacy SSO no habilitado para esta sesión.")
        return 0

    path = Path.home() / ".config" / "kscreenlockerrc"
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    if path.is_symlink():
        raise RuntimeError("No se modifica una configuración KDE que sea un enlace simbólico.")
    original = path.read_text(encoding="utf-8") if path.exists() else ""
    result = update(original)
    if original == result:
        print("ArcadeCloud: bloqueo automático de KDE ya administrado por Office.")
        return 0

    backup = path.with_name("kscreenlockerrc.pre-arcadecloud-office.bak")
    if path.exists() and not backup.exists():
        shutil.copy2(path, backup)
    old_mode = stat.S_IMODE(path.stat().st_mode) if path.exists() else 0o600

    fd, temp_name = tempfile.mkstemp(prefix=".kscreenlockerrc.", dir=path.parent)
    try:
        os.fchmod(fd, old_mode)
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            stream.write(result)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temp_name, path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)

    print("ArcadeCloud: KDE remoto sin segunda contraseña; acceso protegido por Office.")
    print("ArcadeCloud: respaldo de preferencias anterior conservado si existía.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
