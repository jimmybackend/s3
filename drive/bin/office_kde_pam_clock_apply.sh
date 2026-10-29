#!/usr/bin/env bash
# Aplicación no destructiva de estilos y servicio PAM a la Workstation activa.
# No modifica contraseñas, procesos, .config de usuarios, Dock ni Docker caps.
set -euo pipefail

if [[ "$EUID" -ne 0 ]]; then
  echo "Ejecuta con sudo: sudo bash drive/bin/office_kde_pam_clock_apply.sh"
  exit 2
fi
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/docker/workstation"
CONTAINER=arcadecloud-workstation
if ! docker ps --format '{{.Names}}' | grep -Fxq "$CONTAINER"; then
  echo "Workstation apagada. Los cambios se aplicarán desde la nueva imagen en su próximo arranque."
  exit 0
fi

echo "=== INSTALANDO SERVICIO PAM KDE EN CONTENEDOR ACTIVO ==="
# Nunca sustituir módulos de autenticación por pam_permit ni cambiar shadow.
docker exec -i --user root "$CONTAINER" bash -lc '
  set -euo pipefail
  temp="$(mktemp /etc/pam.d/.arcadecloud-kde.XXXXXX)"
  trap '\''rm -f "$temp"'\'' EXIT
  cat > "$temp"
  chown root:root "$temp"
  chmod 0644 "$temp"
  mv -f "$temp" /etc/pam.d/kde
  echo "OK: /etc/pam.d/kde instalado (servicio de autenticación, no bypass)."
' < "$SRC/pam-kde"

echo "=== AJUSTANDO RELOJ OFFICE EN NOVNC ==="
docker exec -i --user root "$CONTAINER" bash -lc '
  set -euo pipefail
  temp="$(mktemp /usr/share/novnc/.arcadecloud-audio.XXXXXX)"
  trap '\''rm -f "$temp"'\'' EXIT
  cat > "$temp"
  chown root:root "$temp"
  chmod 0644 "$temp"
  mv -f "$temp" /usr/share/novnc/arcadecloud-audio.js
  echo "OK: indicador de inactividad ajustado para próximas recargas de noVNC."
' < "$SRC/arcadecloud-audio.js"

echo "=== REVISIÓN ==="
docker exec "$CONTAINER" bash -lc '
  stat -c "%n %a %U:%G" /etc/pam.d/kde
  grep -F "idleCountdown.style.bottom = '\''12px'\''" /usr/share/novnc/arcadecloud-audio.js >/dev/null &&
    echo "OK: regla de posición incorporada."
'

echo "No se reinició Workstation y no se cambiaron contraseñas."
echo "Si KDE sigue rechazando la contraseña, sus auxiliares necesitan un"
echo "mecanismo seguro de autenticación compatible con no-new-privileges."
