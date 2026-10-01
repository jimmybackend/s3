# Arquitectura de ArcadeCloud Drive + FederationCloud

## Estado

**Baseline estable: `v1.0-oop` — 5 de septiembre de 2026.**

La migración incremental del backend heredado a una arquitectura OOP está cerrada. `main` contiene la versión estable y su evolución posterior. ArcadeCloud Drive ya no es sólo un gestor S3: sobre el plano local DB-first existe ahora **FederationCloud**, una capa de identidad, descubrimiento, autorización y resolución entre instalaciones ArcadeCloud.

El runtime ya no depende de un monolito central para operar S3. Las responsabilidades están separadas entre Controller, Service, Repository y Gateway/Infrastructure.

## Estructura general

```text
PHP entrypoint
  -> Controller
     -> Service
        -> Repository / Infrastructure
```

Los entrypoints públicos son delgados. La lógica de negocio vive bajo `drive/src/` o en los módulos OOP de `drive/upload/`.

La federación respeta la misma regla:

```text
federationcloud/*.php
  -> FederationController
     -> FederationService / FederationDirectoryService / ProviderAuthorizationService
        -> repositorios + identidad + cliente HTTP seguro
```

## Dos planos del sistema

### Plano local

Cada instalación conserva su propia autoridad sobre catálogo, usuarios y almacenamiento:

```text
ArcadeCloud Drive
  -> MySQL: navegación, metadatos y ownership
  -> S3: bytes físicos
  -> AWS: servicios auxiliares
```

### Plano federado

FederationCloud no sustituye MySQL ni S3. Añade una capa de confianza entre nodos:

```text
.arcadelink
  -> firma Ed25519
  -> node_id de origen
  -> Federation URL HTTPS
  -> validación remota
  -> resolución del recurso
```

Los nodos conservan autonomía local. Una autorización FederationCloud no concede por sí sola acceso de escritura al MySQL o S3 de otro nodo.

## Composition root

`DriveApplication` centraliza:

- `mysqli`;
- `S3Client` y bucket;
- sesión;
- repositorios;
- servicios de aplicación;
- gateways AWS;
- servicios de sharing;
- servicios de subida;
- almacenamiento y sincronización.

`ApplicationKernel` expone la instancia utilizada por los entrypoints.

Los servicios FederationCloud reciben `DriveApplication` cuando necesitan catálogo, sesión o sharing local, pero mantienen identidad criptográfica, resolución remota y autorización dentro de `drive/src/Federation/`.

## Navegación DB-first

MySQL es la fuente de verdad para la navegación normal.

```text
s3.php / bloque_archivos.php / bloque_carpetas.php
  -> servicios de aplicación
     -> FileS3 / S3Folders
```

S3 no se lista para construir la navegación diaria.

### Carpetas

```text
listar_carpetas.php
  -> FolderQueryController
     -> FolderQueryService
        -> FolderRepository
        -> UserStoragePath
```

`FolderQueryService::destinationsForUser()` separa la identidad física de la presentación. Cada destino se entrega como `value` interno con el `Prefix` real de MySQL y `label` visible construido desde `S3Folders.Nombre`. Los selects de mover deben mostrar sólo `label`; `value` se conserva para ejecutar la operación física.

### Ruta actual

```text
actualizar_ruta.php
  -> NavigationController
     -> UserStoragePath
     -> SessionManager
```

### Búsqueda

```text
buscar_archivo.php
  -> FileSearchController
     -> FileSearchService
        -> FileS3
```

### Uso de almacenamiento

```text
storage_usage.php
  -> StorageUsageController
     -> StorageUsageService
        -> FileS3
        -> SessionManager
```

## Multiusuario

La raíz se calcula exclusivamente desde el ID autenticado:

```text
user_id = 1 -> Data/
user_id = 2 -> Data2/
user_id = N -> DataN/
```

`UserStoragePath` normaliza rutas y bloquea saltos hacia raíces de otros usuarios. `UserStorageProvisioner` garantiza que la raíz exista en catálogo y S3.

