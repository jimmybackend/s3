# ArcadeLink v1 + FederationCloud

Estado: fase 1 funcional de pasaporte/resolución + fase 1.1 de descubrimiento de nodos + continuidad de identidad + fase 1.2 de proveedores autorizados. No incluye P2P, buscador global, OAuth ni replicación automática.

## Contrato v1

- `.arcadelink` es el pasaporte portable y firmado de un recurso.
- `/federationcloud/` es la puerta humana para validarlo y resolverlo.
- `resource_id` (`arl_...`) identifica lógicamente el recurso.
- `content_id` (`sha256:...`) identifica los bytes exactos cuando esa huella ya existe.
- `node_id` (`acn_...`) identifica criptográficamente al nodo emisor.
- `node_name` es sólo un nombre público legible, por ejemplo `jimmybackend`; nunca sustituye al Node ID.

Variables de entorno:

```text
ARCADECLOUD_PUBLIC_URL=https://drive.tudominio.com
ARCADECLOUD_FEDERATION_URL=https://drive.tudominio.com/federationcloud/
ARCADECLOUD_FEDERATION_ENABLED=true
ARCADECLOUD_FEDERATION_IDENTITY=/etc/arcadecloud-drive/federation-node.json
```

Opcionalmente `ARCADECLOUD_FEDERATION_SEED_URL` permite sustituir el seed primario sin cambiar código. Si no se define, el repositorio usa `drive/config/federation-seeds.json`.

La identidad se crea explícitamente con `drive/bin/federation_identity_init.php` y no se genera durante una petición web. Una identidad nueva puede recibir nombre con `--name=nombre`. Una identidad existente sin nombre puede nombrarse una sola vez con `drive/bin/federation_identity_name.php`; cambiar un nombre ya fijado requiere intervención administrativa y no ocurre por registro automático.

## Formato

ArcadeLink v1 publica: versión, `resource_id`, `origin_node_id`, URL pública, URL federada, tipo, título, tamaño, MIME, visibilidad, derechos, `content_id` cuando procede, fecha de emisión, payload cifrado y firma digital.

Firma: Ed25519.

Payload: XChaCha20-Poly1305. Los ArcadeLink históricos usan payload interno versión 1 con `user_id + file_id + resource_id`. Los ArcadeLink nuevos usan payload interno versión 2 y añaden `storage_ref`, derivado de `FileS3.Encriptado`, dentro del contenido cifrado. `storage_ref` no se publica. Esto permite resolver el mismo objeto por su referencia estable si una recuperación de MySQL le asigna otro `FileS3.id_`. El lector conserva compatibilidad con payload versión 1.

## Visibilidad

- `PUBLIC`: preparado para anuncio/búsqueda futura; puede publicar SHA-256 si existe.
- `UNLISTED`: no aparecerá en búsqueda general; resuelve quien posee el `.arcadelink`; puede publicar SHA-256 si existe.
- `PRIVATE`: no se anuncia y fuerza `content_id = null`.

Un `FileS3.AccessType=secure` sólo puede emitirse PRIVATE y debe abrirse desde el Drive original.

## Derechos

Valores v1: `copy_allowed`, `link_only`, `unknown_rights`, `user_owned_authorized`.

El valor por defecto es `unknown_rights`, tratado conservadoramente como sólo enlace. La existencia de un archivo en un Drive no se interpreta automáticamente como autorización para redistribuirlo.

## Resolución

Local:

```text
.arcadelink
 -> validar formato y límites
 -> verificar Node ID y firma
 -> descifrar referencia local
 -> intentar FileS3 por user_id_ + id_ + Found=1
 -> para payload v2, verificar Encriptado y hacer fallback por user_id_ + Encriptado + Found=1
 -> comparar SHA-256 si existe en ambos lados
 -> aplicar visibilidad y protección del archivo
```

Remota:

```text
.arcadelink
 -> verificar firma localmente
 -> validar federation_url
 -> consultar sólo node.php
 -> comprobar Node ID, clave pública y firma del descriptor
 -> consultar resolve.php
 -> recibir estado público del recurso
```

El cliente remoto acepta únicamente HTTPS:443, no sigue redirects, limita tiempos y payloads, rechaza IPv4 privadas/reservadas y fija la IP DNS elegida al conectar. No existe un descargador de URLs arbitrarias.

## Directorio de nodos: seed inicial

El seed inicial vive en:

```text
https://drive.esforzados.com/federationcloud/
```

No se codifica dentro de la lógica PHP: está declarado en `drive/config/federation-seeds.json` y puede reemplazarse con la variable `ARCADECLOUD_FEDERATION_SEED_URL`.

