#!/usr/bin/env bash
set -euo pipefail

# ArcadeCloud Drive installer
# Fase normal: prepara el servidor y abre el setup web de 3 pasos.
# --finalize: después de completar /setup/, cierra la instalación básica.
# FederationCloud se activa en modo básico sobre la IP pública literal.
# Los dominios continúan exigiendo HTTPS.

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 1
fi

MODE="prepare"
APP_ROOT=""
PHP_USER=""
NODE_ROLE=""
MEDIA_WORKER_INSTANCE_ID=""
MEDIA_WORKER_REGION=""
MEDIA_WORKER_HOURLY_USD=""
MEDIA_WORKER_IDLE_GRACE_SECONDS=""
FEDERATION_DYNAMIC_IP=""
SKIP_COMPOSER=0
SKIP_SYSTEM_BOOTSTRAP=0
SKIP_CERTBOT=0

for arg in "$@"; do
  case "$arg" in
    --finalize) MODE="finalize" ;;
    --finalize-from-setup) MODE="finalize-from-setup" ;;
    --reconcile) MODE="reconcile" ;;
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --node-role=*) NODE_ROLE="${arg#*=}" ;;
    --media-worker-instance-id=*) MEDIA_WORKER_INSTANCE_ID="${arg#*=}" ;;
    --media-worker-region=*) MEDIA_WORKER_REGION="${arg#*=}" ;;
    --media-worker-hourly-usd=*) MEDIA_WORKER_HOURLY_USD="${arg#*=}" ;;
    --media-worker-idle-grace-seconds=*) MEDIA_WORKER_IDLE_GRACE_SECONDS="${arg#*=}" ;;
    --federation-dynamic-ip) FEDERATION_DYNAMIC_IP="true" ;;
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
UPDATER_INSTALLER="$WEBROOT/bin/install_arcadecloud_updater.sh"
SERVICE_RECONCILER="$WEBROOT/bin/reconcile_arcadecloud_services.sh"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

need() {
  command -v "$1" >/dev/null 2>&1 || fail "falta el comando requerido: $1"
}

prepare_system() {
  [[ "$MODE" != "reconcile" ]] || return 0
  [[ "$SKIP_SYSTEM_BOOTSTRAP" -eq 0 ]] || return 0
  [[ -f "$SERVER_PREP" ]] || fail "falta el preparador de servidor: $SERVER_PREP"

  local args=(--app-root="$APP_ROOT" --node-role="${NODE_ROLE:-web}")
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
need runuser

[[ -d "$APP_ROOT/.git" ]] || fail "$APP_ROOT no es un checkout Git de ArcadeCloud."
[[ -f "$APP_ROOT/composer.json" ]] || fail "falta composer.json en $APP_ROOT."
[[ -f "$WEBROOT/bin/install_arcadecloud_admin_helper.sh" ]] || fail "falta el instalador administrativo."
[[ -f "$UPDATER_INSTALLER" ]] || fail "falta el instalador de ArcadeCloud Updater."
[[ -f "$SERVICE_RECONCILER" ]] || fail "falta el reconciliador de servicios ArcadeCloud."
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

drive_master_config() {
  local pid arg prev=""
  pid="$(systemctl show -p MainPID --value php-fpm-drive.service 2>/dev/null || true)"
  [[ "$pid" =~ ^[0-9]+$ && "$pid" -gt 1 && -r "/proc/$pid/cmdline" ]] || return 1

  while IFS= read -r -d '' arg; do
    if [[ "$prev" == "-y" || "$prev" == "--fpm-config" ]]; then
      [[ -r "$arg" ]] && { printf '%s' "$arg"; return 0; }
    fi
    case "$arg" in
      --fpm-config=*)
        arg="${arg#*=}"
        [[ -r "$arg" ]] && { printf '%s' "$arg"; return 0; }
        ;;
    esac
    prev="$arg"
  done < "/proc/$pid/cmdline"

  [[ -r /etc/php-fpm.conf ]] && printf '%s' /etc/php-fpm.conf
}