Las consultas y mutaciones de usuario se limitan por `user_id_` y, cuando corresponde, `Found=1`.

La federación no elimina esta frontera: crear un ArcadeLink desde el Drive exige sesión y ownership local del archivo.

## Archivos y carpetas

### Mutaciones de archivos

Los endpoints de compatibilidad siguen delegando en la capa OOP:

```text
eliminar_archivo.php / delete_multiple.php
mover_archivo.php / move_multiple.php
renombrar_archivo.php
  -> FileMutationController
     -> FileMutationService
        -> FileRecordRepository
        -> S3Client
```

El repositorio localiza cada archivo dentro del `user_id_` autenticado. Renombrar cambia sólo el nombre visible. Mover puede cambiar la key física en S3 y actualiza el catálogo.

### Mutaciones de carpetas

```text
crear_carpeta.php
eliminar_carpeta.php
mover_carpeta.php
renombrar_carpeta.php
  -> FolderMutationController
     -> FolderMutationService
        -> FolderMutationRepository
        -> UserStoragePath
        -> StorageObjectNameCodec
        -> S3Client
```

La raíz de usuario no se puede renombrar, mover ni eliminar.

### Movimientos iniciados desde la interfaz

Los movimientos iniciados desde `s3.php` no mantienen abierta una petición HTTP durante una copia grande en S3. Se aceptan como tarea y el frontend consulta su estado.

```text
s3.php / archivos.js / carpetas.js
  -> move_task.php
     -> MoveJobController
        -> MoveJobService
           -> FileMutationService / FolderMutationService
           -> MoveJobStore
           -> S3 + MySQL

move_task_status.php
  -> MoveJobController::status
     -> MoveJobService
        -> MoveJobStore
```

Flujo:

```text
usuario elige destino visible de MySQL
  -> POST de tarea
  -> HTTP 202 inmediato
  -> queued / running
  -> copia y eliminación física en S3
  -> actualización MySQL
  -> completed / failed
  -> polling del navegador
  -> notificación y refresco de bloques
```

`MoveJobStore` guarda estado temporal por `job_id` y `user_id`. Por defecto usa el directorio temporal del sistema y puede configurarse con `ARCADECLOUD_MOVE_JOB_DIR`. Los estados se protegen con `flock` y se depuran automáticamente.

El navegador nunca necesita mostrar el `Prefix` físico. El `label` del destino proviene de `S3Folders.Nombre`, mientras que el `value` interno conserva el `Prefix` real que necesitan `FileMutationService` y `FolderMutationService`.

La opción de crear una carpeta desde el modal de mover delega en `FolderMutationService`; no construye un `Prefix` físico concatenando texto visible.

### Seguridad de archivos

```text
set_file_security.php
unlock_file.php
relock_file.php
  -> FileSecurityController
     -> FileSecurityService
        -> FileSecurityRepository
```

### Acceso y descargas

```text
descargar.php
descargar_archivo.php
descargar_zip.php
download_multiple.php
ver_pdf.php
ver_archivo.php
  -> FileAccessController
     -> FileAccessService / ZipDownloadService
        -> FileRecordLocator
        -> S3Client
```

### Texto

```text
leer_texto.php
guardar_texto.php
validar_php.php
  -> TextEditorController
     -> TextFileService / PhpLintService
        -> FileRecordLocator
        -> S3Client
```

### Rotación de key física

```text
encriptar_archivo.php
  -> FileKeyRotationController
     -> FileKeyRotationService
        -> FileRecordLocator
        -> StorageObjectNameCodec
```

## Nombres lógicos y físicos

- `FileS3.Nombre`: nombre visible.
- `FileS3.Encriptado`: nombre o key física del objeto almacenado.
- `S3Folders.Nombre`: nombre visible de carpeta.
- `S3Folders.Prefix`: identificador físico interno de carpeta.
- renombrar modifica el catálogo visible;
- mover puede cambiar la ubicación física;
- `StorageObjectNameCodec` centraliza la generación y lectura de nombres físicos;
- los `Prefix` físicos no deben mostrarse en rutas o selects de la interfaz cuando pueda construirse una etiqueta desde `S3Folders.Nombre`.

