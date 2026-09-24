#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${1:-/var/www/arcadecloud-drive}"
DRIVE_ROOT="${APP_ROOT}/drive"
RUNTIME_ENV="${ARCADECLOUD_RUNTIME_ENV:-/etc/arcadecloud-drive/runtime-env.json}"
ADMIN_HELPER="/usr/local/sbin/arcadecloud-drive-admin"

log() {
  printf '[media-node-bootstrap] %s\n' "$*" >&2
}

runtime_value() {
  local key="$1"
  python3 - "$RUNTIME_ENV" "$key" <<'PY' 2>/dev/null || true
import json, sys
path, key = sys.argv[1:]
try:
    with open(path, encoding="utf-8") as f:
        data=json.load(f)
    value=data.get(key, "")
    if isinstance(value, str):
        print(value)
except Exception:
    pass
PY
}

metadata_public_ipv4() {
  command -v curl >/dev/null 2>&1 || return 1
  local token
  token="$(curl -fsS --max-time 3 -X PUT     -H 'X-aws-ec2-metadata-token-ttl-seconds: 60'     http://169.254.169.254/latest/api/token 2>/dev/null || true)"
  [[ -n "$token" ]] || return 1

  curl -fsS --max-time 3     -H "X-aws-ec2-metadata-token: $token"     http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null
}

dynamic="$(runtime_value ARCADECLOUD_FEDERATION_DYNAMIC_IP)"
case "${dynamic,,}" in
  1|true|yes|on)
    ipv4="$(metadata_public_ipv4 || true)"
    if [[ "$ipv4" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
      if [[ -x "$ADMIN_HELPER" ]]; then
        payload="$(python3 - "$ipv4" <<'PY'
import json,sys
ip=sys.argv[1]
print(json.dumps({
  "ARCADECLOUD_PUBLIC_URL": f"http://{ip}",
  "ARCADECLOUD_FEDERATION_URL": f"http://{ip}/federationcloud/"
}, separators=(",",":")))
PY
)"
        printf '%s' "$payload" | "$ADMIN_HELPER" env-set-many >/dev/null
        log "Endpoint FederationCloud actualizado con la IPv4 pública actual."
      else
        log "Falta $ADMIN_HELPER; no se modificó el runtime administrado."
      fi
    else
      log "No se pudo obtener una IPv4 pública por IMDSv2."
    fi
    ;;
esac

if [[ -x /usr/bin/php && -f "$DRIVE_ROOT/bin/federation_endpoint_refresh.php" ]]; then
  if command -v timeout >/dev/null 2>&1; then
    timeout 30 /usr/bin/php "$DRIVE_ROOT/bin/federation_endpoint_refresh.php" >/dev/null 2>&1       || log "El anuncio FederationCloud no se confirmó; el procesamiento multimedia continuará."
  else
    /usr/bin/php "$DRIVE_ROOT/bin/federation_endpoint_refresh.php" >/dev/null 2>&1       || log "El anuncio FederationCloud no se confirmó; el procesamiento multimedia continuará."
  fi
fi

exit 0
