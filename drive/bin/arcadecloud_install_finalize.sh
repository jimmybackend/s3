#!/usr/bin/env bash
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "ERROR: la finalización de ArcadeCloud requiere root." >&2
  exit 77
fi

APP_ROOT="/var/www/arcadecloud-drive"
RUN_USER=""
SETUP_LOCK="/etc/arcadecloud-drive/setup.lock"
RUNTIME_ENV="/etc/arcadecloud-drive/runtime-env.json"

for arg in "$@"; do
  case "$arg" in
    --app-root=*) APP_ROOT="${arg#*=}" ;;
    --run-user=*) RUN_USER="${arg#*=}" ;;
    *) echo "ERROR: argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

[[ -f "$SETUP_LOCK" ]] || { echo "SKIP: setup todavía no está completado."; exit 0; }
[[ -d "$APP_ROOT" ]] || { echo "ERROR: no existe APP_ROOT: $APP_ROOT" >&2; exit 3; }
[[ -n "$RUN_USER" ]] || { echo "ERROR: falta --run-user." >&2; exit 2; }
id "$RUN_USER" >/dev/null 2>&1 || { echo "ERROR: usuario inexistente: $RUN_USER" >&2; exit 2; }

FED_ENABLED="false"
if [[ -r "$RUNTIME_ENV" ]]; then
  FED_ENABLED="$(python3 - "$RUNTIME_ENV" <<'PY'
import json, sys
try:
    data=json.load(open(sys.argv[1], encoding="utf-8"))
    print(str(data.get("ARCADECLOUD_FEDERATION_ENABLED","false")).lower())
except Exception:
    print("false")
PY
)"
fi

case "$FED_ENABLED" in
  1|true|yes|on)
    bash "$APP_ROOT/drive/bin/install_federation_sync_timer.sh"       --run-user="$RUN_USER"       --app-root="$APP_ROOT"       --drive-env=/etc/arcadecloud-drive/drive.env       --federation-env=/etc/arcadecloud-drive/federation.env       --interval-sec=120

    if systemctl list-unit-files arcadecloud-federation-https.timer >/dev/null 2>&1; then
      systemctl enable --now arcadecloud-federation-https.timer || true
    fi
    ;;
  *)
    echo "INFO: FederationCloud no está activo; se omite el worker."
    ;;
esac

install -d -o root -g root -m 0755 /var/lib/arcadecloud-drive
cat > /var/lib/arcadecloud-drive/install-finalized.json <<EOF
{
  "version": 1,
  "completed": true,
  "completed_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "federation_enabled": "$FED_ENABLED"
}
EOF
chmod 0644 /var/lib/arcadecloud-drive/install-finalized.json

systemctl disable --now arcadecloud-install-finalize.path >/dev/null 2>&1 || true

echo "OK: instalación ArcadeCloud finalizada."
