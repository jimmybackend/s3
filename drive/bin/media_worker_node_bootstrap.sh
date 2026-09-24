#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${1:-/var/www/arcadecloud-drive}"
DRIVE_ROOT="${APP_ROOT}/drive"
ENV_FILE="${ARCADECLOUD_DRIVE_ENV:-/etc/arcadecloud-drive/drive.env}"

log() {
  printf '[media-node-bootstrap] %s\n' "$*" >&2
}

set_env_value() {
  local key="$1"
  local value="$2"
  local tmp

  if [[ ! -f "$ENV_FILE" ]]; then
    log "No existe $ENV_FILE; no se modificará la configuración FederationCloud."
    return 1
  fi

  tmp="$(mktemp "${ENV_FILE}.XXXXXX")"
  awk -v key="$key" -v value="$value" '
    BEGIN { found=0 }
    index($0, key "=") == 1 {
      print key "=" value
      found=1
      next
    }
    { print }
    END {
      if (!found) print key "=" value
    }
  ' "$ENV_FILE" > "$tmp"

  chmod --reference="$ENV_FILE" "$tmp" 2>/dev/null || chmod 0640 "$tmp"
  chown --reference="$ENV_FILE" "$tmp" 2>/dev/null || true
  mv -f "$tmp" "$ENV_FILE"
}

metadata_public_ipv4() {
  command -v curl >/dev/null 2>&1 || return 1

  local token
  token="$(curl -fsS --max-time 3 -X PUT     -H 'X-aws-ec2-metadata-token-ttl-seconds: 60'     http://169.254.169.254/latest/api/token 2>/dev/null || true)"
  [[ -n "$token" ]] || return 1

  curl -fsS --max-time 3     -H "X-aws-ec2-metadata-token: $token"     http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null
}

if [[ "${ARCADECLOUD_FEDERATION_DYNAMIC_IP:-0}" == "1" ]]; then
  ipv4="$(metadata_public_ipv4 || true)"
  if [[ "$ipv4" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    public_url="http://$ipv4"
    federation_url="http://$ipv4/federationcloud/"

    if set_env_value "ARCADECLOUD_PUBLIC_URL" "$public_url"; then
      set_env_value "ARCADECLOUD_FEDERATION_URL" "$federation_url" || true
      export ARCADECLOUD_PUBLIC_URL="$public_url"
      export ARCADECLOUD_FEDERATION_URL="$federation_url"
      log "Endpoint FederationCloud actualizado con la IPv4 pública actual."
    fi
  else
    log "No se pudo obtener una IPv4 pública por IMDSv2; el worker multimedia continuará sin refrescar FederationCloud."
  fi
fi

if [[ -x /usr/bin/php && -f "$DRIVE_ROOT/bin/federation_endpoint_refresh.php" ]]; then
  if command -v timeout >/dev/null 2>&1; then
    timeout 30 /usr/bin/php "$DRIVE_ROOT/bin/federation_endpoint_refresh.php" >/dev/null 2>&1       || log "El anuncio FederationCloud no se confirmó; el procesamiento multimedia continuará."
  else
    /usr/bin/php "$DRIVE_ROOT/bin/federation_endpoint_refresh.php" >/dev/null 2>&1       || log "El anuncio FederationCloud no se confirmó; el procesamiento multimedia continuará."
  fi
fi

exit 0
