#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/arcadecloud-drive"
RUN_USER=""
if [[ "${1:-}" != "" && "${1:-}" != --* ]]; then
  APP_ROOT="$1"
  shift
fi
for arg in "$@"; do
  case "$arg" in
    --run-user=*) RUN_USER="${arg#*=}" ;;
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    *) echo "Argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done
APP_ROOT="$(realpath "$APP_ROOT")"
DRIVE_ROOT="${APP_ROOT}/drive"
SERVICE="/etc/systemd/system/arcadecloud-media-worker.service"
BOOTSTRAP_SERVICE="/etc/systemd/system/arcadecloud-media-node-bootstrap.service"
TMP_ROOT="/var/lib/arcadecloud-media/tmp"
RUNTIME_ENV="${ARCADECLOUD_RUNTIME_ENV:-/etc/arcadecloud-drive/runtime-env.json}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Ejecuta este instalador como root." >&2
  exit 1
fi

if [[ -z "$RUN_USER" ]]; then
  RUN_USER="$(ps -eo user=,comm= 2>/dev/null | awk '$2 == "php-fpm" && $1 != "root" { print $1; exit }')"
fi
[[ -n "$RUN_USER" ]] || { echo "No se pudo determinar el usuario no privilegiado del worker; usa --run-user=USUARIO." >&2; exit 2; }
[[ "$RUN_USER" != "root" ]] || { echo "El worker multimedia no puede ejecutarse como root." >&2; exit 2; }
id "$RUN_USER" >/dev/null 2>&1 || { echo "Usuario inexistente: $RUN_USER" >&2; exit 2; }
RUN_GROUP="$(id -gn "$RUN_USER")"

for bin in php ffmpeg ffprobe; do
  if ! command -v "${bin}" >/dev/null 2>&1; then
    echo "Falta ${bin}. Instálalo en el nodo antes de activar el worker." >&2
    exit 1
  fi
done

if [[ ! -f "${DRIVE_ROOT}/bin/media_processing_worker.php" ]]; then
  echo "No se encontró el worker en ${DRIVE_ROOT}." >&2
  exit 1
fi

if [[ ! -f "${DRIVE_ROOT}/bin/media_worker_node_bootstrap.sh" ]]; then
  echo "No se encontró el bootstrap del nodo multimedia." >&2
  exit 1
fi

chmod 0755 "${DRIVE_ROOT}/bin/media_worker_node_bootstrap.sh"

mkdir -p "${TMP_ROOT}"
chown -R "$RUN_USER:$RUN_GROUP" /var/lib/arcadecloud-media
chmod 0750 /var/lib/arcadecloud-media "${TMP_ROOT}"

cat > "${BOOTSTRAP_SERVICE}" <<EOF
[Unit]
Description=ArcadeCloud Media Node boot endpoint refresh
After=network-online.target
Wants=network-online.target
Before=arcadecloud-media-worker.service

[Service]
Type=oneshot
WorkingDirectory=${APP_ROOT}
Environment=ARCADECLOUD_RUNTIME_ENV=${RUNTIME_ENV}
ExecStart=/usr/bin/bash ${DRIVE_ROOT}/bin/media_worker_node_bootstrap.sh ${APP_ROOT}
NoNewPrivileges=true

EOF

cat > "${SERVICE}" <<EOF
[Unit]
Description=ArcadeCloud Media Processing Worker
After=network-online.target arcadecloud-media-node-bootstrap.service
Wants=network-online.target
Requires=arcadecloud-media-node-bootstrap.service

[Service]
Type=simple
User=${RUN_USER}
Group=${RUN_GROUP}
WorkingDirectory=${APP_ROOT}
Environment=ARCADECLOUD_MEDIA_WORKER=1
Environment=ARCADECLOUD_MEDIA_TMP=${TMP_ROOT}
Environment=ARCADECLOUD_RUNTIME_ENV=${RUNTIME_ENV}
ExecStart=/usr/bin/php ${DRIVE_ROOT}/bin/media_processing_worker.php --loop --sleep=5
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=false
ProtectSystem=full
ReadWritePaths=/var/lib/arcadecloud-media

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable arcadecloud-media-node-bootstrap.service >/dev/null 2>&1 || true
systemctl enable --now arcadecloud-media-worker.service
systemctl --no-pager --full status arcadecloud-media-worker.service || true

echo
echo "Worker multimedia instalado."
echo "IMPORTANTE: este nodo debe tener acceso privado a MariaDB y permisos IAM S3 GetObject/PutObject sobre el bucket del Drive."
echo "Para autoapagado de su propia EC2 necesita ec2:DescribeInstances y ec2:StopInstances."
echo "Runtime administrado: ${RUNTIME_ENV}"
echo "Usuario worker: ${RUN_USER}"
echo "Si es réplica sin dominio, configura ARCADECLOUD_FEDERATION_DYNAMIC_IP=true en el runtime administrado."
