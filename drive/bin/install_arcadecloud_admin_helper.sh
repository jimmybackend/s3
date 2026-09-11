#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 1
fi

PHP_USER=""
PHP_GROUP=""
IDENTITY_PATH="/etc/arcadecloud-drive/federation-node.json"
RUNTIME_ENV_PATH="/etc/arcadecloud-drive/runtime-env.json"
BOOTSTRAP_AUTH_PATH="/etc/arcadecloud-drive/bootstrap-auth.json"
SETUP_LOCK_PATH="/etc/arcadecloud-drive/setup.lock"
BOOTSTRAP_SETUP=0

for arg in "$@"; do
  case "$arg" in
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --php-group=*) PHP_GROUP="${arg#*=}" ;;
    --identity-path=*) IDENTITY_PATH="${arg#*=}" ;;
    --runtime-env-path=*) RUNTIME_ENV_PATH="${arg#*=}" ;;
    --bootstrap-auth-path=*) BOOTSTRAP_AUTH_PATH="${arg#*=}" ;;
    --setup-lock-path=*) SETUP_LOCK_PATH="${arg#*=}" ;;
    --bootstrap-setup) BOOTSTRAP_SETUP=1 ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

if [[ -z "$PHP_USER" ]]; then
  echo "ERROR: indica --php-user=USUARIO_REAL_PHP_FPM" >&2
  echo "Ejemplo: sudo bash drive/bin/install_arcadecloud_admin_helper.sh --php-user=apache" >&2
  exit 2
fi

if ! id "$PHP_USER" >/dev/null 2>&1; then
  echo "ERROR: el usuario $PHP_USER no existe." >&2
  exit 2
fi

if [[ -z "$PHP_GROUP" ]]; then
  PHP_GROUP="$(id -gn "$PHP_USER")"
fi
if ! getent group "$PHP_GROUP" >/dev/null 2>&1; then
  echo "ERROR: el grupo $PHP_GROUP no existe." >&2
  exit 2
fi

for path_value in "$IDENTITY_PATH" "$RUNTIME_ENV_PATH" "$BOOTSTRAP_AUTH_PATH" "$SETUP_LOCK_PATH"; do
  case "$path_value" in
    /*) ;;
    *) echo "ERROR: todas las rutas administrativas deben ser absolutas." >&2; exit 2 ;;
  esac
done

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_HELPER="$SCRIPT_DIR/arcadecloud-drive-admin-helper.php"
TARGET_HELPER="/usr/local/sbin/arcadecloud-drive-admin"
CONFIG_DIR="/etc/arcadecloud-drive"
CONFIG_FILE="$CONFIG_DIR/admin-helper.json"
SUDOERS_FILE="/etc/sudoers.d/arcadecloud-drive-admin"

if [[ ! -f "$SOURCE_HELPER" ]]; then
  echo "ERROR: no se encontró $SOURCE_HELPER" >&2
  exit 3
fi

install -d -o root -g "$PHP_GROUP" -m 0750 "$CONFIG_DIR"
install -o root -g root -m 0755 "$SOURCE_HELPER" "$TARGET_HELPER"

python3 - "$CONFIG_FILE" "$IDENTITY_PATH" "$RUNTIME_ENV_PATH" "$BOOTSTRAP_AUTH_PATH" "$SETUP_LOCK_PATH" "$PHP_USER" "$PHP_GROUP" <<'PY'
import json, os, sys, tempfile
path, identity, runtime, bootstrap_auth, setup_lock, user, group = sys.argv[1:]
data = {
    "version": 2,
    "identity_path": identity,
    "runtime_env_path": runtime,
    "bootstrap_auth_path": bootstrap_auth,
    "setup_lock_path": setup_lock,
    "php_user": user,
    "php_group": group,
}
fd, tmp = tempfile.mkstemp(prefix="admin-helper-", dir=os.path.dirname(path), text=True)
try:
    with os.fdopen(fd, "w") as f:
        json.dump(data, f, indent=2)
        f.write("\n")
    os.chmod(tmp, 0o644)
    os.replace(tmp, path)
finally:
    if os.path.exists(tmp):
        os.unlink(tmp)
PY
chown root:root "$CONFIG_FILE"
chmod 0644 "$CONFIG_FILE"

if [[ ! -e "$RUNTIME_ENV_PATH" ]]; then
  install -o root -g "$PHP_GROUP" -m 0640 /dev/null "$RUNTIME_ENV_PATH"
  printf '{}\n' > "$RUNTIME_ENV_PATH"
else
  chown root:"$PHP_GROUP" "$RUNTIME_ENV_PATH"
  chmod 0640 "$RUNTIME_ENV_PATH"
fi

if [[ -e "$IDENTITY_PATH" ]]; then
  chown root:"$PHP_GROUP" "$IDENTITY_PATH"
  chmod 0640 "$IDENTITY_PATH"
fi
if [[ -e "$BOOTSTRAP_AUTH_PATH" ]]; then
  chown root:"$PHP_GROUP" "$BOOTSTRAP_AUTH_PATH"
  chmod 0640 "$BOOTSTRAP_AUTH_PATH"
fi
if [[ -e "$SETUP_LOCK_PATH" ]]; then
  chown root:root "$SETUP_LOCK_PATH"
  chmod 0644 "$SETUP_LOCK_PATH"
fi

printf '%s ALL=(root) NOPASSWD: %s\n' "$PHP_USER" "$TARGET_HELPER" > "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
if command -v visudo >/dev/null 2>&1; then
  visudo -cf "$SUDOERS_FILE" >/dev/null
fi

"$TARGET_HELPER" status

if [[ "$BOOTSTRAP_SETUP" -eq 1 ]]; then
  echo
  echo "=== SUPERVISOR TEMPORAL DE INSTALACIÓN ==="
  BOOTSTRAP_RESULT="$("$TARGET_HELPER" bootstrap-init)"
  echo "$BOOTSTRAP_RESULT"
  echo "Abre /setup/?token=ACTIVATION_TOKEN usando el token mostrado arriba."
  echo "Usuario temporal: arcadecloud"
  echo "Contraseña inicial: arcadecloud"
  echo "El token es obligatorio y se consume sólo como activación de la sesión de setup."
fi

echo
echo "OK: helper administrativo instalado."
echo "PHP-FPM user: $PHP_USER"
echo "PHP-FPM group: $PHP_GROUP"
echo "Identity: $IDENTITY_PATH"
echo "Managed runtime env: $RUNTIME_ENV_PATH"
echo "Bootstrap auth: $BOOTSTRAP_AUTH_PATH"
echo "Setup lock: $SETUP_LOCK_PATH"
echo "Sudo permitido únicamente para: $TARGET_HELPER"
