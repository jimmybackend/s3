#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta con sudo/root." >&2
  exit 1
fi

DOMAIN="office.esforzados.com"
GATEWAY_IP="172.31.83.240"
FPM_LISTEN="127.0.0.1:9075"
NGINX_CONF="/etc/nginx/conf.d/office-internal.conf"
BACKUP_DIR="/etc/nginx/arcadecloud-backups"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
CONTROL_SCRIPT="$APP_ROOT/drive/workstation-control.php"
DOCUMENT_SCRIPT="$APP_ROOT/drive/workstation-document.php"

for arg in "$@"; do
  case "$arg" in
    --domain=*) DOMAIN="${arg#*=}" ;;
    --gateway-ip=*) GATEWAY_IP="${arg#*=}" ;;
    --nginx-conf=*) NGINX_CONF="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "ERROR: dominio inválido." >&2; exit 2; }
[[ "$GATEWAY_IP" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || { echo "ERROR: IPv4 del gateway inválida." >&2; exit 2; }
[[ -r "$CONTROL_SCRIPT" ]] || { echo "ERROR: falta $CONTROL_SCRIPT" >&2; exit 3; }
[[ -r "$DOCUMENT_SCRIPT" ]] || { echo "ERROR: falta $DOCUMENT_SCRIPT" >&2; exit 3; }
command -v nginx >/dev/null 2>&1 || { echo "ERROR: nginx no está instalado." >&2; exit 3; }

mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup=""
if [[ -f "$NGINX_CONF" ]]; then
  backup="$BACKUP_DIR/$(basename "$NGINX_CONF").$stamp.bak"
  cp -a "$NGINX_CONF" "$backup"
fi

cat > "$NGINX_CONF" <<EOF
# Managed by ArcadeCloud Workstation internal gateway installer.
server {
    listen 80;
    server_name ${DOMAIN};

    allow ${GATEWAY_IP};
    allow 127.0.0.1;
    deny all;

    location = /__arcadecloud_workstation {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${CONTROL_SCRIPT};
        fastcgi_param SCRIPT_NAME /workstation-control.php;
        fastcgi_param ARCADECLOUD_WORKSTATION_GATE 1;
        fastcgi_pass ${FPM_LISTEN};
    }

    location = /__arcadecloud_office_document {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${DOCUMENT_SCRIPT};
        fastcgi_param SCRIPT_NAME /workstation-document.php;
        fastcgi_param ARCADECLOUD_OFFICE_DOCUMENT_GATE 1;
        fastcgi_pass ${FPM_LISTEN};
    }

    location / {
        proxy_pass http://127.0.0.1:6080;
        proxy_http_version 1.1;

        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;

        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";

        proxy_connect_timeout 5s;
        proxy_read_timeout 86400;
        proxy_send_timeout 86400;
        proxy_buffering off;
        proxy_request_buffering off;
    }
}
EOF

if ! nginx -t; then
  echo "ERROR: nginx -t falló; restaurando configuración anterior." >&2
  if [[ -n "$backup" ]]; then
    cp -a "$backup" "$NGINX_CONF"
  else
    rm -f "$NGINX_CONF"
  fi
  nginx -t || true
  exit 4
fi

if systemctl is-active --quiet nginx.service; then
  systemctl reload nginx.service
else
  systemctl start nginx.service
fi

echo "OK: gateway interno Workstation instalado."
echo "Dominio interno: ${DOMAIN}"
echo "Control plane permitido: ${GATEWAY_IP}"
echo "noVNC local: 127.0.0.1:6080"
echo "Workstation permanece sin publicar 5900/5901/6080 a Internet."
[[ -n "$backup" ]] && echo "Backup: ${backup}"
