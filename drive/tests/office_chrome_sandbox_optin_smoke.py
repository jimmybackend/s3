#!/usr/bin/env python3
"""Fail-closed, opt-in Chrome sandbox checks. Does not need Docker/EC2."""
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
launcher = (root / "docker/workstation/arcadecloud-chrome").read_text()
installer = (root / "bin/install_workstation_node.sh").read_text()
trial = (root / "bin/office_chrome_sandbox_trial.sh").read_text()
rollback = (root / "bin/office_chrome_sandbox_disable.sh").read_text()

for name in ("office_chrome_sandbox_trial.sh", "office_chrome_sandbox_disable.sh",
             "install_workstation_node.sh"):
    check = subprocess.run(["bash", "-n", str(root / "bin" / name)],
                           capture_output=True, text=True)
    assert check.returncode == 0, f"{name}: {check.stderr}"

assert "CHROME_SANDBOX_ARGS=(--no-sandbox)" in launcher
assert 'ARCADECLOUD_CHROME_SANDBOX_ENABLED:-0' in launcher
assert 'CHROME_SANDBOX_ARGS=()' in launcher
assert 'CHROME_SANDBOX_ARGS[@]' in launcher
assert "--cap-drop=ALL" in installer
assert "--security-opt=no-new-privileges:true" in installer
assert "CHROME_SANDBOX_MARKER" in installer
assert "ACTUAL_CHROME_PROFILE_HASH" in installer
assert 'CHROME_RUNTIME_OPTS="--security-opt seccomp=' in installer
assert "ARCADECLOUD_CHROME_SANDBOX_ENABLED=1" in installer
assert "ENABLE_ON_PASS=0" in trial
assert "--enable-on-pass" in trial
assert 'docker run --rm --network none' in trial
assert '--cap-drop=ALL --user 10001:10001' in trial
assert 'PRUEBA APROBADA' in trial
assert trial.index('PRUEBA APROBADA') < trial.index('if [[ "$ENABLE_ON_PASS" -eq 1 ]]')
assert 'install -o root -g root -m 0644' in trial
assert "sha256sum" in trial
assert "seccomp=unconfined" not in installer
assert "ARCADECLOUD_CHROME_SANDBOX_ENABLED=1" not in rollback
assert "rm -f /etc/arcadecloud-drive/chrome-sandbox-enabled" in rollback
assert "systemctl restart" not in rollback
assert "docker restart" not in rollback
print("OK: Chrome sandbox queda inactivo por defecto y sólo se habilita al pasar la prueba.")
print("OK: perfil seccomp con hash y rollback seguros, sin reinicio automático.")
