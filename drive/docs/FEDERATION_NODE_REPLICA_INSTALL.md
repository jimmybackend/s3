# FederationCloud — instalación de nodo y réplica

Estado validado: **21 de septiembre de 2026**.

Este documento es la guía operativa para instalar una nueva instancia de ArcadeCloud Drive como
**nodo FederationCloud** o como **réplica/mirror** de un nodo existente. Antes de empezar reúne la
información indicada en `INSTALLATION_PREPARATION.md`. Resume el flujo que fue
probado en producción con dos EC2, MySQL compartida fuera de las EC2 y Amazon S3 como backend
compartido.

Para detalles de protocolo consulta:

- `FEDERATION_OPERATIONS.md`
- `FEDERATION_CUSTOMS_QUEUE.md`
- `FEDERATION_PROVIDER_APPROVALS.md`
- `FEDERATION_REPLICAS.md`
- `FEDERATION_NODE_RECOVERY.md`

## 1. Reglas que no deben romperse

1. Cada servidor debe tener **su propia identidad Ed25519**.
2. Nunca copies `federation-node.json` del origen a una réplica.
3. `node_id` es la identidad. Dominio/IP son sólo endpoints.
4. Los secretos DB/AWS/SMTP permanecen en `/etc/arcadecloud-drive/` o en el runtime administrado;
   nunca se guardan en Git.
5. El seed debe apuntar al endpoint FederationCloud completo, por ejemplo:
   `https://drive.example.com/federationcloud/`.
6. Una réplica compartida usa `relationship=shared_backend` y requiere aprobación de superadmin la
   primera vez y después de una revocación.
7. Tras aprobarla, reiniciar la réplica **no** debe crear otra Solicitud: usa `provider-presence.php`.
8. Si varios nodos comparten MySQL, todos deben ejecutar una versión que incluya el aislamiento de
   workers introducido en PR #98 (`ccd0a475...`) o posterior.
9. `arcadecloud-federation-sync.service` es `oneshot`; verlo como `inactive (dead)` después de una
   ejecución exitosa es normal. El timer es quien lo vuelve a ejecutar.

## 2. Requisitos de servidor

Antes de FederationCloud, el Drive debe funcionar localmente:

- Nginx operativo;
- PHP-FPM operativo;
- conexión MySQL válida;
- acceso S3 válido;
- HTTPS público válido;
- repositorio Git en la rama `main`;
- dependencias Composer instaladas.

Un clon nuevo **no contiene `vendor/`**. Instala dependencias:

```bash
cd /var/www/arcadecloud-drive
composer install --no-dev --optimize-autoloader
```

El updater de ArcadeCloud hace `git merge --ff-only origin/main`; actualmente **no ejecuta
`composer install`**. Si una actualización modifica `composer.lock`, ejecuta Composer manualmente
antes de considerar completa la actualización.

## 3. Identificar el usuario real de PHP-FPM

No asumas `apache` o `nginx`. Compruébalo:

```bash
ps -eo user=,comm=,args= | grep '[p]hp-fpm'
```

En los ejemplos siguientes se usa:

```text
PHP_USER=nginx
APP_ROOT=/var/www/arcadecloud-drive
```

Sustituye `nginx` cuando tu pool use otro usuario.

## 4. Instalar el helper administrativo

Recomendado para crear identidad y administrar variables desde la UI:

```bash
cd /var/www/arcadecloud-drive

sudo bash drive/bin/install_arcadecloud_admin_helper.sh \
  --php-user=nginx
```

Comprueba:

```bash
sudo -u nginx sudo -n /usr/local/sbin/arcadecloud-drive-admin status
```

El helper debe conservar una identidad existente. **No regeneres la identidad para “arreglar” un
problema de red o configuración.**

## 5. Crear la identidad del nodo

Opción recomendada: sesión `superadmin` -> modal **Nodo**.

Para instalación headless:

```bash
sudo php drive/bin/federation_identity_init.php \
  --path=/etc/arcadecloud-drive/federation-node.json \
  --name=NOMBRE_UNICO
```

Después ajusta lectura al grupo del PHP-FPM si aplica:

```bash
sudo chown root:nginx /etc/arcadecloud-drive/federation-node.json
sudo chmod 640 /etc/arcadecloud-drive/federation-node.json
```

Nunca muestres ni copies el contenido de ese archivo. Para recuperación usa el mecanismo cifrado
descrito en `FEDERATION_NODE_RECOVERY.md`.

## 6. Configuración de un nodo normal/origen

Ejemplo de `/etc/arcadecloud-drive/federation.env`:

