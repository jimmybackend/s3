#!/usr/bin/env python3
"""Normaliza lanzadores Chrome para KDE y XFCE sin borrar el Dock de Plasma.

Se ejecuta antes de iniciar VNC, cuando plasmashell aún no ha cargado sus
preferencias. Solo cambia Exec= de launchers Chrome conocidos. No toca el
applet de la barra, el fondo ni los demás archivos del usuario.
"""
from pathlib import Path
import os
import re
import shutil

RUNNER = "/usr/local/bin/arcadecloud-chrome"
HOME = Path("/home/arcade")
CONFIG = HOME / ".config"
BACKUPS = CONFIG / "arcadecloud-backups"

# Chrome publica distintos Exec para ventana normal/incógnito.
# El mismo launcher ArcadeCloud maneja las opciones originales (%U, --incognito).
BAD_EXEC = re.compile(
    r"(?m)^Exec=(?:/usr/bin/)?google-chrome(?:-stable)?(?=\s|$)"
)
TARGETS = (
    Path("/usr/share/applications/google-chrome.desktop"),
    Path("/usr/share/applications/google-chrome-stable.desktop"),
    HOME / "Desktop" / "Chrome.desktop",
    # XFCE conserva algunos iconos del Dock en archivos de lanzador privados.
    # Se inspeccionan también, pero SOLO se modifica Exec= si invoca Chrome.
    *sorted((CONFIG / "xfce4" / "panel").glob("launcher-*/*.desktop")),
    *sorted((HOME / ".local" / "share" / "applications").glob("*chrome*.desktop")),
)

for path in TARGETS:
    if not path.is_file():
        continue
    try:
        original = path.read_text(encoding="utf-8")
        patched = BAD_EXEC.sub("Exec=" + RUNNER, original)
        if original == patched:
            continue
        if path.is_relative_to(HOME):
            # Respaldo único del lanzador personalizado anterior.
            BACKUPS.mkdir(parents=True, exist_ok=True)
            os.chown(BACKUPS, 10001, 10001)
            # Cada launcher XFCE debe conservar su propia copia; no sobrescribir
            # el respaldo histórico del Chrome.desktop del usuario.
            relative = path.relative_to(HOME)
            name = ("Chrome.desktop" if relative == Path("Desktop/Chrome.desktop")
                    else ".".join(relative.parts))
            backup = BACKUPS / (name + ".pre-arcadecloud-chrome")
            if not backup.exists():
                shutil.copy2(path, backup)
                os.chown(backup, 10001, 10001)
        path.write_text(patched, encoding="utf-8")
        print("[Chrome] Lanzador reparado:", path, flush=True)
    except (OSError, UnicodeError) as exc:
        print("[Chrome] No se pudo reparar", path, str(exc), flush=True)
