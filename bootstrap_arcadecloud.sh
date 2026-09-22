#!/usr/bin/env bash
set -euo pipefail

# Bootstrap para una máquina nueva que todavía no tiene Git.
# Descarga/clona ArcadeCloud y entrega el control al instalador principal.

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este bootstrap con sudo/root." >&2
  exit 1
fi

REPO_URL="https://github.com/jimmybackend/s3.git"
BRANCH="main"
APP_ROOT="/var/www/arcadecloud-drive"
REPO_USER="${SUDO_USER:-root}"

for arg in "$@"; do
  case "$arg" in
    --repo=*) REPO_URL="${arg#*=}" ;;
    --branch=*) BRANCH="${arg#*=}" ;;
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

if ! command -v git >/dev/null 2>&1; then
  if [[ -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    source /etc/os-release
  fi
  if [[ "${ID:-}" == "amzn" && "${VERSION_ID:-}" == "2023" ]] && command -v dnf >/dev/null 2>&1; then
    echo "==> Git no está instalado; instalándolo en Amazon Linux 2023."
    dnf install -y git
  else
    echo "ERROR: Git falta y este bootstrap sólo automatiza su instalación en Amazon Linux 2023." >&2
    exit 3
  fi
fi

if [[ -e "$APP_ROOT" && ! -d "$APP_ROOT/.git" ]]; then
  echo "ERROR: $APP_ROOT ya existe pero no es un checkout Git. No se sobrescribirá." >&2
  exit 4
fi

if ! id "$REPO_USER" >/dev/null 2>&1; then
  REPO_USER="root"
fi

if [[ ! -d "$APP_ROOT/.git" ]]; then
  echo "==> Clonando ArcadeCloud ($BRANCH) en $APP_ROOT como $REPO_USER."
  install -d -m 0755 "$(dirname "$APP_ROOT")"
  if [[ "$REPO_USER" == "root" ]]; then
    git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$APP_ROOT"
  else
    install -d -o "$REPO_USER" -g "$(id -gn "$REPO_USER")" -m 0755 "$APP_ROOT"
    sudo -u "$REPO_USER" git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$APP_ROOT"
  fi
else
  echo "==> ArcadeCloud ya existe; verificando checkout antes de actualizar."
  REPO_USER="$(stat -c '%U' "$APP_ROOT")"
  cd "$APP_ROOT"
  if [[ -n "$(sudo -u "$REPO_USER" git status --porcelain)" ]]; then
    echo "ERROR: el repositorio tiene cambios locales; no se actualizará automáticamente." >&2
    sudo -u "$REPO_USER" git status --short >&2
    exit 5
  fi
  sudo -u "$REPO_USER" git fetch origin "$BRANCH"
  sudo -u "$REPO_USER" git checkout "$BRANCH"
  sudo -u "$REPO_USER" git pull --ff-only origin "$BRANCH"
fi

cd "$APP_ROOT"
echo "==> Commit a instalar:"
git log -1 --oneline

exec bash "$APP_ROOT/drive/bin/install_arcadecloud.sh" --app-root="$APP_ROOT"