```env
ARCADECLOUD_PUBLIC_URL=https://node.example.com
ARCADECLOUD_FEDERATION_URL=https://node.example.com/federationcloud/
ARCADECLOUD_FEDERATION_ENABLED=true
ARCADECLOUD_FEDERATION_IDENTITY=/etc/arcadecloud-drive/federation-node.json
ARCADECLOUD_FEDERATION_SEED_URL=https://seed.example.com/federationcloud/
```

Si el nodo es el seed principal, su seed puede ser él mismo, pero **siempre** debe usar la ruta
`/federationcloud/`.

El origen **no** configura:

```text
ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL
```

Permisos recomendados:

```bash
sudo chown root:nginx /etc/arcadecloud-drive/federation.env
sudo chmod 640 /etc/arcadecloud-drive/federation.env
```

### Error conocido: seed apuntando a la raíz

Incorrecto:

```env
ARCADECLOUD_FEDERATION_SEED_URL=https://drive.example.com
```

Correcto:

```env
ARCADECLOUD_FEDERATION_SEED_URL=https://drive.example.com/federationcloud/
```

Síntomas típicos de una URL incorrecta:

```text
seed_node_id = null
connected_nodes = 1
degraded = true
```

## 7. Configuración de una réplica/mirror

La réplica tiene su propia identidad y añade:

```env
ARCADECLOUD_PUBLIC_URL=https://mirror.example.com
ARCADECLOUD_FEDERATION_URL=https://mirror.example.com/federationcloud/
ARCADECLOUD_FEDERATION_ENABLED=true
ARCADECLOUD_FEDERATION_IDENTITY=/etc/arcadecloud-drive/federation-node.json
ARCADECLOUD_FEDERATION_SEED_URL=https://origin.example.com/federationcloud/

ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL=https://origin.example.com/federationcloud/
ARCADECLOUD_FEDERATION_REPLICA_ROLE=mirror
ARCADECLOUD_FEDERATION_REPLICA_SCOPE=all_allowed_resources
```

Para una copia automática física se requiere `all_allowed_resources`; `selected_resources` no se
usa para replicación automática en el protocolo actual.

### Backend compartido

Una réplica puede compartir MySQL y/o S3 con el origen por decisión de infraestructura. FederationCloud
**no transmite credenciales** ni concede ese acceso.

Si origen y mirror comparten MySQL, ambos workers pueden estar activos simultáneamente desde PR #98:

- Aduana se particiona por `TargetNodeId`;
- trabajos salientes se restringen al `OriginNodeId` local;
- trabajos entrantes se restringen al `target_node_id` firmado;
- una fila incoming no sobrescribe la outgoing del mismo offer protocolario.

## 8. Registrar presencia inicial

Carga los mismos EnvironmentFile que usará el worker:

```bash
sudo -u nginx bash -lc '
set -a
source /etc/arcadecloud-drive/drive.env
source /etc/arcadecloud-drive/federation.env
set +a

/usr/bin/php \
  /var/www/arcadecloud-drive/drive/bin/federation_endpoint_refresh.php
'
```

Nodo normal esperado:

```text
OK: nodo FederationCloud presentado...
NODE_ID=acn_...
ENDPOINT=https://...
REGISTRATION=queued
```

Mirror ya autorizado esperado:

```text
REPLICA_STATUS=active
REPLICA_AVAILABLE=true
```

`REGISTRATION=queued` corresponde a la presencia general del nodo; no significa que una autorización
`mirror` activa haya vuelto a quedar pendiente.

### Error conocido: falta ARCADECLOUD_PUBLIC_URL

Si CLI devuelve:

```text
Falta configurar ARCADECLOUD_PUBLIC_URL
```

verifica que `federation.env` tenga el bloque completo de variables y que el proceso CLI lo cargue.
No dependas sólo de variables visibles en una sesión web.

## 9. Primera autorización del mirror

Desde la **réplica**:

```bash
cd /var/www/arcadecloud-drive

sudo -u nginx php drive/bin/federation_provider_request.php \
  --origin=https://origin.example.com/federationcloud/ \
  --public-url=https://mirror.example.com \
  --federation-url=https://mirror.example.com/federationcloud/ \
  --identity=/etc/arcadecloud-drive/federation-node.json \
  --role=mirror \
  --scope=all_allowed_resources
```

Esperado:

```text
status=queued
```

En el origen, Aduana debe procesar la petición:

```bash
sudo systemctl start arcadecloud-federation-sync.service
sudo journalctl -u arcadecloud-federation-sync.service -n 100 --no-pager
```

