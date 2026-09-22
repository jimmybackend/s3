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
SKIP_CERTBOT=0

for arg in "$@"; do
  case "$arg" in
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --skip-certbot) SKIP_CERTBOT=1 ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
[[ -n "$APP_ROOT" ]] || APP_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
APP_ROOT="$(realpath "$APP_ROOT")"
WEBROOT="$APP_ROOT/drive"
FPM_POOL="/etc/php-fpm.d/arcadecloud-drive.conf"
NGINX_CONF="/etc/nginx/conf.d/arcadecloud-drive.conf"
FPM_LISTEN="127.0.0.1:9075"

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

[[ -r /etc/os-release ]] || fail "no puedo identificar la distribución Linux."
# shellcheck disable=SC1091
source /etc/os-release

if [[ "${ID:-}" != "amzn" || "${VERSION_ID:-}" != "2023" ]]; then
  fail "la instalación automática de paquetes está soportada actualmente en Amazon Linux 2023. Detectado: ${PRETTY_NAME:-desconocido}"
fi

command_exists dnf || fail "Amazon Linux 2023 no tiene dnf disponible."

package_available() {
  dnf -q list --available "$1" >/dev/null 2>&1 || rpm -q "$1" >/dev/null 2>&1
}

install_packages() {
  local required=(
    curl
    git
    nginx
    php
    php-cli
    php-fpm
    php-mysqlnd
    php-mbstring
    php-xml
    php-gd
    php-process
    python3
    tar
    unzip
  )
  local optional=(
    php-opcache
  )
  local wanted=()
  local pkg

  for pkg in "${required[@]}"; do
    if ! rpm -q "$pkg" >/dev/null 2>&1; then
      if package_available "$pkg"; then
        wanted+=("$pkg")
      else
        fail "paquete requerido no disponible en los repositorios configurados: $pkg"
      fi
    fi
  done

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
}

install_composer() {
  if command_exists composer; then
    say "Composer ya instalado: $(composer --version 2>/dev/null | head -1)"
    return
  fi

  if package_available composer; then
    say "Instalando Composer desde repositorio del sistema."
    dnf install -y composer
  else
    say "Composer no está empaquetado; usando el instalador oficial con verificación SHA-384."
    local tmp_dir expected actual
    tmp_dir="$(mktemp -d)"
    trap 'rm -rf "$tmp_dir"' RETURN

    curl -fsSL https://composer.github.io/installer.sig -o "$tmp_dir/installer.sig"
    curl -fsSL https://getcomposer.org/installer -o "$tmp_dir/composer-setup.php"

    expected="$(tr -d '[:space:]' < "$tmp_dir/installer.sig")"
    actual="$(php -r "echo hash_file('sha384', '$tmp_dir/composer-setup.php');")"
    [[ -n "$expected" && "$expected" == "$actual" ]] || fail "la firma SHA-384 del instalador de Composer no coincide."

    php "$tmp_dir/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -rf "$tmp_dir"
    trap - RETURN
  fi

  command_exists composer || fail "Composer no quedó instalado."
  composer --version >/dev/null
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

choose_php_user() {
  if [[ -n "$PHP_USER" ]]; then
    id "$PHP_USER" >/dev/null 2>&1 || fail "el usuario PHP-FPM indicado no existe: $PHP_USER"
    return
  fi

  PHP_USER="$(configured_pool_user "$FPM_POOL" || true)"
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

configure_php_fpm() {
  choose_php_user
  local php_group
  php_group="$(id -gn "$PHP_USER")"

  if grep -RqsE '^[[:space:]]*listen[[:space:]]*=[[:space:]]*127\.0\.0\.1:9075([[:space:]]|$)' /etc/php-fpm.d 2>/dev/null       && [[ ! -f "$FPM_POOL" ]]; then
    fail "127.0.0.1:9075 ya está asignado a otro pool PHP-FPM; no se sobrescribirá."
  fi

  cat > "$FPM_POOL" <<EOF
; Managed by ArcadeCloud installer.
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

  chmod 0644 "$FPM_POOL"
  php-fpm -t >/dev/null

  systemctl enable php-fpm.service >/dev/null
  if systemctl is-active --quiet php-fpm.service; then
    systemctl reload php-fpm.service || systemctl restart php-fpm.service
  else
    systemctl start php-fpm.service
  fi

  systemctl is-active --quiet php-fpm.service || fail "php-fpm.service no quedó activo."
  say "PHP-FPM configurado para ArcadeCloud en $FPM_LISTEN como $PHP_USER:$php_group."
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

  if [[ -e "$NGINX_CONF" ]] && ! grep -q '^# Managed by ArcadeCloud installer\.$' "$NGINX_CONF"; then
    fail "$NGINX_CONF ya existe y no está administrado por ArcadeCloud; no se sobrescribirá."
  fi

  if [[ -e "$NGINX_CONF" ]]; then
    backup="$(mktemp)"
    cp -a "$NGINX_CONF" "$backup"
  fi

  cat > "$NGINX_CONF" <<EOF
# Managed by ArcadeCloud installer.
server {
    listen 80;
    listen [::]:80;
    server_name $server_name;

    root $WEBROOT;
    index index.php index.html;
    client_max_body_size 32m;

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

  nginx -t >/dev/null
  systemctl is-active --quiet php-fpm.service || fail "PHP-FPM no está activo."
  systemctl is-active --quiet nginx.service || fail "Nginx no está activo."
}

say "Preflight automático de Amazon Linux 2023."
install_packages
install_composer
install_certbot_if_available
configure_php_fpm
configure_nginx
validate_runtime

echo
echo "PREPARACIÓN DEL SERVIDOR: OK"
echo "PHP: $(php -r 'echo PHP_VERSION;')"
echo "PHP-FPM user: $PHP_USER"
echo "PHP-FPM listen: $FPM_LISTEN"
echo "Nginx: $(nginx -v 2>&1)"
echo "Composer: $(composer --version 2>/dev/null | head -1)"
if command_exists certbot; then
  echo "Certbot: $(certbot --version 2>/dev/null)"
else
  echo "Certbot: pendiente/no disponible"
fi
