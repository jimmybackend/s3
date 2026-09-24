#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta la reconciliación como root." >&2
  exit 1
fi

APP_ROOT="/var/www/arcadecloud-drive"
PHP_USER=""
RUNTIME_ENV="/etc/arcadecloud-drive/runtime-env.json"

for arg in "$@"; do
  case "$arg" in
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --runtime-env=*) RUNTIME_ENV="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

APP_ROOT="$(realpath "$APP_ROOT")"
DRIVE_ROOT="$APP_ROOT/drive"

[[ -d "$APP_ROOT/.git" ]] || { echo "ERROR: $APP_ROOT no es un checkout ArcadeCloud." >&2; exit 2; }
[[ -f "$RUNTIME_ENV" ]] || { echo "ERROR: falta runtime administrado: $RUNTIME_ENV" >&2; exit 2; }

runtime_value() {
  local key="$1"
  python3 - "$RUNTIME_ENV" "$key" <<'PY' 2>/dev/null || true
import json,sys
path,key=sys.argv[1:]
try:
    data=json.load(open(path, encoding="utf-8"))
    value=data.get(key,"")
    if isinstance(value,str):
        print(value)
except Exception:
    pass
PY
}

if [[ -z "$PHP_USER" ]]; then
  PHP_USER="$(ps -eo user=,comm= 2>/dev/null | awk '$2 == "php-fpm" && $1 != "root" { print $1; exit }')"
fi
[[ -n "$PHP_USER" ]] || { echo "ERROR: no pude detectar el usuario PHP-FPM; usa --php-user." >&2; exit 2; }
[[ "$PHP_USER" != "root" ]] || { echo "ERROR: PHP-FPM no debe ejecutar ArcadeCloud como root." >&2; exit 2; }
id "$PHP_USER" >/dev/null 2>&1 || { echo "ERROR: usuario inexistente: $PHP_USER" >&2; exit 2; }

ROLE="$(runtime_value ARCADECLOUD_NODE_ROLE)"
if [[ -z "$ROLE" ]]; then
  if systemctl cat arcadecloud-media-worker.service >/dev/null 2>&1; then
    ROLE="media-worker"
  else
    ROLE="web"
  fi
fi
case "$ROLE" in
  web|media-worker|combined) ;;
  *) echo "ERROR: rol de nodo inválido: $ROLE" >&2; exit 2 ;;
esac

echo "==> Reconciliando ArcadeCloud"
echo "App root: $APP_ROOT"
echo "Rol: $ROLE"
echo "Usuario runtime: $PHP_USER"

# Helpers privilegiados: siempre se refrescan después de un git update.
bash "$DRIVE_ROOT/bin/install_arcadecloud_admin_helper.sh" --php-user="$PHP_USER" --app-root="$APP_ROOT"
bash "$DRIVE_ROOT/bin/install_arcadecloud_updater.sh" --php-user="$PHP_USER" --repo-root="$APP_ROOT"

if [[ "$ROLE" == "web" || "$ROLE" == "combined" ]]; then
  bash "$DRIVE_ROOT/bin/install_transcribe_reconcile_timer.sh"     --run-user="$PHP_USER" --app-root="$APP_ROOT"
  bash "$DRIVE_ROOT/bin/install_polly_reconcile_timer.sh"     --run-user="$PHP_USER" --app-root="$APP_ROOT"
fi

FED_ENABLED="$(runtime_value ARCADECLOUD_FEDERATION_ENABLED)"
case "${FED_ENABLED,,}" in
  1|true|yes|on)
    bash "$DRIVE_ROOT/bin/install_federation_sync_timer.sh"       --run-user="$PHP_USER" --app-root="$APP_ROOT" --interval-sec=120
    bash "$DRIVE_ROOT/bin/install_federation_drop_cleanup_timer.sh"       --run-user="$PHP_USER" --app-root="$APP_ROOT"
    ;;
esac

if [[ "$ROLE" == "media-worker" || "$ROLE" == "combined" ]]; then
  if ! command -v ffmpeg >/dev/null 2>&1 || ! command -v ffprobe >/dev/null 2>&1; then
    echo "ERROR: el rol $ROLE requiere ffmpeg y ffprobe antes de activar el worker." >&2
    exit 3
  fi
  bash "$DRIVE_ROOT/bin/install_media_processing_worker.sh"     --app-root="$APP_ROOT" --run-user="$PHP_USER"
else
  for unit in arcadecloud-media-worker.service arcadecloud-media-node-bootstrap.service; do
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
    path="/etc/systemd/system/$unit"
    if [[ -f "$path" ]] && grep -Fqi 'ArcadeCloud' "$path"; then
      rm -f "$path"
    fi
  done
  rm -rf /var/lib/arcadecloud-media
fi

# Reinstala node-sync sólo si ya estaba administrado; no arranca una sincronización masiva nueva.
if systemctl cat arcadecloud-drive-node-sync.service >/dev/null 2>&1; then
  bash "$DRIVE_ROOT/bin/install_node_sync_service.sh"     --run-user="$PHP_USER" --app-root="$APP_ROOT"
fi

systemctl daemon-reload
systemctl reset-failed >/dev/null 2>&1 || true

if systemctl is-active --quiet php-fpm-drive.service; then
  systemctl restart php-fpm-drive.service
fi

if command -v nginx >/dev/null 2>&1 && nginx -t >/dev/null 2>&1; then
  systemctl is-active --quiet nginx.service && systemctl reload nginx.service || true
fi

echo "✓ Reconciliación de servicios completada para rol $ROLE."
