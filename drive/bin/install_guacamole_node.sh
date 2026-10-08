#!/usr/bin/env bash
set -euo pipefail

NETWORK="arcadecloud-office"
STATE_ROOT="/var/lib/arcadecloud-guacamole"
ENV_FILE="$STATE_ROOT/guac.env"
DB_VOLUME="arcadecloud-guac-mysql"
DB_CONTAINER="arcadecloud-guac-db"
GUACD_CONTAINER="arcadecloud-guacd"
WEB_CONTAINER="arcadecloud-guacamole"
GUAC_VERSION="1.6.0"
MYSQL_VERSION="8.4"
RDP_ENV_FILE="/etc/arcadecloud-drive/rdp.env"

[[ "${EUID}" -eq 0 ]] || { echo "Ejecuta como root." >&2; exit 1; }
command -v docker >/dev/null 2>&1 || { echo "Docker no está disponible." >&2; exit 2; }
[[ -r "$RDP_ENV_FILE" ]] || { echo "Falta $RDP_ENV_FILE." >&2; exit 2; }

mkdir -p "$STATE_ROOT"
chmod 0700 "$STATE_ROOT"

docker network inspect "$NETWORK" >/dev/null 2>&1 \
  || docker network create "$NETWORK" >/dev/null

if [[ ! -f "$ENV_FILE" ]]; then
  if docker volume inspect "$DB_VOLUME" >/dev/null 2>&1; then
    echo "ERROR: existe $DB_VOLUME pero falta $ENV_FILE; no se regenerarán credenciales sobre una DB existente." >&2
    exit 3
  fi
  umask 077
  GUAC_DB_PASS="$(openssl rand -hex 24)"
  GUAC_ROOT_PASS="$(openssl rand -hex 24)"
  cat > "$ENV_FILE" <<EOF
GUAC_DB_PASS=$GUAC_DB_PASS
GUAC_ROOT_PASS=$GUAC_ROOT_PASS
EOF
fi
chmod 0600 "$ENV_FILE"

# shellcheck disable=SC1090
source "$ENV_FILE"
: "${GUAC_DB_PASS:?Falta GUAC_DB_PASS}"
: "${GUAC_ROOT_PASS:?Falta GUAC_ROOT_PASS}"

docker volume inspect "$DB_VOLUME" >/dev/null 2>&1 \
  || docker volume create "$DB_VOLUME" >/dev/null

if ! docker inspect "$DB_CONTAINER" >/dev/null 2>&1; then
  docker run -d \
    --name "$DB_CONTAINER" \
    --restart unless-stopped \
    --network "$NETWORK" \
    -e MYSQL_DATABASE=guacamole_db \
    -e MYSQL_USER=guacamole_user \
    -e MYSQL_PASSWORD="$GUAC_DB_PASS" \
    -e MYSQL_ROOT_PASSWORD="$GUAC_ROOT_PASS" \
    -v "$DB_VOLUME:/var/lib/mysql" \
    "mysql:$MYSQL_VERSION" >/dev/null
else
  docker start "$DB_CONTAINER" >/dev/null 2>&1 || true
fi

echo "Esperando MySQL de Guacamole..."
for _ in {1..60}; do
  if docker exec "$DB_CONTAINER" mysqladmin ping -uroot -p"$GUAC_ROOT_PASS" --silent >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

if ! docker exec "$DB_CONTAINER" mysqladmin ping -uroot -p"$GUAC_ROOT_PASS" --silent >/dev/null 2>&1; then
  docker logs --tail 100 "$DB_CONTAINER" >&2 || true
  echo "MySQL de Guacamole no alcanzó estado saludable." >&2
  exit 4
fi

SCHEMA_PRESENT="$(docker exec "$DB_CONTAINER" mysql -N -B -uroot -p"$GUAC_ROOT_PASS" guacamole_db \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='guacamole_db' AND table_name='guacamole_entity';" 2>/dev/null || echo 0)"

if [[ "$SCHEMA_PRESENT" != "1" ]]; then
  docker run --rm "guacamole/guacamole:$GUAC_VERSION" \
    /opt/guacamole/bin/initdb.sh --mysql \
    | docker exec -i "$DB_CONTAINER" mysql -uroot -p"$GUAC_ROOT_PASS" guacamole_db
