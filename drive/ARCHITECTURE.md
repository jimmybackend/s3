# Arquitectura de ArcadeCloud Drive

## Estructura general

```text
PHP entrypoint
  -> Controller
     -> Service
        -> Repository / Infrastructure
```

Los archivos públicos deben ser delgados. La lógica de negocio vive bajo `drive/src/` o en módulos OOP de `drive/upload/`.

## Composition root

`DriveApplication` centraliza dependencias compartidas:

- `mysqli`;
- `S3Client`;
- bucket;
- sesión;
- repositorios;
- servicios de aplicación;
- servicios AWS;
- servicios de sharing;
- servicios de subida;
- servicios de almacenamiento y sincronización.

`ApplicationKernel` expone la instancia única utilizada por los entrypoints.

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

Las consultas de usuario deben limitarse por `user_id_` y, cuando corresponda, `Found=1`.

## Archivos y carpetas

### Mutaciones de archivos

```text
eliminar_archivo.php
move_multiple.php
mover_archivo.php
renombrar_archivo.php
  -> FileMutationController
```

### Mutaciones de carpetas

```text
crear_carpeta.php
eliminar_carpeta.php
mover_carpeta.php
renombrar_carpeta.php
  -> FolderMutationController
```

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
```

### Texto

```text
leer_texto.php
guardar_texto.php
validar_php.php
  -> TextEditorController
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
- `FileS3.Encriptado`: basename físico.
- `S3Folders.Nombre`: nombre visible de carpeta.
- la raíz del usuario no se puede renombrar, mover ni eliminar;
- renombrar modifica el catálogo;
- mover puede cambiar la ubicación física;
- `StorageObjectNameCodec` centraliza la generación y lectura de nombres físicos.

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

token_audio.php
token_video.php
token_texto.php
ver.php
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

Los drivers reciben sus dependencias por inyección. `FileS3Repository` registra los archivos y `UploadStateStore` mantiene el estado de multipart cuando corresponde.

### Multipart directo

`up.php` crea multipart en la raíz `DataN/uploads/`. El navegador envía partes directamente a S3 mediante URLs presignadas. Al completar, el objeto se registra en `FileS3`.

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

Las acciones sobre archivos AWS delegan en `AwsFileController` y servicios especializados para Rekognition, Textract, Polly, Translate y Comprehend. Transcribe utiliza `TranscriptionController` y `TranscriptionFileService`.

## Herramienta AWS personal

```text
aws.php
  -> PersonalAwsController
     -> PersonalToolAccessService
     -> PersonalTotpService
        -> PersonalAwsConfig
     -> PersonalAwsPageRenderer
```

Con sesión del Drive, sólo `user_id = 1` tiene acceso. La configuración privada se lee fuera del repositorio y las semillas TOTP permanecen del lado servidor.

## Administración EC2

`ec2.php` muestra y opera los recursos EC2 utilizados por el propietario. `ec2-cron.php` aplica la política horaria de apagado configurada para evitar recursos de prueba encendidos fuera de horario.

## Reglas obligatorias

1. No agregar SQL a entrypoints públicos.
2. No agregar llamadas AWS/S3 a entrypoints cuando exista un Service/Gateway responsable.
3. No listar S3 para navegación normal.
4. No aceptar rutas de otro usuario.
5. No exponer secretos, credenciales ni semillas TOTP en Git, HTML o JSON.
6. No modificar `vendor/`.
7. Mantener los contratos HTTP utilizados por el frontend.
8. Validar cambios con `php -l`, `node --check` cuando aplique y `git diff --check`.
