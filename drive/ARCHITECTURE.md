# Arquitectura OOP de ArcadeCloud Drive

## Estructura

```text
raiz_proyecto/
├── vendor/
├── Config-s3.php
├── db.php
└── drive/
    ├── app_bootstrap.php
    ├── s3.php
    ├── S3Manager.php
    ├── src/
    │   ├── Core/
    │   ├── Application/
    │   ├── Aws/
    │   ├── Http/
    │   ├── Media/
    │   ├── Security/
    │   ├── Sharing/
    │   ├── Storage/
    │   ├── Sync/
    │   ├── Upload/
    │   └── View/
    └── upload/
```

`drive/` es el DocumentRoot de la aplicación. `vendor/`, `Config-s3.php` y `db.php`
están exactamente una carpeta arriba y no deben exponerse por HTTP.

## Regla de diseño

El desarrollo nuevo y las refactorizaciones del Drive se implementan orientados a objetos:

- Los archivos PHP públicos son controladores o entrypoints delgados.
- La lógica de negocio vive en clases bajo `src/` o en módulos OOP existentes.
- `DriveApplication` actúa como composition root y centraliza dependencias compartidas.
- `SessionManager` encapsula sesión y autenticación.
- `DrivePageService` y `DrivePageViewModel` contienen la lógica de la página principal.
- `S3Manager` es un servicio de infraestructura y acepta inyección de S3, mysqli y bucket.
- `upload/` conserva Factory, drivers, repositories y storage orientados a objetos.
- Los endpoints JSON nuevos deben reutilizar `Http\JsonResponse`.
- Los endpoints heredados se migran al patrón Controller -> Service -> Repository/Infrastructure.
- No debe agregarse nueva lógica de negocio procedural a los entrypoints.

## Compatibilidad

`s3.php` conserva temporalmente variables para alimentar el HTML heredado, pero filtros,
paginación, sesión y construcción de estado ya se obtienen mediante objetos. Esto permite
migrar los endpoints restantes por módulos sin romper de golpe la aplicación en producción.

## Autenticación

Los formularios existentes siguen enviando sus credenciales a `psesion.php` y el cierre de
sesión sigue usando `logout.php`. Ambos archivos son ahora entrypoints delgados:

```text
psesion.php
    -> AuthController::login()
        -> AuthenticationService
            -> AuthenticationRepository (Users / AccessControl)
            -> SessionManager

logout.php
    -> AuthController::logout()
        -> SessionManager
```

Reglas preservadas del flujo histórico:

1. máximo de tres intentos de login por identificador dentro de la sesión;
2. credenciales inválidas redirigen a `303.html`;
3. usuarios no activos redirigen a `202.html`;
4. los roles `Administración` y `Soporte` entran a `s3.php`;
5. el acceso correcto se registra en `AccessControl`;
6. las preferencias iniciales de sesión (`show_counts`, `show_metas`, `media_hidden`, `show_filters`) se conservan.

La sesión ya no se manipula desde los endpoints. `SessionManager` concentra inicio, creación de
sesión autenticada, contador de intentos y destrucción de sesión. Al autenticar correctamente se
regenera el identificador de sesión para evitar conservar el ID previo al login.

`AuthenticationRepository` es la única pieza del módulo que conoce las tablas `Users` y
`AccessControl`; `AuthenticationService` concentra la validación del hash, estado del usuario y
creación de la sesión autenticada.

## Costos AWS

El modal existente sigue consultando `costos_aws.php` y conserva el mismo contrato JSON visible:

```text
costos_aws.php
    -> AwsCostController
        -> AwsCostService
            -> CostExplorerGateway
                -> AWS Cost Explorer
```

`AwsCostController` valida la sesión y libera el lock de PHP antes de esperar a AWS.
`AwsCostService` calcula los periodos, porcentajes y previsión del mes. `CostExplorerGateway`
es la única clase del módulo que crea y conoce `CostExplorerClient`.

La respuesta compatible mantiene `mes_actual`, `costo_actual`, `porcentaje_actual`,
`fin_mes_previsto`, `costo_previsto`, `porcentaje_previsto` y `currency`. Se eliminaron del
endpoint los datos de depuración de identidad AWS y cualquier fragmento de access key; una
respuesta HTTP nunca debe exponer credenciales ni identificadores derivados de ellas.

