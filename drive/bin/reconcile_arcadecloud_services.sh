#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: ejecuta la reconciliación como root." >&2
  exit 1
fi

APP_ROOT="/var/www/arcadecloud-drive"
PHP_USER=""
RUNTIME_ENV="/etc/arcadecloud-drive/runtime-env.json"
DEFER_PHP_RESTART="auto"

for arg in "$@"; do
  case "$arg" in
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --php-user=*) PHP_USER="${arg#*=}" ;;
    --runtime-env=*) RUNTIME_ENV="${arg#*=}" ;;
    --defer-php-restart) DEFER_PHP_RESTART="yes" ;;
    --immediate-php-restart) DEFER_PHP_RESTART="no" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

APP_ROOT="$(realpath "$APP_ROOT")"
DRIVE_ROOT="$APP_ROOT/drive"

[[ -d "$APP_ROOT/.git" ]] || { echo "ERROR: $APP_ROOT no es un checkout ArcadeCloud." >&2; exit 2; }
[[ -f "$RUNTIME_ENV" ]] || { echo "ERROR: falta runtime administrado: $RUNTIME_ENV" >&2; exit 2; }


UPDATER_CONTEXT="no"
PARENT_CMD=""
if [[ -r "/proc/$PPID/cmdline" ]]; then
  PARENT_CMD="$(tr '\0' ' ' < "/proc/$PPID/cmdline" 2>/dev/null || true)"
fi
if [[ "$PARENT_CMD" == *"arcadecloud-drive-updater"* ]]; then
  UPDATER_CONTEXT="yes"
fi

# Compatibilidad de bootstrap: la primera actualización que recibe este arreglo
# todavía se ejecuta con el updater anterior. Detectamos ese padre para no
# reiniciar PHP-FPM dentro de la misma petición HTTP que debe devolver JSON.
if [[ "$DEFER_PHP_RESTART" == "auto" ]]; then
  if [[ "$UPDATER_CONTEXT" == "yes" ]]; then
    DEFER_PHP_RESTART="yes"
  else
    DEFER_PHP_RESTART="no"
  fi
fi

runtime_value() {
  local key="$1"
  python3 - "$RUNTIME_ENV" "$key" <<'PY' 2>/dev/null || true
import json,sys
path,key=sys.argv[1:]
try:
    data=json.load(open(path, encoding="utf-8"))
    value=data.get(key,"")
    if isinstance(value,str):
        print(value)
except Exception:
    pass
PY
}

migration_env_name_allowed() {
  case "$1" in
    DB_HOST|DB_PORT|DB_USER|DB_PASSWORD|DB_NAME|AWS_*|ARCADECLOUD_*) return 0 ;;
    *) return 1 ;;
  esac
}

declare -A MIGRATION_ENV_SOURCE=()

import_process_environment() {
  local pid="$1" entry name
  [[ "$pid" =~ ^[0-9]+$ && "$pid" -gt 1 && -r "/proc/$pid/environ" ]] || return 0
  while IFS= read -r -d '' entry; do
    name="${entry%%=*}"
    if migration_env_name_allowed "$name"; then
      export "$entry"
      MIGRATION_ENV_SOURCE["$name"]="php-fpm-process"
    fi
  done < "/proc/$pid/environ"
}

inherit_drive_fpm_environment() {
  local main_pid="" child_pid=""
  main_pid="$(systemctl show -p MainPID --value php-fpm-drive.service 2>/dev/null || true)"
  if [[ "$main_pid" =~ ^[0-9]+$ && "$main_pid" -gt 1 ]]; then
    import_process_environment "$main_pid"
    while IFS= read -r child_pid; do
      [[ -n "$child_pid" ]] && import_process_environment "$child_pid"
    done < <(pgrep -P "$main_pid" 2>/dev/null || true)
  fi
}

