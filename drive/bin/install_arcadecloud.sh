#!/usr/bin/env bash
set -euo pipefail

# ArcadeCloud Drive installer
# Fase normal: prepara el servidor y abre el setup web de 3 pasos.
# --finalize: después de completar /setup/, instala/activa FederationCloud runtime.

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 1
fi

MODE="prepare"
APP_ROOT=""
PHP_USER=""
SKIP_COMPOSER=0
SKIP_SYSTEM_BOOTSTRAP=0
SKIP_CERTBOT=0

for arg in "$@"; do
  case "$arg" in
    --finalize) MODE="finalize" ;;
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --skip-composer) SKIP_COMPOSER=1 ;;
    --skip-system-bootstrap) SKIP_SYSTEM_BOOTSTRAP=1 ;;
    --skip-certbot) SKIP_CERTBOT=1 ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
[[ -n "$APP_ROOT" ]] || APP_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
APP_ROOT="$(realpath "$APP_ROOT")"
WEBROOT="$APP_ROOT/drive"
CONFIG_DIR="/etc/arcadecloud-drive"
RUNTIME_ENV="$CONFIG_DIR/runtime-env.json"
IDENTITY="$CONFIG_DIR/federation-node.json"
SEEDS_JSON="$APP_ROOT/drive/config/federation-seeds.json"
SERVER_PREP="$WEBROOT/bin/install_arcadecloud_server.sh"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

need() {
  command -v "$1" >/dev/null 2>&1 || fail "falta el comando requerido: $1"
}

prepare_system() {
  [[ "$SKIP_SYSTEM_BOOTSTRAP" -eq 0 ]] || return 0
  [[ -f "$SERVER_PREP" ]] || fail "falta el preparador de servidor: $SERVER_PREP"

  local args=(--app-root="$APP_ROOT")
  [[ -n "$PHP_USER" ]] && args+=(--php-user="$PHP_USER")
  [[ "$SKIP_CERTBOT" -eq 1 ]] && args+=(--skip-certbot)

  bash "$SERVER_PREP" "${args[@]}"
}

prepare_system

need php
need php-fpm
need nginx
need composer
need python3
need curl
need git
need systemctl
need sudo

[[ -d "$APP_ROOT/.git" ]] || fail "$APP_ROOT no es un checkout Git de ArcadeCloud."
[[ -f "$APP_ROOT/composer.json" ]] || fail "falta composer.json en $APP_ROOT."
[[ -f "$WEBROOT/bin/install_arcadecloud_admin_helper.sh" ]] || fail "falta el instalador administrativo."
[[ -f "$SEEDS_JSON" ]] || fail "falta drive/config/federation-seeds.json."

pool_user_from_conf() {
  local conf="$1"
  [[ -r "$conf" ]] || return 1
  awk -F= '
    /^[[:space:]]*user[[:space:]]*=/ {
      gsub(/[[:space:]]/, "", $2);
      if ($2 != "") { print $2; exit }
    }
  ' "$conf"
}

detect_php_user() {
  local user=""

  user="$(ps -eo user=,comm= 2>/dev/null | awk '
    $2 == "php-fpm" && $1 != "root" { print $1; exit }
  ')"
  [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }

  user="$(pool_user_from_conf /etc/php-fpm-drive.d/arcadecloud-drive.conf || true)"
  [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }

  user="$(pool_user_from_conf /etc/php-fpm.d/arcadecloud-drive.conf || true)"
  [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }

  user="$(pool_user_from_conf /etc/php-fpm-drive.conf || true)"
  [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }

  user="$(pool_user_from_conf /etc/php-fpm.d/www.conf || true)"
  [[ -n "$user" ]] && printf '%s' "$user"
}

if [[ -z "$PHP_USER" ]]; then
  PHP_USER="$(detect_php_user || true)"
fi
[[ -n "$PHP_USER" ]] || fail "no pude detectar el usuario worker de PHP-FPM; usa --php-user=USUARIO."
[[ "$PHP_USER" != "root" ]] || fail "PHP-FPM no debe ejecutar ArcadeCloud como root."
id "$PHP_USER" >/dev/null 2>&1 || fail "el usuario PHP-FPM no existe: $PHP_USER"
PHP_GROUP="$(id -gn "$PHP_USER")"

