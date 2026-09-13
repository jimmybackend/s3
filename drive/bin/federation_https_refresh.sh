#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/var/www/arcadecloud-drive"
CONFIG="/etc/arcadecloud-drive/federation-endpoint.json"
ADMIN_HELPER="/usr/local/sbin/arcadecloud-drive-admin"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
CERTBOT_BIN="${CERTBOT_BIN:-/usr/bin/certbot}"
NGINX_BIN="${NGINX_BIN:-/usr/sbin/nginx}"
TLS_DIR="/etc/arcadecloud-drive/tls"

for arg in "$@"; do
  case "$arg" in
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --config=*) CONFIG="${arg#*=}" ;;
    --admin-helper=*) ADMIN_HELPER="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "ERROR: federation_https_refresh.sh debe ejecutarse como root." >&2
  exit 77
fi
if [[ ! -x "$PHP_BIN" ]]; then echo "ERROR: PHP CLI no está disponible en $PHP_BIN." >&2; exit 69; fi
if [[ ! -x "$CERTBOT_BIN" ]]; then echo "ERROR: Certbot no está instalado en $CERTBOT_BIN." >&2; exit 69; fi
if [[ ! -x "$ADMIN_HELPER" ]]; then echo "ERROR: instala primero el helper administrativo: $ADMIN_HELPER." >&2; exit 69; fi
if [[ ! -f "$APP_ROOT/drive/bin/federation_endpoint_plan.php" ]]; then echo "ERROR: app-root inválido: $APP_ROOT." >&2; exit 66; fi

PLAN="$($PHP_BIN "$APP_ROOT/drive/bin/federation_endpoint_plan.php" --config="$CONFIG")"

json_value() {
  local key="$1"
  printf '%s' "$PLAN" | "$PHP_BIN" -r '
    $data=json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $key=$argv[1];
    $value=$data[$key] ?? null;
    if (!is_string($value) && !is_bool($value)) exit(3);
    echo is_bool($value) ? ($value ? "1" : "0") : $value;
  ' "$key"
}

MODE="$(json_value mode)"
IDENTIFIER="$(json_value identifier)"
CERT_NAME="$(json_value cert_name)"
TLS_EMAIL="$(json_value tls_email)"
WEBROOT="$(json_value webroot)"
SHORTLIVED="$(json_value shortlived)"

ENV_JSON="$(printf '%s' "$PLAN" | "$PHP_BIN" -r '
  $data=json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
  $env=$data["environment"] ?? null;
  if (!is_array($env)) exit(3);
  echo json_encode($env, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
')"

# Actualiza únicamente las URLs públicas ya permitidas por el helper. La identidad
# Ed25519 no se toca: una IP nueva cambia endpoint, no node_id.
printf '%s' "$ENV_JSON" | "$ADMIN_HELPER" env-set-many >/dev/null

if [[ ! -d "$WEBROOT" ]]; then
  echo "ERROR: el webroot no existe: $WEBROOT" >&2
  exit 66
fi

CERTBOT_ARGS=(
  certonly
  --non-interactive
  --agree-tos
  --email "$TLS_EMAIL"
  --webroot
  --webroot-path "$WEBROOT"
  --cert-name "$CERT_NAME"
  --keep-until-expiring
  --renew-with-new-domains
)

if [[ "$MODE" == "ip" ]]; then
  CERTBOT_VERSION="$($CERTBOT_BIN --version 2>&1 | awk '{print $2}' | head -n1)"
  if ! "$PHP_BIN" -r 'exit(version_compare($argv[1], "5.4", ">=") ? 0 : 1);' "$CERTBOT_VERSION"; then
    echo "ERROR: certificados por IP con webroot requieren Certbot 5.4 o superior; actual: $CERTBOT_VERSION." >&2
    exit 69
  fi
  CERTBOT_ARGS+=(--preferred-profile shortlived --ip-address "$IDENTIFIER")
else
  CERTBOT_ARGS+=(-d "$IDENTIFIER")
fi

"$CERTBOT_BIN" "${CERTBOT_ARGS[@]}"

LIVE_DIR="/etc/letsencrypt/live/$CERT_NAME"
if [[ ! -r "$LIVE_DIR/fullchain.pem" || ! -r "$LIVE_DIR/privkey.pem" ]]; then
  echo "ERROR: Certbot terminó sin dejar el certificado esperado en $LIVE_DIR." >&2
  exit 70
fi

install -d -m 0750 "$TLS_DIR"
ln -sfn "$LIVE_DIR/fullchain.pem" "$TLS_DIR/fullchain.pem"
ln -sfn "$LIVE_DIR/privkey.pem" "$TLS_DIR/privkey.pem"

if [[ -x "$NGINX_BIN" ]]; then
  "$NGINX_BIN" -t
  if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet nginx; then
    systemctl reload nginx
  fi
fi

printf 'FederationCloud HTTPS listo\n'
printf '  modo: %s\n' "$MODE"
printf '  endpoint: https://%s\n' "$IDENTIFIER"
printf '  certificado estable: %s/fullchain.pem\n' "$TLS_DIR"
printf '  clave estable: %s/privkey.pem\n' "$TLS_DIR"
if [[ "$SHORTLIVED" == "1" ]]; then
  printf '  certificado IP: perfil shortlived (160 horas); renovación automatizada obligatoria\n'
fi
