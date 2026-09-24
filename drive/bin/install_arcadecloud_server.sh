#!/usr/bin/env bash
set -euo pipefail

# Prepara el sistema operativo para ArcadeCloud Drive.
# Es idempotente y no modifica credenciales de aplicación.
# Automatización soportada actualmente: Amazon Linux 2023.

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este preparador con sudo/root." >&2
  exit 1
fi

APP_ROOT=""
PHP_USER=""
NODE_ROLE="web"
SKIP_CERTBOT=0

for arg in "$@"; do
  case "$arg" in
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --node-role=*) NODE_ROLE="${arg#*=}" ;;
    --skip-certbot) SKIP_CERTBOT=1 ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
[[ -n "$APP_ROOT" ]] || APP_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
APP_ROOT="$(realpath "$APP_ROOT")"
WEBROOT="$APP_ROOT/drive"

FPM_MASTER="/etc/php-fpm-drive.conf"
FPM_POOL_DIR="/etc/php-fpm-drive.d"
FPM_POOL="$FPM_POOL_DIR/arcadecloud-drive.conf"
FPM_SERVICE="/etc/systemd/system/php-fpm-drive.service"
FPM_LISTEN="127.0.0.1:9075"

NGINX_CONF="/etc/nginx/conf.d/arcadecloud-drive.conf"
MANAGED_MARKER="Managed by ArcadeCloud installer."
CERTBOT_VENV="/opt/arcadecloud-certbot"
ARCADECLOUD_CERTBOT_BIN="/usr/local/bin/arcadecloud-certbot"

fail() {
  echo "ERROR: $*" >&2
  exit 1
}

say() {
  printf '==> %s\n' "$*"
}

command_exists() {
  command -v "$1" >/dev/null 2>&1
}

is_managed_file() {
  local path="$1"
  [[ -r "$path" ]] && grep -Fq "$MANAGED_MARKER" "$path"
}

[[ -r /etc/os-release ]] || fail "no puedo identificar la distribución Linux."
# shellcheck disable=SC1091
source /etc/os-release

if [[ "${ID:-}" != "amzn" || "${VERSION_ID:-}" != "2023" ]]; then
  fail "la instalación automática de paquetes está soportada actualmente en Amazon Linux 2023. Detectado: ${PRETTY_NAME:-desconocido}"
fi

command_exists dnf || fail "Amazon Linux 2023 no tiene dnf disponible."

case "$NODE_ROLE" in
  web|media-worker|combined) ;;
  *) fail "--node-role debe ser web, media-worker o combined." ;;
esac

package_available() {
  dnf -q list --available "$1" >/dev/null 2>&1 || rpm -q "$1" >/dev/null 2>&1
}

detect_php_package_family() {
  local installed_pkg family candidate

  if command_exists php; then
    installed_pkg="$(rpm -qf "$(command -v php)" --qf '%{NAME}\n' 2>/dev/null | head -1 || true)"
    if [[ "$installed_pkg" =~ ^(php8\.[1-9])(-|$) ]]; then
      family="${BASH_REMATCH[1]}"
      if package_available "$family-fpm"; then
        printf '%s' "$family"
        return 0
      fi
    fi
  fi

  for candidate in php8.5 php8.4 php8.3 php8.2 php8.1; do
    if package_available "$candidate" && package_available "$candidate-fpm"; then
      printf '%s' "$candidate"
      return 0
    fi
  done

  return 1
}

