#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta con sudo/root." >&2
  exit 1
fi

DOMAIN="office.esforzados.com"
UPSTREAM="172.31.14.35"
FPM_LISTEN="127.0.0.1:9075"
NGINX_CONF="/etc/nginx/conf.d/office.esforzados.com.conf"
BACKUP_DIR="/etc/nginx/arcadecloud-backups"
CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
GATE_SCRIPT="$APP_ROOT/drive/office-gateway.php"

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
[[ -r "$GATE_SCRIPT" ]] || { echo "ERROR: falta $GATE_SCRIPT" >&2; exit 3; }
command -v nginx >/dev/null 2>&1 || { echo "ERROR: nginx no está instalado." >&2; exit 3; }

for required in "${CERT_DIR}/fullchain.pem" "${CERT_DIR}/privkey.pem"; do
  [[ -r "$required" ]] || { echo "ERROR: falta $required" >&2; exit 3; }
done

mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup=""
if [[ -f "$NGINX_CONF" ]]; then
  backup="$BACKUP_DIR/$(basename "$NGINX_CONF").$stamp.bak"
  cp -a "$NGINX_CONF" "$backup"
fi

ssl_options=""
[[ -r /etc/letsencrypt/options-ssl-nginx.conf ]] && ssl_options="    include /etc/letsencrypt/options-ssl-nginx.conf;"
dhparam=""
[[ -r /etc/letsencrypt/ssl-dhparams.pem ]] && dhparam="    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;"

cat > "$NGINX_CONF" <<EOF
# Managed by ArcadeCloud Office gateway installer.
server {
    server_name ${DOMAIN};

    location = / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${GATE_SCRIPT};
        fastcgi_param SCRIPT_NAME /office-gateway.php;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        fastcgi_param ARCADECLOUD_OFFICE_GATE 1;
        fastcgi_param ARCADECLOUD_OFFICE_GATE_ACTION view;
        fastcgi_pass ${FPM_LISTEN};
    }

    location = /__office_start {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${GATE_SCRIPT};
        fastcgi_param SCRIPT_NAME /office-gateway.php;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        fastcgi_param ARCADECLOUD_OFFICE_GATE 1;
        fastcgi_param ARCADECLOUD_OFFICE_GATE_ACTION start;
        fastcgi_pass ${FPM_LISTEN};
    }

    location = /__office_activity {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${GATE_SCRIPT};
        fastcgi_param SCRIPT_NAME /office-gateway.php;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        fastcgi_param ARCADECLOUD_OFFICE_GATE 1;
        fastcgi_param ARCADECLOUD_OFFICE_GATE_ACTION activity;
        fastcgi_pass ${FPM_LISTEN};
    }

    location = /__office_idle {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${GATE_SCRIPT};
        fastcgi_param SCRIPT_NAME /office-gateway.php;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        fastcgi_param ARCADECLOUD_OFFICE_GATE 1;
        fastcgi_param ARCADECLOUD_OFFICE_GATE_ACTION idle;
        fastcgi_pass ${FPM_LISTEN};
    }

    location = /__office_auth {
        internal;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${GATE_SCRIPT};
        fastcgi_param SCRIPT_NAME /office-gateway.php;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        fastcgi_param ARCADECLOUD_OFFICE_GATE 1;
        fastcgi_param ARCADECLOUD_OFFICE_GATE_ACTION auth;
        fastcgi_pass ${FPM_LISTEN};
    }

    location / {
        auth_request /__office_auth;

        proxy_pass http://${UPSTREAM};
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

        proxy_intercept_errors on;
        error_page 502 504 = @office_gate;
    }

    location @office_gate {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME ${GATE_SCRIPT};
        fastcgi_param SCRIPT_NAME /office-gateway.php;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_X_FORWARDED_PROTO https;
        fastcgi_param ARCADECLOUD_OFFICE_GATE 1;
        fastcgi_param ARCADECLOUD_OFFICE_GATE_ACTION view;
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

echo "OK: gateway Office instalado."
echo "Dominio: https://${DOMAIN}/"
echo "Upstream privado: http://${UPSTREAM}"
echo "Pantalla de encendido y actividad: ${GATE_SCRIPT}"
echo "Inactividad: reutiliza el control seguro de 10 minutos del nodo de cómputo."
[[ -n "$backup" ]] && echo "Backup: ${backup}"
