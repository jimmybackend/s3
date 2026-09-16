#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 1
fi

RUN_USER=""
RUN_USER_SOURCE=""
APP_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="/usr/bin/php"
INTERVAL_SEC=60
DRIVE_ENV="/etc/arcadecloud-drive/drive.env"
LIMIT=250

for arg in "$@"; do
  case "$arg" in
    --run-user=*) RUN_USER="${arg#*=}"; RUN_USER_SOURCE="argumento --run-user" ;;
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-bin=*) PHP_BIN="${arg#*=}" ;;
    --interval-sec=*) INTERVAL_SEC="${arg#*=}" ;;
    --drive-env=*) DRIVE_ENV="${arg#*=}" ;;
    --limit=*) LIMIT="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

if [[ -z "$RUN_USER" ]] && systemctl is-active --quiet php-fpm-drive.service; then
  MASTER_PID="$(systemctl show -p MainPID --value php-fpm-drive.service 2>/dev/null || true)"
  if [[ "$MASTER_PID" =~ ^[0-9]+$ ]] && (( MASTER_PID > 1 )); then
    CHILD_PID="$(pgrep -P "$MASTER_PID" 2>/dev/null | head -n 1 || true)"
    if [[ "$CHILD_PID" =~ ^[0-9]+$ ]]; then
      RUN_USER="$(ps -o user= -p "$CHILD_PID" 2>/dev/null | xargs || true)"
      if [[ -n "$RUN_USER" ]]; then
        RUN_USER_SOURCE="worker hijo de php-fpm-drive.service"
      fi
    fi
  fi
fi

if [[ -z "$RUN_USER" ]]; then
  EXEC_START="$(systemctl show -p ExecStart --value php-fpm-drive.service 2>/dev/null || true)"
  FPM_CONF=""
  FPM_DAEMON=""

  if [[ "$EXEC_START" =~ [[:space:]]-y[[:space:]]+([^[:space:];}\}]+) ]]; then
    FPM_CONF="${BASH_REMATCH[1]}"
  fi
  if [[ "$EXEC_START" =~ path=([^[:space:];}\}]+) ]]; then
    FPM_DAEMON="${BASH_REMATCH[1]}"
  fi

  if [[ -z "$FPM_CONF" && -f /etc/php-fpm-drive.conf ]]; then
    FPM_CONF="/etc/php-fpm-drive.conf"
  fi
  if [[ -z "$FPM_DAEMON" || ! -x "$FPM_DAEMON" ]]; then
    if [[ -x /usr/sbin/php-fpm ]]; then
      FPM_DAEMON="/usr/sbin/php-fpm"
    elif command -v php-fpm >/dev/null 2>&1; then
      FPM_DAEMON="$(command -v php-fpm)"
    fi
  fi

  if [[ -n "$FPM_CONF" && -f "$FPM_CONF" && -n "$FPM_DAEMON" && -x "$FPM_DAEMON" ]]; then
    FPM_TEST_OUTPUT="$($FPM_DAEMON -tt -y "$FPM_CONF" 2>&1 || true)"
    RUN_USER="$(printf '%s\n' "$FPM_TEST_OUTPUT" | awk '
      match($0, /(^|[[:space:]])user[[:space:]]*=[[:space:]]*[^[:space:];]+/) {
        value = substr($0, RSTART, RLENGTH)
        sub(/^.*user[[:space:]]*=[[:space:]]*/, "", value)
        sub(/[[:space:];].*$/, "", value)
        print value
        exit
      }
    ')"
    if [[ -n "$RUN_USER" ]]; then
      RUN_USER_SOURCE="configuración PHP-FPM $FPM_CONF"
    fi
  fi
fi

if [[ -z "$RUN_USER" ]]; then
  echo "ERROR: no pude determinar de forma segura el usuario del pool php-fpm-drive." >&2
  exit 2
fi
if [[ "$RUN_USER" == "root" ]]; then
  echo "ERROR: el worker Polly no debe ejecutarse como root." >&2
  exit 2
fi
if ! id "$RUN_USER" >/dev/null 2>&1; then
  echo "ERROR: el usuario detectado '$RUN_USER' no existe." >&2
  exit 2
fi
if [[ ! "$INTERVAL_SEC" =~ ^[0-9]+$ ]] || (( INTERVAL_SEC < 60 || INTERVAL_SEC > 3600 )); then
  echo "ERROR: --interval-sec debe estar entre 60 y 3600." >&2
  exit 2
fi
if [[ ! "$LIMIT" =~ ^[0-9]+$ ]] || (( LIMIT < 1 || LIMIT > 1000 )); then
  echo "ERROR: --limit debe estar entre 1 y 1000." >&2
  exit 2
fi

APP_ROOT="$(realpath "$APP_ROOT")"
if [[ ! -f "$APP_ROOT/drive/bin/polly_reconcile.php" ]]; then
  echo "ERROR: no se encontró drive/bin/polly_reconcile.php bajo $APP_ROOT." >&2
  exit 3
fi
if [[ ! -x "$PHP_BIN" ]]; then
  echo "ERROR: PHP no es ejecutable: $PHP_BIN" >&2
  exit 3
fi
if [[ "$DRIVE_ENV" != /* ]]; then
  echo "ERROR: --drive-env debe ser una ruta absoluta." >&2
  exit 3
fi

SERVICE=/etc/systemd/system/arcadecloud-polly-reconcile.service
TIMER=/etc/systemd/system/arcadecloud-polly-reconcile.timer

cat > "$SERVICE" <<EOF
[Unit]
Description=ArcadeCloud Amazon Polly server reconciliation
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=$RUN_USER
WorkingDirectory=$APP_ROOT
EnvironmentFile=-$DRIVE_ENV
ExecStart=$PHP_BIN $APP_ROOT/drive/bin/polly_reconcile.php --limit=$LIMIT
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=6
PrivateTmp=true
NoNewPrivileges=true
EOF

cat > "$TIMER" <<EOF
[Unit]
Description=ArcadeCloud Amazon Polly reconciliation timer

[Timer]
OnActiveSec=20s
OnUnitInactiveSec=${INTERVAL_SEC}s
RandomizedDelaySec=10s
AccuracySec=5s
Persistent=true
Unit=arcadecloud-polly-reconcile.service

[Install]
WantedBy=timers.target
EOF

chmod 0644 "$SERVICE" "$TIMER"
systemctl daemon-reload
systemctl reset-failed arcadecloud-polly-reconcile.service >/dev/null 2>&1 || true

if ! systemctl start arcadecloud-polly-reconcile.service; then
  echo "ERROR: la primera reconciliación Polly falló por infraestructura; el timer NO se habilitó." >&2
  systemctl status arcadecloud-polly-reconcile.service --no-pager >&2 || true
  journalctl -u arcadecloud-polly-reconcile.service -n 80 --no-pager >&2 || true
  exit 4
fi

systemctl enable --now arcadecloud-polly-reconcile.timer

echo "OK: reconciliación automática de Amazon Polly instalada."
echo "Usuario Drive detectado: $RUN_USER"
echo "Fuente de detección: $RUN_USER_SOURCE"
echo "EnvironmentFile: $DRIVE_ENV"
echo "Intervalo: ${INTERVAL_SEC}s después de cada ejecución"
echo "Límite de tareas pendientes por ciclo: $LIMIT"
echo "Servicio: arcadecloud-polly-reconcile.service"
echo "Timer: arcadecloud-polly-reconcile.timer"