import_drive_fpm_pool_environment() {
  local conf="" line="" name="" value=""
  conf="$(drive_master_config || true)"
  [[ -n "$conf" && -r "$conf" ]] || return 0

  while IFS= read -r line; do
    [[ "$line" =~ ^env\[([A-Za-z_][A-Za-z0-9_]*)\][[:space:]]*=[[:space:]]*(.*)$ ]] || continue
    name="${BASH_REMATCH[1]}"
    value="${BASH_REMATCH[2]}"
    value="${value%"${value##*[![:space:]]}"}"
    if migration_env_name_allowed "$name" && [[ -n "$value" ]]; then
      export "$name=$value"
      MIGRATION_ENV_SOURCE["$name"]="php-fpm-pool"
    fi
  done < <(
    php-fpm -tt -y "$conf" 2>&1 | awk -v target="127.0.0.1:9075" '
      function clean(line) {
        sub(/^.*NOTICE:[[:space:]]*/, "", line)
        sub(/^[[:space:]]+/, "", line)
        sub(/[[:space:]]+$/, "", line)
        return line
      }
      function flush(    i) {
        if (listen == target) {
          for (i = 1; i <= env_count; i++) print envs[i]
        }
        listen = ""
        env_count = 0
        delete envs
      }
      {
        line = clean($0)
        if (line ~ /^\[[^]]+\]$/) {
          flush()
          next
        }
        if (line ~ /^listen[[:space:]]*=/) {
          sub(/^listen[[:space:]]*=[[:space:]]*/, "", line)
          sub(/[[:space:];].*$/, "", line)
          listen = line
          next
        }
        if (line ~ /^env\[[A-Za-z_][A-Za-z0-9_]*\][[:space:]]*=/) {
          envs[++env_count] = line
        }
      }
      END { flush() }
    '
  )
}

db_runtime_source() {
  local name="$1" managed=""
  managed="$(runtime_value "$name")"
  if [[ -n "$managed" ]]; then
    printf '%s' "runtime-env"
    return 0
  fi
  if [[ -n "${MIGRATION_ENV_SOURCE[$name]:-}" ]]; then
    printf '%s' "${MIGRATION_ENV_SOURCE[$name]}"
    return 0
  fi
  if [[ -n "${!name:-}" ]]; then
    printf '%s' "inherited-shell"
    return 0
  fi
  printf '%s' "missing"
}

verify_migration_database_environment() {
  local name source missing=0
  local required=(DB_HOST DB_USER DB_PASSWORD DB_NAME)
  printf 'Fuentes DB para migración:'
  for name in "${required[@]}"; do
    source="$(db_runtime_source "$name")"
    printf ' %s=%s' "$name" "$source"
    [[ "$source" != "missing" ]] || missing=1
  done
  printf '\n'
  if [[ "$missing" -ne 0 ]]; then
    echo "ERROR: la aplicación web tiene conexión MySQL, pero el updater no pudo localizar todas las variables DB del runtime de PHP-FPM." >&2
    echo "Revisa runtime-env.json, EnvironmentFile y directivas env[DB_*] del pool php-fpm-drive." >&2
    return 1
  fi
}

pool_user_from_conf() {
  local conf="$1"
  [[ -r "$conf" ]] || return 1
  awk -F= '
    /^[[:space:]]*user[[:space:]]*=/ {
      gsub(/[[:space:]]/, "", $2)
      if ($2 != "") { print $2; exit }
    }
  ' "$conf"
}

drive_master_config() {
  local pid arg prev=""
  pid="$(systemctl show -p MainPID --value php-fpm-drive.service 2>/dev/null || true)"
  [[ "$pid" =~ ^[0-9]+$ && "$pid" -gt 1 && -r "/proc/$pid/cmdline" ]] || return 1

  while IFS= read -r -d '' arg; do
    if [[ "$prev" == "-y" || "$prev" == "--fpm-config" ]]; then
      [[ -r "$arg" ]] && { printf '%s' "$arg"; return 0; }
    fi
    case "$arg" in
      --fpm-config=*)
        arg="${arg#*=}"
        [[ -r "$arg" ]] && { printf '%s' "$arg"; return 0; }
        ;;
    esac
    prev="$arg"
  done < "/proc/$pid/cmdline"

  [[ -r /etc/php-fpm.conf ]] && printf '%s' /etc/php-fpm.conf
}

expanded_pool_user() {
  local conf="$1"
  local target_listen="${2:-127.0.0.1:9075}"
  [[ -r "$conf" ]] || return 1

  php-fpm -tt -y "$conf" 2>&1 | awk -v target="$target_listen" '
    function clean(line) {
      sub(/^.*NOTICE:[[:space:]]*/, "", line)
      sub(/^[[:space:]]+/, "", line)
      sub(/[[:space:]]+$/, "", line)
      return line
    }
    function emit_if_match() {
      if (listen == target && user != "") {
        print user
        exit
      }
    }
    {
      line = clean($0)
      if (line ~ /^\[[^]]+\]$/) {
        emit_if_match()
        user = ""
        listen = ""
        next
      }
      if (line ~ /^user[[:space:]]*=/) {
        sub(/^user[[:space:]]*=[[:space:]]*/, "", line)
        sub(/[[:space:];].*$/, "", line)
        user = line
        next
      }
      if (line ~ /^listen[[:space:]]*=/) {
        sub(/^listen[[:space:]]*=[[:space:]]*/, "", line)
        sub(/[[:space:];].*$/, "", line)
        listen = line
        next
      }
    }
    END {
      emit_if_match()
    }
  '
}

