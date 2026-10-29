#!/usr/bin/env bash
# ArcadeCloud Office: diagnóstico NO DESTRUCTIVO del desbloqueo KDE.
# No revela contraseñas, no desbloquea pantallas, no cambia PAM ni Docker.
set -euo pipefail
container=arcadecloud-workstation

if [[ "$EUID" -ne 0 ]]; then
  echo "Ejecuta: sudo bash drive/bin/office_kde_unlock_diagnostic.sh"
  exit 2
fi
echo "=== CONTENEDOR Y PROTECCIONES ==="
if ! docker ps --format '{{.Names}}' | grep -Fxq "$container"; then
  echo "Workstation apagada: abre KDE/noVNC desde so.php antes de ejecutar."
  exit 2
fi
docker inspect --format 'NoNewPrivileges: {{json .HostConfig.SecurityOpt}}; CapDrop: {{json .HostConfig.CapDrop}}; CapAdd: {{json .HostConfig.CapAdd}}' "$container"

echo "=== KDE: BLOQUEO, PAM Y PRIVILEGIOS ==="
docker exec --user root "$container" bash -lc '
set -u
echo "--- Estado de la cuenta arcade (NO muestra contraseña) ---"
passwd -S arcade 2>&1 | cut -d " " -f 1-2 || true

echo "--- Sesiones KDE y bloqueador ---"
ps -eo pid,ppid,user,comm | grep -E "kcheckpass|kscreenlocker|ksmserver|plasmashell|Xtigervnc|xrdp" || true

echo "--- PAM: servicios relevantes (nombres de archivos) ---"
for path in /etc/pam.d/kde /etc/pam.d/kscreenlocker /etc/pam.d/common-auth /etc/pam.d/login; do
  if [[ -f "$path" ]]; then
    stat -c "%n permisos=%a propietario=%U:%G" "$path"
  else
    echo "NO EXISTE: $path"
  fi
done
echo "--- Programas KDE de validación (modo y propietario) ---"
for path in /usr/lib/x86_64-linux-gnu/libexec/kcheckpass /usr/lib/kscreenlocker_greet /usr/libexec/kcheckpass /usr/libexec/kscreenlocker_greet; do
  if [[ -e "$path" ]]; then
    stat -c "%n %A propietario=%U:%G" "$path"
    if command -v getcap >/dev/null 2>&1; then getcap "$path" || true; fi
  fi
done

echo "--- NoNewPrivs en procesos del bloqueo ---"
for pid in $(pgrep -u arcade -f "kscreenlocker|ksmserver" 2>/dev/null | head -n 8); do
  if [[ -r "/proc/$pid/status" ]]; then
    name=$(awk "/^Name:/ {print \$2}" "/proc/$pid/status")
    flag=$(awk "/^NoNewPrivs:/ {print \$2}" "/proc/$pid/status")
    echo "Proceso: $name NoNewPrivs=$flag"
  fi
done

echo "--- Configuración del bloqueo automático ---"
cfg=/home/arcade/.config/kscreenlockerrc
if [[ -f "$cfg" ]]; then
  awk "/^\[Daemon\]/{p=1;next} /^\[/{p=0} p && /^(Autolock|LockOnResume|LockOnStart|RequirePassword|Timeout)=/" "$cfg"
else
  echo "Sin configuración explícita: KDE usa valores predeterminados."
fi

echo "--- Registros recientes de KDE (sin datos de cuentas) ---"
grep -Ei "kcheckpass|kscreenlocker|pam_authenticate|pam_unix|authentication failure" /tmp/arcade-plasma.log 2>/dev/null | tail -n 18 || true
'
echo "=== RESULTADO ==="
echo "No se cambió ninguna contraseña ni se desactivó el bloqueo."
echo "Si KDE no puede autenticar, revisar PAM y los privilegios de kcheckpass."