Debe aparecer:

```text
type = shared_backend_authorization
status = pending
role = mirror
scope = all_allowed_resources
requires_superadmin = true
```

Luego el `superadmin` del origen abre **Solicitudes** y aprueba.

Comprueba:

```bash
curl -fsS https://origin.example.com/federationcloud/providers.php | python3 -m json.tool
```

Esperado:

```text
role = mirror
scope = all_allowed_resources
available = true
```

## 10. Después de una revocación

Revocar es una acción administrativa válida y no elimina la identidad.

Para volver a autorizar, ejecuta otra vez `federation_provider_request.php` desde la réplica y aprueba
la nueva Solicitud.

Versiones anteriores podían reutilizar un Request ID ya terminado y devolver `status=done` sin crear
otra solicitud. PR #98 corrigió el CLI para generar un Request ID fresco en cada solicitud manual.

Si observas el comportamiento antiguo, actualiza **origen y réplica** a una versión que contenga
`ccd0a475` o posterior antes de continuar.

## 11. Instalar el worker y timer

Instálalo en **cada nodo** que vaya a participar activamente:

```bash
cd /var/www/arcadecloud-drive

sudo bash drive/bin/install_federation_sync_timer.sh \
  --run-user=nginx \
  --app-root=/var/www/arcadecloud-drive \
  --drive-env=/etc/arcadecloud-drive/drive.env \
  --federation-env=/etc/arcadecloud-drive/federation.env \
  --interval-sec=120
```

El instalador:

1. instala/actualiza el esquema FederationCloud;
2. instala `arcadecloud-federation-sync.service`;
3. instala y habilita `arcadecloud-federation-sync.timer`.

Comprueba:

```bash
systemctl is-enabled arcadecloud-federation-sync.timer
systemctl is-active arcadecloud-federation-sync.timer
systemctl list-timers --all arcadecloud-federation-sync.timer --no-pager
```

Esperado:

```text
enabled
active
```

El servicio es oneshot. Después de completar normalmente puede verse:

```text
inactive (dead)
status=0/SUCCESS
```

Eso **no es un fallo**.

## 12. Diagnóstico de un ciclo

Ejecuta manualmente:

```bash
sudo systemctl start arcadecloud-federation-sync.service
sudo journalctl -u arcadecloud-federation-sync.service -n 100 --no-pager
```

Mirror sano:

```json
"replica_presence": {
  "configured": true,
  "status": "active",
  "available": true,
  "authorization_requested": false
}
```

Sin trabajo pendiente, estos ceros son normales:

```json
"customs": {"processed": 0, "queue_depth": 0},
"replicas": {
  "outgoing": {"processed": 0, "errors": 0},
  "incoming": {"processed": 0, "errors": 0}
}
```

Busca `degraded: true` o `errors > 0` antes de investigar los ceros.

## 13. Prueba de aislamiento con MySQL compartida

Esta prueba confirma que un mirror no roba Aduanas dirigidas al origen.

1. En el mirror ejecuta `federation_endpoint_refresh.php` para generar `REGISTRATION=queued`.
2. Ejecuta primero el worker del **mirror**.
3. Su sección `customs` debe permanecer:
   `processed=0, queue_depth=0`.
4. Ejecuta el worker del **origen**.
5. El origen debe procesar:
   `type=node_presence` y el Node ID del mirror.

Esto demuestra que `TargetNodeId` está aislando correctamente la cola compartida.

## 14. Prueba de failover de aplicación

Si la réplica comparte un backend externo disponible (por ejemplo MySQL remota + S3), prueba que la
aplicación del mirror no dependa del PHP-FPM del origen.

En el origen:

```bash
sudo systemctl stop php-fpm-drive
```

Mientras esté detenido, desde el mirror prueba:

- login;
- navegación;
- descarga;
- subida pequeña;
- rename/move.

Después:

```bash
sudo systemctl start php-fpm-drive
sudo systemctl is-active php-fpm-drive
```

Debe responder `active`.

Esta prueba valida el failover de **la aplicación EC2**. No protege contra la caída de un componente
que ambos nodos compartan. Si ambos dependen de la misma MySQL y esa MySQL cae, ambos quedan
afectados. Lo mismo aplica a un único bucket/servicio S3 compartido.

## 15. Presencia, disponibilidad y reinicio

Una autorización y una presencia son cosas distintas:

```text
authorized=true, available=false  -> aprobado pero no visto recientemente
authorized=true, available=true   -> aprobado y LastSeen reciente
```

