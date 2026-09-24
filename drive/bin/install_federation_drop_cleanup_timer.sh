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
  echo "ERROR: indica --run-user=USUARIO_PHP_FPM." >&2
  exit 2
fi
if ! id "$RUN_USER" >/dev/null 2>&1; then
  echo "ERROR: el usuario $RUN_USER no existe." >&2
  exit 2
fi

APP_ROOT="$(realpath "$APP_ROOT")"
if [[ ! -f "$APP_ROOT/drive/bin/federation_drop_cleanup.php" ]]; then
  echo "ERROR: no se encontró drive/bin/federation_drop_cleanup.php bajo $APP_ROOT." >&2
  exit 3
fi
if [[ ! -x "$PHP_BIN" ]]; then
  echo "ERROR: PHP no es ejecutable: $PHP_BIN" >&2
  exit 3
fi

SERVICE=/etc/systemd/system/arcadecloud-federation-drop-cleanup.service
TIMER=/etc/systemd/system/arcadecloud-federation-drop-cleanup.timer

cat > "$SERVICE" <<EOF
[Unit]
Description=ArcadeCloud FederationDrop expired object cleanup
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=$RUN_USER
WorkingDirectory=$APP_ROOT
EnvironmentFile=-$DRIVE_ENV
EnvironmentFile=-$FEDERATION_ENV
ExecStart=$PHP_BIN $APP_ROOT/drive/bin/federation_drop_cleanup.php 200
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=6
PrivateTmp=true
NoNewPrivileges=true
EOF

cat > "$TIMER" <<EOF
[Unit]
Description=ArcadeCloud FederationDrop cleanup timer

[Timer]
OnBootSec=10m
OnUnitInactiveSec=1h
RandomizedDelaySec=5m
Persistent=true
Unit=arcadecloud-federation-drop-cleanup.service

[Install]
WantedBy=timers.target
EOF

chmod 0644 "$SERVICE" "$TIMER"
systemctl daemon-reload
systemctl enable --now arcadecloud-federation-drop-cleanup.timer

echo "OK: limpieza FederationDrop instalada."
echo "Servicio: arcadecloud-federation-drop-cleanup.service"
echo "Timer: arcadecloud-federation-drop-cleanup.timer"
