# ArcadeLink v1 + FederationCloud

Estado: **cloud federado funcional en identidad, descubrimiento, Aduana, autorización provider/mirror, ArcadeLink, catálogo, ubicaciones, réplicas físicas y failover por ubicación**.

ArcadeCloud Drive comenzó como un gestor web para Amazon S3. FederationCloud añade una capa federada que permite que varias instalaciones tengan identidad criptográfica propia, se descubran, se validen por HTTPS y establezcan relaciones de confianza sin compartir secretos ni publicar rutas privadas de S3.

La guía operativa para instalar un nodo o una réplica está en
`drive/docs/FEDERATION_NODE_REPLICA_INSTALL.md`. Consulta también
`drive/docs/FEDERATED_CLOUD_STATUS.md` para el estado funcional validado.

## Contrato v1

- `.arcadelink` es el pasaporte portable y firmado de un recurso.
- `/federationcloud/` es la puerta humana para validarlo y resolverlo.
- `resource_id` (`arl_...`) identifica lógicamente el recurso.
- `content_id` (`sha256:...`) identifica los bytes exactos cuando esa huella ya existe y la política permite publicarla.
- `node_id` (`acn_...`) identifica criptográficamente al nodo emisor.
- `node_name` es sólo un nombre público legible, por ejemplo `drive.ejemplo.com`; nunca sustituye al Node ID.

Variables de entorno:

```text
ARCADECLOUD_PUBLIC_URL=https://drive.tudominio.com
ARCADECLOUD_FEDERATION_URL=https://drive.tudominio.com/federationcloud/
ARCADECLOUD_FEDERATION_ENABLED=true
ARCADECLOUD_FEDERATION_IDENTITY=/etc/arcadecloud-drive/federation-node.json
```

Opcionalmente `ARCADECLOUD_FEDERATION_SEED_URL` permite sustituir el seed primario sin cambiar código. Si no se define, el repositorio usa `drive/config/federation-seeds.json`.

La identidad puede provisionarse de dos maneras. Para instalaciones headless continúan disponibles `drive/bin/federation_identity_init.php` y las utilidades CLI de identidad. En la aplicación, una sesión `superadmin` puede crear la identidad inicial o renombrar `node_name` desde el footer. Antes de crear llaves se consulta al seed para comprobar que el nombre esté disponible y el registro firmado final vuelve a imponer la unicidad. Si el sistema necesita escribir bajo `/etc/arcadecloud-drive`, la UI muestra la ruta y los permisos requeridos; opcionalmente puede usarse el helper privilegiado limitado descrito en `drive/docs/SUPERADMIN_SERVER_SETTINGS.md` y `drive/docs/NODE_PROVISIONING.md`.

## Formato

ArcadeLink v1 publica: versión, `resource_id`, `origin_node_id`, URL pública, URL federada, tipo, título, tamaño, MIME, visibilidad, derechos, `content_id` cuando procede, fecha de emisión, payload cifrado y firma digital.

Firma: Ed25519.

Payload: XChaCha20-Poly1305. Los ArcadeLink históricos usan payload interno versión 1 con `user_id + file_id + resource_id`. Los ArcadeLink nuevos usan payload interno versión 2 y añaden `storage_ref`, derivado de la referencia física estable de `FileS3`, dentro del contenido cifrado. `storage_ref` no se publica. Esto permite resolver el mismo objeto por su referencia estable si una recuperación de MySQL le asigna otro `FileS3.id_`. El lector conserva compatibilidad con payload versión 1.

## Creación desde el Drive

ArcadeLink ya está integrado en la interfaz normal del Drive.

Flujo:

```text
archivo en el Drive
 -> Compartir
 -> ArcadeLink FederationCloud
 -> elegir visibilidad y derechos
 -> Descargar .arcadelink
```

El frontend envía la referencia completa del archivo y el backend la valida contra `user_id_`, `Found=1`, `Ruta` y `Encriptado`, reconstruyendo la key real antes de generar el pasaporte.

La creación no publica credenciales AWS, cookies, sesiones ni una URL permanente del objeto S3.

## Interfaz de resolución

`/federationcloud/` utiliza un flujo simple alineado con la interfaz principal del Drive:

```text
dropzone .arcadelink
 -> selección o drag & drop
 -> validación automática
 -> desaparece el dropzone
 -> aparece el recurso resuelto
 -> Abrir
```

