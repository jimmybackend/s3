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
# La nueva política mínima requiere SYS_CHROOT para el chroot del zygote.
# Sólo puede añadirse cuando el hash del perfil aprobado coincide.
sandbox_option = (
    'CHROME_RUNTIME_OPTS="--cap-add=SYS_CHROOT '
    '--security-opt seccomp=$CHROME_SANDBOX_PROFILE '
    '--env ARCADECLOUD_CHROME_SANDBOX_ENABLED=1"'
)
assert 'CHROME_RUNTIME_OPTS=""' in installer
assert sandbox_option in installer
assert installer.index(sandbox_option) > installer.index('ACTUAL_CHROME_PROFILE_HASH')
assert '--cap-add=SYS_CHROOT' in trial
assert '--cap-add=SYS_ADMIN' not in trial
assert 'seccomp=unconfined' not in trial
assert "ARCADECLOUD_CHROME_SANDBOX_ENABLED=1" in installer
assert "ENABLE_ON_PASS=0" in trial
assert "--enable-on-pass" in trial
assert 'docker run --rm --network none' in trial
assert '--cap-drop=ALL' in trial and '--user 10001:10001' in trial
assert 'ENSAYO APROBADO' in trial
assert trial.index('ENSAYO APROBADO') < trial.index('if [[ "$ENABLE_ON_PASS" -eq 1 ]]')
assert 'install -o root -g root -m 0644' in trial
assert "sha256sum" in trial
assert "install -d -m " not in trial, "No modificar permisos del directorio de configuracion"
assert "seccomp=unconfined" not in installer
assert "ARCADECLOUD_CHROME_SANDBOX_ENABLED=1" not in rollback
assert "rm -f /etc/arcadecloud-drive/chrome-sandbox-enabled" in rollback
assert "systemctl restart" not in rollback
assert "docker restart" not in rollback
print("OK: Chrome sandbox queda inactivo por defecto y sólo se habilita al pasar la prueba.")
print("OK: perfil seccomp con hash y rollback seguros, sin reinicio automático.")