ArcadeLink no publica la referencia física privada como autoridad pública. La referencia necesaria para continuidad local permanece dentro del payload cifrado.

## Autenticación

```text
psesion.php
  -> AuthController::login
     -> AuthenticationService
        -> AuthenticationRepository
        -> SessionManager

logout.php
  -> AuthController::logout
     -> SessionManager
```

`AuthenticationRepository` conoce `Users` y `AccessControl`. `SessionManager` concentra inicio, autenticación, preferencias de sesión y destrucción.

La administración sensible de proveedores FederationCloud utiliza `Users.system_role`; sólo `superadmin` puede aprobar, rechazar o revocar autorizaciones.

## Sharing tradicional

```text
generar_token.php
  -> ShareController
     -> ShareLinkService
        -> ShareFileRepository
        -> ShareTokenStore

token_audio.php / token_video.php / token_texto.php / ver.php
  -> PublicShareController
     -> ShareAccessService
        -> ShareFileRepository
        -> ShareTokenStore
        -> ShareObjectStorage
     -> SharePageRenderer
```

La creación de enlaces requiere sesión y ownership. El acceso público requiere token válido. Las peticiones directas por key requieren sesión y ownership.

## FederationCloud y ArcadeLink

FederationCloud vive bajo:

```text
drive/federationcloud/
drive/src/Federation/
```

Cada nodo posee una identidad independiente:

```text
node_name
node_id = acn_...
public_key
public_url
federation_url
```

La clave privada y `payload_key` permanecen fuera del repositorio y del DocumentRoot.

### Descriptor de nodo

```text
federationcloud/node.php
  -> FederationController::nodeApi()
     -> FederationService
        -> NodeIdentityService
        -> FederationConfig
```

El descriptor público se firma con Ed25519.

### ArcadeLink

```text
Compartir archivo
  -> federationcloud/create.php
     -> FederationController::createApi()
        -> FederationService
           -> FederatedResourceRepository
           -> ArcadeLinkService
```

La UI normal del Drive permite descargar un `.arcadelink` desde el modal **Compartir**.

La resolución humana usa:

```text
federationcloud/index.php
  -> FederationController::index()
     -> FederationService
        -> ArcadeLinkService
        -> FederationResolverService
        -> FederationHttpClient
```

El flujo visual es:

```text
dropzone
 -> seleccionar/soltar .arcadelink
 -> validación automática
 -> recurso verificado
 -> Abrir
```

### Seguridad criptográfica

- firma: Ed25519;
- payload privado: XChaCha20-Poly1305;
- `content_id`: SHA-256 cuando ya existe y la visibilidad permite publicarlo;
- cliente remoto limitado a HTTPS:443;
- sin redirects;
- protección SSRF y fijación de IP DNS;
- sin descargador arbitrario de URLs.

### Descubrimiento y directorio

```text
federationcloud/register.php
federationcloud/nodes.php
  -> servicios de directorio FederationCloud
     -> validación criptográfica
     -> verificación HTTPS del nodo anunciado
     -> FederationNodes
```

Registrar un nodo no lo autoriza para servir recursos de otro nodo.

### Proveedores autorizados

```text
federation_provider_request.php
  -> provider-request.php
     -> verificar descriptor + node.php remoto
     -> FederationNodeAuthorizations: pending

superadmin en nodo origen
  -> provider-admin.php
     -> aprobar / rechazar / revocar
     -> autorización Ed25519 origen -> proveedor
```

La arquitectura se probó con dos instalaciones: `drive.esforzados.com` como origen y
`fastdrive.esforzados.com` como `mirror` autorizado, incluyendo workers simultáneos sobre una
MySQL compartida y failover de aplicación.

### Estado de federación

Implementado:

- identidad Ed25519 por nodo;
- descriptor firmado y descubrimiento mediante seed;
- Aduana serializada y aislada por `TargetNodeId`;
- solicitud/aprobación/revocación provider/mirror;
- reactivación de mirrors sin nueva aprobación;
- ArcadeLink portable;
- `FederatedResources` y `FederationResourceLocations`;
- selección `mirror -> provider -> origin`;
- replicación/copias autorizadas para recursos compatibles;
- workers de réplica aislados por Node ID sobre MySQL compartida;
- resolución local/remota y sharing temporal.

La instalación operativa está en `drive/docs/FEDERATION_NODE_REPLICA_INSTALL.md`.

P2P/BitTorrent no forma parte de la etapa actual.

## Multimedia

```text
media_playlist.php
  -> MediaPlaylistController
     -> MediaPlaylistService
        -> MediaPlaylistRepository

thumb.php
  -> ThumbnailController
     -> ThumbnailService
```

La playlist se construye desde `FileS3`. Las miniaturas utilizan catálogo, S3 y cache privada.

## Subidas

### API principal

```text
api/upload.php
  -> UploadController
     -> UploadFactory
        -> LocalPresignedPutUploader
        -> RemoteUrlUploader
        -> DropboxUploader
        -> Chunked15MBUploader
```

Los drivers reciben dependencias por inyección y registran los objetos terminados en `FileS3`.

### Subida simple compatible

```text
upload.php / subir_archivo.php
  -> LegacyUploadController
     -> SingleUploadService / UploadFactory
        -> UploadCatalogRepository
        -> StorageObjectNameCodec
```

### Multipart administrativo

```text
up.php
  -> AdminMultipartUploadService
     -> PublicMultipartUploadService
     -> UserDirectoryRepository
     -> UserStorageProvisioner
     -> UploadCatalogRepository
```

El navegador envía las partes directamente a S3 mediante URLs presignadas. Al completar, el objeto se registra en `FileS3` para el usuario destino.

### Zona pública

```text
upload_publico.php
  -> PublicUploadController
     -> PublicDropzoneUploadService
        -> UploadCatalogRepository

subir_publico.php
  -> PublicSharedBrowserController
     -> PublicSharedBrowserService
        -> PublicSharedBrowserRepository
     -> PublicSharedPageRenderer
```

La zona pública queda confinada a la raíz compartida configurada.

### Limpieza manual

```text
up-clean.php
  -> UploadCleanupController
     -> UploadCleanupService

bin/upload_cleanup.php
  -> UploadCleanupCommand
     -> UploadCleanupService
```

La edad predeterminada es 30 días. Los objetos registrados en `FileS3` nunca se eliminan como huérfanos.

## Sincronización

```text
sync_s3_to_db.php
sync_status.php
  -> SyncController
     -> S3SyncService
        -> SyncRepository
        -> SyncJobStore

bin/sync_worker.php
```

La sincronización recorre S3 por lotes y actualiza el catálogo sin convertir S3 en la fuente de navegación normal.

## Servicios AWS

```text
costos_aws.php
  -> AwsCostController
     -> AwsCostService
        -> CostExplorerGateway
```

Las acciones sobre archivos AWS delegan en `AwsFileController` y servicios especializados para Rekognition, Textract, Polly, Translate y Comprehend.

Transcribe utiliza `TranscriptionController` y `TranscriptionFileService`. El frontend inicia la transcripción, informa que el trabajo continúa en segundo plano y consulta su estado hasta notificar que el resultado está listo.

## Herramientas AWS personales

```text
aws.php
  -> PersonalAwsController
     -> PersonalToolAccessService
     -> PersonalTotpService
        -> PersonalAwsConfig
     -> PersonalAwsPageRenderer
```

Con sesión del Drive, sólo `user_id = 1` tiene acceso. Contraseñas, hashes operativos, nombres privados de cuentas y semillas TOTP se leen desde configuración privada fuera del repositorio. Las semillas permanecen del lado servidor.