Después de una validación aparece **Validar otro ArcadeLink** para reiniciar el flujo. Los datos criptográficos y técnicos secundarios permanecen dentro de una sección colapsable de detalles.

## Visibilidad

- `PUBLIC`: preparado para anuncio/búsqueda futura; puede publicar SHA-256 si existe.
- `UNLISTED`: no aparecerá en búsqueda general; resuelve quien posee el `.arcadelink`; puede publicar SHA-256 si existe.
- `PRIVATE`: no se anuncia y fuerza `content_id = null`.

Un `FileS3.AccessType=secure` sólo puede emitirse PRIVATE y debe abrirse desde el Drive original.

## Derechos

Valores v1: `copy_allowed`, `link_only`, `unknown_rights`, `user_owned_authorized`.

El valor conservador es `unknown_rights`, tratado como sólo enlace. La existencia de un archivo en un Drive no se interpreta automáticamente como autorización para redistribuirlo.

Para compartir sin anuncio público, la interfaz integrada recomienda `UNLISTED + link_only`.

## Resolución

Local:

```text
.arcadelink
 -> validar formato y límites
 -> verificar Node ID y firma
 -> descifrar referencia local
 -> intentar FileS3 por user_id_ + id_ + Found=1
 -> para payload v2, verificar referencia estable y hacer fallback por catálogo local
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

## Apertura

Si la política lo permite, FederationCloud reutiliza `ShareLinkService` para generar un acceso temporal del Drive. FederationCloud no publica una dirección física permanente del objeto almacenado.

Un ArcadeLink validado puede mostrar **Abrir** cuando el recurso es accesible bajo la política vigente.

## Directorio de nodos: seed inicial

El seed inicial vive en:

```text
https://drive.esforzados.com/federationcloud/
```

No se codifica dentro de la lógica PHP: está declarado en `drive/config/federation-seeds.json` y puede reemplazarse con la variable `ARCADECLOUD_FEDERATION_SEED_URL`.

El mismo archivo publica también la ficha pública de bootstrap del primer nodo conocido:

```text
name: drive.esforzados.com
public_url: https://drive.esforzados.com
federation_url: https://drive.esforzados.com/federationcloud/
role: primary-seed
registration: automatic
```

Esta ficha contiene únicamente información pública de descubrimiento. No debe incluir credenciales MySQL/AWS,
`secret_key`, `payload_key`, cookies, sesiones ni ningún otro secreto. `node_id` y `public_key` se obtienen y
verifican desde el descriptor firmado `node.php` del servidor vivo; no se inventan ni se fijan manualmente en Git.

Los nodos independientes se presentan directamente al seed mediante su descriptor firmado. Ese registro no requiere
aprobación manual ni concede acceso a recursos ajenos; sirve para presencia y descubrimiento. Las autorizaciones
especiales de provider/mirror continúan separadas y sí usan su flujo específico.

Cada instalación posee su propia identidad Ed25519 y se anuncia al seed mediante `POST /federationcloud/register.php`. El anuncio contiene únicamente el descriptor público firmado. `node_name`, cuando existe, forma parte del descriptor firmado. Antes de persistir un nodo remoto, el seed valida protocolo, Node ID, nombre, clave y firma; valida HTTPS; consulta mediante el cliente SSRF-safe el `node.php` anunciado; vuelve a validar el descriptor recibido directamente; exige coincidencia de Node ID, clave, nombre y URLs; y sólo entonces actualiza `LastSeen`.

El primer registro liga el Node ID a su clave, nombre y URLs. Un registro posterior puede actualizar el `node_name` únicamente cuando conserva exactamente el mismo Node ID, clave pública, Public URL y Federation URL y el descriptor nuevo está firmado por la misma identidad. Cambiar silenciosamente clave o URLs sigue siendo rechazado y requiere recuperación administrativa explícita.

`node_name` es único en el seed cuando está definido, pero no concede autoridad criptográfica. La autoridad sigue siendo la clave Ed25519 que genera el `node_id`. `POST /federationcloud/name-availability.php` permite hacer un preflight antes de crear una identidad, pero la restricción única y el registro firmado siguen siendo la autoridad final frente a carreras. No se persiste IP, ciudad, país ni ubicación física como fuente de confianza.

`GET /federationcloud/nodes.php` devuelve el nodo local, el seed, el contador y hasta 100 nodos activos. En esta fase, “Nodos conectados” significa nodos con `Status=active` observados durante los últimos 15 minutos. El footer muestra `node_name` cuando existe y conserva el Node ID completo en el tooltip.

Los nodos no-seed se anuncian al seed al consultar su directorio. Si el seed no está disponible, el nodo conserva operación local y el endpoint responde en modo degradado con su propio nodo; no se bloquea la navegación del Drive.

## Proveedores autorizados

Un nodo registrado no obtiene permiso para servir recursos de otro nodo. La identidad y la autorización son capas separadas.

Ejemplo probado:

```text
Nodo origen:     jimmybackend / drive.esforzados.com
Nodo proveedor:  fastdrive / fastdrive.esforzados.com
```

El proveedor solicita autorización desde su propio servidor con `drive/bin/federation_provider_request.php`. El comando envía su descriptor firmado a `POST /federationcloud/provider-request.php` del origen. El nodo origen no confía sólo en el POST: valida la firma, consulta directamente el `node.php` del candidato usando el cliente protegido contra SSRF y exige coincidencia de Node ID, nombre, clave pública y URLs.

Una solicitud válida se registra como `pending` en `FederationNodeAuthorizations`. No se convierte en proveedor activo automáticamente.

En el Drive, únicamente una sesión con `Users.system_role = 'superadmin'` puede consultar `GET /federationcloud/provider-admin.php` y decidir por `POST` con token CSRF. El footer muestra `Solicitudes: N` y abre un modal con:

- solicitudes pendientes: `Aprobar` o `Rechazar`;
- proveedores activos: `Revocar autorización`.

`Users.role` describe el área funcional del usuario y no concede autoridad FederationCloud por sí solo.

Al aprobar, el nodo origen genera una autorización Ed25519 sobre el vínculo exacto `OriginNodeId + ProviderNodeId + Role + Scope`. La firma se guarda como `OriginSignature`. Alterar proveedor, rol o alcance invalida esa autorización.

`GET /federationcloud/providers.php` es público y devuelve únicamente proveedores con `Status=active`, junto con la autorización firmada del origen. No publica solicitudes pendientes, rechazadas, revocadas ni bloqueadas.

Roles soportados:

- `provider`: servidor autorizado para proporcionar recursos permitidos por el origen;
- `mirror`: copia autorizada que puede participar en resolución y replicación física verificada.

Alcances soportados:

- `all_allowed_resources`: todos los recursos cuya política permita ser servidos por proveedores;
- `selected_resources`: preparado para autorización granular posterior.

Ser proveedor no concede permisos de escritura sobre MySQL ni S3. Un servidor proveedor debe empezar como ruta de lectura/descarga. Puede usar una fuente de almacenamiento autorizada sin que FederationCloud le otorgue automáticamente credenciales o permisos de modificación.

La autorización origen→proveedor ya fue probada entre `drive.esforzados.com` y `fastdrive.esforzados.com`: el segundo nodo creó identidad propia, publicó `node.php` por HTTPS, envió la solicitud y fue aprobado desde el nodo origen.

La confianza entre nodos alimenta el catálogo y las ubicaciones federadas. `FederatedResources` y
`FederationResourceLocations` ya permiten anunciar ubicaciones y seleccionar automáticamente
`mirror -> provider -> origin` según disponibilidad.

## Recuperación del mismo nodo

El contenido almacenado en S3 y la identidad FederationCloud son cosas distintas. S3 conserva los archivos, pero para que una reinstalación siga siendo exactamente el mismo nodo deben restaurarse también las mismas llaves de `/etc/arcadecloud-drive/federation-node.json`.

Nunca se debe copiar esa identidad privada en texto plano a un repositorio, correo o almacenamiento compartido. El repositorio incluye:

```text
drive/bin/federation_identity_backup.php
drive/bin/federation_identity_restore.php
```

El respaldo cifra la identidad con una frase de recuperación leída desde un archivo local mediante Argon2id13 + XChaCha20-Poly1305. El archivo cifrado resultante puede almacenarse en S3 u otro respaldo. La frase de recuperación debe conservarse fuera de S3. Restaurar ese respaldo conserva `node_id`, `node_name`, clave Ed25519 y `payload_key`; por tanto el nodo puede volver a verificar y descifrar sus ArcadeLink.

Para continuidad completa de enlaces nuevos después de reconstruir MySQL: restaurar la identidad, reconstruir `FileS3` desde la información persistente y conservar la referencia física estable. El resolver v2 puede encontrar el recurso por esa referencia aunque haya cambiado `id_`. Los ArcadeLink antiguos de payload v1 siguen funcionando mientras se conserve su `file_id`; no se puede retroactivamente añadir `storage_ref` a un enlace ya firmado.

## Tablas FederationCloud

`FederationNodes` y `FederationNodeAuthorizations` forman parte del esquema central `adbbmis1_Cloud.sql`; no existe un archivo SQL auxiliar que actúe como segunda fuente de verdad.

`FederationNodes` persiste `NodeId`, `NodeName`, `PublicKey`, `PublicUrl`, `FederationUrl`, `Status`, `FirstSeen` y `LastSeen`.

`FederationNodeAuthorizations` persiste la relación entre nodo origen y proveedor: `OriginNodeId`, `ProviderNodeId`, `Role`, `Scope`, `Status`, `OriginSignature`, `RequestedAt`, `AuthorizedAt`, `LastSeen` y `RevokedAt`. La combinación origen/proveedor es única.

Los Node ID y firmas usan comparaciones ASCII binarias cuando corresponde porque Base64URL distingue mayúsculas y minúsculas. `NodeName` usa ASCII case-insensitive y un índice único para impedir variantes confusas como nombres equivalentes por mayúsculas/minúsculas.

En una instalación nueva las tablas se crean al importar el esquema central. En una producción existente se ejecuta manualmente sólo el DDL puntual necesario; nunca se reimporta el dump maestro completo sobre una base activa.

## Actividad y costos

Para usuarios autenticados se registran en `DriveActivityEvents`:

- `arcadelink_create`
- `arcadelink_resolve`
- `arcadelink_open`

Las operaciones sin costo AWS directo usan la unidad existente `drive.no_direct_aws_charge=1`. Las peticiones anónimas no se atribuyen a un usuario ficticio.

## Seguridad del registro y solicitudes

`register.php`, `name-availability.php` y `provider-request.php` aceptan payloads limitados. Los descriptores deben estar firmados y el nodo receptor verifica activamente el endpoint anunciado mediante el cliente FederationCloud protegido contra SSRF. `provider-admin.php` exige sesión `superadmin` y CSRF para decisiones mutables. El preflight de nombres no otorga identidad ni autoridad; sólo reduce colisiones antes de generar llaves.

Para exposición pública se recomienda además rate limiting en Nginx/ALB sobre los endpoints públicos de FederationCloud; esta protección perimetral no se sustituye por la validación criptográfica.

## Frontera de seguridad

FederationCloud nunca debe publicar o transportar como parte del directorio o del ArcadeLink:

- claves AWS;
- contraseñas MySQL;
- cookies o sesiones;
- clave privada Ed25519;
- `payload_key`;
- rutas privadas permanentes de S3.

La federación crea identidad y confianza entre nodos; no fusiona sus perímetros de seguridad. La administración web del servidor está deliberadamente limitada a capacidades allowlisted y se documenta en `drive/docs/SUPERADMIN_SERVER_SETTINGS.md` y `drive/docs/SERVER_ADMIN_SECURITY_BOUNDARY.md`.

## Estado persistente actual

Implementado:

1. `FederationNodes`: identidad pública y presencia.
2. `FederationNodeAuthorizations`: confianza firmada origen→provider/mirror.
3. `FederationIngressQueue`: Aduana serializada y aislada por `TargetNodeId`.
4. `FederatedResources`: catálogo federado de recursos.
5. `FederationResourceLocations`: ubicaciones `origin|provider|mirror`.
6. `FederationReplicaJobs`: control plane de réplicas.
7. `FederationReplicaObjects`: objetos de réplica verificados.
8. ArcadeLink v2 con referencia estable cifrada y compatibilidad con v1.
9. reactivación automática de mirrors autorizados.
10. ejecución simultánea de workers sobre MySQL compartida desde PR #98.

Reglas: PRIVATE no publica fingerprint; `FileS3` sigue siendo fuente de verdad local; secretos y llaves
privadas no entran al protocolo. Las instalaciones existentes reciben sólo las migraciones previstas
por los scripts del repo y nunca una reimportación destructiva del dump maestro.

## Fuera del alcance

P2P/BitTorrent, alta disponibilidad automática de dependencias compartidas y distribución de secretos
siguen fuera del alcance actual.