detect_public_ipv4() {
  local token ip
  token="$(curl -fsS --max-time 2 -X PUT     -H 'X-aws-ec2-metadata-token-ttl-seconds: 60'     http://169.254.169.254/latest/api/token 2>/dev/null || true)"
  if [[ -n "$token" ]]; then
    ip="$(curl -fsS --max-time 2       -H "X-aws-ec2-metadata-token: $token"       http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null || true)"
    if [[ -n "$ip" ]]; then printf '%s' "$ip"; return 0; fi
  fi

  ip="$(curl -4fsS --max-time 4 https://checkip.amazonaws.com 2>/dev/null | tr -d '[:space:]' || true)"
  [[ -n "$ip" ]] && printf '%s' "$ip"
}

validate_public_ipv4() {
  python3 - "$1" <<'PY'
import ipaddress, sys
try:
    ip = ipaddress.ip_address(sys.argv[1])
    ok = isinstance(ip, ipaddress.IPv4Address) and ip.is_global
except ValueError:
    ok = False
raise SystemExit(0 if ok else 1)
PY
}

primary_seed() {
  python3 - "$SEEDS_JSON" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as f:
    data = json.load(f)
seeds = data.get("seeds") or []
if not seeds or not isinstance(seeds[0], str):
    raise SystemExit(1)
print(seeds[0].rstrip("/") + "/")
PY
}

runtime_set_many() {
  local json="$1"
  printf '%s' "$json" | /usr/local/sbin/arcadecloud-drive-admin env-set-many
}

install_helper() {
  bash "$WEBROOT/bin/install_arcadecloud_admin_helper.sh"     --php-user="$PHP_USER"     --bootstrap-setup
}

prepare_composer() {
  [[ "$SKIP_COMPOSER" -eq 0 ]] || return 0
  need composer
  local repo_user
  repo_user="$(stat -c '%U' "$APP_ROOT")"
  if [[ "$repo_user" == "root" ]]; then
    (cd "$APP_ROOT" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction)
  else
    sudo -u "$repo_user" bash -lc "cd '$APP_ROOT' && composer install --no-dev --optimize-autoloader --no-interaction"
  fi
}

prepare_federation_basic() {
  local public_ip seed node_name payload

  public_ip="$(detect_public_ipv4 || true)"
  if [[ -n "$public_ip" ]] && ! validate_public_ipv4 "$public_ip"; then
    public_ip=""
  fi

  seed="$(primary_seed)"

  if [[ ! -f "$IDENTITY" ]]; then
    node_name="arcadecloud-$(python3 - <<'PY'
import secrets
print(secrets.token_hex(6))
PY
)"
    /usr/local/sbin/arcadecloud-drive-admin identity-create "$node_name" >/dev/null
    echo "✓ Identidad FederationCloud generada automáticamente: $node_name"
  else
    echo "✓ Identidad FederationCloud existente conservada."
  fi

  if [[ -n "$public_ip" ]]; then
    payload="$(python3 - "$public_ip" "$seed" <<'PY'
import json, sys
ip, seed = sys.argv[1], sys.argv[2]
print(json.dumps({
    "ARCADECLOUD_PUBLIC_URL": f"https://{ip}",
    "ARCADECLOUD_FEDERATION_URL": f"https://{ip}/federationcloud/",
    "ARCADECLOUD_FEDERATION_ENABLED": "true",
    "ARCADECLOUD_FEDERATION_SEED_URL": seed,
}, separators=(",", ":")))
PY
)"
    runtime_set_many "$payload"
    echo "✓ FederationCloud básico preparado con IP pública detectada: $public_ip"
    echo "  HTTPS se reconciliará durante --finalize; no se desactiva validación TLS."
  else
    payload="$(python3 - "$seed" <<'PY'
import json, sys
print(json.dumps({
    "ARCADECLOUD_FEDERATION_ENABLED": "false",
    "ARCADECLOUD_FEDERATION_SEED_URL": sys.argv[1],
}, separators=(",", ":")))
PY
)"
    runtime_set_many "$payload"
    echo "⚠ No se detectó IPv4 pública global. FederationCloud queda pendiente de endpoint."
  fi
}

