#!/usr/bin/env python3
"""Office KDE diagnostics must stay read-only; SSO scaffold remains opt-in."""
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
diagnostic = root / "bin/office_kde_unlock_diagnostic.sh"
body = diagnostic.read_text(encoding="utf-8")
test = subprocess.run(["bash", "-n", str(diagnostic)], capture_output=True, text=True)
assert test.returncode == 0, test.stderr
for forbidden in (
    "kscreenlockerrc --group Daemon --key Autolock false",
    "pkill kscreenlocker",
    "pkill kcheckpass",
    "kill -9",
    "systemctl restart",
    "docker restart",
    "chpasswd",
    "pam_permit",
):
    assert forbidden not in body, f"Unexpected destructive diagnostic: {forbidden}"
assert "NoNewPrivs" in body and "/etc/pam.d/kde" in body
assert "No se cambió ninguna contraseña" in body
print("OK: diagnóstico KDE/PAM es sintácticamente válido y de solo lectura.")