detect_drive_php_user() {
  local user="" master_conf=""

  user="$(pool_user_from_conf /etc/php-fpm-drive.d/arcadecloud-drive.conf || true)"
  [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }

  user="$(pool_user_from_conf /etc/php-fpm.d/arcadecloud-drive.conf || true)"
  [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }

  master_conf="$(drive_master_config || true)"
  if [[ -n "$master_conf" ]]; then
    user="$(expanded_pool_user "$master_conf" || true)"
    [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }
  fi

  if [[ -r /etc/php-fpm-drive.conf ]]; then
    user="$(expanded_pool_user /etc/php-fpm-drive.conf || true)"
    [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }
  fi

  user="$(pool_user_from_conf /etc/php-fpm.d/www.conf || true)"
  [[ -n "$user" ]] && { printf '%s' "$user"; return 0; }

  user="$(ps -eo user=,comm= 2>/dev/null | awk '$2 == "php-fpm" && $1 != "root" { print $1; exit }')"
  [[ -n "$user" ]] && printf '%s' "$user"
}

DETECTED_PHP_USER="$(detect_drive_php_user || true)"

if [[ -n "$DETECTED_PHP_USER" ]]; then
  if [[ -n "$PHP_USER" && "$PHP_USER" != "$DETECTED_PHP_USER" ]]; then
    echo "ERROR: --php-user=$PHP_USER no coincide con el pool real de Drive ($DETECTED_PHP_USER)." >&2
    echo "No se modificarán permisos administrativos con un usuario PHP-FPM incorrecto." >&2
    exit 2
  fi
  PHP_USER="$DETECTED_PHP_USER"
fi

[[ -n "$PHP_USER" ]] || { echo "ERROR: no pude detectar el usuario del pool php-fpm-drive; usa --php-user sólo después de verificarlo." >&2; exit 2; }
[[ "$PHP_USER" != "root" ]] || { echo "ERROR: PHP-FPM no debe ejecutar ArcadeCloud como root." >&2; exit 2; }
id "$PHP_USER" >/dev/null 2>&1 || { echo "ERROR: usuario inexistente: $PHP_USER" >&2; exit 2; }

ROLE="$(runtime_value ARCADECLOUD_NODE_ROLE)"
if [[ -z "$ROLE" ]]; then
  if systemctl cat arcadecloud-media-worker.service >/dev/null 2>&1; then
    ROLE="media-worker"
  else
    ROLE="web"
  fi
fi
case "$ROLE" in
  web|media-worker|combined) ;;
  *) echo "ERROR: rol de nodo inválido: $ROLE" >&2; exit 2 ;;
esac

echo "==> Reconciliando ArcadeCloud"
echo "App root: $APP_ROOT"
echo "Rol: $ROLE"
echo "Usuario runtime: $PHP_USER"

# El updater puede introducir tablas FederationCloud nuevas. La migración canónica
# es idempotente y debe ejecutarse antes de reactivar servicios que dependan de ellas.
FEDERATION_MIGRATOR="$DRIVE_ROOT/bin/federation_catalog_migrate.php"
if [[ -f "$FEDERATION_MIGRATOR" ]]; then
  if [[ "$UPDATER_CONTEXT" == "yes" ]]; then
    echo "==> Esquema FederationCloud: reconciliación diferida al runtime web con conexión MySQL activa"
  else
    echo "==> Reconciliando esquema FederationCloud"
  if ! runuser -u "$PHP_USER" -- test -r "$RUNTIME_ENV"; then
    echo "ERROR: el usuario PHP-FPM $PHP_USER no puede leer $RUNTIME_ENV." >&2
    exit 3
  fi
  PHP_BIN="$(command -v php || true)"
  [[ -n "$PHP_BIN" ]] || { echo "ERROR: PHP CLI no está disponible para migrar FederationCloud." >&2; exit 3; }

  # Producción puede conservar DB_*/AWS_* en el entorno efectivo de PHP-FPM
  # aunque runtime-env.json sólo tenga variables administradas más recientes.
  # Heredamos únicamente nombres de configuración permitidos y nunca imprimimos
  # sus valores. app_bootstrap.php aplica después runtime-env.json como override.
  inherit_drive_fpm_environment
  import_drive_fpm_pool_environment
  export ARCADECLOUD_RUNTIME_ENV="$RUNTIME_ENV"

  verify_migration_database_environment || exit 3

  if ! runuser --preserve-environment -u "$PHP_USER" -- "$PHP_BIN" "$FEDERATION_MIGRATOR"; then
    echo "ERROR: no se pudo reconciliar el esquema FederationCloud con el entorno CLI efectivo de php-fpm-drive." >&2
    exit 3
  fi
  fi
