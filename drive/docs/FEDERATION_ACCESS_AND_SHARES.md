# FederationCloud: solicitudes privadas y zona lógica Shares

## Objetivo

La búsqueda global puede descubrir recursos con `DiscoveryPolicy = requestable_metadata` sin publicar acceso directo. El usuario de otro nodo puede solicitar acceso y el propietario decide localmente.

Las solicitudes y grants **no forman parte del gossip global**. Sólo viajan entre el nodo solicitante y el nodo origen del recurso.

## Portal de usuario

El portal autenticado vive en:

```text
/federationcloud/portal.php
```

Integra cuatro superficies sobre las APIs existentes:

```text
Buscar global
Solicitudes
  - recibidas
  - enviadas
Compartidos
  - recibidos
  - enviados
Réplicas
```

La búsqueda del portal usa `/federationcloud/search.php` y consulta la copia local de `FederatedResources`; no hace fan-out en tiempo real a todos los nodos.

Las solicitudes y Shares usan `/federationcloud/access.php` con el token CSRF de sesión. La interfaz no obtiene ni expone `user_id` remoto, correo, contraseñas ni credenciales de infraestructura.

## Políticas visibles al compartir

El modal ArcadeLink permite seleccionar explícitamente:

```text
local_only
requestable_metadata
public_metadata
```

También conserva modo `Automático`:

```text
PUBLIC   -> public_metadata
UNLISTED -> local_only
PRIVATE  -> local_only
```

Reglas:

- `local_only`: el recurso no entra al catálogo global;
- `requestable_metadata`: se replica metadata, pero el acceso requiere aprobación del propietario;
- `public_metadata`: sólo es válido con `visibility=PUBLIC` y publica metadata al catálogo global.

El endpoint `create.php` recibe `discovery_policy` y lo pasa a `FederationService`; el backend sigue validando la combinación final, por lo que la seguridad no depende del JavaScript.

## Identidad entre nodos

No se replica ni se transmite `Users.id` como identidad global. Un `user_id` sólo tiene significado en su MySQL local.

Una solicitud contiene:

```text
request_id
resource_id
requester_node_id
requester_federation_url
requested_at
expires_at
public_key
signature Ed25519
```

El nodo solicitante guarda localmente qué usuario hizo la solicitud. El nodo origen guarda qué usuario local es dueño del recurso. Ninguno necesita conocer el ID interno del usuario remoto.

## Flujo

```text
búsqueda local del catálogo global
  -> recurso requestable_metadata
  -> usuario pulsa Solicitar acceso
  -> solicitud Ed25519
  -> POST access-request.php al nodo origen
  -> propietario la ve como incoming
  -> aprobar / rechazar
  -> decisión Ed25519
  -> solicitante consulta access-status.php
  -> si se aprobó, recibe URL temporal
  -> aparece en Compartidos/Recibidos
```

Si el nodo origen está caído:

```text
solicitud -> queued
worker FederationCloud -> reintento por backoff
```

No se necesita un nodo central para guardar la cola.

## Grants

Al aprobar, el nodo origen crea un enlace temporal mediante el `ShareLinkService` existente. El grant firmado contiene la URL HTTPS y su expiración.

El URL temporal:

- no se publica en `FederationEvents`;
- no se replica al catálogo global;
- sólo se devuelve al mismo `request_id` firmado por el nodo solicitante;
- sólo se guarda en la DB local del solicitante.

## Compartidos como carpeta lógica

`FederationShares` es una zona lógica MySQL, no una carpeta física S3.

Direcciones:

- `received`: acceso temporal recibido desde otro nodo;
- `sent`: acceso concedido por el propietario local.

El Drive muestra además una entrada virtual **Compartidos** en el árbol lateral. Esa entrada abre directamente la vista de Compartidos del portal FederationCloud.

Abrir un Share **no crea una copia local**. El Share continúa siendo una referencia viva al recurso remoto mientras el grant permanezca activo.

MySQL sigue siendo la fuente de navegación. FederationCloud no lista buckets S3 para construir Compartidos.

## Agregar a Mi Drive

Un Share recibido y activo puede copiarse explícitamente con:

```text
Agregar a Mi Drive
```

El flujo es:

