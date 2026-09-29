#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/arcadecloud-drive"
RECONCILE_ONLY=0

for arg in "$@"; do
  case "$arg" in
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --reconcile) RECONCILE_ONLY=1 ;;
    /*) APP_ROOT="$arg" ;;
    *) echo "Argumento Workstation desconocido: $arg" >&2; exit 2 ;;
  esac
done

APP_ROOT="$(realpath "$APP_ROOT")"
DRIVE_ROOT="$APP_ROOT/drive"
IMAGE="arcadecloud/workstation:phase1"
CONTAINER="arcadecloud-workstation"
SERVICE_NAME="arcadecloud-workstation.service"
STATE_ROOT="/var/lib/arcadecloud-office"
WORKSPACE="$STATE_ROOT/phase1-workspace"
PERSISTENT_HOME="$STATE_ROOT/home/arcade"
ENV_FILE="/etc/arcadecloud-drive/workstation.env"
RDP_ENV_FILE="/etc/arcadecloud-drive/rdp.env"
OFFICE_NETWORK="arcadecloud-office"
SERVICE="/etc/systemd/system/$SERVICE_NAME"
OFFICE_UID=10001
OFFICE_GID=10001
PHP_GROUP="${ARCADECLOUD_PHP_GROUP:-apache}"
PHP_GID=""
WAS_ACTIVE=0

if systemctl is-active --quiet "$SERVICE_NAME" 2>/dev/null; then
  WAS_ACTIVE=1
fi

[[ "${EUID}" -eq 0 ]] || { echo "Ejecuta como root." >&2; exit 1; }
[[ -f /etc/os-release ]] || { echo "No se pudo detectar el sistema operativo." >&2; exit 1; }
source /etc/os-release
[[ "${ID:-}" == "amzn" && "${VERSION_ID:-}" == "2023" ]] || {
  echo "Fase 1 validada únicamente para Amazon Linux 2023; detectado: ${PRETTY_NAME:-desconocido}." >&2
  exit 2
}
[[ "$(uname -m)" == "x86_64" ]] || { echo "Se requiere x86_64." >&2; exit 2; }

VCPU="$(nproc)"
MEM_KB="$(awk '/MemTotal:/ {print $2}' /proc/meminfo)"
[[ "$VCPU" -ge 4 ]] || { echo "Se requieren al menos 4 vCPU para esta workstation." >&2; exit 2; }
[[ "$MEM_KB" -ge 7000000 ]] || { echo "Se requieren al menos 7 GB de RAM visibles." >&2; exit 2; }

if ! command -v docker >/dev/null 2>&1; then
  dnf install -y docker
fi
systemctl enable --now docker

PHP_GID="$(getent group "$PHP_GROUP" | cut -d: -f3)"
[[ "$PHP_GID" =~ ^[0-9]+$ ]] || {
  echo "No se pudo resolver el GID del grupo PHP-FPM: $PHP_GROUP" >&2
  exit 3
}

mkdir -p "$STATE_ROOT" "$WORKSPACE" "$WORKSPACE/sessions" "$PERSISTENT_HOME" "$PERSISTENT_HOME/Projects" "$PERSISTENT_HOME/Downloads"
chmod 0750 "$STATE_ROOT"
chown "$OFFICE_UID:$PHP_GID" "$WORKSPACE" "$WORKSPACE/sessions"
chmod 2770 "$WORKSPACE" "$WORKSPACE/sessions"
chown -R "$OFFICE_UID:$OFFICE_GID" "$PERSISTENT_HOME"
chmod 0700 "$PERSISTENT_HOME"

# ArcadeCloud ya administra /etc/arcadecloud-drive y su grupo PHP-FPM.
# Crear la ruta si falta, pero nunca cambiar propietario/modo del directorio
# ni de runtime-env.json desde el instalador de Workstation.
mkdir -p "$(dirname "$ENV_FILE")"

if [[ ! -f "$ENV_FILE" ]]; then
  umask 077
  cat > "$ENV_FILE" <<EOF
VNC_GEOMETRY=1600x900
VNC_DEPTH=24
EOF
fi
chown root:root "$ENV_FILE"
chmod 0600 "$ENV_FILE"

if [[ ! -f "$RDP_ENV_FILE" ]]; then
  umask 077
  RDP_PASSWORD="$(openssl rand -hex 24)"
  cat > "$RDP_ENV_FILE" <<EOF
ARCADECLOUD_RDP_PASSWORD=$RDP_PASSWORD
EOF
fi
chown root:root "$RDP_ENV_FILE"
chmod 0600 "$RDP_ENV_FILE"

docker network inspect "$OFFICE_NETWORK" >/dev/null 2>&1 \
  || docker network create "$OFFICE_NETWORK" >/dev/null

docker build -t "$IMAGE" "$DRIVE_ROOT/docker/workstation"

if [[ -x "$DRIVE_ROOT/bin/install_guacamole_node.sh" ]]; then
  bash "$DRIVE_ROOT/bin/install_guacamole_node.sh"
fi

cat > "$SERVICE" <<EOF
[Unit]
Description=ArcadeCloud Remote Workstation Phase 1
After=docker.service network-online.target
Requires=docker.service
Wants=network-online.target

[Service]
Type=simple
ExecStartPre=-/usr/bin/docker rm -f $CONTAINER
ExecStart=/usr/bin/docker run --rm --name $CONTAINER \
  --env-file $ENV_FILE \
  --env-file $RDP_ENV_FILE \
  --network $OFFICE_NETWORK \
  --publish 127.0.0.1:6080:6080 \
  --volume $WORKSPACE:/workspace \
  --volume $PERSISTENT_HOME:/home/arcade \
  --group-add $PHP_GID \
  --memory=5g --cpus=3 --shm-size=512m \
  --security-opt=no-new-privileges:true \
  --cap-drop=ALL \
  --cap-add=SETUID \
  --cap-add=SETGID \
  --cap-add=CHOWN \
  --cap-add=DAC_OVERRIDE \
  --cap-add=FOWNER \
  $IMAGE
ExecStop=/usr/bin/docker stop -t 20 $CONTAINER
Restart=on-failure
RestartSec=5
TimeoutStartSec=0
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload

# Fase 1: el servicio queda disponible pero NO habilitado al boot.
# El broker/Resource Mode Manager será quien lo inicie cuando exista una
# sesión Office. Así un arranque exclusivo para multimedia no consume GUI.
systemctl disable "$SERVICE_NAME" >/dev/null 2>&1 || true

if [[ "$RECONCILE_ONLY" -eq 1 ]]; then
  if [[ "$WAS_ACTIVE" -eq 1 ]]; then
    echo "✓ Workstation estaba activa: no se reinicia durante la actualización; la nueva imagen/unidad XRDP/audio se aplicará en la próxima sesión."
  else
    systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
    echo "✓ Workstation reconciliada y permanece apagada hasta que Office la solicite."
  fi
  echo "Workspace Office: $WORKSPACE"
  echo "Home persistente Linux: $PERSISTENT_HOME"
  echo "Sesiones documentales: $WORKSPACE/sessions (grupo $PHP_GROUP/$PHP_GID)"
  exit 0
fi

systemctl restart "$SERVICE_NAME"

echo "Esperando health local..."
for _ in {1..30}; do
  if php "$DRIVE_ROOT/bin/workstation_health.php" >/dev/null 2>&1; then
    php "$DRIVE_ROOT/bin/workstation_health.php"
    echo
    echo "Fase 1 instalada. noVNC sólo escucha en 127.0.0.1:6080; XRDP queda accesible únicamente por la red Docker Office."
    echo "Workspace Office: $WORKSPACE"
    echo "Home persistente Linux: $PERSISTENT_HOME"
    echo "Sesiones documentales: $WORKSPACE/sessions (grupo $PHP_GROUP/$PHP_GID)"
    echo "La autenticación del escritorio la controla ArcadeCloud; no se solicita contraseña VNC."
    echo "Workstation queda deshabilitada al boot; se inicia sólo bajo demanda."
    exit 0
  fi
  sleep 2
done

systemctl --no-pager --full status "$SERVICE_NAME" || true
echo "La workstation no alcanzó estado saludable." >&2
exit 1
