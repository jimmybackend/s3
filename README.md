# ArcadeCloud Drive + FederationCloud

ArcadeCloud Drive es una **plataforma de almacenamiento multiusuario con capa de cloud federado**, construida sobre **PHP + MySQL + Amazon S3**.

MySQL sigue siendo la fuente de verdad para navegación y metadatos, Amazon S3 conserva el contenido físico y **FederationCloud** añade identidad criptográfica de nodos, descubrimiento, autorización entre servidores y resolución de recursos mediante archivos portables `.arcadelink`.

El proyecto comenzó como un Drive web para S3. Hoy puede operar como una red de instalaciones ArcadeCloud independientes que se reconocen y validan entre sí sin publicar claves AWS, sesiones ni rutas físicas privadas de S3.

## Estado actual

**Baseline estable histórico: `v1.0-oop` — 5 de septiembre de 2026.**

La migración incremental del backend heredado a arquitectura orientada a objetos está terminada. `main` contiene la evolución posterior de ese baseline e incorpora actualmente:

- navegación DB-first;
- arquitectura OOP estable;
- multiusuario por `user_id_`;
- almacenamiento físico en Amazon S3;
- actividad y costos;
- servicios AWS;
- **FederationCloud**;
- **ArcadeLink** portable y firmado;
- identidad Ed25519 por nodo;
- descubrimiento y validación HTTPS entre nodos;
- solicitudes y aprobación de proveedores;
- resolución local y remota de ArcadeLinks.

Principios que siguen siendo obligatorios:

- entrypoints PHP delgados;
- lógica de negocio bajo `drive/src/`;
- navegación diaria DB-first;
- acceso multiusuario limitado por `user_id_`;
- S3 como almacenamiento físico, no como índice de navegación;
- servicios AWS detrás de Controller / Service / Gateway;
- configuración privada y secretos fuera del repositorio;
- procesos largos ejecutados y consultados de forma asíncrona;
- FederationCloud no concede acceso implícito a MySQL, S3 ni secretos de otro nodo.

## De Drive S3 a cloud federado

La arquitectura actual separa dos planos:

```text
PLANO LOCAL
Navegador
   -> Entrypoint PHP
      -> Controller
         -> Service
            -> Repository / Infrastructure
               -> MySQL / S3 / AWS

PLANO FEDERADO
.arcadelink
   -> validar firma Ed25519
      -> identificar node_id origen
         -> consultar FederationCloud por HTTPS
            -> resolver recurso / proveedor autorizado
```

Cada instalación conserva autonomía local. FederationCloud añade una capa de confianza y resolución entre instalaciones.

La federación actualmente cubre:

```text
identidad criptográfica
+ descubrimiento de nodos
+ autorización origen -> proveedor
+ ArcadeLink portable
+ resolución entre nodos
```

Todavía no incluye P2P, replicación automática, escritura remota sobre S3 de terceros, buscador global ni selección automática del mejor proveedor por recurso.

Consulta `drive/docs/FEDERATED_CLOUD_STATUS.md` y `drive/docs/FEDERATIONCLOUD.md`.

## ArcadeLink

`.arcadelink` es el pasaporte portable de un recurso ArcadeCloud.

Un ArcadeLink puede conservar:

- `resource_id` lógico;
- nodo de origen;
- URL federada;
- título y tipo de recurso;
- tamaño;
- visibilidad;
- derechos declarados;
- `content_id` SHA-256 cuando exista y la política permita publicarlo;
- payload privado cifrado;
- firma Ed25519.

La referencia local sensible se protege con **XChaCha20-Poly1305**.

Desde la interfaz normal del Drive, el botón **Compartir** permite generar y descargar un `.arcadelink`. En `/federationcloud/` puede soltarse ese archivo en un dropzone; el sistema valida automáticamente la firma, resuelve el recurso y ofrece **Abrir** cuando la política lo permite.

Modos de visibilidad:

- `PUBLIC`: preparado para anuncio o búsqueda futura;
- `UNLISTED`: resuelve quien posee el ArcadeLink, sin búsqueda general;
- `PRIVATE`: no anuncia fingerprint público y exige política local compatible.

