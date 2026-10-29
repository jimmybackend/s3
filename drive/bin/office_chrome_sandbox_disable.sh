#!/usr/bin/env bash
# Fast rollback: restore original Chrome compatibility mode without
# changing user profiles or disabling Docker no-new-privileges.
set -euo pipefail

if [[ "$EUID" -ne 0 ]]; then
  echo "Ejecuta: sudo bash drive/bin/office_chrome_sandbox_disable.sh"
  exit 2
fi
rm -f /etc/arcadecloud-drive/chrome-sandbox-enabled
echo "Chrome sandbox opt-in deshabilitado: configuración de compatibilidad restablecida."
bash /var/www/arcadecloud-drive/drive/bin/install_workstation_node.sh --reconcile
echo "No se reinició Workstation; los cambios se aplican en la próxima sesión."
echo "El archivo seccomp de ensayo puede quedarse almacenado para inspección futura."
