#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 1
fi

RUN_USER=""
APP_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
WEBROOT=""
BACKEND_HOST="localhost"
RUNTIME_ENV="/etc/arcadecloud-drive/runtime-env.json"
DRIVE_ENV="/etc/arcadecloud-drive/drive.env"
FEDERATION_ENV="/etc/arcadecloud-drive/federation.env"
STATE_PATH="/var/lib/arcadecloud-drive/federation-https-state.json"
NGINX_IP_CONFIG="/etc/nginx/conf.d/arcadecloud-federation-ip.conf"
PHP_BIN="$(command -v php || true)"
CERTBOT_BIN="$(command -v certbot || true)"
NGINX_BIN="$(command -v nginx || true)"
SYSTEMCTL_BIN="$(command -v systemctl || true)"
CURL_BIN="$(command -v curl || true)"
RUNUSER_BIN="$(command -v runuser || true)"
INTERVAL_HOURS=12

for arg in "$@"; do
  case "$arg" in
    --run-user=*) RUN_USER="${arg#*=}" ;;
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --webroot=*) WEBROOT="${arg#*=}" ;;
    --backend-host=*) BACKEND_HOST="${arg#*=}" ;;
    --runtime-env=*) RUNTIME_ENV="${arg#*=}" ;;
    --drive-env=*) DRIVE_ENV="${arg#*=}" ;;
    --federation-env=*) FEDERATION_ENV="${arg#*=}" ;;
    --state-path=*) STATE_PATH="${arg#*=}" ;;
    --nginx-ip-config=*) NGINX_IP_CONFIG="${arg#*=}" ;;
    --php-bin=*) PHP_BIN="${arg#*=}" ;;
    --certbot-bin=*) CERTBOT_BIN="${arg#*=}" ;;
    --nginx-bin=*) NGINX_BIN="${arg#*=}" ;;
    --interval-hours=*) INTERVAL_HOURS="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

if [[ -z "$RUN_USER" ]]; then
  echo "ERROR: indica --run-user=USUARIO_REAL_PHP_FPM (por ejemplo apache o nginx, según tu pool)." >&2
  exit 2
fi
if ! id "$RUN_USER" >/dev/null 2>&1; then
  echo "ERROR: el usuario $RUN_USER no existe." >&2
  exit 2
fi
RUN_GROUP="$(id -gn "$RUN_USER")"

APP_ROOT="$(realpath "$APP_ROOT")"
if [[ -z "$WEBROOT" ]]; then WEBROOT="$APP_ROOT/drive"; fi
WEBROOT="$(realpath "$WEBROOT")"

if [[ ! -f "$APP_ROOT/drive/bin/federation_https_reconcile.php" ]]; then
  echo "ERROR: no se encontró federation_https_reconcile.php bajo $APP_ROOT." >&2
  exit 3
fi
if [[ ! -f "$APP_ROOT/drive/bin/federation_endpoint_refresh.php" ]]; then
  echo "ERROR: no se encontró federation_endpoint_refresh.php bajo $APP_ROOT." >&2
  exit 3
fi
if [[ ! -d "$WEBROOT" ]]; then
  echo "ERROR: webroot no existe: $WEBROOT" >&2
  exit 3
fi
if [[ ! "$BACKEND_HOST" =~ ^[A-Za-z0-9._-]+$ ]]; then
  echo "ERROR: --backend-host contiene caracteres inválidos." >&2
  exit 2
fi
if [[ ! "$INTERVAL_HOURS" =~ ^[0-9]+$ ]] || (( INTERVAL_HOURS < 1 || INTERVAL_HOURS > 72 )); then
  echo "ERROR: --interval-hours debe estar entre 1 y 72." >&2
  exit 2
fi

for pair in \
  "PHP:$PHP_BIN" \
  "Certbot:$CERTBOT_BIN" \
  "Nginx:$NGINX_BIN" \
  "systemctl:$SYSTEMCTL_BIN" \
  "curl:$CURL_BIN" \
  "runuser:$RUNUSER_BIN"; do
  name="${pair%%:*}"
  path="${pair#*:}"
  if [[ -z "$path" || ! -x "$path" ]]; then
    echo "ERROR: falta $name ejecutable. Instálalo o indica su ruta explícitamente." >&2
    exit 3
  fi
done