La ventana actual de disponibilidad es de 15 minutos.

Al volver a encender una réplica previamente autorizada:

```text
provider-presence.php
 -> verifica autorización active
 -> valida descriptor/node.php
 -> actualiza LastSeen
 -> available=true
```

No debe crear una nueva Solicitud y debe conservar `role` y `scope`.

## 16. Updater en nodos nuevos

Instalación del updater:

```bash
sudo bash drive/bin/install_arcadecloud_updater.sh \
  --php-user=nginx \
  --repo-root=/var/www/arcadecloud-drive \
  --repo-user=ec2-user
```

Comprueba que el repositorio esté limpio antes de actualizar:

```bash
cd /var/www/arcadecloud-drive
git status --short
```

`vendor/` es una dependencia generada y no debe comprometer el estado Git.

Recuerda: el updater sólo hace fast-forward. Si cambia `composer.lock`, ejecuta:

```bash
composer install --no-dev --optimize-autoloader
```

## 17. HTTPS y endpoint cambiante

Para nodos que deban reconciliar dominio/IP y certificado automáticamente existe:

```bash
sudo bash drive/bin/install_federation_https_service.sh \
  --run-user=nginx \
  --app-root=/var/www/arcadecloud-drive \
  --webroot=/var/www/arcadecloud-drive/drive
```

El instalador **no habilita el timer automáticamente**. Primero:

```bash
sudo systemctl start arcadecloud-federation-https.service
sudo systemctl status arcadecloud-federation-https.service --no-pager
sudo nginx -t
```

Sólo si todo está correcto:

```bash
sudo systemctl enable --now arcadecloud-federation-https.timer
```

Cambiar IP o dominio no debe regenerar la identidad.

## 18. Checklist final de nodo normal

```text
[ ] Drive local funciona
[ ] composer install ejecutado
[ ] PHP-FPM user identificado
[ ] identidad propia creada
[ ] federation.env completo
[ ] seed termina en /federationcloud/
[ ] endpoint HTTPS responde
[ ] endpoint_refresh presenta el nodo
[ ] sync timer enabled + active
[ ] customs sin degraded/errors
[ ] identidad respaldada de forma cifrada
```

## 19. Checklist final de réplica/mirror

```text
[ ] identidad distinta del origen
[ ] mismo código/main compatible en ambos nodos
[ ] backend DB/S3 configurado por infraestructura, no por FederationCloud
[ ] federation.env contiene REPLICA_ORIGIN_URL
[ ] role=mirror
[ ] scope=all_allowed_resources
[ ] primera solicitud aprobada por superadmin
[ ] providers.php muestra role=mirror
[ ] available=true
[ ] authorization_requested=false al reiniciar
[ ] timer enabled + active en origen
[ ] timer enabled + active en mirror
[ ] prueba de aislamiento TargetNodeId superada si comparten MySQL
[ ] prueba de failover de aplicación superada si aplica
```

## 20. Qué NO hacer

- no copiar `federation-node.json` entre nodos;
- no regenerar identidad por un fallo de red;
- no usar la raíz del dominio como seed si falta `/federationcloud/`;
- no publicar DB/AWS/SMTP secrets en solicitudes FederationCloud;
- no interpretar `inactive (dead)` de un oneshot como fallo si terminó `SUCCESS`;
- no interpretar `REGISTRATION=queued` como pérdida de la autorización mirror;
- no habilitar dos workers sobre MySQL compartida usando código anterior a PR #98;
- no reimportar el dump maestro sobre una DB de producción activa;
- no suponer que el updater ejecuta Composer.

## 21. Ejemplo validado en producción

La topología usada para validar este procedimiento fue:

```text
Drive/origen
  -> identidad FederationCloud propia
  -> timer activo
  -> MySQL externa
  -> S3

Fastdrive/mirror
  -> identidad FederationCloud propia y distinta
  -> role=mirror
  -> scope=all_allowed_resources
  -> timer activo
  -> misma MySQL externa
  -> mismo S3
```

Se comprobó:

- autorización `mirror`;
- reactivación sin nueva Solicitud;
- `available=true`;
- dos workers simultáneos sobre la misma MySQL;
- Fastdrive ignorando una Aduana dirigida a Drive;
- Drive procesando esa misma Aduana;
- operación de Fastdrive mientras PHP-FPM del origen estaba detenido.

Eso valida el procedimiento operativo, no convierte la DB/S3 compartida en alta disponibilidad:
cada dependencia compartida sigue siendo un punto de fallo que debe resolverse en su propia capa.
