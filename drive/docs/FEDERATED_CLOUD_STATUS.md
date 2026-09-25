# Estado actual: ArcadeCloud Drive + FederationCloud

Fecha: **24 de septiembre de 2026**.

ArcadeCloud Drive opera como plataforma de almacenamiento multiusuario con una capa FederationCloud
funcional para identidad, descubrimiento, autorización, catálogo, ubicaciones, réplicas y failover por
nodo.

Para instalar un nodo o una réplica desde cero consulta primero:

- `FEDERATION_NODE_REPLICA_INSTALL.md`

## Base local que no cambia

Cada instalación conserva los principios de ArcadeCloud Drive:

- MySQL es la fuente de verdad para navegación y metadatos;
- Amazon S3 conserva los objetos físicos;
- cada operación local permanece aislada por `user_id_`;
- S3 no se lista durante la navegación normal;
- la lógica sigue `Entrypoint -> Controller -> Service -> Repository / Infrastructure`;
- secretos y claves privadas permanecen fuera del repositorio.

FederationCloud añade coordinación entre nodos; no sustituye esas reglas.

## Capacidades funcionando

### Identidad de nodo

Cada instalación usa una identidad Ed25519 independiente:

```text
node_name
node_id = acn_...
public_key
public_url
federation_url
```

`node_id` es la identidad real. Dominio/IP son endpoints y pueden cambiar sin regenerar llaves.

### Directorio, seed y Aduana

Los nodos publican `node.php`, se presentan ante un seed y son validados por HTTPS antes de quedar
activos.

Las entradas se serializan mediante `FederationIngressQueue`. En instalaciones que comparten MySQL,
cada worker recupera y reclama sólo filas cuyo `TargetNodeId` coincide con su propia identidad.

Esto fue corregido y validado en PR #98.

### Autorización provider/mirror

La identidad de un nodo y su autorización privilegiada son capas distintas.

Una relación `shared_backend` entra por:

```text
provider-request.php
 -> Aduana
 -> verificación criptográfica + node.php
 -> pending
 -> superadmin
 -> approve / reject
```

Roles:

- `provider`;
- `mirror`.

Scopes:

- `all_allowed_resources`;
- `selected_resources`.

La replicación automática actual requiere `all_allowed_resources`.

### Reactivación de réplicas

Una réplica previamente autorizada no vuelve a pedir aprobación después de reiniciar:

```text
provider-presence.php
 -> autorización active
 -> descriptor válido
 -> LastSeen actualizado
 -> available=true
```

La disponibilidad es temporal; la autorización permanece hasta revocación.

### ArcadeLink

`.arcadelink` es el tipo de archivo nativo y firmado de ArcadeCloud. El contrato externo es estricto: sólo la extensión `.arcadelink` es válida y el media type es `application/vnd.arcadecloud.arcadelink`.

En producción ya se validó el flujo completo de un ArcadeLink con **un archivo** y de una colección con **tres archivos**, incluyendo lectura, presentación y descarga correcta de todos los recursos. La matriz detallada de pruebas y pendientes está en `ARCADELINK_PRODUCTION_VALIDATION.md`.

Puede:

1. crearse desde **Compartir**;
2. conservar `resource_id`, procedencia, visibilidad y derechos;
3. cifrar referencia privada con XChaCha20-Poly1305;
4. firmarse con Ed25519;
5. verificarse localmente;
6. resolverse contra FederationCloud;
7. abrirse bajo la política vigente.

### Catálogo y ubicaciones

Están implementados:

- `FederatedResources`;
- `FederationResourceLocations`;
- eventos `resource.upsert`, `location.upsert` y tombstones;
- selección de ubicaciones;
- prioridad de mirror/provider/origin.

Orden actual:

```text
active mirror
active provider
active origin
stale mirror
stale provider
stale origin
```

### Réplicas físicas autorizadas

Para recursos `PUBLIC + copy_allowed` con Content ID SHA-256, FederationCloud puede crear trabajos de
réplica autorizados.

El control plane usa:

- `FederationReplicaJobs`;
- `FederationReplicaObjects`;
- `replica-offer.php`;
- `replica-resolve.php`.

Los bytes se descargan por URL S3 prefirmada y no atraviesan PHP del origen.

En MySQL compartida, PR #98 aísla:

- outgoing por `OriginNodeId`;
- incoming por `target_node_id` firmado;
- Aduana por `TargetNodeId`.

### Workers simultáneos sobre backend compartido

Se validó que origen y mirror puedan ejecutar simultáneamente:

```text
arcadecloud-federation-sync.timer
```

sobre la misma MySQL sin reclamar trabajos del otro nodo.

Prueba realizada:

1. Fastdrive generó una presencia dirigida al seed/origen.
2. El worker de Fastdrive ejecutó primero y reportó `customs.processed=0`.
3. El worker de Drive ejecutó después y procesó `type=node_presence` del Node ID de Fastdrive.

Eso valida el aislamiento por nodo.

## Topología validada en producción

```text
Drive/origen
  drive.esforzados.com
  identidad propia
  timer activo
         |
         +------ MySQL externa compartida
         |
         +------ Amazon S3 compartido
         |
Fastdrive/mirror
  fastdrive.esforzados.com
  identidad propia y distinta
  role=mirror
  scope=all_allowed_resources
  timer activo
```

Se comprobó:

- autorización inicial como `mirror`;
- revocación y reautorización;
- Request ID fresco después de revocación;
- `available=true`;
- reactivación sin nueva Solicitud;
- ambos timers `enabled + active`;
- aislamiento de Aduana en MySQL compartida;
- operación de Fastdrive mientras `php-fpm-drive` del origen estaba detenido.

## Failover: qué se probó y qué no significa

Se detuvo el PHP-FPM de la EC2 de origen y Fastdrive continuó con operaciones de Drive usando la MySQL
externa y S3.

Eso demuestra tolerancia a la caída de **la aplicación/EC2 origen** en esa topología.

No significa alta disponibilidad de todas las dependencias:

- si la MySQL compartida cae, ambos nodos se afectan;
- si el backend S3 compartido deja de estar disponible, ambos se afectan;
- DNS, certificados y red siguen teniendo sus propias dependencias.

Cada capa compartida debe resolver su propia alta disponibilidad.

## Seguridad

FederationCloud nunca publica como parte del directorio, ArcadeLink o autorización:

- claves AWS;
- contraseñas MySQL;
- cookies o sesiones;
- clave privada Ed25519;
- `payload_key`;
- rutas S3 privadas permanentes.

Compartir backend es una decisión de infraestructura y no un privilegio transmitido por FederationCloud.

## Estado de operación esperado

Origen normal:

```json
"replica_presence": {
  "configured": false,
  "status": "not_replica"
}
```

Mirror autorizado:

```json
"replica_presence": {
  "configured": true,
  "status": "active",
  "available": true,
  "role": "mirror",
  "scope": "all_allowed_resources",
  "authorization_requested": false
}
```

Sin trabajo pendiente:

```json
"customs": {
  "processed": 0,
  "queue_depth": 0
}
```

Los ceros son normales. Investiga `degraded=true` o contadores de `errors` mayores que cero.

## Fuera del alcance actual

FederationCloud todavía no pretende ser:

- P2P/BitTorrent;
- un reemplazo distribuido de MySQL;
- una capa que comparta secretos automáticamente;
- alta disponibilidad automática de dependencias externas compartidas.

La instalación y troubleshooting actuales están documentados en
`FEDERATION_NODE_REPLICA_INSTALL.md`.
