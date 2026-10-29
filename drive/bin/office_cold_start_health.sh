#!/usr/bin/env bash
# Diagnóstico de arranque independiente sin reiniciar EC2 ni Docker.
# No lee archivos secretos ni cambia contraseñas.
set -uo pipefail
if [[ "${EUID:-1}" -ne 0 ]]; then
  echo "Usa: sudo bash drive/bin/office_cold_start_health.sh"
  exit 2
fi
CONTAINER=arcadecloud-workstation
echo "=== ESTADO ACTUAL ==="
systemctl is-active arcadecloud-workstation.service || true
docker ps --format 'table {{.Names}}\t{{.Status}}' | grep -E "NAMES|arcadecloud-workstation|arcadecloud-guacamole" || true
if ! docker ps --format '{{.Names}}' | grep -Fxq "$CONTAINER"; then
  echo "Workstation apagada. Iníciala abriendo KDE/noVNC desde Aplicaciones."
  exit 2
fi

echo "=== PROCESOS, KDE Y AUDIO ==="
docker exec --user root "$CONTAINER" bash -lc '
  echo "--- Escritorios ---"
  ps -eo user,pid,comm,args | grep -E "[X]tigervnc|[p]lasmashell|[p]ipewire|[w]ireplumber|[w]ebsockify|[x]rdp-sesman" |
    cut -c 1-140 || true
  echo "--- Audio independiente ---"
  su -s /bin/bash arcade -c "XDG_RUNTIME_DIR=/run/user/10001 PULSE_SERVER=unix:/run/user/10001/pulse/native pactl list short sinks" || true
  echo "--- Selección de inicio VNC ---"
  pgrep -af "tigervncserver|Xtigervnc" | cut -c 1-220 || true
  echo "--- Logs de arranque ---"
  for file in /tmp/arcade-audio-bootstrap.log /tmp/arcade-virtual-audio.log /tmp/arcade-plasma.log /tmp/arcade-audio-web.log; do
    echo "----- $file -----"
    if [[ -f "$file" ]]; then tail -n 12 "$file"; else echo "Sin log"; fi
  done
  echo "--- TigerVNC ---"
  find /home/arcade/.vnc -maxdepth 1 -type f -name "*.log" -print -exec tail -n 12 {} \; 2>/dev/null || true
'
echo "=== ACCESO HTTP LOCAL ==="
curl --silent --output /dev/null --write-out 'noVNC local: HTTP %{http_code}\n' --connect-timeout 2 --max-time 4 http://127.0.0.1:6080/vnc.html || true
echo "Verifica si KDE abre sin entrar a Guacamole."
