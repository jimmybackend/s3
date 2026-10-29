#!/usr/bin/env python3
"""Checks that Office password is persistent and never printed to the terminal."""
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
setter = root / "bin/office_password_set.sh"
entry = (root / "docker/workstation/entrypoint.sh").read_text()
installer = (root / "bin/install_guacamole_node.sh").read_text()
source = setter.read_text()

result = subprocess.run(["bash", "-n", str(setter)], capture_output=True, text=True)
assert result.returncode == 0, result.stderr

assert "RDP_ENV=/etc/arcadecloud-drive/rdp.env" in source
assert 'read -r -s -p "Nueva contraseña' in source
assert 'read -r -s -p "Confirma la contraseña' in source
assert 'chmod 0600 "$temp"' in source
assert 'mv -f "$temp" "$RDP_ENV"' in source
assert "docker ps --format" in source
assert "docker exec -i --user root" in source
assert 'bash "$GUAC_INSTALLER"' in source
assert 'passwd -S arcade' in source
assert 'echo "$password"' not in source
assert 'cat "$RDP_ENV"' not in source
assert 'systemctl restart' not in source
assert "ARCADECLOUD_RDP_PASSWORD" in entry and "| chpasswd" in entry
assert 'RDP_ENV_FILE="/etc/arcadecloud-drive/rdp.env"' in installer
assert "RDP_PASSWORD=\"$(awk -F=" in installer
assert "(@CID,'password','$RDP_PASSWORD')" in installer
print("OK: contraseña única persistente, oculta y sincronizable sin reinicios.")