## Media y miniaturas

Los endpoints de lectura de playlist y miniaturas ya no contienen SQL ni manipulación directa
de sesión:

```text
media_playlist.php
    -> MediaPlaylistController
        -> MediaPlaylistService
            -> MediaPlaylistRepository (FileS3)
            -> UserStoragePath

thumb.php
    -> ThumbnailController
        -> ThumbnailService
            -> MySQL / S3 / cache privada
```

`MediaPlaylistRepository` filtra siempre por `user_id_`, ruta, `Found=1` y excluye archivos con
`AccessType='secure'`. `MediaPlaylistService` normaliza la ruta dentro de la raíz real del usuario
y conserva el contrato `audio` / `video` que consume el reproductor flotante.

`ThumbnailController` conserva ETag, respuestas 304, headers `X-Thumb-*` y fallback de imagen.
El snapshot de sesión necesario para validar archivos protegidos se obtiene mediante
`SessionManager`; el entrypoint `thumb.php` no lee `$_SESSION` ni `$_GET`.

## Provisionamiento multiusuario

La raíz física/lógica del Drive se deriva exclusivamente del `Users.id` autenticado:

```text
user_id = 1  -> Data/
user_id = 2  -> Data2/
user_id = 3  -> Data3/
user_id = N  -> DataN/
```

`UserStoragePath` es la única clase autorizada para calcular y normalizar esas raíces.
`UserStorageProvisioner` se ejecuta al entrar a `s3.php` y es idempotente:

1. consulta `S3Folders` por `user_id_ + Prefix`;
2. si la raíz ya está registrada, no consulta ni escribe S3;
3. si es el primer acceso, crea el objeto vacío `DataN/` en S3;
4. registra la raíz en `S3Folders` con `Found=1`;
5. la sesión se normaliza de nuevo contra la raíz del usuario antes de construir la página.

Esto permite que un usuario creado por cualquier sistema de registro quede provisionado en su
primer acceso al Drive, siempre usando el ID real asignado por MySQL. La separación lógica en
BD sigue siendo obligatoria mediante `user_id_` y ningún endpoint debe aceptar una raíz de otro usuario.

## Nombres visibles vs. nombres físicos en S3

El nombre que ve el usuario es un dato lógico de MySQL. Renombrar no renombra objetos en S3.

- Archivo nuevo: `f_<32hex>-<nombre-de-creacion.ext>`.
- Carpeta nueva: `d_<32hex>-<nombre-de-creacion>/`.
- Raíz: `Data/` para `user_id=1`; `DataN/` para los demás usuarios. La raíz no se puede renombrar, mover ni eliminar.
- `FileS3.Nombre` y `S3Folders.Nombre` son los nombres visibles y pueden cambiar sin modificar la key/prefix físico.
- Mover sí cambia la ubicación física, pero conserva el basename físico y el nombre visible.
- La sincronización nunca sobrescribe un `Nombre` visible existente. Si reconstruye una fila ausente, `StorageObjectNameCodec` recupera el nombre de creación desde el sufijo de la key.
- Para archivos históricos `f_*` que no incorporaban nombre, la sincronización manual intenta `headObject` y metadata `original-name`; si tampoco existe, solo puede recuperar el basename físico.

Esta separación evita colisiones de nombres y permite reconstruir el catálogo desde S3. Como consecuencia deliberada, un nombre cambiado únicamente en MySQL después de la creación no puede recuperarse desde S3 si se pierde completamente la base de datos; se recuperará el nombre de creación. Para preservar también los renombrados posteriores hace falta respaldar MySQL o un manifiesto independiente.

## Compartir archivos

Los enlaces compartidos siguen conservando los endpoints históricos para no romper URLs ni JavaScript existente:

- `generar_token.php`
- `token_audio.php`
- `token_video.php`
- `token_texto.php`
- `ver.php` como visor de compatibilidad para enlaces antiguos

Estos entrypoints no contienen lógica de sesión, SQL, filesystem ni AWS. El flujo es:

