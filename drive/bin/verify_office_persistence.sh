#!/usr/bin/env bash
# ArcadeCloud Office / FastDrive: comprobación no destructiva de persistencia.
# No reinicia servicios, no imprime contraseñas ni habilita Workstation al boot.
set -uo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Ejecuta con sudo: sudo bash drive/bin/verify_office_persistence.sh"
  exit 2
fi

home_dir=/var/lib/arcadecloud-office/home/arcade
workspace_dir=/var/lib/arcadecloud-office/phase1-workspace
unit=/etc/systemd/system/arcadecloud-workstation.service
workstation_env=/etc/arcadecloud-drive/workstation.env
rdp_env=/etc/arcadecloud-drive/rdp.env
failures=0

ok()   { printf 'OK: %s\n' "$*"; }
warn() { printf 'AVISO: %s\n' "$*"; }
bad()  { printf 'FALTA: %s\n' "$*"; failures=$((failures + 1)); }
file_exists() {
  if [[ -f "$1" ]]; then ok "$2"; else bad "$2 ($1)"; fi
}
directory_exists() {
  if [[ -d "$1" ]]; then ok "$2"; else bad "$2 ($1)"; fi
}

echo "=== ARCADECLOUD OFFICE: PERSISTENCIA FASTDRIVE ==="
directory_exists "$home_dir" "Home KDE/XFCE/Chrome persistente"
directory_exists "$workspace_dir" "Workspace documental persistente"
directory_exists "$home_dir/.config" "Preferencias del escritorio persistentes"
file_exists "$workstation_env" "Configuración de Workstation"
file_exists "$rdp_env" "Contraseña RDP guardada para el próximo arranque"
file_exists "$unit" "Unidad systemd de Workstation"

if [[ -f "$workstation_env" ]]; then
  if grep -Eq '^ARCADECLOUD_DESKTOP=plasma$' "$workstation_env"; then
    ok "KDE Plasma será el escritorio noVNC al reiniciar"
  else
    warn "El selector ARCADECLOUD_DESKTOP no está fijado en plasma"
  fi
fi

if [[ -f "$rdp_env" ]]; then
  if grep -q '^ARCADECLOUD_RDP_PASSWORD=.' "$rdp_env"; then
    ok "Contraseña RDP configurada (valor oculto)"
  else
    bad "Contraseña RDP ausente o vacía"
  fi
  mode=$(stat -c %a "$rdp_env")
  if [[ "$mode" == "600" ]]; then
    ok "Archivo de contraseña con permisos privados"
  else
    warn "Revisar permisos de rdp.env (actuales: $mode; esperado 600)"
  fi
fi

if [[ -f "$unit" ]]; then
  if grep -Fq -- "--volume $home_dir:/home/arcade" "$unit"; then
    ok "La unidad monta el home persistente al arrancar"
  else
    bad "La unidad NO monta el home persistente"
  fi
  if grep -Fq -- "--volume $workspace_dir:/workspace" "$unit"; then
    ok "La unidad monta el workspace persistente"
  else
    bad "La unidad NO monta el workspace persistente"
  fi
  if grep -Fq -- "--env-file $rdp_env" "$unit" && grep -Fq -- "--env-file $workstation_env" "$unit"; then
    ok "La unidad recupera sus configuraciones en cada arranque"
  else
    bad "La unidad no usa ambos archivos env"
  fi
fi

if systemctl is-enabled --quiet docker.service; then
  ok "Docker habilitado en el arranque EC2"
else
  bad "Docker no está habilitado en el arranque"
fi

if [[ "$(systemctl is-enabled arcadecloud-workstation.service 2>/dev/null || true)" == "disabled" ]]; then
  ok "Workstation bajo demanda (ahorra recursos y respeta autoapagado)"
else
  warn "Comprobar inicio bajo demanda; Workstation no aparece como disabled"
fi

if docker image inspect arcadecloud/workstation:phase1 >/dev/null 2>&1; then
  ok "Imagen Docker Workstation disponible para siguientes sesiones"
else
  bad "No se encuentra la imagen Docker Workstation"
fi

if docker volume inspect arcadecloud-guac-mysql >/dev/null 2>&1; then
  ok "Base de datos Guacamole en volumen persistente"
else
  bad "No se encontró el volumen MySQL de Guacamole"
fi

for container in arcadecloud-guac-db arcadecloud-guacd arcadecloud-guacamole; do
  policy=$(docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' "$container" 2>/dev/null || true)
  if [[ "$policy" == "unless-stopped" || "$policy" == "always" ]]; then
    ok "$container: reinicio automático ($policy)"
  else
    warn "$container: política Docker desconocida o no persistente (${policy:-ausente})"
  fi
done

if [[ -f "$home_dir/.config/plasma-org.kde.plasma.desktop-appletsrc" ]]; then
  ok "Panel/Dock de KDE guardado en volumen persistente"
else
  warn "No se encontró la configuración habitual del panel KDE"
fi

echo "=== RESULTADO ==="
if (( failures > 0 )); then
  printf 'REVISAR: %d requisitos críticos sin cumplir. No se modificó nada.\n' "$failures"
  exit 1
fi
echo "OK: persistencia básica preparada. No se modificó ni reinició ningún servicio."
echo "Nota: detener/iniciar EC2 conserva datos EBS; terminar la instancia es diferente."