Cada instalación posee su propia identidad Ed25519 y se anuncia al seed mediante `POST /federationcloud/register.php`. El anuncio contiene únicamente el descriptor público firmado. `node_name`, cuando existe, forma parte del descriptor firmado. Antes de persistir un nodo remoto, el seed valida protocolo, Node ID, nombre, clave y firma; valida HTTPS; consulta mediante el cliente SSRF-safe el `node.php` anunciado; vuelve a validar el descriptor recibido directamente; exige coincidencia de Node ID, clave, nombre y URLs; y sólo entonces actualiza `LastSeen`.

El primer registro liga el Node ID a su clave, nombre y URLs. Los registros posteriores no pueden cambiar silenciosamente esa asociación. Si el mismo Node ID intenta reaparecer con otra URL, otra clave o un nombre diferente, el seed responde conflicto y exige recuperación administrativa explícita.

`node_name` es único en el seed cuando está definido, pero no concede autoridad criptográfica. La autoridad sigue siendo la clave Ed25519 que genera el `node_id`. No se persiste IP, ciudad, país ni ubicación física.

`GET /federationcloud/nodes.php` devuelve el nodo local, el seed, el contador y hasta 100 nodos activos. En esta fase, “Nodos conectados” significa nodos con `Status=active` observados durante los últimos 15 minutos. El footer muestra `node_name` cuando existe y conserva el Node ID completo en el tooltip.

Los nodos no-seed se anuncian al seed al consultar su directorio. Si el seed no está disponible, el nodo conserva operación local y el endpoint responde en modo degradado con su propio nodo; no se bloquea la navegación del Drive.

## Proveedores autorizados

Un nodo registrado no obtiene permiso para servir recursos de otro nodo. La identidad y la autorización son capas separadas.

Ejemplo:

```text
Nodo origen:     jimmybackend / drive.esforzados.com
Nodo proveedor:  fastdrive / fastdrive.esforzados.com
```

El proveedor solicita autorización desde su propio servidor con `drive/bin/federation_provider_request.php`. El comando envía su descriptor firmado a `POST /federationcloud/provider-request.php` del origen. El nodo origen no confía sólo en el POST: valida la firma, consulta directamente el `node.php` del candidato usando el cliente protegido contra SSRF y exige coincidencia de Node ID, nombre, clave pública y URLs.

Una solicitud válida se registra como `pending` en `FederationNodeAuthorizations`. No se convierte en proveedor activo automáticamente.

En el Drive, únicamente una sesión con rol `Administración` puede consultar `GET /federationcloud/provider-admin.php` y decidir por `POST` con token CSRF. El footer muestra `Solicitudes: N` y abre un modal con:

- solicitudes pendientes: `Aprobar` o `Rechazar`;
- proveedores activos: `Revocar autorización`.

Al aprobar, el nodo origen genera una autorización Ed25519 sobre el vínculo exacto `OriginNodeId + ProviderNodeId + Role + Scope`. La firma se guarda como `OriginSignature`. Alterar proveedor, rol o alcance invalida esa autorización.

`GET /federationcloud/providers.php` es público y devuelve únicamente proveedores con `Status=active`, junto con la autorización firmada del origen. No publica solicitudes pendientes, rechazadas, revocadas ni bloqueadas.

Roles soportados:

- `provider`: servidor autorizado para proporcionar recursos permitidos por el origen;
- `mirror`: reservado para una fase posterior con copia/replicación física verificada.

Alcances soportados:

- `all_allowed_resources`: todos los recursos cuya política permita ser servidos por proveedores;
- `selected_resources`: preparado para autorización granular posterior.

Ser proveedor no concede permisos de escritura sobre MySQL ni S3. Un servidor como `fastdrive.esforzados.com` debe empezar como ruta de lectura/descarga. Puede usar el mismo bucket S3 y la misma fuente de metadatos autorizada sin duplicar los objetos físicos, pero las operaciones de modificación siguen perteneciendo al Drive/origen y a sus permisos normales.

Esta fase crea la confianza entre nodos. La selección durante una descarga (`drive.esforzados.com` frente a `fastdrive.esforzados.com`) se implementará sobre `FederatedResources` y `FederationResourceLocations`; todavía no se anuncia una ubicación de descarga alternativa por recurso.

## Recuperación del mismo nodo

El contenido almacenado en S3 y la identidad FederationCloud son cosas distintas. S3 conserva los archivos, pero para que una reinstalación siga siendo exactamente el mismo nodo deben restaurarse también las mismas llaves de `/etc/arcadecloud-drive/federation-node.json`.

Nunca se debe copiar esa identidad privada en texto plano a un repositorio, correo o almacenamiento compartido. El repositorio incluye:

```text
drive/bin/federation_identity_backup.php
drive/bin/federation_identity_restore.php
```

