#!/usr/bin/env bash
set -euo pipefail

# ArcadeCloud Drive uninstaller
# Removes local resources managed by ArcadeCloud.
# It NEVER deletes remote MySQL data or S3 objects/buckets.

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta este desinstalador con sudo/root." >&2
  exit 1
fi

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
APP_ROOT="$(realpath "$APP_ROOT")"
DRY_RUN=0
ASSUME_YES=0
KEEP_REPOSITORY=0
KEEP_CONFIG=0
KEEP_IP_CERTIFICATE=0

for arg in "$@"; do
  case "$arg" in
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --dry-run) DRY_RUN=1 ;;
    --yes) ASSUME_YES=1 ;;
    --keep-repository) KEEP_REPOSITORY=1 ;;
    --keep-config) KEEP_CONFIG=1 ;;
    --keep-ip-certificate) KEEP_IP_CERTIFICATE=1 ;;
    --help|-h)
      cat <<'EOF'
Uso:
  sudo bash drive/bin/uninstall_arcadecloud.sh [opciones]

Opciones:
  --dry-run               Muestra qué se eliminaría sin modificar el servidor.
  --yes                   No solicita confirmación interactiva.
  --app-root=RUTA         Checkout ArcadeCloud a eliminar.
  --keep-repository       Conserva el checkout Git.
  --keep-config           Conserva /etc/arcadecloud-drive.
  --keep-ip-certificate   Conserva el certificado IP gestionado por ArcadeCloud.

No elimina datos remotos de MySQL, buckets/objetos S3 ni paquetes compartidos
del sistema como PHP, Nginx, Git, Composer o curl.
EOF
      exit 0
      ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

APP_ROOT="$(realpath -m "$APP_ROOT")"

case "$APP_ROOT" in
  /|/var|/var/www|/home|/root|/usr|/opt)
    echo "ERROR: APP_ROOT demasiado amplio o peligroso: $APP_ROOT" >&2
    exit 2
    ;;
esac

say() {
  printf '%s\n' "$*"
}

run() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    printf '[DRY-RUN]'
    printf ' %q' "$@"
    printf '\n'
    return 0
  fi
  "$@"
}

run_quiet() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    run "$@"
    return 0
  fi
  "$@" >/dev/null 2>&1
}

remove_path() {
  local path="$1"
  [[ -e "$path" || -L "$path" ]] || return 0
  run rm -rf -- "$path"
}

remove_file_if_contains() {
  local path="$1" marker="$2"
  [[ -f "$path" ]] || return 0
  if grep -Fq "$marker" "$path"; then
    run rm -f -- "$path"
  else
    say "PRESERVADO: $path no parece administrado por ArcadeCloud."
  fi
}

stop_unit() {
  local unit="$1"
  if systemctl cat "$unit" >/dev/null 2>&1 || [[ -e "/etc/systemd/system/$unit" ]]; then
    run_quiet systemctl disable --now "$unit" || true
    run_quiet systemctl stop "$unit" || true
  fi
}

remove_arcadecloud_unit() {
  local unit="$1" path="/etc/systemd/system/$1"
  stop_unit "$unit"
  [[ -f "$path" ]] || return 0
  if grep -Fqi "ArcadeCloud" "$path"; then
    run rm -f -- "$path"
  else
    say "PRESERVADO: $path no contiene la marca ArcadeCloud."
  fi
}

read_ip_certificate_name() {
  local state="/var/lib/arcadecloud-drive/federation-https-state.json"
  [[ -r "$state" ]] || return 0
  python3 - "$state" <<'PY' 2>/dev/null || true
import ipaddress, json, sys
try:
    data=json.load(open(sys.argv[1], encoding="utf-8"))
    if data.get("mode") != "ip":
        raise SystemExit(0)
    host=str(data.get("host","")).strip()
    ipaddress.ip_address(host)
    print(host)
except Exception:
    pass
PY
}

delete_managed_ip_certificate() {
  [[ "$KEEP_IP_CERTIFICATE" -eq 0 ]] || {
    say "PRESERVADO: certificado IP por --keep-ip-certificate."
    return 0
  }

  local cert_name certbot=""
  cert_name="$(read_ip_certificate_name)"
  [[ -n "$cert_name" ]] || return 0

  if [[ -x /usr/local/bin/arcadecloud-certbot ]]; then
    certbot=/usr/local/bin/arcadecloud-certbot
  elif command -v certbot >/dev/null 2>&1; then
    certbot="$(command -v certbot)"
  fi

  if [[ -n "$certbot" ]]; then
    if [[ "$DRY_RUN" -eq 1 ]]; then
      printf '[DRY-RUN] %q delete --non-interactive --cert-name %q\n' "$certbot" "$cert_name"
    else
      "$certbot" delete --non-interactive --cert-name "$cert_name" >/dev/null 2>&1 ||         say "AVISO: no se pudo borrar automáticamente el certificado IP $cert_name."
    fi
  else
    say "AVISO: existe estado de certificado IP $cert_name pero Certbot no está disponible; se conserva /etc/letsencrypt."
  fi
}

say "ArcadeCloud Drive — desinstalador"
say "App root: $APP_ROOT"
say
say "Se eliminarán recursos locales administrados por ArcadeCloud."
say "NO se eliminarán: MySQL remoto, datos/tablas remotas, buckets u objetos S3,"
say "ni paquetes compartidos del sistema."
say

if [[ "$DRY_RUN" -eq 0 && "$ASSUME_YES" -eq 0 ]]; then
  read -r -p 'Escribe DESINSTALAR para continuar: ' confirmation
  [[ "$confirmation" == "DESINSTALAR" ]] || {
    echo "Cancelado."
    exit 0
  }
