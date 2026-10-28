#!/usr/bin/env python3
"""Regresión: jamás borrar datos del perfil ni locks si Chrome está vivo."""
from importlib.machinery import SourceFileLoader
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch
import os
import sys

root = Path(__file__).resolve().parents[1]
module = SourceFileLoader(
    "arcadecloud_chrome_unlock",
    str(root / "docker/workstation/arcadecloud-chrome-unlock.py")
).load_module()

with TemporaryDirectory() as temp:
    profile = Path(temp)
    module.PROFILE = profile
    module.LOCK = profile / "SingletonLock"
    (profile / "Preferences").write_text('{"keep":true}')
    (profile / "Login Data").write_text("keep-private-data")

    def locks():
        for name in ("SingletonLock", "SingletonSocket", "SingletonCookie"):
            path = profile / name
            if path.is_symlink():
                path.unlink()
            path.symlink_to("old-container-721" if name == "SingletonLock" else "old-target")

    locks()
    with patch.object(module, "any_chrome_process", return_value=True):
        module.clean()
    assert module.LOCK.is_symlink(), "Nunca borrar bloqueo de Chrome activo"

    with patch.object(module, "any_chrome_process", return_value=False), \
         patch.object(module.socket, "gethostname", return_value="new-container"):
        module.clean()
    assert all(not (profile / n).is_symlink() for n in ("SingletonLock", "SingletonSocket", "SingletonCookie"))
    assert (profile / "Preferences").read_text() == '{"keep":true}'
    assert (profile / "Login Data").read_text() == "keep-private-data"

    locks()
    with patch.object(module, "any_chrome_process", return_value=False), \
         patch.object(module.socket, "gethostname", return_value="old-container"), \
         patch.object(module.os, "kill", return_value=None):
        module.clean()
    assert module.LOCK.is_symlink(), "Nunca borrar bloqueo con PID todavía vivo"

print("OK: Chrome Singleton no borra perfiles ni bloqueos activos")