expanded_pool_user() {
  local conf="$1"
  local target_listen="${2:-127.0.0.1:9075}"
  [[ -r "$conf" ]] || return 1

  php-fpm -tt -y "$conf" 2>&1 | awk -v target="$target_listen" '
    function clean(line) {
      sub(/^.*NOTICE:[[:space:]]*/, "", line)
      sub(/^[[:space:]]+/, "", line)
      sub(/[[:space:]]+$/, "", line)
      return line
    }
    function emit_if_match() {
      if (listen == target && user != "") {
        print user
        exit
      }
    }
    {
      line = clean($0)
      if (line ~ /^\[[^]]+\]$/) {
        emit_if_match()
        user = ""
        listen = ""
        next
      }
      if (line ~ /^user[[:space:]]*=/) {
        sub(/^user[[:space:]]*=[[:space:]]*/, "", line)
        sub(/[[:space:];].*$/, "", line)
        user = line
        next
      }
      if (line ~ /^listen[[:space:]]*=/) {
        sub(/^listen[[:space:]]*=[[:space:]]*/, "", line)
        sub(/[[:space:];].*$/, "", line)
        listen = line
        next
      }
    }
    END {
      emit_if_match()
    }
  '
}

detect_php_user() {
  local user="" master_conf=""

  # La fuente de verdad es el pool dedicado de ArcadeCloud, no cualquier
  # proceso php-fpm del servidor (puede haber varios pools/apps).
  user="$(pool_user_from_conf /etc/php-fpm-drive.d/arcadecloud-drive.conf || true)"
  [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }

  user="$(pool_user_from_conf /etc/php-fpm.d/arcadecloud-drive.conf || true)"
  [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }

  master_conf="$(drive_master_config || true)"
  if [[ -n "$master_conf" ]]; then
    user="$(expanded_pool_user "$master_conf" || true)"
    [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }
  fi

  if [[ -r /etc/php-fpm-drive.conf ]]; then
    user="$(expanded_pool_user /etc/php-fpm-drive.conf || true)"
    [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }
  fi

  # Compatibilidad con instalaciones antiguas sin pool dedicado.
  user="$(pool_user_from_conf /etc/php-fpm.d/www.conf || true)"
  [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }

  # Último recurso: sólo cuando no existe configuración identificable.
  user="$(ps -eo user=,comm= 2>/dev/null | awk '
    $2 == "php-fpm" && $1 != "root" { print $1; exit }
  ')"
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

runtime_value() {
  local key="$1"
  python3 - "$RUNTIME_ENV" "$key" <<'PY' 2>/dev/null || true
import json,sys
try:
    data=json.load(open(sys.argv[1], encoding="utf-8"))
    value=data.get(sys.argv[2],"")
    if isinstance(value,str):
        print(value)
except Exception:
    pass
PY
}

persist_node_settings() {
  local role payload existing
  existing="$(runtime_value ARCADECLOUD_NODE_ROLE)"
  role="$NODE_ROLE"
  [[ -n "$role" ]] || role="$existing"
  [[ -n "$role" ]] || role="web"

  case "$role" in
    web|media-worker|combined) ;;
    *) fail "--node-role debe ser web, media-worker o combined." ;;
  esac

  if [[ -n "$MEDIA_WORKER_INSTANCE_ID" && ! "$MEDIA_WORKER_INSTANCE_ID" =~ ^i-[a-fA-F0-9]{8,17}$ ]]; then
    fail "--media-worker-instance-id no tiene formato EC2 válido."
  fi
  if [[ -n "$MEDIA_WORKER_REGION" && ! "$MEDIA_WORKER_REGION" =~ ^[a-zA-Z0-9-]{3,64}$ ]]; then
    fail "--media-worker-region contiene caracteres inválidos."
  fi
  if [[ -n "$MEDIA_WORKER_HOURLY_USD" && ! "$MEDIA_WORKER_HOURLY_USD" =~ ^[0-9]+([.][0-9]{1,8})?$ ]]; then
    fail "--media-worker-hourly-usd debe ser un número USD positivo."
  fi
  if [[ -n "$MEDIA_WORKER_IDLE_GRACE_SECONDS" ]]; then
    [[ "$MEDIA_WORKER_IDLE_GRACE_SECONDS" =~ ^[0-9]+$ ]] || fail "--media-worker-idle-grace-seconds debe ser entero."
    (( MEDIA_WORKER_IDLE_GRACE_SECONDS >= 60 && MEDIA_WORKER_IDLE_GRACE_SECONDS <= 3600 ))       || fail "--media-worker-idle-grace-seconds debe estar entre 60 y 3600."
  fi

  payload="$(python3 - "$role" "$MEDIA_WORKER_INSTANCE_ID" "$MEDIA_WORKER_REGION"     "$MEDIA_WORKER_HOURLY_USD" "$MEDIA_WORKER_IDLE_GRACE_SECONDS" "$FEDERATION_DYNAMIC_IP" <<'PY'