Derechos v1:

- `link_only`;
- `unknown_rights`;
- `user_owned_authorized`;
- `copy_allowed`.

## FederationCloud

Cada instalación puede tener identidad propia:

```text
node_name
node_id = acn_...
public_key
public_url
federation_url
```

La identidad se firma con **Ed25519** y se almacena fuera del repositorio.

Los nodos pueden descubrirse mediante un seed y validar en vivo el `node.php` anunciado antes de aceptar su identidad.

Un nodo registrado **no** se convierte automáticamente en proveedor. La autorización de proveedor es una segunda capa de confianza:

```text
nodo proveedor
  -> envía solicitud firmada
     -> nodo origen verifica identidad y HTTPS
        -> solicitud pending
           -> superadmin aprueba/rechaza
              -> autorización firmada origen -> proveedor
```

La arquitectura ya fue probada con dos instalaciones distintas, una como nodo origen y otra como proveedor autorizado.

## Principios locales

### DB-first

La navegación normal consulta MySQL. S3 se utiliza para leer, escribir, mover, eliminar, sincronizar y procesar objetos cuando una operación lo requiere.

### Multiusuario

Cada usuario tiene una raíz aislada derivada de su ID:

```text
user_id = 1 -> Data/
user_id = 2 -> Data2/
user_id = N -> DataN/
```

La raíz de usuario no se puede renombrar, mover ni eliminar. Las consultas del catálogo se limitan mediante `user_id_` y `Found=1`.

### Nombre visible y nombre físico

- `FileS3.Nombre`: nombre visible.
- `FileS3.Encriptado`: nombre físico del objeto.
- `S3Folders.Nombre`: nombre visible de carpeta.
- `S3Folders.Prefix`: referencia física interna.
- renombrar cambia el catálogo visible;
- mover puede cambiar la ubicación física.

## Funcionalidad

### Archivos y carpetas

- navegación paginada DB-first;
- búsqueda global por nombre;
- filtros por nombre, extensión y fecha;
- miniaturas, imágenes y PDF;
- reproducción de audio y video;
- editor de texto/código;
- crear, renombrar, mover y eliminar carpetas;
- renombrar, mover, descargar y eliminar archivos;
- acciones múltiples;
- ZIP;
- enlaces compartidos tradicionales;
- ArcadeLink federado;
- protección, desbloqueo y rebloqueo;
- cálculo de uso de almacenamiento.

### Actividad y costos

`drive/activity_costs.php` muestra la actividad del usuario autenticado y separa:

- **costo atribuido / ESTIMADO**;
- **costo REAL AWS**, cuando Cost Explorer está autorizado.

Los eventos FederationCloud registrados incluyen:

- `arcadelink_create`;
- `arcadelink_resolve`;
- `arcadelink_open`.

Consulta `drive/docs/ACTIVITY_COSTS.md`.

### Multimedia

```text
media_playlist.php
  -> MediaPlaylistController
     -> MediaPlaylistService
        -> MediaPlaylistRepository
```

### Subidas

```text
api/upload.php
  -> UploadController
     -> UploadFactory
        -> LocalPresignedPutUploader
        -> RemoteUrlUploader
        -> DropboxUploader
        -> Chunked15MBUploader
```

También existe `up.php` para multipart directo navegador -> S3. PHP inicia, firma y completa la operación; el cuerpo pesado viaja directamente a S3 y al finalizar se registra en `FileS3`.

### Limpieza de subidas abandonadas

`drive/bin/upload_cleanup.php` analiza multipart incompletos, objetos huérfanos y estados locales antiguos dentro de `DataN/uploads/`. La eliminación requiere `--execute` y nunca elimina objetos registrados en `FileS3`.

### Sincronización

```text
sync_s3_to_db.php / sync_status.php
  -> SyncController
     -> S3SyncService
        -> SyncRepository
        -> SyncJobStore

bin/sync_worker.php
```

La sincronización reconstruye y actualiza catálogo; no sustituye la navegación DB-first.

### Servicios AWS

El Drive integra:

- Amazon S3;
- Rekognition;
- Textract;
- Transcribe;
- Polly;
- Translate;
- Comprehend;
- Cost Explorer.

