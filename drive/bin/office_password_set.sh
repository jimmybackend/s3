#!/usr/bin/env bash
# ArcadeCloud Office: un solo origen para la contraseña de KDE/XFCE y RDP.
# No imprime, registra ni recibe contraseñas como argumento.
# Compatible con Workstation apagada y arranque bajo demanda.
set -euo pipefail
umask 077

RDP_ENV=/etc/arcadecloud-drive/rdp.env
GUAC_INSTALLER=/var/www/arcadecloud-drive/drive/bin/install_guacamole_node.sh
CONTAINER=arcadecloud-workstation

if [[ "$EUID" -ne 0 ]]; then
  echo "Ejecuta con sudo: sudo bash drive/bin/office_password_set.sh"
  exit 2
fi
if [[ ! -c /dev/tty ]]; then
  echo "ERROR: Es necesario un terminal interactivo para introducir la contraseña."
  exit 2
fi
if [[ ! -f "$GUAC_INSTALLER" ]]; then
  echo "ERROR: No existe el instalador de Guacamole de FastDrive."
  exit 2
fi
if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: Docker no está instalado."
  exit 2
fi

echo "=== CONTRASEÑA ÚNICA DE ARCADECLOUD OFFICE ==="
echo "Usuario Linux (KDE/XFCE/XRDP): arcade"
echo "Se guardará para los próximos reinicios de Workstation y EC2."
echo "No aparecerá en pantalla ni se añadirá al historial del terminal."
echo "Nota: la cuenta administrativa de Guacamole es independiente."

read -r -s -p "Nueva contraseña (12-64 caracteres): " password </dev/tty
printf '\n'
read -r -s -p "Confirma la contraseña: " confirmation </dev/tty
printf '\n'

if [[ "$password" != "$confirmation" ]]; then
  echo "ERROR: Las contraseñas no coinciden. No se hizo ningún cambio."
  exit 1
fi
if [[ ! "$password" =~ ^[A-Za-z0-9._@!#%-]{12,64}$ ]]; then
  echo "ERROR: Usa entre 12 y 64 caracteres, sólo letras, números y . _ @ ! # % -"
  echo "No se hizo ningún cambio. Este conjunto evita errores al sincronizar Guacamole."
  exit 1
fi

# Guardar de forma atómica, respetando cualquier otra línea de configuración.
# No conservar copias con contraseñas anteriores que causen confusión.
dir="$(dirname "$RDP_ENV")"
mkdir -p "$dir"
temp="$(mktemp "$dir/.rdp.env.XXXXXXXX")"
cleanup() { rm -f "$temp"; }
trap cleanup EXIT

if [[ -f "$RDP_ENV" ]]; then
  grep -v '^ARCADECLOUD_RDP_PASSWORD=' "$RDP_ENV" > "$temp" || true
fi
printf 'ARCADECLOUD_RDP_PASSWORD=%s\n' "$password" >> "$temp"
chown root:root "$temp"
chmod 0600 "$temp"

# Al próximo arranque se asignará este mismo valor por chpasswd.
mv -f "$temp" "$RDP_ENV"
echo "OK: contraseña persistente actualizada (valor oculto)."

# No iniciar Workstation sólo para cambiar contraseña. Si está funcionando,
# aplicar inmediatamente al usuario arcade, incluso si KDE está bloqueado.
if docker ps --format '{{.Names}}' | grep -Fxq "$CONTAINER"; then
  if printf 'arcade:%s\n' "$password" | docker exec -i --user root "$CONTAINER" chpasswd; then
    echo "OK: contraseña de Linux arcade actualizada en la sesión activa."
    docker exec "$CONTAINER" passwd -S arcade | awk '{print "Estado de cuenta: "$1" "$2}'
  else
    echo "ERROR: se guardó la nueva contraseña, pero falló su aplicación a Linux."
    echo "En el próximo reinicio del contenedor se aplicará desde rdp.env."
    exit 1
  fi
else
  echo "OK: Workstation apagada; se aplicará automáticamente en el próximo inicio."
fi

# Sincronizar el valor RDP en la base de datos interna Guacamole.
# El instalador es idempotente: conserva su cuenta administrativa existente.
if bash "$GUAC_INSTALLER"; then
  echo "OK: contraseña RDP de Guacamole sincronizada."
else
  echo "ERROR: Linux/rdp.env ya están actualizados; falló la sincronización de Guacamole."
  echo "Reejecuta sudo bash drive/bin/install_guacamole_node.sh después de revisar el error."
  exit 1
fi

unset password confirmation
echo "=== COMPLETADO ==="
echo "La nueva contraseña es la que acabas de introducir."
echo "KDE, XFCE y XRDP comparten el usuario arcade; la pantalla de bloqueo"
echo "puede necesitar el teclado correcto si la clave contiene símbolos."
echo "La contraseña administrativa del portal Guacamole no se ha modificado."
