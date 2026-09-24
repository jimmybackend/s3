#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${1:-/var/www/arcadecloud-drive}"
DRIVE_ROOT="${APP_ROOT}/drive"
SERVICE="/etc/systemd/system/arcadecloud-media-worker.service"
TMP_ROOT="/var/lib/arcadecloud-media/tmp"
DRIVE_ENV="${ARCADECLOUD_DRIVE_ENV:-/etc/arcadecloud-drive/drive.env}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "Ejecuta este instalador como root." >&2
  exit 1
fi

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
chown -R nginx:nginx /var/lib/arcadecloud-media 2>/dev/null || true
chmod 0750 /var/lib/arcadecloud-media "${TMP_ROOT}"

cat > "${SERVICE}" <<EOF
[Unit]
Description=ArcadeCloud Media Processing Worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=nginx
Group=nginx
WorkingDirectory=${APP_ROOT}
EnvironmentFile=-${DRIVE_ENV}
Environment=ARCADECLOUD_MEDIA_WORKER=1
Environment=ARCADECLOUD_MEDIA_TMP=${TMP_ROOT}
Environment=ARCADECLOUD_DRIVE_ENV=${DRIVE_ENV}
ExecStartPre=/usr/bin/bash ${DRIVE_ROOT}/bin/media_worker_node_bootstrap.sh ${APP_ROOT}
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
systemctl enable --now arcadecloud-media-worker.service
systemctl --no-pager --full status arcadecloud-media-worker.service || true

echo
echo "Worker multimedia instalado."
echo "IMPORTANTE: este nodo debe tener acceso privado a MariaDB y permisos IAM S3 GetObject/PutObject sobre el bucket del Drive."
echo "Para autoapagado de su propia EC2 necesita ec2:DescribeInstances y ec2:StopInstances."
echo "Si es réplica sin dominio, configura ARCADECLOUD_FEDERATION_DYNAMIC_IP=1 en ${DRIVE_ENV}."
