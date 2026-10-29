#!/usr/bin/env python3
"""Regression tests for remote SSO KDE/noVNC without shared-desktop leakage."""
from pathlib import Path
import os
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
script = root / "docker/workstation/arcadecloud-kde-remote-sso.py"
installer = (root / "bin/install_workstation_node.sh").read_text()
entry = (root / "docker/workstation/entrypoint.sh").read_text()
lease = (root / "src/Office/OfficeSessionLeaseRepository.php").read_text()
dockerfile = (root / "docker/workstation/Dockerfile").read_text()

assert "ARCADECLOUD_OFFICE_LEGACY_SSO=1" in installer
assert "COPY arcadecloud-kde-remote-sso.py" in dockerfile
assert "python3 /usr/local/bin/arcadecloud-kde-remote-sso.py" in entry
assert entry.index("python3 /usr/local/bin/arcadecloud-kde-remote-sso.py") < entry.index("exec startplasma-x11")
assert "no-new-privileges:true" in installer
assert "ARCADECLOUD_SESSION_TRANSPORT=novnc" in entry
assert "ARCADECLOUD_SESSION_TRANSPORT=xrdp" in entry
assert "if (is_array($current) && (int)($current['UserId'] ?? 0) !== $userId)" in lease
assert "Incluso con lease" in lease

with tempfile.TemporaryDirectory(prefix="arcadecloud-kde-legacy-test-") as folder:
    home = Path(folder)
    (home / ".config").mkdir()
    config = home / ".config/kscreenlockerrc"
    original = "[Greeter]\nWallpaperPlugin=org.kde.image\n[Daemon]\nAutolock=true\nRequirePassword=true\nTimeout=4\n"
    config.write_text(original)

    env = dict(os.environ, HOME=folder, ARCADECLOUD_OFFICE_LEGACY_SSO="1",
               ARCADECLOUD_SESSION_TRANSPORT="novnc", ARCADECLOUD_DESKTOP="plasma")
    subprocess.run(["python3", str(script)], env=env, check=True, capture_output=True)
    changed = config.read_text()
    assert "Autolock=false" in changed
    assert "RequirePassword=false" in changed
    assert "LockOnResume=false" in changed
    assert "LockOnStart=false" in changed
    assert "Timeout=4" in changed  # unrelated preferences preserved
    assert "WallpaperPlugin=org.kde.image" in changed
    assert (home / ".config/kscreenlockerrc.pre-arcadecloud-office.bak").read_text() == original
    subprocess.run(["python3", str(script)], env=env, check=True, capture_output=True)
    assert config.read_text() == changed

    # The same account's XRDP desktop MUST NOT receive an automatic SSO unlock.
    config.write_text(original)
    env["ARCADECLOUD_SESSION_TRANSPORT"] = "xrdp"
    subprocess.run(["python3", str(script)], env=env, check=True, capture_output=True)
    assert config.read_text() == original

    # If the operator opts out, keep KDE native lock policy untouched.
    env["ARCADECLOUD_SESSION_TRANSPORT"] = "novnc"
    env["ARCADECLOUD_OFFICE_LEGACY_SSO"] = "0"
    subprocess.run(["python3", str(script)], env=env, check=True, capture_output=True)
    assert config.read_text() == original

print("OK: noVNC KDE no solicita segunda clave en modo legacy SSO autenticado.")
print("OK: XRDP intacto; wallpaper, Dock, Chrome y preferencias de KDE preservados.")
print("OK: leasing impide que otro UserId herede el escritorio compartido.")