install_packages() {
  local php_family
  php_family="$(detect_php_package_family || true)"
  [[ -n "$php_family" ]] || fail "no encontré una familia PHP soportada (php8.1..php8.5) en los repositorios configurados."

  say "Familia PHP seleccionada automáticamente: $php_family"

  local wanted=()
  local pkg command_name package_name

  # Para herramientas del sistema validamos primero el comando, no el nombre
  # exacto del RPM. AL2023, por ejemplo, trae curl desde curl-minimal.
  local command_packages=(
    "curl:curl"
    "git:git"
    "nginx:nginx"
    "python3:python3"
    "tar:tar"
    "unzip:unzip"
    "sudo:sudo"
    "runuser:util-linux"
    "flock:util-linux"
    "timeout:coreutils"
    "ps:procps-ng"
    "pgrep:procps-ng"
    "find:findutils"
    "awk:gawk"
    "grep:grep"
    "sed:sed"
    "openssl:openssl"
  )

  for pkg in "${command_packages[@]}"; do
    command_name="${pkg%%:*}"
    package_name="${pkg#*:}"
    if command_exists "$command_name"; then
      say "$command_name ya disponible: $(command -v "$command_name")"
      continue
    fi
    package_available "$package_name" || fail "paquete requerido no disponible en los repositorios configurados: $package_name"
    wanted+=("$package_name")
  done

  local php_required=(
    "$php_family"
    "$php_family-cli"
    "$php_family-fpm"
    "$php_family-mysqlnd"
    "$php_family-mbstring"
    "$php_family-xml"
    "$php_family-gd"
    "$php_family-process"
  )

  for pkg in "${php_required[@]}"; do
    if ! rpm -q "$pkg" >/dev/null 2>&1; then
      package_available "$pkg" || fail "paquete requerido no disponible en los repositorios configurados: $pkg"
      wanted+=("$pkg")
    fi
  done

  local optional=("$php_family-opcache")
  for pkg in "${optional[@]}"; do
    if ! rpm -q "$pkg" >/dev/null 2>&1 && package_available "$pkg"; then
      wanted+=("$pkg")
    fi
  done

  if (( ${#wanted[@]} > 0 )); then
    say "Instalando dependencias faltantes: ${wanted[*]}"
    dnf install -y "${wanted[@]}"
  else
    say "Dependencias base del sistema ya instaladas."
  fi

  command_exists php || fail "la familia $php_family se instaló pero no expuso el comando php."
  command_exists php-fpm || fail "la familia $php_family se instaló pero no expuso el comando php-fpm."
}

install_media_dependencies() {
  [[ "$NODE_ROLE" == "media-worker" || "$NODE_ROLE" == "combined" ]] || return 0

  say "Preparando dependencias multimedia para rol $NODE_ROLE."

  if ! rpm -q spal-release >/dev/null 2>&1; then
    package_available spal-release       || fail "spal-release no está disponible. Actualiza system-release de Amazon Linux 2023 a una versión con SPAL y vuelve a ejecutar el instalador."
    dnf install -y spal-release
  fi

  local media_packages=(ffmpeg-free lame lame-libs)
  local missing=()
  local pkg
  for pkg in "${media_packages[@]}"; do
    if ! rpm -q "$pkg" >/dev/null 2>&1; then
      package_available "$pkg"         || fail "paquete multimedia requerido no disponible en SPAL: $pkg"
      missing+=("$pkg")
    fi
  done

  if (( ${#missing[@]} > 0 )); then
    dnf install -y "${missing[@]}"
  fi

  command_exists ffmpeg || fail "ffmpeg no quedó disponible después de instalar ffmpeg-free."
  command_exists ffprobe || fail "ffprobe no quedó disponible después de instalar ffmpeg-free."

  if ! ffmpeg -hide_banner -encoders 2>/dev/null | grep -qE '(^|[[:space:]])libmp3lame([[:space:]]|$)'; then
    fail "FFmpeg quedó instalado pero no expone el encoder libmp3lame requerido para Extraer MP3."
  fi

  say "Multimedia listo: $(ffmpeg -version 2>/dev/null | head -1)"
}

install_composer() {
  if command_exists composer; then
    say "Composer ya instalado: $(COMPOSER_ALLOW_SUPERUSER=1 composer --version 2>/dev/null | head -1)"
    return
  fi

  if package_available composer; then
    say "Instalando Composer desde repositorio del sistema."
    dnf install -y composer
  else
    say "Composer no está empaquetado; usando el instalador oficial con verificación SHA-384."
    local tmp_dir expected actual
    tmp_dir="$(mktemp -d)"

    curl -fsSL https://composer.github.io/installer.sig -o "$tmp_dir/installer.sig"
    curl -fsSL https://getcomposer.org/installer -o "$tmp_dir/composer-setup.php"

    expected="$(tr -d '[:space:]' < "$tmp_dir/installer.sig")"
    actual="$(php -r "echo hash_file('sha384', '$tmp_dir/composer-setup.php');")"
    if [[ -z "$expected" || "$expected" != "$actual" ]]; then
      rm -rf "$tmp_dir"
      fail "la firma SHA-384 del instalador de Composer no coincide."
    fi

    php "$tmp_dir/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -rf "$tmp_dir"
  fi

  command_exists composer || fail "Composer no quedó instalado."
  COMPOSER_ALLOW_SUPERUSER=1 composer --version >/dev/null
}

install_certbot_if_available() {
  [[ "$SKIP_CERTBOT" -eq 0 ]] || return 0

  if command_exists certbot; then
    say "Certbot ya está instalado."
    return
  fi

  local packages=()
  package_available certbot && packages+=(certbot)
  package_available python3-certbot-nginx && packages+=(python3-certbot-nginx)

  if (( ${#packages[@]} > 0 )); then
    say "Instalando Certbot disponible en los repositorios."
    dnf install -y "${packages[@]}"
  else
    echo "ADVERTENCIA: Certbot no está disponible en los repositorios configurados; HTTPS quedará pendiente." >&2
  fi
}

certbot_version_at_least_54() {
  local bin="${1:-}"
  [[ -n "$bin" && -x "$bin" ]] || return 1
  local version
  version="$("$bin" --version 2>/dev/null || true)"
  [[ "$version" =~ certbot[[:space:]]+([0-9]+)\.([0-9]+) ]] || return 1
  local major="${BASH_REMATCH[1]}" minor="${BASH_REMATCH[2]}"
  (( major > 5 || (major == 5 && minor >= 4) ))
}

python_version_at_least_310() {
  local bin="${1:-}"
  [[ -n "$bin" ]] || return 1
  "$bin" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 10) else 1)' >/dev/null 2>&1
}

select_certbot_python() {
  local candidate

  # /usr/bin/python3 is intentionally Python 3.9 for the lifetime of AL2023.
  # Use a namespaced newer Python for ArcadeCloud's isolated Certbot venv.
  for candidate in python3.11 python3.12 python3.13 python3.14; do
    if command_exists "$candidate" && python_version_at_least_310 "$(command -v "$candidate")"; then
      command -v "$candidate"
      return 0
    fi
  done

  for candidate in python3.11 python3.12 python3.13 python3.14; do
    if package_available "$candidate"; then
      local packages=("$candidate")
      package_available "$candidate-pip" && packages+=("$candidate-pip")
      say "Instalando $candidate aislado para Certbot moderno." >&2
      dnf install -y "${packages[@]}" >&2

      if command_exists "$candidate" && python_version_at_least_310 "$(command -v "$candidate")"; then
        command -v "$candidate"
        return 0
      fi
    fi
  done

  fail "Certbot >= 5.4 requiere Python >= 3.10 y no pude obtener un intérprete compatible en Amazon Linux 2023."
}

install_modern_certbot_for_ip() {
  [[ "$SKIP_CERTBOT" -eq 0 ]] || return 0

  local current=""
  if [[ -x "$ARCADECLOUD_CERTBOT_BIN" ]]; then
    current="$ARCADECLOUD_CERTBOT_BIN"
  elif command_exists certbot; then
    current="$(command -v certbot)"
  fi

  if certbot_version_at_least_54 "$current"; then
    say "Certbot compatible con certificados IP: $("$current" --version 2>/dev/null)"
    if [[ "$current" != "$ARCADECLOUD_CERTBOT_BIN" ]]; then
      ln -sfn "$current" "$ARCADECLOUD_CERTBOT_BIN"
    fi
    return 0
  fi

  local certbot_python
  certbot_python="$(select_certbot_python)"
  say "Preparando Certbot >= 5.4 en entorno aislado con $certbot_python."

  rm -rf "$CERTBOT_VENV"
  "$certbot_python" -m venv "$CERTBOT_VENV" >/dev/null 2>&1 \
    || fail "$certbot_python no pudo crear $CERTBOT_VENV para instalar Certbot moderno."

  "$CERTBOT_VENV/bin/python" -m pip install --upgrade pip >/dev/null
  "$CERTBOT_VENV/bin/python" -m pip install --upgrade 'certbot>=5.4' 'certbot-nginx>=5.4' >/dev/null
  certbot_version_at_least_54 "$CERTBOT_VENV/bin/certbot"     || fail "Certbot moderno se instaló pero no cumple la versión mínima 5.4."

  ln -sfn "$CERTBOT_VENV/bin/certbot" "$ARCADECLOUD_CERTBOT_BIN"
  say "Certbot para ArcadeCloud: $("$ARCADECLOUD_CERTBOT_BIN" --version 2>/dev/null)"
}

configured_pool_user() {
  local conf="$1"
  [[ -r "$conf" ]] || return 1
  awk -F= '
    /^[[:space:]]*user[[:space:]]*=/ {
      gsub(/[[:space:]]/, "", $2);
      if ($2 != "") { print $2; exit }
    }
  ' "$conf"
}

expanded_drive_fpm() {
  [[ -r "$FPM_MASTER" ]] || return 1
  php-fpm -tt -y "$FPM_MASTER" 2>&1 || true
}

existing_drive_user() {
  expanded_drive_fpm | awk '
    match($0, /(^|[[:space:]])user[[:space:]]*=[[:space:]]*[^[:space:];]+/) {
      value = substr($0, RSTART, RLENGTH)
      sub(/^.*user[[:space:]]*=[[:space:]]*/, "", value)
      sub(/[[:space:];].*$/, "", value)
      print value
      exit
    }
  '
}

existing_drive_listen() {
  expanded_drive_fpm | awk '
    match($0, /(^|[[:space:]])listen[[:space:]]*=[[:space:]]*[^[:space:];]+/) {
      value = substr($0, RSTART, RLENGTH)
      sub(/^.*listen[[:space:]]*=[[:space:]]*/, "", value)
      sub(/[[:space:];].*$/, "", value)
      print value
      exit
    }
  '
}

choose_php_user() {
  if [[ -n "$PHP_USER" ]]; then
    id "$PHP_USER" >/dev/null 2>&1 || fail "el usuario PHP-FPM indicado no existe: $PHP_USER"
    [[ "$PHP_USER" != "root" ]] || fail "PHP-FPM no puede ejecutarse como root."
    return
  fi

  if [[ -r "$FPM_MASTER" ]]; then
    PHP_USER="$(existing_drive_user || true)"
  fi
  [[ -n "$PHP_USER" ]] || PHP_USER="$(configured_pool_user /etc/php-fpm.d/www.conf || true)"

  if [[ -z "$PHP_USER" ]] && id nginx >/dev/null 2>&1; then
    PHP_USER="nginx"
  fi
  if [[ -z "$PHP_USER" ]] && id apache >/dev/null 2>&1; then
    PHP_USER="apache"
  fi

  [[ -n "$PHP_USER" ]] || fail "no pude determinar un usuario no-root para PHP-FPM."
  [[ "$PHP_USER" != "root" ]] || fail "PHP-FPM no puede ejecutarse como root."
  id "$PHP_USER" >/dev/null 2>&1 || fail "el usuario PHP-FPM no existe: $PHP_USER"
}

validate_existing_drive_fpm() {
  [[ -r "$FPM_MASTER" ]] || return 1
  systemctl cat php-fpm-drive.service >/dev/null 2>&1 || return 1

  local test_output listen existing_user
  test_output="$(php-fpm -t -y "$FPM_MASTER" 2>&1)" || {
    printf '%s\n' "$test_output" >&2
    fail "la configuración existente de php-fpm-drive no es válida."
  }

  listen="$(existing_drive_listen || true)"
  [[ "$listen" == "$FPM_LISTEN" ]]     || fail "php-fpm-drive existente escucha en '$listen'; ArcadeCloud espera $FPM_LISTEN y no lo sobrescribirá."

  existing_user="$(existing_drive_user || true)"
  [[ -n "$existing_user" ]] || fail "no pude determinar el usuario del php-fpm-drive existente."
  [[ "$existing_user" != "root" ]] || fail "php-fpm-drive existente no puede ejecutar como root."

  if [[ -n "$PHP_USER" && "$PHP_USER" != "$existing_user" ]]; then
    fail "--php-user=$PHP_USER no coincide con el php-fpm-drive existente ($existing_user)."
  fi
  PHP_USER="$existing_user"

  systemctl enable --now php-fpm-drive.service >/dev/null
  systemctl is-active --quiet php-fpm-drive.service || fail "php-fpm-drive.service no quedó activo."
  say "php-fpm-drive existente validado y conservado ($PHP_USER, $FPM_LISTEN)."
}

configure_php_fpm() {
  if [[ -e "$FPM_MASTER" || -e "$FPM_SERVICE" ]]; then
    if ! is_managed_file "$FPM_MASTER" || ! is_managed_file "$FPM_SERVICE"; then
      if [[ ! -r "$FPM_MASTER" ]] || ! systemctl cat php-fpm-drive.service >/dev/null 2>&1; then
        fail "se encontró una configuración php-fpm-drive parcial/no administrada; no se sobrescribirá."
      fi
      validate_existing_drive_fpm
      return
    fi
  fi

  choose_php_user
  local php_group
  php_group="$(id -gn "$PHP_USER")"

  install -d -m 0755 "$FPM_POOL_DIR"
  install -d -m 0755 /var/log/php-fpm-drive

  cat > "$FPM_MASTER" <<EOF
; $MANAGED_MARKER
[global]
pid = /run/php-fpm-drive/php-fpm.pid
error_log = /var/log/php-fpm-drive/error.log
daemonize = no
include = $FPM_POOL_DIR/*.conf
EOF

  cat > "$FPM_POOL" <<EOF
; $MANAGED_MARKER
[arcadecloud-drive]
user = $PHP_USER
group = $php_group
listen = $FPM_LISTEN
listen.allowed_clients = 127.0.0.1
pm = ondemand
pm.max_children = 12
pm.process_idle_timeout = 20s
pm.max_requests = 500
catch_workers_output = yes
clear_env = no
EOF

  cat > "$FPM_SERVICE" <<EOF
# $MANAGED_MARKER
[Unit]
Description=ArcadeCloud Drive PHP FastCGI Process Manager
After=network.target

[Service]
Type=simple
ExecStart=/usr/sbin/php-fpm --nodaemonize --fpm-config $FPM_MASTER
ExecReload=/bin/kill -USR2 \$MAINPID
PrivateTmp=true
RuntimeDirectory=php-fpm-drive
RuntimeDirectoryMode=0755
Restart=on-failure

[Install]
WantedBy=multi-user.target
EOF

  chmod 0644 "$FPM_MASTER" "$FPM_POOL" "$FPM_SERVICE"

  local test_output
  test_output="$(php-fpm -t -y "$FPM_MASTER" 2>&1)" || {
    printf '%s\n' "$test_output" >&2
    fail "la configuración php-fpm-drive generada no pasó la validación."
  }

  systemctl daemon-reload
  systemctl enable php-fpm-drive.service >/dev/null
  if systemctl is-active --quiet php-fpm-drive.service; then
    systemctl restart php-fpm-drive.service
  else
    systemctl start php-fpm-drive.service
  fi

  systemctl is-active --quiet php-fpm-drive.service || fail "php-fpm-drive.service no quedó activo."
  say "php-fpm-drive configurado en $FPM_LISTEN como $PHP_USER:$php_group."
}

detect_public_ipv4() {
  local token ip
  token="$(curl -fsS --max-time 2 -X PUT     -H 'X-aws-ec2-metadata-token-ttl-seconds: 60'     http://169.254.169.254/latest/api/token 2>/dev/null || true)"
  if [[ -n "$token" ]]; then
    ip="$(curl -fsS --max-time 2       -H "X-aws-ec2-metadata-token: $token"       http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null || true)"
    [[ -n "$ip" ]] && { printf '%s' "$ip"; return 0; }
  fi

  curl -4fsS --max-time 4 https://checkip.amazonaws.com 2>/dev/null | tr -d '[:space:]' || true
}

configure_nginx() {
  [[ -d "$WEBROOT" ]] || fail "no existe el DocumentRoot esperado: $WEBROOT"

  local public_ip server_name backup=""
  public_ip="$(detect_public_ipv4)"
  server_name="${public_ip:-_}"

  if [[ -e "$NGINX_CONF" ]] && ! is_managed_file "$NGINX_CONF"; then
    fail "$NGINX_CONF ya existe y no está administrado por ArcadeCloud; no se sobrescribirá."
  fi

  if [[ -e "$NGINX_CONF" ]]; then
    backup="$(mktemp)"
    cp -a "$NGINX_CONF" "$backup"
  fi

  cat > "$NGINX_CONF" <<EOF
# $MANAGED_MARKER
server {
    listen 80;
    listen [::]:80;
    server_name $server_name;

    root $WEBROOT;
    index index.php index.html;
    client_max_body_size 32m;

    location ^~ /.well-known/acme-challenge/ {
        try_files \$uri =404;
    }

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_pass $FPM_LISTEN;
    }

    location ^~ /src/ { deny all; }
    location ^~ /bin/ { deny all; }
    location ^~ /vendor/ { deny all; }

    location ~ /\. {
        deny all;
    }

    location ~* \.(?:env|ini|log|sql|bak)$ {
        deny all;
    }
}
EOF

  chmod 0644 "$NGINX_CONF"

  if ! nginx -t; then
    if [[ -n "$backup" ]]; then
      cp -a "$backup" "$NGINX_CONF"
    else
      rm -f "$NGINX_CONF"
    fi
    rm -f "$backup"
    fail "la configuración Nginx generada no pasó nginx -t; se restauró el estado anterior."
  fi
  rm -f "$backup"

  systemctl enable nginx.service >/dev/null
  if systemctl is-active --quiet nginx.service; then
    systemctl reload nginx.service
  else
    systemctl start nginx.service
  fi

  systemctl is-active --quiet nginx.service || fail "nginx.service no quedó activo."
  say "Nginx configurado para servir $WEBROOT por HTTP."
  [[ -n "$public_ip" ]] && say "Endpoint HTTP inicial detectado: http://$public_ip/"
}

validate_runtime() {
  local cmd
  for cmd in php php-fpm nginx composer git curl python3 systemctl sudo; do
    command_exists "$cmd" || fail "falta el comando requerido después de preparar el servidor: $cmd"
  done

  php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'     || fail "PHP quedó sin pdo_mysql/mysqlnd."
  php -r 'exit(extension_loaded("mbstring") ? 0 : 1);'     || fail "PHP quedó sin mbstring."

  if [[ "$NODE_ROLE" == "media-worker" || "$NODE_ROLE" == "combined" ]]; then
    command_exists ffmpeg || fail "rol $NODE_ROLE sin ffmpeg."
    command_exists ffprobe || fail "rol $NODE_ROLE sin ffprobe."
    ffmpeg -hide_banner -encoders 2>/dev/null | grep -qE '(^|[[:space:]])libmp3lame([[:space:]]|$)'       || fail "rol $NODE_ROLE sin encoder libmp3lame."
  fi

  nginx -t >/dev/null
  systemctl is-active --quiet php-fpm-drive.service || fail "php-fpm-drive no está activo."
  systemctl is-active --quiet nginx.service || fail "Nginx no está activo."
}

say "Preflight automático de Amazon Linux 2023."
install_packages
install_media_dependencies
install_composer
install_certbot_if_available
install_modern_certbot_for_ip
configure_php_fpm
configure_nginx
validate_runtime

echo
echo "PREPARACIÓN DEL SERVIDOR: OK"
echo "Node role: $NODE_ROLE"
echo "PHP: $(php -r 'echo PHP_VERSION;')"
echo "PHP-FPM service: php-fpm-drive.service"
echo "PHP-FPM user: $PHP_USER"
echo "PHP-FPM listen: $FPM_LISTEN"
echo "Nginx: $(nginx -v 2>&1)"
echo "Composer: $(COMPOSER_ALLOW_SUPERUSER=1 composer --version 2>/dev/null | head -1)"
if [[ "$NODE_ROLE" == "media-worker" || "$NODE_ROLE" == "combined" ]]; then
  echo "FFmpeg: $(ffmpeg -version 2>/dev/null | head -1)"
  echo "FFprobe: $(ffprobe -version 2>/dev/null | head -1)"
  echo "MP3 encoder: libmp3lame"
fi
if [[ -x "$ARCADECLOUD_CERTBOT_BIN" ]]; then
  echo "Certbot ArcadeCloud: $("$ARCADECLOUD_CERTBOT_BIN" --version 2>/dev/null)"
elif command_exists certbot; then
  echo "Certbot: $(certbot --version 2>/dev/null)"
else
  echo "Certbot: pendiente/no disponible"
fi
