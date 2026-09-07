# Arquitectura de ArcadeCloud Drive

## Estado

**Baseline estable: `v1.0-oop` — 5 de septiembre de 2026.**

La migración incremental del backend heredado a una arquitectura OOP está cerrada. `main` contiene la versión estable desplegada en producción. A partir de este punto, los cambios nuevos son mantenimiento o nuevas funcionalidades y deben partir de `main`.

El runtime ya no depende de un monolito central para operar S3. Las responsabilidades están separadas entre Controller, Service, Repository y Gateway/Infrastructure.

## Estructura general

```text
PHP entrypoint
  -> Controller
     -> Service
        -> Repository / Infrastructure
```

Los entrypoints públicos son delgados. La lógica de negocio vive bajo `drive/src/` o en los módulos OOP de `drive/upload/`.

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
- `StorageObjectNameCodec` centraliza la generación y lectura de nombres físicos.
- los `Prefix` físicos no deben mostrarse en rutas o selects de la interfaz cuando pueda construirse una etiqueta desde `S3Folders.Nombre`.

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

## Sharing

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

Las acciones AWS del listado soportan interacción táctil mediante eventos de puntero y mantienen separación visual de las acciones principales en móvil.

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
- las acciones AWS, incluida la interacción móvil, fueron probadas en producción;
- Transcribe funciona de manera asíncrona con seguimiento de estado;
- la rama de migración fue fusionada y retirada;
- producción ejecuta `main`.

## Reglas obligatorias

1. No agregar SQL a entrypoints públicos.
2. No agregar llamadas AWS/S3 a entrypoints cuando exista un Service/Gateway responsable.
3. No listar S3 para navegación normal.
4. No aceptar rutas o registros de otro usuario.
5. No exponer secretos, credenciales ni semillas TOTP en Git, HTML o JSON público.
6. No modificar `vendor/`.
7. Mantener los contratos HTTP utilizados por el frontend.
8. Validar cambios con `php -l`, `node --check` cuando aplique y `git diff --check`.
9. Las nuevas funcionalidades deben desarrollarse desde `main` en ramas independientes y volver mediante merge validado.