El respaldo cifra la identidad con una frase de recuperación leída desde un archivo local mediante Argon2id13 + XChaCha20-Poly1305. El archivo cifrado resultante puede almacenarse en S3 u otro respaldo. La frase de recuperación debe conservarse fuera de S3. Restaurar ese respaldo conserva `node_id`, `node_name`, clave Ed25519 y `payload_key`; por tanto el nodo puede volver a verificar y descifrar sus ArcadeLink.

Para continuidad completa de enlaces nuevos después de reconstruir MySQL: restaurar la identidad, reconstruir `FileS3` desde la información persistente y conservar `Encriptado`. El resolver v2 puede encontrar el recurso por esa referencia aunque haya cambiado `id_`. Los ArcadeLink antiguos de payload v1 siguen funcionando mientras se conserve su `file_id`; no se puede retroactivamente añadir `storage_ref` a un enlace ya firmado.

## Tablas FederationCloud

`FederationNodes` y `FederationNodeAuthorizations` forman parte del esquema central `adbbmis1_Cloud.sql`; no existe un archivo SQL auxiliar que actúe como segunda fuente de verdad.

`FederationNodes` persiste `NodeId`, `NodeName`, `PublicKey`, `PublicUrl`, `FederationUrl`, `Status`, `FirstSeen` y `LastSeen`.

`FederationNodeAuthorizations` persiste la relación entre nodo origen y proveedor: `OriginNodeId`, `ProviderNodeId`, `Role`, `Scope`, `Status`, `OriginSignature`, `RequestedAt`, `AuthorizedAt`, `LastSeen` y `RevokedAt`. La combinación origen/proveedor es única.

Los Node ID y firmas usan comparaciones ASCII binarias cuando corresponde porque Base64URL distingue mayúsculas y minúsculas. `NodeName` usa ASCII case-insensitive y un índice único para impedir variantes confusas como nombres equivalentes por mayúsculas/minúsculas.

En una instalación nueva las tablas se crean al importar el esquema central. En una producción existente se ejecuta manualmente sólo el DDL puntual necesario; nunca se reimporta el dump maestro completo sobre una base activa.

## Apertura local

Si la política lo permite, FederationCloud reutiliza `ShareLinkService` para generar el acceso temporal ya existente del Drive. FederationCloud no publica una dirección física permanente del objeto almacenado.

## Actividad y costos

Para usuarios autenticados se registran en `DriveActivityEvents`:

- `arcadelink_create`
- `arcadelink_resolve`
- `arcadelink_open`

Las operaciones sin costo AWS directo usan la unidad existente `drive.no_direct_aws_charge=1`. Las peticiones anónimas no se atribuyen a un usuario ficticio.

## Seguridad del registro y solicitudes

`register.php` y `provider-request.php` aceptan payloads JSON limitados. Los descriptores deben estar firmados y el nodo receptor verifica activamente el endpoint anunciado mediante el cliente FederationCloud protegido contra SSRF. `provider-admin.php` exige sesión `Administración` y CSRF para decisiones mutables.

Para exposición pública se recomienda además rate limiting en Nginx/ALB sobre `/federationcloud/register.php` y `/federationcloud/provider-request.php`; esta protección perimetral no se sustituye por la validación criptográfica.

## Diseño persistente posterior

Implementado ahora:

1. `FederationNodes`: Node ID, nombre firmado, clave pública, URLs, estado y fechas de observación.
2. `FederationNodeAuthorizations`: solicitudes y autorizaciones firmadas origen→proveedor.
3. Continuidad ArcadeLink: payload v2 con referencia estable cifrada y compatibilidad con payload v1.

Pendiente para fases posteriores:

4. `FederatedResources`: Resource ID, nodo origen, metadatos públicos, SHA-256 nullable, visibilidad, derechos, procedencia, versión/firma y estado.
5. `FederationResourceLocations`: relación `origin|provider|mirror` entre recurso/contenido y nodos que anuncian una ubicación, con última verificación.
6. `FederationLocalBindings`: binding explícita `resource_id -> user_id_ + FileS3.id_` para ciclo de vida y revocación futura.

Reglas: PRIVATE nunca tendrá fingerprint público; toda binding local conservará `user_id_`; `FileS3` seguirá siendo fuente de verdad local; no se duplicarán `Nombre`, `Ruta` o `Encriptado` como autoridad. Las instalaciones nuevas se describen siempre en el esquema central; las bases ya desplegadas reciben sólo el DDL puntual necesario y nunca una reimportación completa del dump maestro.

## Siguiente fase

Pendiente: selección de proveedor por recurso, índice público de recursos, mirror lookup por SHA-256, copia autorizada/Guardar en mi Drive, OAuth para nubes externas y revocación administrable de ubicaciones. P2P/BitTorrent sigue fuera de esta etapa.
