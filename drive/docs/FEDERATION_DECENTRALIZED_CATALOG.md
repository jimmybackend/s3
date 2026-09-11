# Catálogo global descentralizado FederationCloud

## Objetivo

FederationCloud no debe depender de un nodo matriz para recordar dónde existen recursos. Cada instalación mantiene una copia local y eventualmente consistente del **catálogo federado global**.

El nombre visible actual del nodo principal puede ser `esforzados`; la identidad real sigue siendo su `NodeId` `acn_...`. `NodeName` es una etiqueta legible y no debe utilizarse como identidad criptográfica.

## Qué se replica y qué NO

Se replica únicamente información FederationCloud necesaria para descubrimiento:

- descriptores públicos de nodos;
- metadatos de recursos que su propietario marcó como indexables;
- ubicaciones conocidas de esos recursos;
- eventos firmados que producen esos cambios.

No se replica:

- tabla `Users`;
- contraseñas o sesiones;
- `DB_PASSWORD`;
- credenciales AWS/SMTP;
- identidad privada Ed25519 o `payload_key`;
- catálogo `FileS3` completo;
- keys S3 privadas;
- archivos físicos.

MySQL local continúa siendo la fuente de verdad del Drive de cada instalación y S3 continúa almacenando los bytes.

## Modelo sin nodo matriz

```text
Nodo A                 Nodo B                 Nodo C
MySQL local            MySQL local            MySQL local
+ catálogo global      + catálogo global      + catálogo global
+ event log            + event log            + event log
    |                       |                       |
    +------ gossip ---------+------ gossip --------+
```

No existe una base maestra FederationCloud.

Un seed puede seguir ayudando a que un nodo nuevo descubra sus primeros peers, pero después del descubrimiento el catálogo se propaga peer-to-peer. Si el seed desaparece, los nodos que ya se conocían continúan operando y sincronizándose.

## Log de eventos firmado

Cada cambio global se representa como un evento Ed25519:

```text
version
origin_node_id
origin_sequence
event_type
entity_id
payload
issued_at
public_key
event_id
signature
```

Tipos iniciales permitidos:

- `node.upsert`
- `resource.upsert`
- `resource.tombstone`
- `location.upsert`
- `location.tombstone`

Un peer puede retransmitir el evento de otro nodo, pero no puede modificarlo: cada receptor recalcula `event_id`, deriva `NodeId` desde la clave pública y comprueba la firma Ed25519 del origen.

## Secuencias y vector clock

Cada origen mantiene una secuencia monotónica independiente:

```text
A: 1,2,3,4...
B: 1,2,3...
C: 1,2...
```

Cada nodo conserva un vector clock:

```json
{
  "acn_A": 120,
  "acn_B": 85,
  "acn_C": 44
}
```

El reloj es **contiguo**, no el máximo visto. Si llegan los eventos `1,2,3,5`, el reloj permanece en `3`; cuando aparece `4`, puede avanzar a `5`. Esto impide perder eventos recibidos fuera de orden.

## Sincronización por bloques

Una corrida de `drive/bin/federation_sync.php`:

1. elige pocos peers conocidos;
2. envía su vector clock a `sync-pull.php`;
3. recibe como máximo un bloque pequeño de eventos que le faltan;
4. verifica firma y materializa eventos nuevos;
5. usa el clock recibido del peer para calcular qué eventos le faltan al otro;
6. envía ese segundo bloque a `sync-push.php`;
7. registra éxito o fallo del peer.

Valores predeterminados:

```text
batch = 25 eventos
peers por corrida = 3
peers examinados = 50
```

Pueden ajustarse mediante:

```text
ARCADECLOUD_FEDERATION_SYNC_BATCH
ARCADECLOUD_FEDERATION_SYNC_PEERS
ARCADECLOUD_FEDERATION_SYNC_SCAN
```

La selección cambia entre corridas; no existe un destino fijo llamado "nodo 1".

## Backoff y nodos caídos

Cuando un peer no responde se registra el fallo y se calcula backoff exponencial:

```text
30 s -> 60 s -> 120 s -> 240 s ... máximo 1 h
```

Así un nodo caído no recibe tráfico continuo.

Cuando vuelve, otro peer le entrega bloques faltantes según su vector clock. No necesita reconstruirse desde el nodo que originalmente produjo todos los eventos; cualquier nodo que posea esos eventos puede retransmitirlos.

## Materialización local

El event log es el registro distribuido. Para búsquedas rápidas se materializan tablas locales:

### `FederatedResources`

Índice global de recursos publicables. Mantiene `ResourceId`, nodo origen, título, media type, tamaño, `content_id` cuando puede publicarse, derechos, política de descubrimiento y el `.arcadelink` firmado.

`OwnerUserId` sólo se conserva en el nodo propietario y nunca forma parte del evento replicado.