import json,sys
role,instance,region,hourly,idle,dynamic=sys.argv[1:]
data={
  "ARCADECLOUD_NODE_ROLE": role,
  "ARCADECLOUD_MEDIA_WORKER": "true" if role in {"media-worker","combined"} else "false",
}
if instance: data["ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID"]=instance
if region: data["ARCADECLOUD_MEDIA_WORKER_REGION"]=region
if hourly: data["ARCADECLOUD_MEDIA_WORKER_HOURLY_USD"]=hourly
if idle: data["ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS"]=idle
if dynamic: data["ARCADECLOUD_FEDERATION_DYNAMIC_IP"]="true"
print(json.dumps(data,separators=(",",":")))
PY
)"
  runtime_set_many "$payload"
  echo "✓ Rol del nodo: $role"
  [[ -z "$MEDIA_WORKER_INSTANCE_ID" ]] || echo "✓ EC2 multimedia controlada: $MEDIA_WORKER_INSTANCE_ID"
}

reconcile_services() {
  bash "$SERVICE_RECONCILER" --app-root="$APP_ROOT" --php-user="$PHP_USER" --runtime-env="$RUNTIME_ENV"
}

install_helper() {
  bash "$WEBROOT/bin/install_arcadecloud_admin_helper.sh" \
    --php-user="$PHP_USER" \
    --app-root="$APP_ROOT"
}

install_updater() {
  bash "$UPDATER_INSTALLER" --php-user="$PHP_USER" --repo-root="$APP_ROOT"
}

select_certbot_bin() {
  if [[ -x /usr/local/bin/arcadecloud-certbot ]]; then
    printf '%s' /usr/local/bin/arcadecloud-certbot
    return 0
  fi
  command -v certbot 2>/dev/null || true
}

SETUP_ACTIVATION_URL=""

prepare_setup_activation() {
  if [[ -f "$CONFIG_DIR/setup.lock" ]]; then
    echo "✓ El setup ya está cerrado; no se genera una nueva activación."
    return 0
  fi

  local result token public_ip public_url base_url
  result="$(/usr/local/sbin/arcadecloud-drive-admin bootstrap-reset)"
  token="$(python3 -c 'import json,sys; print(json.load(sys.stdin).get("activation_token",""))' <<<"$result")"
  [[ "$token" =~ ^[a-f0-9]{64}$ ]] || fail "no se pudo generar el token temporal de setup."

  public_ip="$(detect_public_ipv4 || true)"
  if [[ -n "$public_ip" ]] && validate_public_ipv4 "$public_ip"; then
    base_url="http://$public_ip"
  else
    public_url="$(python3 - "$RUNTIME_ENV" 2>/dev/null <<'PY' || true
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as f:
        print((json.load(f).get("ARCADECLOUD_PUBLIC_URL") or "").rstrip("/"))
except Exception:
    pass
PY
)"
    base_url="$public_url"
  fi

  if [[ -n "$base_url" ]]; then
    SETUP_ACTIVATION_URL="$base_url/setup/?token=$token"
  else
    SETUP_ACTIVATION_URL="/setup/?token=$token"
  fi
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
    "ARCADECLOUD_PUBLIC_URL": f"http://{ip}",
    "ARCADECLOUD_FEDERATION_URL": f"http://{ip}/federationcloud/",
    "ARCADECLOUD_FEDERATION_ENABLED": "true",
    "ARCADECLOUD_FEDERATION_SEED_URL": seed,
}, separators=(",", ":")))
PY
)"
    runtime_set_many "$payload"
    echo "✓ Drive básico preparado por HTTP con IP pública detectada: $public_ip"
    echo "✓ FederationCloud/ArcadeLink preparado para finalizar sobre la IP pública."
    echo "  Al cerrar el setup se solicitará HTTPS para la IP y el nodo se presentará automáticamente al seed."
  else
    payload="$(python3 - "$seed" <<'PY'
