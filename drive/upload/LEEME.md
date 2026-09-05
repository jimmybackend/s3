# Módulo de subidas

La API principal del Drive es `drive/api/upload.php`. El endpoint delega en `UploadController`, que resuelve el driver mediante `UploadFactory`.

## Arquitectura

```text
api/upload.php
  -> UploadController
     -> UploadFactory
        -> LocalPresignedPutUploader
        -> RemoteUrlUploader
        -> DropboxUploader
        -> Chunked15MBUploader
```

Estructura:

```text
upload/
├── UploadFactory.php
├── core/
│   ├── UploaderInterface.php
│   └── UploadResponse.php
├── drivers/
│   ├── LocalPresignedPutUploader.php
│   ├── RemoteUrlUploader.php
│   ├── DropboxUploader.php
│   └── Chunked15MBUploader.php
├── repositories/
│   └── FileS3Repository.php
└── storage/
    └── UploadStateStore.php
```

`DriveApplication` inyecta base de datos, S3, bucket, `StorageObjectNameCodec`, `SessionManager` y almacenamiento de estado.

## Contrato HTTP

Parámetros base:

```text
mode=<modo>
action=<acción>
```

Modos:

- `local_put`: archivo local con PUT directo a S3 mediante URL presignada.
- `remote_url`: descarga una URL remota y la almacena en S3.
- `dropbox`: recibe `multipart/form-data` desde el navegador.
- `chunked`: multipart por partes con URLs presignadas.

Acciones:

- `init`
- `part`
- `complete`

## Destino

Al iniciar una subida autenticada se debe enviar `ruta_objetivo`. `UploadDestinationService` normaliza y valida esa ruta dentro de la raíz del usuario antes de que el driver la utilice.

```text
user 1 -> Data/
user 2 -> Data2/
user N -> DataN/
```

Ningún driver puede decidir una raíz distinta a la autorizada para el usuario.

## local_put

Flujo:

```text
1. navegador -> api/upload.php?mode=local_put&action=init
2. PHP genera URL presignada
3. navegador -> S3 mediante PUT
4. navegador -> action=complete
5. catálogo FileS3 actualizado
```

El cuerpo del archivo no atraviesa PHP en el PUT.

## remote_url

El servidor recibe una URL, descarga el contenido por streaming y lo almacena en S3. Después registra el objeto en `FileS3`.

```text
api/upload.php?mode=remote_url&action=init
```

## dropbox

Recibe `multipart/form-data` y utiliza `DropboxUploader`.

```text
api/upload.php?mode=dropbox&action=init
```

El archivo se registra mediante `FileS3Repository`.

## chunked

`Chunked15MBUploader` gestiona multipart reanudable.

### init

Crea el multipart y guarda el estado necesario.

### part

Firma o procesa una parte según el contrato del frontend.

### complete

Ordena las partes, completa el multipart y actualiza el catálogo.

`UploadStateStore` mantiene el estado JSON en `drive/upload/storage/state/`. Esa ruta debe ser escribible por el proceso PHP y no debe exponerse públicamente.

## Multipart directo de `up.php`

`up.php` utiliza `PublicMultipartUploadService` para una subida directa navegador -> S3 destinada a un usuario seleccionado por un operador autorizado.

```text
Navegador
  -> PHP: init / sign / resume / complete
  -> S3: partes del archivo
```

Los objetos se crean dentro de:

```text
Data/uploads/
Data2/uploads/
DataN/uploads/
```

El objeto sólo se registra en `FileS3` después de completar correctamente el multipart.

## Limpieza

`drive/bin/upload_cleanup.php` inspecciona multipart abandonados, objetos huérfanos y estados locales antiguos. La edad predeterminada es 30 días y el modo predeterminado es simulación.

Un objeto registrado en `FileS3` nunca se considera huérfano.

## Registro en MySQL

`FileS3Repository` y los repositorios de subida almacenan los datos del catálogo, incluyendo:

- `Nombre`
- `Encriptado`
- `Tamano`
- `Metadatos`
- `Ruta`
- `Found`
- `AccessType`
- `user_id_`

## Seguridad

- La API principal requiere sesión autenticada.
- La ruta se normaliza por usuario.
- Los drivers reciben dependencias por inyección.
- Las credenciales AWS no se envían al navegador.
- Las URLs presignadas tienen expiración limitada.
- Los nombres físicos se generan mediante la estrategia de almacenamiento del Drive.

## Validación

Los workflows de subida comprueban sintaxis PHP, fronteras OOP, ausencia de dependencias globales en drivers, referencias del frontend y `git diff --check`.