fi

# Helpers privilegiados: siempre se refrescan después de un git update.
bash "$DRIVE_ROOT/bin/install_arcadecloud_admin_helper.sh" --php-user="$PHP_USER" --app-root="$APP_ROOT"
bash "$DRIVE_ROOT/bin/install_arcadecloud_updater.sh" --php-user="$PHP_USER" --repo-root="$APP_ROOT"

if [[ "$ROLE" == "web" || "$ROLE" == "combined" ]]; then
  bash "$DRIVE_ROOT/bin/install_transcribe_reconcile_timer.sh"     --run-user="$PHP_USER" --app-root="$APP_ROOT"
  bash "$DRIVE_ROOT/bin/install_polly_reconcile_timer.sh"     --run-user="$PHP_USER" --app-root="$APP_ROOT"
fi

FED_ENABLED="$(runtime_value ARCADECLOUD_FEDERATION_ENABLED)"
case "${FED_ENABLED,,}" in
  1|true|yes|on)
    bash "$DRIVE_ROOT/bin/install_federation_sync_timer.sh"       --run-user="$PHP_USER" --app-root="$APP_ROOT" --interval-sec=120
    bash "$DRIVE_ROOT/bin/install_federation_drop_cleanup_timer.sh"       --run-user="$PHP_USER" --app-root="$APP_ROOT"
    ;;
esac

if [[ "$ROLE" == "media-worker" || "$ROLE" == "combined" ]]; then
  if ! command -v ffmpeg >/dev/null 2>&1 || ! command -v ffprobe >/dev/null 2>&1; then
    echo "ERROR: el rol $ROLE requiere ffmpeg y ffprobe antes de activar el worker." >&2
    exit 3
  fi
  bash "$DRIVE_ROOT/bin/install_media_processing_worker.sh"     --app-root="$APP_ROOT" --run-user="$PHP_USER"
else
  for unit in arcadecloud-media-worker.service arcadecloud-media-node-bootstrap.service; do
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
    path="/etc/systemd/system/$unit"
    if [[ -f "$path" ]] && grep -Fqi 'ArcadeCloud' "$path"; then
      rm -f "$path"
    fi
  done
  rm -rf /var/lib/arcadecloud-media
fi

# Reinstala node-sync sólo si ya estaba administrado; no arranca una sincronización masiva nueva.
if systemctl cat arcadecloud-drive-node-sync.service >/dev/null 2>&1; then
  bash "$DRIVE_ROOT/bin/install_node_sync_service.sh"     --run-user="$PHP_USER" --app-root="$APP_ROOT"
fi

systemctl daemon-reload
systemctl reset-failed >/dev/null 2>&1 || true

if systemctl is-active --quiet php-fpm-drive.service; then
  if [[ "$DEFER_PHP_RESTART" == "yes" ]]; then
    SYSTEMD_RUN="$(command -v systemd-run || true)"
    SYSTEMCTL_BIN="$(command -v systemctl || true)"
    if [[ -z "$SYSTEMD_RUN" || -z "$SYSTEMCTL_BIN" ]]; then
      echo "ERROR: no se puede diferir el reinicio de PHP-FPM porque falta systemd-run/systemctl." >&2
      exit 4
    fi
    RESTART_UNIT="arcadecloud-drive-php-restart-$(date +%s)-$"
    "$SYSTEMD_RUN" --quiet --unit="$RESTART_UNIT" --on-active=5s       "$SYSTEMCTL_BIN" restart php-fpm-drive.service >/dev/null
    echo "✓ Reinicio de php-fpm-drive programado después de responder al updater web."
  else
    systemctl restart php-fpm-drive.service
  fi
fi

if command -v nginx >/dev/null 2>&1 && nginx -t >/dev/null 2>&1; then
  systemctl is-active --quiet nginx.service && systemctl reload nginx.service || true
fi

echo "✓ Reconciliación de servicios completada para rol $ROLE."