### `FederationResourceLocations`

Ubicaciones conocidas por recurso:

- `origin`
- `provider`
- `mirror`

La primera versión publica la ubicación `origin`. La estructura ya permite proveedores/mirrors posteriores.

### `FederationEvents`

Eventos firmados de todos los orígenes conocidos.

### `FederationClocks`

Última secuencia contigua conocida por origen.

### `FederationPeerSyncState`

Estado local de anti-entropy: último intento/éxito, fallos consecutivos, siguiente intento y tamaño de los últimos bloques.

## Búsqueda global sin fan-out

`/federationcloud/search.php?q=...` consulta **la copia local del catálogo global**.

No hace esto:

```text
usuario busca
 -> consultar nodo 1
 -> consultar nodo 2
 -> consultar nodo 3
 -> ...
```

Hace esto:

```text
usuario busca
 -> MySQL local / FederatedResources
 -> resultados inmediatos
 -> ubicaciones conocidas
```

Por eso la búsqueda continúa disponible aunque otros nodos estén temporalmente fuera de línea. La frescura depende de la última sincronización gossip.

## Políticas de descubrimiento

### `local_only`

No se publica al catálogo global. Si un recurso antes era indexable se emiten tombstones para retirarlo eventualmente de los demás nodos.

### `public_metadata`

Sólo es válido para `PUBLIC`. Se replica metadata pública y el ArcadeLink firmado.

### `requestable_metadata`

Permite que un recurso no público sea descubrible para un futuro flujo de solicitud de acceso. El payload privado del ArcadeLink continúa cifrado y `content_id` puede permanecer oculto.

La UI actual conserva compatibilidad:

```text
PUBLIC   -> public_metadata por defecto
UNLISTED -> local_only por defecto
PRIVATE  -> local_only por defecto
```

El flujo de solicitud/aceptación utilizará `requestable_metadata` en la siguiente capa.

## Conflictos

No se intenta resolver el mismo recurso mediante "último write wins" entre nodos distintos.

Regla:

- el `OriginNodeId` es autoridad sobre `resource.upsert/tombstone` de sus Resource IDs;
- un nodo sólo puede anunciar una ubicación cuyo `NodeId` sea el mismo que firmó el evento;
- una secuencia `(OriginNodeId, OriginSequence)` no puede apuntar a dos Event IDs diferentes;
- un conflicto firmado de secuencia se rechaza.

Esto evita una base multi-master arbitraria: es multi-origen, pero cada entidad conserva una autoridad criptográfica clara.

## Instalación del esquema

Después de actualizar código:

```bash
php drive/bin/federation_catalog_migrate.php
```

Es idempotente mediante `CREATE TABLE IF NOT EXISTS`.

## Sincronización automática con systemd

Ejemplo para PHP-FPM ejecutado como `nginx`:

```bash
sudo bash drive/bin/install_federation_sync_timer.sh \
  --run-user=nginx \
  --interval-sec=120
```

El timer añade jitter de hasta 30 segundos para que muchos nodos no despierten simultáneamente.

Ver estado:

```bash
systemctl status arcadecloud-federation-sync.timer --no-pager
systemctl list-timers arcadecloud-federation-sync.timer --no-pager
```

Ejecutar un ciclo manual:

```bash
php drive/bin/federation_sync.php
```

El comando usa un lock no bloqueante para impedir corridas superpuestas.

## Endpoints

Máquina a máquina:

```text
POST /federationcloud/sync-pull.php
POST /federationcloud/sync-push.php
```

Los endpoints sólo aceptan eventos de la allowlist y cada evento debe pasar verificación criptográfica. Deben mantenerse rate limits perimetrales en Nginx al exponerse a Internet.

Usuario autenticado:

```text
GET /federationcloud/search.php?q=termino
GET /federationcloud/resource.php?resource_id=arl_...
```

Superadmin:

```text
GET /federationcloud/sync-status.php
```

## Disponibilidad y límites

Este diseño ofrece **consistencia eventual**, no transacciones globales síncronas.

Durante una partición de red dos nodos pueden tener catálogos con distinta frescura. Cuando vuelve la conectividad, los relojes y eventos firmados convergen.

No se promete que los bytes de un archivo sobrevivan a la caída del único nodo que los almacena. Para eso hacen falta ubicaciones `provider/mirror` reales y copias autorizadas. Lo que sí sobrevive desde esta fase es el conocimiento global ya replicado de recursos y nodos.

## Estado de pruebas

Esta fase se valida sin requerir dos nodos reales:

- sintaxis PHP;
- firma/verificación Ed25519 de eventos;
- Event ID determinista;
- rechazo de eventos alterados;
- allowlist de tipos;
- guards estáticos del esquema, endpoints y tamaños de bloque.

La prueba de instalación y tráfico real entre nodos queda separada para la ventana de pruebas acordada.
