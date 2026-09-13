# FederationCloud — Aduana, presencia automática y copias compartidas

## Objetivo

FederationCloud separa la **presencia de un nodo independiente** de una **autorización privilegiada para servir recursos de otro nodo**.

Un nodo independiente tiene su propia información, su propia identidad Ed25519 y anuncia únicamente dónde está disponible (`public_url` / `federation_url`). Ese anuncio no concede acceso al S3, MySQL ni a los recursos privados de otro nodo y por eso no requiere una decisión humana.

Una copia/réplica que pretende servir recursos del nodo origen mediante infraestructura compartida es una relación privilegiada. Esa relación continúa apareciendo en **Solicitudes** y sólo `Users.system_role = superadmin` puede aprobarla, rechazarla o revocarla.

> Compartir S3/DB no se demuestra enviando nombres de bucket, hosts, contraseñas ni fingerprints sensibles por FederationCloud. La relación se declara explícitamente como `shared_backend` y el superadmin decide después de verificar su propia infraestructura por un canal administrativo confiable. Cada copia conserva una identidad FederationCloud distinta; nunca se clona la clave privada Ed25519 del nodo origen.

## Aduana

Las llegadas externas se registran en MySQL en `FederationIngressQueue`. El endpoint público hace sólo trabajo ligero: valida formato/firma, documenta la llegada y responde. La comprobación HTTPS en vivo y la transición de estado se realizan fuera de PHP-FPM.

Estados:

```text
queued -> processing -> done
                    -> retry -> processing
                    -> rejected
                    -> failed
```

Cada petición tiene `RequestId` único, hash de la documentación, Node ID de origen, tipo, prioridad, timestamps, intentos y último error. Reenviar el mismo `RequestId` con la misma documentación es idempotente; reutilizarlo con otro payload se rechaza.

`drive/bin/federation_sync.php` mantiene un `flock(... LOCK_EX | LOCK_NB)` y procesa **como máximo una petición de Aduana por ciclo**. Así pueden llegar muchas solicitudes HTTP sin convertir las operaciones pesadas en multiproceso.

## Dos carriles de entrada

### 1. Nodo independiente: automático

```text
register.php
  -> validar descriptor firmado
  -> Aduana: node_presence
  -> 202/registro aceptado lógicamente
  -> worker único
  -> consultar node.php HTTPS del candidato
  -> comprobar Node ID + clave + URLs + nombre
  -> FederationNodes = active
  -> node.upsert firmado
  -> gossip
  -> resto de la federación
```

No crea una fila `pending` en `FederationNodeAuthorizations` y no aparece en **Solicitudes**.

### 2. Copia/réplica con backend compartido: superadmin

```text
provider-request.php
  relationship=shared_backend
  -> Aduana: shared_backend_authorization
  -> worker único
  -> verificar descriptor + node.php HTTPS
  -> FederationNodeAuthorizations = pending
  -> Solicitudes
  -> superadmin: Aprobar / Rechazar
```

Sólo una autorización `active` puede participar como provider/mirror del nodo origen.

## Encendido después de estar fuera de línea

El endpoint HTTPS y el worker de sincronización no dependen de que el servidor se encienda dentro de una ventana anterior.

Al arrancar/reconciliar:

```text
EC2 inicia
  -> reconciliador HTTPS resuelve dominio o IP pública actual
  -> actualiza public_url / federation_url si cambió la IP
  -> federation_endpoint_refresh.php
  -> registra presencia ante el seed
  -> emite node.upsert local
  -> timer de sync se activa
  -> Aduana procesa uno por uno
  -> gossip replica el endpoint y disponibilidad
```

El timer de sync usa:

```ini
OnActiveSec=45s
OnUnitInactiveSec=120s
RandomizedDelaySec=30s
Persistent=true
```

Por ello la primera ejecución se agenda desde la activación real del timer, aunque el boot haya ocurrido mucho antes.

## Identidad vs endpoint

`NodeId -> PublicKey` es el binding permanente. Una declaración firmada por la misma clave puede cambiar `public_url` y `federation_url`; esto permite IPv4 pública variable sin crear otro nodo.

Una clave pública distinta para un Node ID existente sigue siendo un conflicto y requiere recuperación administrativa explícita.

## Disponibilidad replicada

`FederationCatalogService` emite un `node.upsert` de presencia como máximo una vez cada cinco minutos mediante un `availability_bucket`. El directorio considera activos los nodos vistos en los últimos 15 minutos. De este modo un nodo encendido permanece visible y, después de volver de una suspensión, reaparece en la federación sin aprobación humana.

## Seguridad

- `register.php` no concede acceso a recursos de otro nodo.
- `provider-request.php` queda reservado a `relationship=shared_backend`.
- una copia compartida siempre requiere superadmin;
- ningún request de Aduana contiene `secret_key`, `payload_key`, contraseñas DB, cookies, sesiones ni credenciales AWS;
- la clave Ed25519 privada no se replica entre nodos;
- el Node ID se deriva de la clave pública y no de la IP o dominio;
- las verificaciones de endpoint reutilizan `FederationHttpClient` y sus protecciones SSRF;
- trabajos `processing` abandonados se recuperan como `retry`;
- el worker tiene lock exclusivo y procesa una sola llegada por ciclo.

## Operación

La migración `drive/bin/federation_catalog_migrate.php` instala también `drive/sql/federation_ingress_queue.sql`.

El mismo instalador del gossip crea y habilita el worker:

```bash
sudo bash drive/bin/install_federation_sync_timer.sh \
  --run-user=apache \
  --app-root=/var/www/arcadecloud-drive \
  --drive-env=/etc/arcadecloud-drive/drive.env \
  --federation-env=/etc/arcadecloud-drive/federation.env \
  --interval-sec=120
```

En instalaciones donde PHP-FPM use otro usuario debe pasarse ese usuario real.