```text
Compartidos/Recibidos
  -> grant temporal existente
  -> GET al endpoint público con download=1
  -> el nodo origen devuelve una única redirección HTTPS a S3
  -> el nodo receptor valida que el destino sea S3 público
  -> descarga a archivo temporal con límite de 5 GiB
  -> SingleUploadService
  -> DataN/
  -> FileS3 con user_id local
```

La copia resultante es un archivo normal del usuario:

- vive físicamente dentro de su raíz `Data/`, `Data2/`, `DataN/`;
- aparece en **Archivos y carpetas**;
- queda registrada en `FileS3`;
- recibe metadatos de procedencia FederationCloud (`share_id`, `resource_id`, `remote_node_id`);
- no elimina ni reemplaza la entrada de Compartidos.

`FederationShares` conserva:

```text
LocalFileId
LocalS3Key
ImportedAt
ImportedResourceUpdatedAt
```

Así Compartidos sigue diciendo de dónde vino el archivo aunque el usuario ya tenga una copia propia.

## Versiones nuevas

Agregar a Mi Drive nunca sobrescribe silenciosamente la copia personal.

Si el catálogo global aprende que `FederatedResources.UpdatedAt` es posterior a `ImportedResourceUpdatedAt`, la UI marca que hay una versión nueva y ofrece:

```text
Agregar versión nueva
```

La nueva versión se guarda como otro archivo normal en `DataN/`; la copia anterior permanece intacta. Esto evita destruir cambios locales del usuario.

## Seguridad de la copia

El importador de Compartidos:

- exige sesión autenticada y CSRF propio;
- sólo acepta Shares `received` del usuario actual;
- exige estado `active` y grant no expirado;
- sólo usa HTTPS puerto 443;
- no sigue redirects automáticamente;
- permite exactamente el salto controlado del endpoint Share hacia una URL S3 prefirmada;
- rechaza IP privadas/reservadas y metadata service;
- valida que el destino final sea `*.amazonaws.com` en formato S3;
- limita cada copia a 5 GiB;
- no lista S3 para descubrir archivos.

## Reintentos

`drive/bin/federation_sync.php` procesa como máximo 5 solicitudes salientes pendientes por ciclo, además del gossip del catálogo. Los errores no detienen el gossip global.

Una solicitud expira por defecto después de 7 días. Un grant puede durar de 1 a 30 días según la decisión del propietario.

## Endpoints máquina a máquina

```text
POST /federationcloud/access-request.php
POST /federationcloud/access-status.php
```

Ambos reciben el documento de solicitud firmado. `access-status.php` no acepta solamente un Request ID; esto evita que conocer un identificador permita recuperar un grant.

## Endpoints de usuario

Solicitudes y Shares:

```text
GET  /federationcloud/access.php
POST /federationcloud/access.php
```

Copia a Mi Drive:

```text
GET  /federationcloud/share-drive.php
POST /federationcloud/share-drive.php
```

`share-drive.php` GET devuelve el estado de los Shares recibidos y si ya tienen una copia local. POST acepta:

```text
action=import
share_id=far_...
```

y exige `X-Federation-Share-Drive-CSRF`.

## Privacidad

No se sincronizan globalmente:

- correo o nombre del solicitante;
- `user_id` remoto;
- URL temporal de grant;
- tokens de descarga;
- decisiones privadas;
- contraseñas o credenciales del servidor;
- rutas físicas de la copia personal `DataN/`.

El catálogo global sabe que el recurso existe y dónde está; la relación de acceso y la copia personal siguen siendo privadas para los nodos/usuarios implicados.

## Migración

El mismo comando del catálogo instala/actualiza estas tablas:

```bash
php drive/bin/federation_catalog_migrate.php
```

Incluye:

```text
FederationAccessRequests
FederationShares
```

y agrega idempotentemente las columnas de vínculo con Mi Drive a instalaciones existentes.

## Pruebas sin dos nodos reales

CI valida:

- solicitud Ed25519 válida;
- rechazo de manipulación;
- grant aprobado firmado;
- rechazo firmado sin URL;
- lint de servicios/endpoints;
- sintaxis del portal y de la integración de Compartidos;
- guard DB-first: la copia usa `SingleUploadService` y `UserStoragePath`;
- guard SSRF: HTTPS, DNS público, sin redirects automáticos y destino S3;
- ausencia de `ListObjects` en el flujo de Compartidos.

La prueba física usuario/nodo A -> usuario/nodo B, copia S3 y actualización de versión queda reservada para la prueba real del lunes.
