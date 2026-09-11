#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 1
fi

RUN_USER=""
APP_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="/usr/bin/php"
INTERVAL_SEC=120

for arg in "$@"; do
  case "$arg" in
    --run-user=*) RUN_USER="${arg#*=}" ;;
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-bin=*) PHP_BIN="${arg#*=}" ;;
    --interval-sec=*) INTERVAL_SEC="${arg#*=}" ;;
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
if [[ ! "$INTERVAL_SEC" =~ ^[0-9]+$ ]] || (( INTERVAL_SEC < 60 || INTERVAL_SEC > 3600 )); then
  echo "ERROR: --interval-sec debe estar entre 60 y 3600." >&2
  exit 2
fi
APP_ROOT="$(realpath "$APP_ROOT")"
if [[ ! -f "$APP_ROOT/drive/bin/federation_sync.php" ]]; then
  echo "ERROR: no se encontró drive/bin/federation_sync.php bajo $APP_ROOT." >&2
  exit 3
fi
if [[ ! -x "$PHP_BIN" ]]; then
  echo "ERROR: PHP no es ejecutable: $PHP_BIN" >&2
  exit 3
fi

SERVICE=/etc/systemd/system/arcadecloud-federation-sync.service
TIMER=/etc/systemd/system/arcadecloud-federation-sync.timer

cat > "$SERVICE" <<EOF
[Unit]
Description=ArcadeCloud FederationCloud gossip sync
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=$RUN_USER
WorkingDirectory=$APP_ROOT
ExecStart=$PHP_BIN $APP_ROOT/drive/bin/federation_sync.php
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=6
PrivateTmp=true
NoNewPrivileges=true
EOF

cat > "$TIMER" <<EOF
[Unit]
Description=ArcadeCloud FederationCloud gossip sync timer

[Timer]
OnBootSec=90s
OnUnitActiveSec=${INTERVAL_SEC}s
RandomizedDelaySec=30s
AccuracySec=15s
Persistent=true
Unit=arcadecloud-federation-sync.service

[Install]
WantedBy=timers.target
EOF

chmod 0644 "$SERVICE" "$TIMER"
systemctl daemon-reload
systemctl enable --now arcadecloud-federation-sync.timer

echo "OK: sincronización FederationCloud instalada."
echo "Usuario: $RUN_USER"
echo "Intervalo base: ${INTERVAL_SEC}s + jitter de hasta 30s"
echo "Servicio: arcadecloud-federation-sync.service"
echo "Timer: arcadecloud-federation-sync.timer"
