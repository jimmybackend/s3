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

for arg in "$@"; do
  case "$arg" in
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --php-group=*) PHP_GROUP="${arg#*=}" ;;
    --identity-path=*) IDENTITY_PATH="${arg#*=}" ;;
    --runtime-env-path=*) RUNTIME_ENV_PATH="${arg#*=}" ;;
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

case "$IDENTITY_PATH" in
  /*) ;;
  *) echo "ERROR: identity-path debe ser absoluto." >&2; exit 2 ;;
esac
case "$RUNTIME_ENV_PATH" in
  /*) ;;
  *) echo "ERROR: runtime-env-path debe ser absoluto." >&2; exit 2 ;;
esac

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

python3 - "$CONFIG_FILE" "$IDENTITY_PATH" "$RUNTIME_ENV_PATH" "$PHP_USER" "$PHP_GROUP" <<'PY'
import json, os, sys, tempfile
path, identity, runtime, user, group = sys.argv[1:]
data = {
    "version": 1,
    "identity_path": identity,
    "runtime_env_path": runtime,
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

printf '%s ALL=(root) NOPASSWD: %s\n' "$PHP_USER" "$TARGET_HELPER" > "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
if command -v visudo >/dev/null 2>&1; then
  visudo -cf "$SUDOERS_FILE" >/dev/null
fi

"$TARGET_HELPER" status

echo
echo "OK: helper administrativo instalado."
echo "PHP-FPM user: $PHP_USER"
echo "PHP-FPM group: $PHP_GROUP"
echo "Identity: $IDENTITY_PATH"
echo "Managed runtime env: $RUNTIME_ENV_PATH"
echo "Sudo permitido únicamente para: $TARGET_HELPER"