import json, sys
print(json.dumps({
    "ARCADECLOUD_PUBLIC_URL": "",
    "ARCADECLOUD_FEDERATION_URL": "",
    "ARCADECLOUD_FEDERATION_ENABLED": "false",
    "ARCADECLOUD_FEDERATION_SEED_URL": sys.argv[1],
}, separators=(",", ":")))
PY
)"
    runtime_set_many "$payload"
    echo "⚠ No se detectó IPv4 pública global. El Drive queda preparado localmente y FederationCloud desactivado."
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
    echo "Drive HTTP por IP: http://$public_ip/"
  elif [[ -n "$public_url" ]]; then
    echo "Drive: $public_url/"
  fi

  if [[ -n "$SETUP_ACTIVATION_URL" ]]; then
    echo
    echo "URL DE SETUP — CÓPIALA COMPLETA EN EL NAVEGADOR:"
    echo "$SETUP_ACTIVATION_URL"
    echo
    echo "Supervisor temporal: arcadecloud / arcadecloud"
    echo "La URL se regenera automáticamente si vuelves a ejecutar el instalador antes de terminar."
  else
    echo "Setup ya completado; no hay token temporal activo."
  fi

  echo "Dominio y HTTPS: opcionales; pueden configurarse después desde el servidor."

  echo
  echo "Al completar el tercer paso, el setup finalizará automáticamente HTTPS,"
  echo "registrará el nodo en FederationCloud, confirmará el directorio global y cerrará el supervisor temporal."
  echo
  echo "SMTP, tokens AWS, credenciales de control y mirrors se configuran después"
  echo "desde Servidor -> Configuración avanzada."
}

federation_runtime_enabled() {
  python3 - "$RUNTIME_ENV" <<'PY'
import json, sys
try:
    with open(sys.argv[1], encoding="utf-8") as f:
        data = json.load(f)
except Exception:
    raise SystemExit(1)
import ipaddress
from urllib.parse import urlparse

enabled = str(data.get("ARCADECLOUD_FEDERATION_ENABLED", "")).strip().lower()
url = str(data.get("ARCADECLOUD_FEDERATION_URL", "")).strip()
ok = False
if enabled in {"1", "true", "yes", "on"}:
    parsed = urlparse(url)
    if parsed.scheme == "https" and parsed.hostname:
        ok = True
    elif parsed.scheme == "http" and parsed.hostname:
        try:
            ipaddress.ip_address(parsed.hostname)
            ok = True
        except ValueError:
            pass
raise SystemExit(0 if ok else 1)
PY
}

migrate_federation_schema() {
  local migrator="$WEBROOT/bin/federation_catalog_migrate.php"
  [[ -f "$migrator" ]] || fail "falta el migrador FederationCloud: $migrator"

  echo "==> Migrando esquema FederationCloud antes del registro global."
  if ! runuser -u "$PHP_USER" -- php "$migrator"; then
    fail "No se pudo preparar el esquema FederationCloud en MySQL; el setup permanece abierto para reintentar."
  fi
  echo "✓ Esquema FederationCloud preparado antes del registro global."
}