fi

if ! docker inspect "$GUACD_CONTAINER" >/dev/null 2>&1; then
  docker run -d \
    --name "$GUACD_CONTAINER" \
    --restart unless-stopped \
    --network "$NETWORK" \
    "guacamole/guacd:$GUAC_VERSION" >/dev/null
else
  docker start "$GUACD_CONTAINER" >/dev/null 2>&1 || true
fi

if ! docker inspect "$WEB_CONTAINER" >/dev/null 2>&1; then
  docker run -d \
    --name "$WEB_CONTAINER" \
    --restart unless-stopped \
    --network "$NETWORK" \
    -p 127.0.0.1:8085:8080 \
    -e GUACD_HOSTNAME="$GUACD_CONTAINER" \
    -e GUACD_PORT=4822 \
    -e MYSQL_HOSTNAME="$DB_CONTAINER" \
    -e MYSQL_PORT=3306 \
    -e MYSQL_DATABASE=guacamole_db \
    -e MYSQL_USERNAME=guacamole_user \
    -e MYSQL_PASSWORD="$GUAC_DB_PASS" \
    "guacamole/guacamole:$GUAC_VERSION" >/dev/null
else
  docker start "$WEB_CONTAINER" >/dev/null 2>&1 || true
fi

RDP_PASSWORD="$(awk -F= '/^ARCADECLOUD_RDP_PASSWORD=/{print $2}' "$RDP_ENV_FILE")"
[[ -n "$RDP_PASSWORD" ]] || { echo "Falta ARCADECLOUD_RDP_PASSWORD." >&2; exit 5; }

docker exec -i "$DB_CONTAINER" mysql -uguacamole_user -p"$GUAC_DB_PASS" guacamole_db <<SQL
INSERT INTO guacamole_connection
(connection_name, protocol, max_connections, max_connections_per_user)
SELECT 'ArcadeCloud Linux', 'rdp', 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM guacamole_connection WHERE connection_name='ArcadeCloud Linux'
);

SET @CID := (
    SELECT connection_id
    FROM guacamole_connection
    WHERE connection_name='ArcadeCloud Linux'
    ORDER BY connection_id DESC
    LIMIT 1
);

UPDATE guacamole_connection
SET protocol='rdp',
    max_connections=1,
    max_connections_per_user=1
WHERE connection_id=@CID;

DELETE FROM guacamole_connection_parameter
WHERE connection_id=@CID
AND parameter_name IN (
    'hostname','port','username','password','security','ignore-cert',
    'enable-audio','enable-audio-input','resize-method','color-depth',
    'enable-wallpaper','enable-theming','enable-font-smoothing',
    'enable-full-window-drag','enable-desktop-composition','enable-menu-animations'
);

INSERT INTO guacamole_connection_parameter
(connection_id, parameter_name, parameter_value)
VALUES
(@CID,'hostname','arcadecloud-workstation'),
(@CID,'port','3389'),
(@CID,'username','arcade'),
(@CID,'password','$RDP_PASSWORD'),
(@CID,'security','any'),
(@CID,'ignore-cert','true'),
(@CID,'enable-audio','true'),
(@CID,'enable-audio-input','true'),
(@CID,'resize-method','display-update'),
(@CID,'color-depth','16'),
(@CID,'enable-wallpaper','false'),
(@CID,'enable-theming','false'),
(@CID,'enable-font-smoothing','false'),
(@CID,'enable-full-window-drag','false'),
(@CID,'enable-desktop-composition','false'),
(@CID,'enable-menu-animations','false');

INSERT IGNORE INTO guacamole_connection_permission
(entity_id, connection_id, permission)
SELECT entity_id, @CID, 'READ'
FROM guacamole_entity
WHERE name='guacadmin' AND type='USER';
SQL

echo "✓ Guacamole preparado en 127.0.0.1:8085/guacamole/."
echo "✓ Conexión ArcadeCloud Linux: XRDP + audio + micrófono."
echo "✓ La contraseña administrativa de Guacamole existente no se modifica."
