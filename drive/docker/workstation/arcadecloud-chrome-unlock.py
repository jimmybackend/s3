#!/usr/bin/env python3
"""Limpia exclusivamente bloqueos Singleton obsoletos de Chrome.

El home de ArcadeCloud Office persiste entre contenedores, pero el hostname
Docker cambia. Nunca limpiar mientras quede un proceso Chrome vivo dentro de
la Workstation: podría estar utilizando realmente el perfil.
"""
from pathlib import Path
import os
import re
import socket

PROFILE = Path("/home/arcade/.config/google-chrome")
LOCK = PROFILE / "SingletonLock"
BROWSER_BASENAMES = {
    "chrome", "google-chrome", "google-chrome-stable",
    "chromium", "chromium-browser",
}


def any_chrome_process() -> bool:
    for proc in Path("/proc").iterdir():
        if not proc.name.isdecimal():
            continue
        try:
            command = (proc / "cmdline").read_bytes().split(b"\0", 1)[0]
            if command and Path(os.fsdecode(command)).name in BROWSER_BASENAMES:
                return True
        except (FileNotFoundError, ProcessLookupError, PermissionError, OSError):
            continue
    return False


def clean() -> None:
    if not LOCK.is_symlink():
        return  # No borrar archivos normales ni intentar desbloquear a la fuerza.

    target = os.readlink(LOCK)
    match = re.fullmatch(r"(.+)-([0-9]+)", target)
    if not match:
        return  # El lock no tiene la estructura esperada de Chromium.

    old_hostname, old_pid_str = match.groups()
    current_hostname = socket.gethostname()

    if any_chrome_process():
        print("[Chrome] Se conservaron los bloqueos: Chrome sigue ejecutándose.", flush=True)
        return

    if old_hostname == current_hostname:
        # El mismo contenedor puede haber cerrado Chrome abruptamente.
        try:
            os.kill(int(old_pid_str), 0)
        except ProcessLookupError:
            pass
        except PermissionError:
            return
        else:
            return

    # No quedan procesos Chrome y el bloqueo pertenece al contenedor anterior
    # (o a un PID ya terminado). Solo suprimir symlinks, nunca datos del perfil.
    for name in ("SingletonLock", "SingletonSocket", "SingletonCookie"):
        path = PROFILE / name
        if path.is_symlink():
            path.unlink(missing_ok=True)
    print("[Chrome] Bloqueos obsoletos del perfil liberados de forma segura.", flush=True)


if __name__ == "__main__":
    clean()
