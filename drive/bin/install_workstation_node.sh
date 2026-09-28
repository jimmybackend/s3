#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${1:-/var/www/arcadecloud-drive}"
APP_ROOT="$(realpath "$APP_ROOT")"
DRIVE_ROOT="$APP_ROOT/drive"
IMAGE="arcadecloud/workstation:phase1"
CONTAINER="arcadecloud-workstation"
STATE_ROOT="/var/lib/arcadecloud-office"
WORKSPACE="$STATE_ROOT/phase1-workspace"
ENV_FILE="/etc/arcadecloud-drive/workstation.env"
SERVICE="/etc/systemd/system/arcadecloud-workstation.service"

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

mkdir -p "$STATE_ROOT" "$WORKSPACE" "$(dirname "$ENV_FILE")"
chmod 0750 "$STATE_ROOT"\nchown 1000:1000 "$WORKSPACE"\nchmod 0750 "$WORKSPACE"

if [[ ! -f "$ENV_FILE" ]]; then
  umask 077
  PASSWORD="$(openssl rand -hex 12)"
  cat > "$ENV_FILE" <<EOF
VNC_PASSWORD=$PASSWORD
VNC_GEOMETRY=1600x900
VNC_DEPTH=24
EOF
fi
chmod 0600 "$ENV_FILE"

docker build -t "$IMAGE" "$DRIVE_ROOT/docker/workstation"

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
  --publish 127.0.0.1:6080:6080 \
  --volume $WORKSPACE:/workspace \
  --memory=5g --cpus=3 \
  --security-opt=no-new-privileges:true \
  --cap-drop=ALL \
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
systemctl enable --now arcadecloud-workstation.service

echo "Esperando health local..."
for _ in {1..30}; do
  if php "$DRIVE_ROOT/bin/workstation_health.php" >/dev/null 2>&1; then
    php "$DRIVE_ROOT/bin/workstation_health.php"
    echo
    echo "Fase 1 instalada. noVNC sólo escucha en 127.0.0.1:6080."
    echo "Workspace de prueba: $WORKSPACE"
    echo "La contraseña VNC permanece sólo en $ENV_FILE."
    exit 0
  fi
  sleep 2
done

systemctl --no-pager --full status arcadecloud-workstation.service || true
echo "La workstation no alcanzó estado saludable." >&2
exit 1