Amazon Transcribe trabaja de forma asíncrona y el frontend consulta su estado hasta completar.

## Arquitectura OOP

El código de aplicación vive principalmente bajo `drive/src/`:

```text
drive/src/
├── Activity/
├── Application/
├── Aws/
├── Console/
├── Core/
├── Federation/
├── Http/
│   └── Controller/
├── Media/
├── Security/
├── Sharing/
├── Storage/
├── Sync/
├── Upload/
└── View/
```

`DriveApplication` es el composition root. `ApplicationKernel` expone la instancia de aplicación y los entrypoints públicos delegan en controladores.

FederationCloud respeta la misma arquitectura y no introduce lógica criptográfica o SQL directamente en entrypoints públicos.

## Estructura principal

```text
s3/
├── .github/
├── composer.json
├── composer.lock
├── Config-s3.php
├── db.php
├── README.md
└── drive/
    ├── app_bootstrap.php
    ├── index.php
    ├── login.php
    ├── s3.php
    ├── activity_costs.php
    ├── federationcloud/
    ├── ARCHITECTURE.md
    ├── api/
    ├── bin/
    ├── config/
    ├── css/
    ├── js/
    ├── docs/
    ├── src/
    └── upload/
```

## Configuración privada

Las credenciales y secretos no deben almacenarse en Git ni dentro del DocumentRoot.

Variables habituales:

```text
DB_HOST
DB_PORT
DB_USER
DB_PASSWORD
DB_NAME
AWS_REGION
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
AWS_S3_BUCKET
```

FederationCloud añade:

```text
ARCADECLOUD_PUBLIC_URL
ARCADECLOUD_FEDERATION_URL
ARCADECLOUD_FEDERATION_ENABLED
ARCADECLOUD_FEDERATION_IDENTITY
ARCADECLOUD_FEDERATION_SEED_URL   # opcional
```

La identidad del nodo se guarda fuera del DocumentRoot, por ejemplo:

```text
/etc/arcadecloud-drive/federation-node.json
```

No debe publicarse ni copiarse a Git.

## Dependencias

Composer administra las dependencias PHP.

```bash
composer install --no-dev --optimize-autoloader
```

## Documentación

- `drive/ARCHITECTURE.md`: arquitectura y reglas obligatorias.
- `drive/docs/FEDERATED_CLOUD_STATUS.md`: estado actual de la evolución hacia cloud federado.
- `drive/docs/FEDERATIONCLOUD.md`: protocolo ArcadeLink/FederationCloud, seguridad y roadmap.
- `drive/docs/FEDERATION_PROVIDER_APPROVALS.md`: autorización de proveedores.
- `drive/docs/FEDERATION_NODE_RECOVERY.md`: continuidad y recuperación de identidad.
- `drive/docs/RELEASE_V1_OOP.md`: baseline histórico del cierre OOP.
- `drive/docs/ACTIVITY_COSTS.md`: auditoría y costos.
- `drive/docs/DB_FIRST_NAVIGATION.md`: navegación y catálogo.
- `drive/docs/KEY_ROTATION.md`: rotación de key física.
- `drive/docs/UPLOAD_CLEANUP.md`: limpieza segura de subidas abandonadas.
- `drive/docs/PERSONAL_AWS_TOOL.md`: herramienta AWS personal.
- `drive/docs/RUNTIME_ENDPOINTS.md`: inventario de entrypoints runtime.
- `drive/upload/LEEME.md`: API y drivers de subida.

## CI

`.github/workflows/` valida sintaxis PHP, fronteras OOP, seguridad, subida, sincronización, sharing, navegación, multimedia, actividad/costos y FederationCloud, incluyendo smoke tests criptográficos y de autorización de proveedores.

## Flujo de desarrollo

Los cambios nuevos parten de `main`, se desarrollan en ramas independientes, pasan validación y vuelven mediante pull request.

El tag `v1.0-oop` sigue siendo el baseline histórico de la migración OOP; FederationCloud representa la evolución funcional posterior del proyecto.

## Licencia

GPL-3.0. Consulta `LICENSE`.
