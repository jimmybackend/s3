#!/usr/bin/env python3
"""Verificaciones conservadoras de Chrome compartido KDE / Guacamole."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1] / "docker/workstation"
launcher = (ROOT / "arcadecloud-chrome").read_text(encoding="utf-8")
fixer = (ROOT / "repair-chrome-launchers.py").read_text(encoding="utf-8")

assert 'PROFILE_DIR="${HOME}/.config/google-chrome"' in launcher, "KDE perdió el perfil existente"
assert "arcadecloud-chrome-rdp" in launcher, "Guacamole necesita perfil propio"
assert '--user-data-dir="$PROFILE_DIR"' in launcher, "Falta aislamiento de Chrome RDP"
assert '10#${BASH_REMATCH[1]} >= 10' in launcher, "Aislar solo pantallas XRDP"
assert 'arcadecloud-chrome-unlock.py' in launcher, "Conservar desbloqueo KDE tras reinicios"
assert 'launcher-*/*.desktop' in fixer, "Falta reparar iconos guardados XFCE"
assert 'BACKUPS / (name + ".pre-arcadecloud-chrome")' in fixer, "Respaldar lanzadores sin colisiones"
print("OK: KDE conserva perfil; XRDP usa perfil separado y repara su Dock.")