La configuración privada de producción utiliza:

```text
/etc/arcadecloud-drive/personal-aws.json
```

## Administración EC2 y RDS

```text
ec2.php
  -> PersonalToolAccessService
  -> Ec2Gateway / RdsGateway
  -> Ec2PanelHelper

ec2-cron.php
  -> Ec2CostGuardService
     -> Ec2Gateway
     -> Ec2CronLogger
```

`ec2.php` muestra y opera los recursos del propietario. `ec2-cron.php` aplica la política horaria de protección de costos. Los clientes AWS no se construyen dentro de los entrypoints.

## Criterios de cierre de v1.0-oop

La migración se considera cerrada porque:

- los entrypoints principales delegan en Controller/Service;
- la navegación normal permanece DB-first;
- no existen consumidores runtime del antiguo monolito S3;
- las operaciones sensibles usan el `user_id_` autenticado;
- las acciones AWS fueron probadas en producción;
- Transcribe funciona de manera asíncrona con seguimiento de estado;
- la rama de migración fue fusionada y retirada;
- producción ejecuta `main`.

FederationCloud es una extensión posterior a ese baseline y no cambia el significado histórico del tag `v1.0-oop`.

## Reglas obligatorias

1. No agregar SQL a entrypoints públicos.
2. No agregar llamadas AWS/S3 a entrypoints cuando exista un Service/Gateway responsable.
3. No listar S3 para navegación normal.
4. No aceptar rutas o registros de otro usuario.
5. No exponer secretos, credenciales, identidades privadas, `payload_key` ni semillas TOTP en Git, HTML o JSON público.
6. No modificar `vendor/`.
7. Mantener los contratos HTTP utilizados por el frontend.
8. Todo endpoint FederationCloud remoto debe validar identidad, firma, HTTPS y límites de payload antes de confiar en otro nodo.
9. Registrar un nodo no debe equivaler a autorizarlo como proveedor.
10. Mantener `FileS3` como fuente de verdad local aunque exista una capa federada.
11. Validar cambios con `php -l`, `node --check` cuando aplique y `git diff --check`.
12. Las nuevas funcionalidades deben desarrollarse desde `main` en ramas independientes y volver mediante merge validado.

## Operaciones del filesystem en ArcadeCloud OS

El OS utiliza una sola capa frontend, `ArcadeCloudFilesystemOperations`, para
describir operaciones, impedir envíos idénticos simultáneos, normalizar estados
y publicar cambios confirmados. El portapapeles global y el drag & drop siguen
compartiendo `MoveJobService`/`DriveMoveTasks`; no existe un segundo clipboard,
EventBus ni WindowManager.

Inventario auditado:

- copiar y mover archivos/carpetas: jobs reales en segundo plano, con progreso
  por elementos para lotes y progreso indeterminado cuando el backend no puede
  medirlo;
- eliminar, renombrar y crear carpetas: mutaciones directas existentes sobre
  `FileMutationService` y `FolderMutationService`;
- uploads locales, por URL y multipart: centro de tareas existente, con bytes
  reales y notificación selectiva de la ruta destino;
- descarga simple y múltiple: endpoints existentes, sin tarea servidor ni
  cancelación falsa;
- cancelación: cooperativa entre archivos en `MoveJobService`; una mutación S3
  individual en curso no se presenta como cancelable;
- conflictos de copia: `FileMutationService` y `FolderMutationService` conservan
  ambos elementos generando de forma segura nombres `- copia`; no sobrescriben
  silenciosamente y por ello no se ofrece una política de reemplazo inexistente;
- eliminación: permanece permanente; no hay Papelera ni Undo simulados.

`filesystem:changed` se emite únicamente después de una respuesta satisfactoria
o de la finalización confirmada de un job. Su payload conserva rutas origen y
destino para que `refreshAffected` actualice sólo las Explorer coincidentes. Un
fallo mantiene la vista actual, conserva el clipboard de movimiento y registra
el diagnóstico en la operación sin publicar un cambio exitoso.

