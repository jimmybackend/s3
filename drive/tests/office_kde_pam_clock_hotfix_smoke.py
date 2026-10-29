#!/usr/bin/env python3
"""Office KDE/PAM hotfix must leave the runtime and credentials untouched."""
from pathlib import Path
import subprocess
root = Path(__file__).resolve().parents[1]
script = root / "bin/office_kde_pam_clock_apply.sh"
diag = root / "bin/office_kde_unlock_diagnostic.sh"
for path in (script, diag):
    completed = subprocess.run(["bash", "-n", str(path)], capture_output=True, text=True)
    assert completed.returncode == 0, completed.stderr
body = script.read_text(encoding="utf-8")
assert "docker exec -i --user root" in body
assert "dock" in body
for forbidden in ("chpasswd", "systemctl restart", "docker restart", "no-new-privileges:false",
                  "chmod 777", "pam_permit", "set-default-sink", "kscreenlocker_greet -quit"):
    assert forbidden not in body, f"Unsafe hotfix operation: {forbidden}"
assert "/etc/pam.d/kde" in body
assert "arcadecloud-audio.js" in body
assert "No se reinició Workstation" in body
print("OK: hotfix PAM/reloj validado sin reinicios, passwords ni permisos globales.")
