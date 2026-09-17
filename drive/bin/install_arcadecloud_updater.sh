#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 1
fi

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT=""
REPO_USER=""
PHP_USER=""
BRANCH="main"
PUBLIC_URL="https://github.com/jimmybackend/s3.git"
PHP_SERVICE="php-fpm-drive"
ADMIN_HELPER_CONFIG="/etc/arcadecloud-drive/admin-helper.json"

for arg in "$@"; do
  case "$arg" in
    --repo-root=*) REPO_ROOT="${arg#*=}" ;;
    --repo-user=*) REPO_USER="${arg#*=}" ;;
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --branch=*) BRANCH="${arg#*=}" ;;
    --public-url=*) PUBLIC_URL="${arg#*=}" ;;
    --php-service=*) PHP_SERVICE="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

if [[ -z "$REPO_ROOT" ]]; then
  REPO_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
fi
if [[ ! -d "$REPO_ROOT/.git" ]]; then
  echo "ERROR: $REPO_ROOT no contiene .git" >&2
  exit 2
fi

if [[ -z "$REPO_USER" ]]; then
  REPO_USER="$(stat -c '%U' "$REPO_ROOT")"
  if [[ "$REPO_USER" == "root" && -n "${SUDO_USER:-}" && "${SUDO_USER}" != "root" ]]; then
    REPO_USER="$SUDO_USER"
  fi
fi
if ! id "$REPO_USER" >/dev/null 2>&1; then
  echo "ERROR: el usuario del repo $REPO_USER no existe." >&2
  exit 2
fi

if [[ -z "$PHP_USER" && -r "$ADMIN_HELPER_CONFIG" ]]; then
  PHP_USER="$(python3 - "$ADMIN_HELPER_CONFIG" <<'PY'
import json, sys
try:
    with open(sys.argv[1]) as f:
        data = json.load(f)
    print(str(data.get('php_user', '')).strip())
except Exception:
    print('')
PY
)"
fi
if [[ -z "$PHP_USER" ]]; then
  echo "ERROR: no pude detectar el usuario PHP-FPM. Indica --php-user=USUARIO." >&2
  exit 2
fi
if ! id "$PHP_USER" >/dev/null 2>&1; then
  echo "ERROR: el usuario PHP-FPM $PHP_USER no existe." >&2
  exit 2
fi

if [[ ! "$BRANCH" =~ ^[A-Za-z0-9._/-]+$ ]]; then
  echo "ERROR: branch inválida." >&2
  exit 2
fi
if [[ ! "$PUBLIC_URL" =~ ^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(\.git)?$ ]]; then
  echo "ERROR: public-url debe apuntar a github.com por HTTPS." >&2
  exit 2
fi
if [[ -n "$PHP_SERVICE" && ! "$PHP_SERVICE" =~ ^[A-Za-z0-9@_.-]+$ ]]; then
  echo "ERROR: php-service inválido." >&2
  exit 2
fi

SOURCE_HELPER="$SCRIPT_DIR/arcadecloud-drive-updater.php"
TARGET_HELPER="/usr/local/sbin/arcadecloud-drive-updater"
CONFIG_DIR="/etc/arcadecloud-drive"
CONFIG_FILE="$CONFIG_DIR/updater.json"
SUDOERS_FILE="/etc/sudoers.d/arcadecloud-drive-updater"

if [[ ! -f "$SOURCE_HELPER" ]]; then
  echo "ERROR: no se encontró $SOURCE_HELPER" >&2
  exit 3
fi

if [[ ! -d "$CONFIG_DIR" ]]; then
  install -d -o root -g root -m 0755 "$CONFIG_DIR"
fi
install -o root -g root -m 0755 "$SOURCE_HELPER" "$TARGET_HELPER"

python3 - "$CONFIG_FILE" "$REPO_ROOT" "$REPO_USER" "$BRANCH" "$PUBLIC_URL" "$PHP_SERVICE" <<'PY'
import json, os, sys, tempfile
path, repo_root, repo_user, branch, public_url, php_service = sys.argv[1:]
data = {
    "version": 1,
    "repo_root": repo_root,
    "repo_user": repo_user,
    "branch": branch,
    "public_url": public_url,
    "php_service": php_service,
}
fd, tmp = tempfile.mkstemp(prefix="updater-", dir=os.path.dirname(path), text=True)
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

printf '%s ALL=(root) NOPASSWD: %s\n' "$PHP_USER" "$TARGET_HELPER" > "$SUDOERS_FILE"
chmod 0440 "$SUDOERS_FILE"
if command -v visudo >/dev/null 2>&1; then
  visudo -cf "$SUDOERS_FILE" >/dev/null
fi

"$TARGET_HELPER" status

echo
echo "OK: ArcadeCloud Updater instalado."
echo "Repo: $REPO_ROOT"
echo "Usuario Git: $REPO_USER"
echo "PHP-FPM user: $PHP_USER"
echo "Rama estable: $BRANCH"
echo "Fallback público: $PUBLIC_URL"
echo "PHP service: ${PHP_SERVICE:-sin reinicio programado}"
echo "No se instala timer: sólo consulta cuando el superadmin pulsa Buscar actualizaciones."