fi

# Capture certificate state before deleting /var/lib or /etc.
delete_managed_ip_certificate

units=(
  arcadecloud-media-worker.service
  arcadecloud-media-node-bootstrap.service
  arcadecloud-federation-drop-cleanup.timer
  arcadecloud-federation-drop-cleanup.service
  arcadecloud-federation-sync.timer
  arcadecloud-federation-sync.service
  arcadecloud-federation-migrate.service
  arcadecloud-federation-https.timer
  arcadecloud-federation-https.service
  arcadecloud-polly-reconcile.timer
  arcadecloud-polly-reconcile.service
  arcadecloud-transcribe-reconcile.timer
  arcadecloud-transcribe-reconcile.service
  arcadecloud-drive-node-sync.service
  arcadecloud-drive-sync-migrate.service
)
for unit in "${units[@]}"; do
  remove_arcadecloud_unit "$unit"
done

# php-fpm-drive is removed only when the installer marker proves ownership.
if [[ -f /etc/systemd/system/php-fpm-drive.service ]] &&    grep -Fq "Managed by ArcadeCloud installer." /etc/systemd/system/php-fpm-drive.service; then
  stop_unit php-fpm-drive.service
  run rm -f /etc/systemd/system/php-fpm-drive.service
else
  [[ ! -f /etc/systemd/system/php-fpm-drive.service ]] ||     say "PRESERVADO: php-fpm-drive.service no está marcado como creado por ArcadeCloud."
fi

remove_file_if_contains /etc/php-fpm-drive.conf "Managed by ArcadeCloud installer."
remove_file_if_contains /etc/php-fpm-drive.d/arcadecloud-drive.conf "Managed by ArcadeCloud installer."
if [[ -d /etc/php-fpm-drive.d ]] && [[ -z "$(find /etc/php-fpm-drive.d -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]]; then
  run rmdir /etc/php-fpm-drive.d || true
fi

remove_file_if_contains /etc/nginx/conf.d/arcadecloud-drive.conf "Managed by ArcadeCloud installer."
remove_file_if_contains /etc/nginx/conf.d/arcadecloud-federation-ip.conf "Managed by ArcadeCloud FederationCloud HTTPS."

remove_path /usr/local/sbin/arcadecloud-drive-admin
remove_path /usr/local/sbin/arcadecloud-drive-updater
remove_path /etc/sudoers.d/arcadecloud-drive-admin
remove_path /etc/sudoers.d/arcadecloud-drive-updater

if [[ -L /usr/local/bin/arcadecloud-certbot ]]; then
  run rm -f /usr/local/bin/arcadecloud-certbot
elif [[ -e /usr/local/bin/arcadecloud-certbot ]]; then
  say "PRESERVADO: /usr/local/bin/arcadecloud-certbot no es un enlace simbólico."
fi
remove_path /opt/arcadecloud-certbot

if [[ "$KEEP_CONFIG" -eq 0 ]]; then
  remove_path /etc/arcadecloud-drive
else
  say "PRESERVADO: /etc/arcadecloud-drive por --keep-config."
fi

remove_path /var/lib/arcadecloud-drive
remove_path /var/lib/arcadecloud-media
remove_path /var/log/php-fpm-drive

# Runtime caches created by ArcadeCloud only.
remove_path /tmp/arcadecloud-federation-sync.lock
remove_path /tmp/arcadecloud-drive-login-rate
remove_path /tmp/arcadecloud-drive-move-jobs
remove_path /tmp/arcadecloud-drive-thumbnails
remove_path /tmp/arcadecloud-drive-cost-explorer-cache

run systemctl daemon-reload
run_quiet systemctl reset-failed || true

if command -v nginx >/dev/null 2>&1; then
  if [[ "$DRY_RUN" -eq 1 ]]; then
    say "[DRY-RUN] nginx -t && systemctl reload nginx"
  elif nginx -t >/dev/null 2>&1; then
    systemctl is-active --quiet nginx && systemctl reload nginx || true
  else
    say "AVISO: Nginx no pasó nginx -t después de retirar ArcadeCloud; no se recargó." >&2
  fi
fi

if [[ "$KEEP_REPOSITORY" -eq 0 ]]; then
  if [[ -d "$APP_ROOT/.git" && -d "$APP_ROOT/drive" ]]; then
    case "$APP_ROOT" in
      /var/www/arcadecloud-drive|/opt/arcadecloud-drive|/srv/arcadecloud-drive|/home/*/arcadecloud-drive)
        if [[ "$DRY_RUN" -eq 1 ]]; then
          printf '[DRY-RUN] rm -rf -- %q\n' "$APP_ROOT"
        else
          cd /
          rm -rf -- "$APP_ROOT"
        fi
        ;;
      *)
        say "PRESERVADO: checkout en ruta no estándar ($APP_ROOT). Usa --app-root con una ruta ArcadeCloud permitida o elimínalo manualmente."
        ;;
    esac
  else
    say "PRESERVADO: $APP_ROOT no parece un checkout ArcadeCloud válido."
  fi
else
  say "PRESERVADO: repositorio por --keep-repository."
fi

say
if [[ "$DRY_RUN" -eq 1 ]]; then
  say "DRY-RUN terminado: no se modificó el servidor."
else
  say "DESINSTALACIÓN LOCAL COMPLETADA."
  say "Los datos externos de MySQL y S3 permanecen intactos."
  say "El nodo dejará de aparecer como activo en FederationCloud al vencer la ventana de presencia."
fi