#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 77
fi

APP_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_USER=""
REPO_USER=""
AUTO_FEDERATION=1

for arg in "$@"; do
  case "$arg" in
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --repo-user=*) REPO_USER="${arg#*=}" ;;
    --no-federation) AUTO_FEDERATION=0 ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

APP_ROOT="$(realpath "$APP_ROOT")"
[[ -d "$APP_ROOT/.git" ]] || { echo "ERROR: $APP_ROOT no es un clon Git de ArcadeCloud." >&2; exit 3; }
[[ -f "$APP_ROOT/composer.json" ]] || { echo "ERROR: falta composer.json." >&2; exit 3; }

for cmd in git php composer python3 systemctl curl; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "ERROR: falta el comando requerido: $cmd" >&2; exit 3; }
done

if [[ -z "$PHP_USER" ]]; then
  PHP_USER="$(ps -eo user=,comm= | awk '$2 == "php-fpm" && $1 != "root" {print $1; exit}')"
fi
[[ -n "$PHP_USER" ]] || {
  echo "ERROR: no pude detectar el usuario de PHP-FPM. Usa --php-user=USUARIO." >&2
  exit 3
}
id "$PHP_USER" >/dev/null 2>&1 || { echo "ERROR: usuario PHP-FPM inválido: $PHP_USER" >&2; exit 3; }

if [[ -z "$REPO_USER" ]]; then
  REPO_USER="$(stat -c '%U' "$APP_ROOT")"
fi
id "$REPO_USER" >/dev/null 2>&1 || { echo "ERROR: usuario del repo inválido: $REPO_USER" >&2; exit 3; }

echo "========================================================"
echo "ArcadeCloud Drive · instalación básica"
echo "========================================================"
echo "APP_ROOT: $APP_ROOT"
echo "PHP-FPM user: $PHP_USER"
echo "Repo user: $REPO_USER"
echo

echo "[1/6] Dependencias Composer"
runuser -u "$REPO_USER" -- composer install   --working-dir="$APP_ROOT"   --no-dev --optimize-autoloader --no-interaction

echo "[2/6] Helper administrativo y supervisor temporal"
HELPER_OUTPUT="$(bash "$APP_ROOT/drive/bin/install_arcadecloud_admin_helper.sh"   --php-user="$PHP_USER"   --bootstrap-setup)"
echo "$HELPER_OUTPUT"

echo "[3/6] Updater"
bash "$APP_ROOT/drive/bin/install_arcadecloud_updater.sh"   --php-user="$PHP_USER"   --repo-root="$APP_ROOT"   --repo-user="$REPO_USER"

PUBLIC_IP=""
detect_ec2_ip() {
  local token ip
  token="$(curl -fsS --max-time 2 -X PUT     -H 'X-aws-ec2-metadata-token-ttl-seconds: 60'     http://169.254.169.254/latest/api/token 2>/dev/null || true)"
  [[ -n "$token" ]] || return 1
  ip="$(curl -fsS --max-time 2     -H "X-aws-ec2-metadata-token: $token"     http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null || true)"
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  printf '%s' "$ip"
}
PUBLIC_IP="$(detect_ec2_ip || true)"

echo "[4/6] FederationCloud básico"
FEDERATION_STATUS="pending"
if (( AUTO_FEDERATION == 1 )) && [[ -n "$PUBLIC_IP" ]]; then
  HELPER=/usr/local/sbin/arcadecloud-drive-admin
  STATUS_JSON="$("$HELPER" status)"
  IDENTITY_EXISTS="$(python3 -c 'import json,sys; print("yes" if json.load(sys.stdin).get("identity_exists") else "no")' <<<"$STATUS_JSON")"
  if [[ "$IDENTITY_EXISTS" != "yes" ]]; then
    NODE_SUFFIX="$(tr -d '-' < /proc/sys/kernel/random/uuid | cut -c1-12)"
    "$HELPER" identity-create "arcadecloud-$NODE_SUFFIX" >/dev/null
  fi

  python3 - "$PUBLIC_IP" <<'PY' | "$HELPER" env-set-many