show_setup_url() {
  local public_url="" public_ip=""
  public_url="$(python3 - "$RUNTIME_ENV" 2>/dev/null <<'PY' || true
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as f:
        print((json.load(f).get("ARCADECLOUD_PUBLIC_URL") or "").rstrip("/"))
except Exception:
    pass
PY
)"
  public_ip="$(detect_public_ipv4 || true)"

  echo
  echo "========================================================"
  echo "PREPARACIÓN COMPLETA"
  echo "========================================================"
  echo "Ahora completa únicamente:"
  echo "  1. MySQL"
  echo "  2. AWS / S3"
  echo "  3. Primer superadmin"
  echo

  if [[ -n "$public_ip" ]]; then
    echo "Setup HTTP inicial: http://$public_ip/setup/"
    if [[ -n "$public_url" ]]; then
      echo "Endpoint público objetivo después de HTTPS: $public_url/"
    fi
  elif [[ -n "$public_url" ]]; then
    echo "Setup: $public_url/setup/"
  else
    echo "Abre /setup/ en el endpoint HTTP/HTTPS que ya tengas configurado."
  fi

  echo
  echo "Cuando termines los tres pasos ejecuta:"
  echo "  sudo bash $WEBROOT/bin/install_arcadecloud.sh --finalize --app-root=$APP_ROOT --php-user=$PHP_USER"
  echo
  echo "SMTP, tokens AWS, credenciales de control y mirrors se configuran después"
  echo "desde Servidor -> Configuración avanzada."
}

finalize_installation() {
  [[ -f "$CONFIG_DIR/setup.lock" ]] || fail "el setup básico aún no está cerrado. Completa MySQL, AWS/S3 y superadmin primero."
  [[ -r "$RUNTIME_ENV" ]] || fail "no se puede leer $RUNTIME_ENV."

  python3 - "$RUNTIME_ENV" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as f:
    data=json.load(f)
required=[
 "DB_HOST","DB_USER","DB_PASSWORD","DB_NAME",
 "AWS_REGION","AWS_S3_BUCKET","AWS_ACCESS_KEY_ID","AWS_SECRET_ACCESS_KEY"
]
missing=[k for k in required if not str(data.get(k,"")).strip()]
if missing:
    print("Faltan variables básicas: "+", ".join(missing), file=sys.stderr)
    raise SystemExit(1)
PY

  bash "$WEBROOT/bin/install_arcadecloud_admin_helper.sh" --php-user="$PHP_USER"

  bash "$WEBROOT/bin/install_federation_sync_timer.sh"     --run-user="$PHP_USER"     --app-root="$APP_ROOT"     --interval-sec=120

  if command -v certbot >/dev/null 2>&1 && command -v nginx >/dev/null 2>&1; then
    bash "$WEBROOT/bin/install_federation_https_service.sh"       --run-user="$PHP_USER"       --app-root="$APP_ROOT"       --webroot="$WEBROOT"

    if systemctl start arcadecloud-federation-https.service; then
      systemctl enable --now arcadecloud-federation-https.timer
      echo "✓ HTTPS FederationCloud reconciliado y timer habilitado."
    else
      echo "⚠ Drive quedó instalado, pero FederationCloud HTTPS sigue pendiente." >&2
      echo "  Revisa: systemctl status arcadecloud-federation-https.service --no-pager" >&2
    fi
  else
    echo "⚠ Certbot/Nginx no están disponibles; se omite reconciliación HTTPS automática." >&2
  fi

  systemctl is-active arcadecloud-federation-sync.timer >/dev/null     && echo "✓ FederationCloud sync timer activo."

  echo
  echo "ArcadeCloud Drive: instalación básica finalizada."
  echo "Las opciones avanzadas quedan disponibles dentro de Servidor -> Configuración avanzada."
}

echo "ArcadeCloud Drive Installer"
echo "App root: $APP_ROOT"
echo "PHP-FPM: $PHP_USER:$PHP_GROUP"
echo "Modo: $MODE"
echo

if [[ "$MODE" == "finalize" ]]; then
  finalize_installation
  exit 0
fi

prepare_composer
install_helper
prepare_federation_basic
show_setup_url