## Aplicaciones de archivo (Etapa 4)

### Inventario previo conservado

Antes de esta etapa, imagen, PDF y texto ya se abrían como ventanas dinámicas, pero la decisión vivía en `so.js`; audio y video usaban un único overlay que reemplazaba al anterior. Office/Workstation se abría en una pestaña externa mediante `office-launch.php`. Los tipos desconocidos se entregaban al visor inline del navegador y todos los archivos legibles conservaban descarga. El editor existente (`editor.php`) se mantiene para texto; el visor inline existente (`ver_archivo.php`) se mantiene para imagen, PDF y multimedia. Las acciones de fondo de pantalla, información/servicios, seguridad, compartir y descarga del menú no se eliminan.

| Familia | Extensiones catalogadas | Implementación actual de apertura |
| --- | --- | --- |
| Imagen | jpg, jpeg, png, gif, webp, bmp, avif, tif, tiff, svg | ventana OS `image`; imagen responsive; descargar y usar como fondo |
| PDF | pdf | ventana OS `pdf`; render inline del navegador, conservando scroll/zoom del visor |
| Texto/datos | txt, md, html, css, js, JSON, CSV, SQL, PHP, Python, subtítulos, log, XML, YAML | ventana OS `text` con el editor existente y `text-preview` con el visor inline existente |
| Audio | mp3, wav, ogg, opus, m4a, aac, flac, amr | ventana OS `audio` con controles nativos |
| Video | mp4, webm, mov, avi, mkv, m4v, mpeg, mpg | ventana OS `video` responsive con controles nativos |
| Office | DOC/DOCX/ODT/RTF, XLS/XLSX/ODS, PPT/PPTX/ODP | aplicación Office existente en pestaña externa; no se modificó Workstation/Guacamole |
| Otros | cualquier archivo legible sin asociación local | conserva descarga y el aviso de no soportado; no se inventa una aplicación |

Las miniaturas, servicios AWS y diálogos operativos preexistentes siguen siendo modales o paneles según su implementación anterior; no forman parte del registro de aplicaciones de documentos.

### File Application Registry

`ArcadeCloudWindowManager.apps` continúa siendo el único registro. `ArcadeCloudFileApplicationService.registerApplications()` lo amplía con `appId`, título, icono, `multiInstance`, ciclo de vida, tipos MIME y extensiones admitidas. No existe un segundo WindowManager ni un segundo EventBus. Imagen, PDF, texto, audio y video son aplicaciones dinámicas multiinstancia. Office representa únicamente la integración real existente.

### File Association Resolution

`applicationsFor(file)` consulta las definiciones del registro. Un MIME catalogado y no genérico tiene prioridad; la extensión visible sólo complementa o actúa como fallback porque el catálogo histórico no siempre conserva MIME. `so.php` expone el MIME guardado en `FileS3.Metadatos` cuando existe, junto con FileId, nombre y fecha. La asociación predeterminada interna sigue el orden imagen, PDF, texto, audio, video y Office. No se creó persistencia SQL ni se guardan asociaciones o URLs firmadas en `Users.os_preferences`.

`openFile(file, options)` es el punto de entrada común. “Abrir con…” aparece solamente cuando el registro devuelve dos o más aplicaciones reales compatibles (actualmente editor y visor para texto) y usa el diálogo temático del OS. Office sigue delegando en `office-launch.php`; los viewers locales siguen usando endpoints autenticados existentes.

### Application Instance Lifecycle

La identidad de deduplicación es `appId + FileId`; sólo cuando FileId no existe se usa la key autorizada. La URL de contenido no es identidad. Por defecto, abrir otra vez el mismo archivo en la misma aplicación enfoca su ventana; `forceNew` permite una duplicación explícita. Archivos diferentes siempre obtienen `windowId` diferentes.