for path_value in "$RUNTIME_ENV" "$DRIVE_ENV" "$FEDERATION_ENV" "$STATE_PATH" "$NGINX_IP_CONFIG" "$APP_ROOT" "$WEBROOT" "$PHP_BIN" "$CERTBOT_BIN" "$NGINX_BIN"; do
  case "$path_value" in
    /*) ;;
    *) echo "ERROR: las rutas deben ser absolutas: $path_value" >&2; exit 2 ;;
  esac
done

if ! ps -eo user=,comm= | awk -v u="$RUN_USER" '$1 == u && $2 == "php-fpm" { found=1 } END { exit(found ? 0 : 1) }'; then
  echo "ADVERTENCIA: no se detectó un worker php-fpm ejecutándose como $RUN_USER." >&2
  echo "Verifica el usuario real del pool con: ps -eo user=,comm=,args= | grep '[p]hp-fpm'" >&2
fi

RUNTIME_DIR="$(dirname "$RUNTIME_ENV")"
if [[ ! -d "$RUNTIME_DIR" ]]; then
  install -d -o root -g "$RUN_GROUP" -m 0750 "$RUNTIME_DIR"
fi
if [[ ! -e "$RUNTIME_ENV" ]]; then
  install -o root -g "$RUN_GROUP" -m 0640 /dev/null "$RUNTIME_ENV"
  printf '{}\n' > "$RUNTIME_ENV"
else
  if ! "$RUNUSER_BIN" -u "$RUN_USER" -- test -r "$RUNTIME_ENV"; then
    echo "ERROR: $RUN_USER no puede leer $RUNTIME_ENV." >&2
    echo "No se cambió propietario/grupo automáticamente para no romper el pool PHP-FPM existente." >&2
    exit 4
  fi
fi
install -d -o root -g root -m 0755 "$(dirname "$STATE_PATH")"

SERVICE=/etc/systemd/system/arcadecloud-federation-https.service
TIMER=/etc/systemd/system/arcadecloud-federation-https.timer

cat > "$SERVICE" <<EOF
[Unit]
Description=ArcadeCloud FederationCloud HTTPS endpoint reconciliation
After=network-online.target nginx.service
Wants=network-online.target
Requires=nginx.service

[Service]
Type=oneshot
WorkingDirectory=$APP_ROOT
EnvironmentFile=-$DRIVE_ENV
EnvironmentFile=-$FEDERATION_ENV
ExecStart=$PHP_BIN $APP_ROOT/drive/bin/federation_https_reconcile.php --runtime-env=$RUNTIME_ENV --webroot=$WEBROOT --nginx-ip-config=$NGINX_IP_CONFIG --state-path=$STATE_PATH --backend-host=$BACKEND_HOST --run-user=$RUN_USER --app-root=$APP_ROOT --php-bin=$PHP_BIN --certbot-bin=$CERTBOT_BIN --nginx-bin=$NGINX_BIN --systemctl-bin=$SYSTEMCTL_BIN --curl-bin=$CURL_BIN --runuser-bin=$RUNUSER_BIN
TimeoutStartSec=300
UMask=0027
EOF

cat > "$TIMER" <<EOF
[Unit]
Description=ArcadeCloud FederationCloud HTTPS renewal and endpoint timer

[Timer]
OnActiveSec=45s
OnUnitInactiveSec=${INTERVAL_HOURS}h
RandomizedDelaySec=10m
AccuracySec=1m
Persistent=true
Unit=arcadecloud-federation-https.service

[Install]
WantedBy=timers.target
EOF

chmod 0644 "$SERVICE" "$TIMER"
systemctl daemon-reload

# Validaciones estáticas. No se solicita certificado ni se habilita el timer todavía.
"$PHP_BIN" -l "$APP_ROOT/drive/bin/federation_https_reconcile.php" >/dev/null
"$PHP_BIN" -l "$APP_ROOT/drive/bin/federation_endpoint_refresh.php" >/dev/null
"$NGINX_BIN" -t

echo "OK: reconciliador HTTPS FederationCloud instalado, aún no activado."
echo "Servicio: arcadecloud-federation-https.service"
echo "Timer: arcadecloud-federation-https.timer"
echo "Frecuencia al activarlo: primera reconciliación 45s después de activar el timer y luego cada ${INTERVAL_HOURS}h después de completar el servicio."
echo "Usuario de runtime FederationCloud: $RUN_USER"
echo "Runtime administrado: $RUNTIME_ENV"
echo "EnvironmentFile Drive: $DRIVE_ENV"
echo "EnvironmentFile FederationCloud: $FEDERATION_ENV"
echo "Webroot ACME: $WEBROOT"
echo "Backend HTTP local para modo IP: 127.0.0.1:80 (Host: $BACKEND_HOST)"
echo
echo "IMPORTANTE: el instalador NO ejecutó Certbot ni habilitó el timer."
echo "1) Primera prueba: sudo systemctl start arcadecloud-federation-https.service"
echo "2) Revisar: sudo systemctl status arcadecloud-federation-https.service --no-pager"
echo "3) Si todo está bien: sudo systemctl enable --now arcadecloud-federation-https.timer"
