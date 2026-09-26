#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta con sudo/root." >&2
  exit 1
fi

DOMAIN="fastdrive.esforzados.com"
UPSTREAM="172.31.14.35"
FPM_LISTEN="127.0.0.1:9075"
NGINX_CONF="/etc/nginx/conf.d/fastdrive.esforzados.com.conf"
BACKUP_DIR="/etc/nginx/arcadecloud-backups"
CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
WAKE_SCRIPT="$APP_ROOT/drive/fastdrive-wake.php"

for arg in "$@"; do
  case "$arg" in
    --domain=*) DOMAIN="${arg#*=}"; CERT_DIR="/etc/letsencrypt/live/${DOMAIN}" ;;
    --upstream=*) UPSTREAM="${arg#*=}" ;;
    --nginx-conf=*) NGINX_CONF="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "ERROR: dominio inválido." >&2; exit 2; }
[[ "$UPSTREAM" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || { echo "ERROR: IPv4 privada inválida." >&2; exit 2; }
[[ -r "$WAKE_SCRIPT" ]] || { echo "ERROR: falta $WAKE_SCRIPT" >&2; exit 3; }

for required in nginx "${CERT_DIR}/fullchain.pem" "${CERT_DIR}/privkey.pem"; do
  if [[ "$required" == "nginx" ]]; then
    command -v nginx >/dev/null 2>&1 || { echo "ERROR: nginx no está instalado." >&2; exit 3; }
  elif [[ ! -r "$required" ]]; then
    echo "ERROR: falta $required" >&2
    exit 3
  fi
done

mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup=""
if [[ -f "$NGINX_CONF" ]]; then
  backup="${BACKUP_DIR}/$(basename "$NGINX_CONF").${stamp}.bak"
  cp -a "$NGINX_CONF" "$backup"
fi

ssl_options=""
if [[ -r /etc/letsencrypt/options-ssl-nginx.conf ]]; then
  ssl_options="    include /etc/letsencrypt/options-ssl-nginx.conf;"
fi

dhparam=""
if [[ -r /etc/letsencrypt/ssl-dhparams.pem ]]; then
  dhparam="    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;"
fi

cat > "$NGINX_CONF" <<EOF
# Managed by ArcadeCloud FastDrive gateway installer.
server {
    server_name ${DOMAIN};

    client_max_body_size 32m;

    location / {
        proxy_pass http://${UPSTREAM};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;

        proxy_connect_timeout 5s;
        proxy_send_timeout 300s;
        proxy_read_timeout 300s;
        proxy_request_buffering off;
        proxy_buffering off;

        proxy_intercept_errors on;
        error_page 502 504 = @fastdrive_wake;
    }

    location = /__fastdrive_start {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${WAKE_SCRIPT};
        fastcgi_param SCRIPT_NAME /fastdrive-wake.php;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        fastcgi_param ARCADECLOUD_FASTDRIVE_GATE 1;
        fastcgi_param ARCADECLOUD_FASTDRIVE_GATE_ACTION start;
        fastcgi_pass ${FPM_LISTEN};
    }

    location @fastdrive_wake {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${WAKE_SCRIPT};
        fastcgi_param SCRIPT_NAME /fastdrive-wake.php;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        fastcgi_param ARCADECLOUD_FASTDRIVE_GATE 1;
        fastcgi_param ARCADECLOUD_FASTDRIVE_GATE_ACTION status;
        fastcgi_pass ${FPM_LISTEN};
    }

    listen 443 ssl;
    ssl_certificate ${CERT_DIR}/fullchain.pem;
    ssl_certificate_key ${CERT_DIR}/privkey.pem;
${ssl_options}
${dhparam}
}

server {
    listen 80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
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

echo "OK: gateway FastDrive instalado."
echo "Dominio: https://${DOMAIN}/"
echo "Upstream privado: http://${UPSTREAM}"
echo "Wake local: ${WAKE_SCRIPT}"
echo "PHP-FPM local: ${FPM_LISTEN}"
[[ -n "$backup" ]] && echo "Backup: ${backup}"