Cada ventana dinámica se registra en WindowManager, por lo que obtiene de forma aislada focus, cascada, geometría pequeña, resize, minimizar, maximizar, taskbar y cierre. Minimizar audio/video no detiene la reproducción; cerrarlo sí pausa y libera el recurso. Al iniciar otro audio o video local, el reproductor anterior se pausa para evitar reproducción simultánea accidental.

El cierre ejecuta el cleanup de WindowManager: elimina la asociación de instancia, desuscribe el EventBus, retira listeners multimedia y el WindowManager pausa y descarga el `src` de media. Los viewers no crean blobs ni object URLs. Los errores se muestran dentro de la ventana y no navegan fuera del OS.

### Filesystem Event Integration

Cada instancia se suscribe al `filesystem:changed` ya existente. Compara primero FileId y usa la key únicamente como compatibilidad con operaciones históricas. Un delete confirmado sustituye el contenido por “Este archivo ya no está disponible”. Un rename con FileId y nuevo nombre actualiza metadata, título y taskbar sin cerrar la ventana. Los eventos de modificación marcan el documento como cambiado; no se inventan ETag, versiones ni colaboración. Cuando una operación todavía sólo publica keys y rutas, la reacción se limita a una coincidencia segura por key.

La autorización continúa en `ver_archivo.php`, `editor.php`, endpoints de descarga y Office: sesión, ownership y key/FileId se vuelven a validar en backend. El frontend no recibe credenciales AWS, secretos de base de datos ni tokens internos, y no persiste URLs temporales.

### Auditoría final de compatibilidad

**Funciones preexistentes conservadas:** WindowManager, cascada, taskbar, drag/resize/minimize/maximize, Explorer multiinstancia, clipboard global, operaciones unificadas, progreso/jobs, EventBus, editor de texto, render PDF del navegador, Office/Workstation, descarga, servicios, compartir, seguridad y “Usar como fondo”.

**Funciones corregidas:** audio y video dejaron de compartir un overlay destructivo; la selección del visor dejó de estar dispersa por extensión en el flujo principal; imágenes/PDF/textos repetidos ahora aplican identidad estable y política de focus.

**Funciones nuevas/completadas:** registro tipado, resolución MIME-first, `openFile`, instancias documentales aisladas, reproductores como ventanas, diálogo “Abrir con…”, coordinación multimedia, notificaciones rename/delete/modify y cleanup por instancia.

## Etapa 5: Desktop Shell

`ArcadeCloudDesktopShell` integra, sin duplicarlos, el `ArcadeCloudWindowManager`, su registro de aplicaciones y `ArcadeCloudEventBus`. El manager publica `activeId`, conserva el orden MRU mediante `lastFocused` y continúa siendo la autoridad de apertura, foco, minimizar, restaurar, cierre, geometría y botones de tareas. La taskbar refleja estados activo, abierto y minimizado; mantiene una tarea por instancia, títulos accesibles y overflow horizontal.

La ventana **Aplicaciones** se construye desde las definiciones lanzables que existen en el registro y en el DOM. Los handlers exclusivos de archivos no aparecen como lanzadores. Su búsqueda es local y admite flechas, Enter y Escape. Los singleton se restauran a través del manager y Mis datos conserva la creación explícita multiinstancia.

El router global de teclado comprueba primero el elemento enfocado. Inputs, textareas, `contenteditable`, terminales y editores conservan copiar, cortar, pegar y Delete nativos. Fuera de ellos enruta Ctrl/Cmd+L y las operaciones existentes del Explorer activo. Alt+Tab usa sólo registros abiertos/minimizados, ordenados por uso reciente; al soltar Alt restaura y enfoca la selección.

El menú contextual del fondo sólo ofrece acciones reales (Explorer, Configuración y refresco de Explorers). Menús, selector, diálogos y notificaciones ocupan capas definidas de la shell. Las notificaciones son de sesión, no modales, se apilan hasta cinco y escuchan `notification:show` y los estados finales de `filesystem:operation`; una tarea en ejecución sigue siendo tarea y sólo su resultado final produce una notificación resumida.