finalize_installation() {
  local require_lock="${1:-1}"
  if [[ "$require_lock" -eq 1 ]]; then
    [[ -f "$CONFIG_DIR/setup.lock" ]] || fail "el setup básico aún no está cerrado. Completa MySQL, AWS/S3 y superadmin primero."
  fi
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
  install_updater
  persist_node_settings

  if federation_runtime_enabled; then
    local certbot_bin
    certbot_bin="$(select_certbot_bin)"
    [[ -n "$certbot_bin" && -x "$certbot_bin" ]] \
      || fail "FederationCloud necesita Certbot compatible para publicar un endpoint HTTPS verificable."

    bash "$WEBROOT/bin/install_federation_https_service.sh" \
      --run-user="$PHP_USER" \
      --app-root="$APP_ROOT" \
      --webroot="$WEBROOT" \
      --certbot-bin="$certbot_bin"

    # El reconciliador HTTPS republica el descriptor al terminar. Por eso el
    # catálogo/Aduana debe existir ANTES de arrancar ese servicio; de lo contrario
    # un nodo nuevo puede intentar escribir FederationEvents antes de migrar MySQL.
    migrate_federation_schema

    if ! systemctl start arcadecloud-federation-https.service; then
      systemctl status arcadecloud-federation-https.service --no-pager >&2 || true
      fail "No se pudo obtener/verificar HTTPS para FederationCloud; el nodo global todavía no puede registrarse."
    fi
    systemctl enable --now arcadecloud-federation-https.timer
    echo "✓ Endpoint HTTPS FederationCloud listo y renovación automática habilitada."

    if ! runuser -u "$PHP_USER" -- php "$WEBROOT/bin/federation_endpoint_refresh.php" --require-directory; then
      fail "El nodo quedó localmente listo, pero drive.esforzados.com no confirmó su registro global."
    fi
    echo "✓ Nodo presentado al seed global y directorio FederationCloud confirmado."

    bash "$WEBROOT/bin/install_federation_sync_timer.sh" \
      --run-user="$PHP_USER" \
      --app-root="$APP_ROOT" \
      --interval-sec=120

    bash "$WEBROOT/bin/install_federation_drop_cleanup_timer.sh" \
      --run-user="$PHP_USER" \
      --app-root="$APP_ROOT"

    if systemctl start arcadecloud-federation-sync.service; then
      echo "✓ Primera sincronización FederationCloud ejecutada."
    else
      echo "⚠ El registro global quedó confirmado, pero la primera sincronización se reintentará por el timer." >&2
    fi

    systemctl is-active arcadecloud-federation-sync.timer >/dev/null \
      && echo "✓ FederationCloud sync timer activo."
  else
    echo "⚠ No hay endpoint público válido; Drive quedó instalado, pero el nodo no puede entrar aún al directorio global." >&2
  fi

  reconcile_services

  echo
  echo "ArcadeCloud Drive: instalación básica finalizada."
  echo "Drive listo. FederationCloud/ArcadeLink queda publicado por HTTPS y registrado en el directorio global cuando existe endpoint público."
  echo "Las opciones avanzadas quedan disponibles dentro de Servidor -> Configuración avanzada."
}

echo "ArcadeCloud Drive Installer"
echo "App root: $APP_ROOT"
echo "PHP-FPM: $PHP_USER:$PHP_GROUP"
echo "Modo: $MODE"
echo

if [[ "$MODE" == "reconcile" ]]; then
  install_helper
  install_updater
  persist_node_settings
  reconcile_services
  exit 0
fi

if [[ "$MODE" == "finalize" ]]; then
  finalize_installation 1
  exit 0
fi

if [[ "$MODE" == "finalize-from-setup" ]]; then
  [[ ! -f "$CONFIG_DIR/setup.lock" ]] || fail "el setup ya está cerrado; la finalización web no se repetirá."
  [[ -f "$CONFIG_DIR/bootstrap-auth.json" ]] || fail "el supervisor bootstrap no está activo."
  [[ -n "${SUDO_USER:-}" && "${SUDO_USER}" == "$PHP_USER" ]] \
    || fail "--finalize-from-setup sólo puede ser invocado por el helper privilegiado desde PHP-FPM."
  finalize_installation 0
  exit 0
fi

prepare_composer
install_helper
install_updater
persist_node_settings
prepare_federation_basic
prepare_setup_activation
show_setup_url
