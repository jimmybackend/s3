#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 1
fi

RUN_USER=""
APP_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="/usr/bin/php"
DRIVE_ENV="/etc/arcadecloud-drive/drive.env"
FEDERATION_ENV="/etc/arcadecloud-drive/federation.env"

for arg in "$@"; do
  case "$arg" in
    --run-user=*) RUN_USER="${arg#*=}" ;;
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-bin=*) PHP_BIN="${arg#*=}" ;;
    --drive-env=*) DRIVE_ENV="${arg#*=}" ;;
    --federation-env=*) FEDERATION_ENV="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

if [[ -z "$RUN_USER" ]]; then
  echo "ERROR: indica --run-user=USUARIO_PHP_FPM (por ejemplo nginx)." >&2
  exit 2
fi
if ! id "$RUN_USER" >/dev/null 2>&1; then
  echo "ERROR: el usuario $RUN_USER no existe." >&2
  exit 2
fi
if [[ ! -x "$PHP_BIN" ]]; then
  echo "ERROR: PHP CLI no está disponible en $PHP_BIN" >&2
  exit 3
fi

APP_ROOT="$(realpath "$APP_ROOT")"
WORKER="$APP_ROOT/drive/bin/sync_node_worker.php"
MIGRATOR="$APP_ROOT/drive/bin/sync_schema_migrate.php"
for required in "$WORKER" "$MIGRATOR"; do
  if [[ ! -f "$required" ]]; then
    echo "ERROR: no se encontró $required" >&2
    exit 3
  fi
done

for env_path in "$DRIVE_ENV" "$FEDERATION_ENV"; do
  if [[ "$env_path" != /* ]]; then
    echo "ERROR: las rutas EnvironmentFile deben ser absolutas: $env_path" >&2
    exit 3
  fi
done

MIGRATION_SERVICE=/etc/systemd/system/arcadecloud-drive-sync-migrate.service
SERVICE=/etc/systemd/system/arcadecloud-drive-node-sync.service

cat > "$MIGRATION_SERVICE" <<EOF
[Unit]
Description=ArcadeCloud Drive FileS3 sync schema migration
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=$RUN_USER
WorkingDirectory=$APP_ROOT
EnvironmentFile=-$DRIVE_ENV
EnvironmentFile=-$FEDERATION_ENV
ExecStart=$PHP_BIN $MIGRATOR
PrivateTmp=true
NoNewPrivileges=true
EOF

cat > "$SERVICE" <<EOF
[Unit]
Description=ArcadeCloud Drive whole-node S3 to MySQL synchronization
After=network-online.target arcadecloud-drive-sync-migrate.service
Wants=network-online.target
Requires=arcadecloud-drive-sync-migrate.service

[Service]
Type=simple
User=$RUN_USER
WorkingDirectory=$APP_ROOT
EnvironmentFile=-$DRIVE_ENV
EnvironmentFile=-$FEDERATION_ENV
ExecStart=$PHP_BIN $WORKER
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=6
PrivateTmp=true
NoNewPrivileges=true
TimeoutStopSec=30
EOF

chmod 0644 "$MIGRATION_SERVICE" "$SERVICE"
systemctl daemon-reload
systemctl reset-failed arcadecloud-drive-sync-migrate.service >/dev/null 2>&1 || true

echo "=== Migrando identidad FileS3 ==="
if ! systemctl start arcadecloud-drive-sync-migrate.service; then
  systemctl status arcadecloud-drive-sync-migrate.service --no-pager -l || true
  journalctl -u arcadecloud-drive-sync-migrate.service -n 50 --no-pager || true
  echo "ERROR: la migración FileS3 falló; node-sync no quedó listo." >&2
  exit 4
fi

echo "OK: migración FileS3 completada."
echo "OK: servicio de sincronización completa del nodo instalado."
echo "Usuario: $RUN_USER"
echo "Migración: arcadecloud-drive-sync-migrate.service"
echo "Servicio: arcadecloud-drive-node-sync.service"
echo
echo "Iniciar node-sync en segundo plano:"
echo "  sudo systemctl start --no-block arcadecloud-drive-node-sync.service"
echo "Estado:"
echo "  sudo systemctl status arcadecloud-drive-node-sync.service --no-pager -l"
echo "Log:"
echo "  sudo journalctl -u arcadecloud-drive-node-sync.service -n 100 --no-pager"