```text
generar_token.php
    -> ShareController
        -> ShareLinkService
            -> ShareFileRepository (MySQL / ownership)
            -> ShareTokenStore (tokens.json con flock)

 token_*.php / ver.php
    -> PublicShareController
        -> ShareAccessService
            -> ShareFileRepository
            -> ShareTokenStore
            -> ShareObjectStorage (S3)
        -> SharePageRenderer
```

Reglas del módulo:

1. crear un enlace requiere sesión autenticada;
2. antes de crear el token se comprueba que el objeto pertenece al `user_id_` real en `FileS3` y que `Found=1`;
3. una petición directa por key sin token también requiere sesión y ownership;
4. el acceso con un token válido sigue siendo público hasta su expiración;
5. los tokens nuevos conservan el formato histórico y agregan `user_id`, `file_id` y `nombre` para poder validar ownership;
6. los tokens legacy que no tienen esos campos siguen siendo legibles para no romper enlaces existentes;
7. `tokens.json` se mantiene fuera del repositorio y se actualiza con bloqueo de archivo para evitar escrituras concurrentes corruptas;
8. `ShareObjectStorage` es la única pieza del módulo que conoce S3;
9. `ver.php` conserva `?t=`/`?token=` y `?direct`/`?download`, pero delega toda validación y acceso a las clases del módulo.

La persistencia en `tokens.json` es deliberadamente compatible con producción. Si más adelante se cambia a MySQL u otro storage, debe hacerse detrás de la misma responsabilidad de `ShareTokenStore`, sin volver a introducir persistencia en los endpoints.

## Subidas de archivos

La subida que utiliza actualmente el Drive conserva `api/upload.php` como URL pública, pero el
endpoint ya no conoce sesiones globales, S3, MySQL ni la selección concreta del driver:

```text
api/upload.php
    -> UploadController
        -> UploadFactory
            -> LocalPresignedPutUploader
            -> RemoteUrlUploader
            -> DropboxUploader
            -> Chunked15MBUploader
```

`DriveApplication` inyecta `mysqli`, `S3Client`, bucket, `StorageObjectNameCodec`,
`SessionManager` y el storage de estado en `UploadFactory`. Los cuatro drivers dejaron de leer
`$db_connection`, `$GLOBALS`, `Config::getS3()`, `$_SESSION`, `$_SERVER` y `$_FILES`.

Los endpoints antiguos `upload.php` y `subir_archivo.php` se conservan como fachadas de
compatibilidad y delegan a `LegacyUploadController`; no se eliminan mientras no se confirme que
no existen consumidores externos.

La zona pública conserva sus URLs históricas, pero también está separada por responsabilidades:

```text
upload_publico.php
    -> PublicUploadController
        -> PublicDropzoneUploadService
            -> UploadCatalogRepository
            -> S3

subir_publico.php
    -> PublicSharedBrowserController
        -> PublicSharedBrowserService
            -> PublicSharedBrowserRepository
            -> S3
        -> PublicSharedPageRenderer
```

Reglas de seguridad y compatibilidad de la zona pública:

1. toda subida y navegación pública queda confinada a `Config::RUTA_COMPARTIDA` (`Data/Compartidos/` actualmente);
2. un `prefix` o `ruta` no puede escapar de esa raíz ni contener segmentos `..`;
3. la creación de carpetas rechaza separadores, caracteres de control y nombres `.`/`..`;
4. `upload_publico.php` conserva el contrato JSON y registra el archivo en `FileS3` mediante repository;
5. `subir_publico.php` conserva la página, navegación, Dropzone y creación de carpetas, pero ya no contiene llamadas S3 ni SQL;
6. el nombre visible se recupera de `FileS3` por `Encriptado + Ruta` y, si no existe catálogo, se muestra el basename físico;
7. la navegación normal autenticada del Drive sigue siendo DB-first; el listado S3 de `subir_publico.php` es una superficie pública heredada y explícita, no se usa para la navegación normal multiusuario.

El workflow `oop-upload.yml` valida sintaxis, entrypoints delgados, ausencia de globals en los
drivers, límites de la raíz compartida y `git diff --check` antes de considerar cerrado el módulo.