import json,sys
ip=sys.argv[1]
print(json.dumps({
  "ARCADECLOUD_PUBLIC_URL": f"https://{ip}",
  "ARCADECLOUD_FEDERATION_URL": f"https://{ip}/federationcloud/",
  "ARCADECLOUD_FEDERATION_ENABLED": "true"
}))
PY

  if command -v nginx >/dev/null 2>&1 && command -v certbot >/dev/null 2>&1 && command -v runuser >/dev/null 2>&1; then
    if bash "$APP_ROOT/drive/bin/install_federation_https_service.sh"       --run-user="$PHP_USER"       --app-root="$APP_ROOT"       --webroot="$APP_ROOT/drive" >/tmp/arcadecloud-https-install.log 2>&1       && systemctl start arcadecloud-federation-https.service; then
        systemctl enable --now arcadecloud-federation-https.timer
        FEDERATION_STATUS="active"
        echo "FederationCloud básico: activo sobre IP pública $PUBLIC_IP"
    else
        printf '%s' '{"ARCADECLOUD_FEDERATION_ENABLED":"false"}' | "$HELPER" env-set-many
        FEDERATION_STATUS="pending_https"
        echo "ADVERTENCIA: Drive seguirá funcionando, pero FederationCloud quedó pendiente de HTTPS." >&2
        cat /tmp/arcadecloud-https-install.log >&2 2>/dev/null || true
    fi
  else
    printf '%s' '{"ARCADECLOUD_FEDERATION_ENABLED":"false"}' | "$HELPER" env-set-many
    FEDERATION_STATUS="pending_https"
    echo "ADVERTENCIA: faltan Nginx/Certbot/runuser; FederationCloud quedó pendiente de HTTPS." >&2
  fi
else
  echo "INFO: no se detectó IPv4 pública EC2; FederationCloud se podrá activar después desde configuración avanzada."
fi

echo "[5/6] Finalización automática posterior al setup"
FINALIZE_SERVICE=/etc/systemd/system/arcadecloud-install-finalize.service
FINALIZE_PATH=/etc/systemd/system/arcadecloud-install-finalize.path

cat > "$FINALIZE_SERVICE" <<EOF
[Unit]
Description=Finalize ArcadeCloud installation after web setup
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=$APP_ROOT/drive/bin/arcadecloud_install_finalize.sh --app-root=$APP_ROOT --run-user=$PHP_USER
EOF

cat > "$FINALIZE_PATH" <<'EOF'
[Unit]
Description=Watch for ArcadeCloud setup completion

[Path]
PathExists=/etc/arcadecloud-drive/setup.lock
Unit=arcadecloud-install-finalize.service

[Install]
WantedBy=multi-user.target
EOF

chmod 0644 "$FINALIZE_SERVICE" "$FINALIZE_PATH"
chmod 0755 "$APP_ROOT/drive/bin/arcadecloud_install_finalize.sh"
systemctl daemon-reload
systemctl enable --now arcadecloud-install-finalize.path

echo "[6/6] Listo para los tres pasos web"

ACTIVATION_TOKEN="$(printf '%s\n' "$HELPER_OUTPUT" | python3 -c '
import json,sys,re
text=sys.stdin.read()
m=re.search(r"\{[^\n]*\"activation_token\"[^\n]*\}", text)
if not m:
    print("")
else:
    try: print(json.loads(m.group(0)).get("activation_token",""))
    except Exception: print("")
')"

echo
echo "========================================================"
echo "CONFIGURACIÓN BÁSICA WEB"
echo "========================================================"
echo "1. MySQL"
echo "2. AWS / S3"
echo "3. Primer superadmin"
echo
echo "SMTP, AWS temporal/control y mirror quedan para Configuración avanzada."
echo "FederationCloud básico: $FEDERATION_STATUS"
if [[ -n "$PUBLIC_IP" && -n "$ACTIVATION_TOKEN" ]]; then
  if [[ "$FEDERATION_STATUS" == "active" ]]; then
    echo "Abre: https://$PUBLIC_IP/setup/?token=$ACTIVATION_TOKEN"
  else
    echo "Token de setup generado. Completa HTTPS antes de introducir secretos por navegador."
    echo "Ruta: /setup/?token=$ACTIVATION_TOKEN"
  fi
elif [[ -n "$ACTIVATION_TOKEN" ]]; then
  echo "Token de setup: $ACTIVATION_TOKEN"
  echo "Abre https://TU-ENDPOINT/setup/?token=$ACTIVATION_TOKEN"
else
  echo "El bootstrap ya existía o el token ya fue emitido anteriormente."
  echo "Si lo perdiste y setup sigue abierto: sudo /usr/local/sbin/arcadecloud-drive-admin bootstrap-reset"
fi
