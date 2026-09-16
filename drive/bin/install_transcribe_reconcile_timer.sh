#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 1
fi

RUN_USER=""
APP_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="/usr/bin/php"
INTERVAL_SEC=60
DRIVE_ENV="/etc/arcadecloud-drive/drive.env"
LIMIT=250

for arg in "$@"; do
  case "$arg" in
    --run-user=*) RUN_USER="${arg#*=}" ;;
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-bin=*) PHP_BIN="${arg#*=}" ;;
    --interval-sec=*) INTERVAL_SEC="${arg#*=}" ;;
    --drive-env=*) DRIVE_ENV="${arg#*=}" ;;
    --limit=*) LIMIT="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

if [[ -z "$RUN_USER" ]]; then
  echo "ERROR: indica --run-user=USUARIO_PHP_FPM (por ejemplo apache o nginx según tu pool)." >&2
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
if [[ ! "$LIMIT" =~ ^[0-9]+$ ]] || (( LIMIT < 1 || LIMIT > 1000 )); then
  echo "ERROR: --limit debe estar entre 1 y 1000." >&2
  exit 2
fi

APP_ROOT="$(realpath "$APP_ROOT")"
if [[ ! -f "$APP_ROOT/drive/bin/transcribe_reconcile.php" ]]; then
  echo "ERROR: no se encontró drive/bin/transcribe_reconcile.php bajo $APP_ROOT." >&2
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

SERVICE=/etc/systemd/system/arcadecloud-transcribe-reconcile.service
TIMER=/etc/systemd/system/arcadecloud-transcribe-reconcile.timer

cat > "$SERVICE" <<EOF
[Unit]
Description=ArcadeCloud Amazon Transcribe server reconciliation
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=$RUN_USER
WorkingDirectory=$APP_ROOT
EnvironmentFile=-$DRIVE_ENV
ExecStart=$PHP_BIN $APP_ROOT/drive/bin/transcribe_reconcile.php --limit=$LIMIT
Nice=10
IOSchedulingClass=best-effort
IOSchedulingPriority=6
PrivateTmp=true
NoNewPrivileges=true
EOF

cat > "$TIMER" <<EOF
[Unit]
Description=ArcadeCloud Amazon Transcribe reconciliation timer

[Timer]
OnActiveSec=20s
OnUnitInactiveSec=${INTERVAL_SEC}s
RandomizedDelaySec=10s
AccuracySec=5s
Persistent=true
Unit=arcadecloud-transcribe-reconcile.service

[Install]
WantedBy=timers.target
EOF

chmod 0644 "$SERVICE" "$TIMER"
systemctl daemon-reload

# Ejecuta una reconciliación inmediata. Si no puede cargar DB/AWS, no dejamos
# habilitado un timer roto.
systemctl reset-failed arcadecloud-transcribe-reconcile.service >/dev/null 2>&1 || true
if ! systemctl start arcadecloud-transcribe-reconcile.service; then
  echo "ERROR: la primera reconciliación Transcribe falló; el timer NO se habilitó." >&2
  systemctl status arcadecloud-transcribe-reconcile.service --no-pager >&2 || true
  journalctl -u arcadecloud-transcribe-reconcile.service -n 80 --no-pager >&2 || true
  exit 4
fi

systemctl enable --now arcadecloud-transcribe-reconcile.timer

echo "OK: reconciliación automática de Amazon Transcribe instalada."
echo "Usuario: $RUN_USER"
echo "EnvironmentFile: $DRIVE_ENV"
echo "Intervalo: ${INTERVAL_SEC}s después de cada ejecución"
echo "Límite de eventos pendientes por ciclo: $LIMIT"
echo "Servicio: arcadecloud-transcribe-reconcile.service"
echo "Timer: arcadecloud-transcribe-reconcile.timer"
