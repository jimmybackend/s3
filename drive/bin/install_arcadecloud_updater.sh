#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este instalador con sudo/root." >&2
  exit 1
fi

PHP_USER=""
REPO_ROOT=""
REPO_USER=""

for arg in "$@"; do
  case "$arg" in
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --repo-root=*) REPO_ROOT="${arg#*=}" ;;
    --repo-user=*) REPO_USER="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
[[ -n "$REPO_ROOT" ]] || REPO_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
[[ -d "$REPO_ROOT/.git" ]] || { echo "ERROR: $REPO_ROOT no es un repositorio Git." >&2; exit 2; }

if [[ -z "$PHP_USER" ]]; then
  echo "ERROR: indica --php-user=USUARIO_REAL_PHP_FPM" >&2
  exit 2
fi
id "$PHP_USER" >/dev/null 2>&1 || { echo "ERROR: usuario PHP inexistente: $PHP_USER" >&2; exit 2; }

if [[ -z "$REPO_USER" ]]; then
  REPO_USER="$(stat -c '%U' "$REPO_ROOT")"
fi
id "$REPO_USER" >/dev/null 2>&1 || { echo "ERROR: usuario del repo inexistente: $REPO_USER" >&2; exit 2; }

SOURCE="$SCRIPT_DIR/arcadecloud-drive-updater.php"
TARGET="/usr/local/sbin/arcadecloud-drive-updater"
CONFIG_DIR="/etc/arcadecloud-drive"
CONFIG="$CONFIG_DIR/updater.json"
SUDOERS="/etc/sudoers.d/arcadecloud-drive-updater"

install -d -o root -g root -m 0755 "$CONFIG_DIR"
install -o root -g root -m 0755 "$SOURCE" "$TARGET"

python3 - "$CONFIG" "$REPO_ROOT" "$REPO_USER" "$PHP_USER" <<'PY'
import json, os, sys, tempfile
path, root, user, php_user = sys.argv[1:]
data = {"version": 2, "repo_root": root, "repo_user": user, "php_user": php_user}
fd, tmp = tempfile.mkstemp(prefix="updater-", dir=os.path.dirname(path), text=True)
try:
    with os.fdopen(fd, "w") as f:
        json.dump(data, f, indent=2)
        f.write("\n")
    os.chmod(tmp, 0o644)
    os.replace(tmp, path)
finally:
    if os.path.exists(tmp): os.unlink(tmp)
PY
chown root:root "$CONFIG"
chmod 0644 "$CONFIG"

printf '%s ALL=(root) NOPASSWD: %s check, %s apply\n' "$PHP_USER" "$TARGET" "$TARGET" > "$SUDOERS"
chmod 0440 "$SUDOERS"
if command -v visudo >/dev/null 2>&1; then visudo -cf "$SUDOERS" >/dev/null; fi

"$TARGET" check

echo
echo "OK: ArcadeCloud Updater instalado."
echo "Repo: $REPO_ROOT"
echo "Repo user: $REPO_USER"
echo "PHP-FPM user: $PHP_USER"
