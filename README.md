# ArcadeCloud Drive

ArcadeCloud Drive es un gestor de archivos familiar multiusuario construido sobre **PHP + MySQL + Amazon S3**. MySQL es la fuente de verdad para la navegación y S3 conserva el contenido físico.

## Estado estable

**Versión de cierre: `v1.0-oop` — 5 de septiembre de 2026.**

La migración incremental del backend heredado a arquitectura orientada a objetos está terminada. La versión estable vive en `main` y mantiene los contratos HTTP y la funcionalidad existente del Drive. Las nuevas funciones posteriores a ese baseline continúan respetando la misma arquitectura.

Principios de esta línea estable:

- entrypoints PHP delgados;
- lógica de negocio bajo `drive/src/`;
- navegación diaria DB-first;
- acceso multiusuario limitado por `user_id_`;
- S3 como almacenamiento físico, no como índice de navegación;
- servicios AWS detrás de Controller / Service / Gateway;
- configuración privada y secretos fuera del repositorio;
- procesos largos, como Amazon Transcribe, ejecutados y consultados de forma asíncrona.

## Principios

### DB-first

La navegación normal consulta MySQL. S3 se utiliza para leer, escribir, mover, eliminar, sincronizar y procesar objetos cuando una operación lo requiere.

```text
Navegador
   -> Entrypoint PHP
      -> Controller
         -> Service
            -> Repository / Infrastructure
               -> MySQL / S3 / AWS
```

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
- Renombrar cambia el catálogo MySQL.
- Mover puede cambiar la ubicación física manteniendo el basename físico.

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
- enlaces compartidos;
- protección, desbloqueo y rebloqueo;
- cálculo de uso de almacenamiento.

### Actividad y costos

`drive/activity_costs.php` muestra la actividad del usuario autenticado y separa claramente:

- **costo atribuido / ESTIMADO**, calculado a partir de unidades observables de las operaciones del Drive;
- **costo REAL AWS**, obtenido mediante la integración existente con AWS Cost Explorer cuando la autorización privada ya existente lo permite.

La página incluye filtros por período, servicio y operación, desgloses diarios, por servicio y por acción, y actividad reciente. El registro es best effort: una falla de telemetría no debe romper una operación válida del Drive.

Los precios de atribución están desacoplados en `drive/config/activity-cost-pricing.json`. Las unidades que no pueden tasarse con suficiente precisión se marcan como parciales o no tasadas en vez de inventar un costo.

La tabla `DriveActivityEvents` forma parte del esquema base `adbbmis1_Cloud.sql`; una instalación nueva crea el módulo de actividad junto con el resto de la base de datos, sin ejecutar una migración incremental separada.

Consulta `drive/docs/ACTIVITY_COSTS.md`.

### Multimedia

El reproductor flotante utiliza una playlist obtenida desde MySQL mediante:

```text
media_playlist.php
  -> MediaPlaylistController
     -> MediaPlaylistService
        -> MediaPlaylistRepository
```

### Subidas

La API principal es:

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

Las superficies públicas de subida y navegación compartida están separadas en `PublicUploadController` y `PublicSharedBrowserController` y quedan confinadas a la raíz compartida configurada.

### Limpieza de subidas abandonadas

`drive/bin/upload_cleanup.php` analiza multipart incompletos, objetos huérfanos y estados locales antiguos dentro de `DataN/uploads/`. Por defecto trabaja en simulación. La eliminación requiere `--execute` y nunca elimina objetos registrados en `FileS3`.

### Sincronización

La sincronización S3 -> MySQL se ejecuta en segundo plano:

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

Las acciones AWS del listado funcionan también en dispositivos táctiles. Amazon Transcribe inicia el trabajo en segundo plano y el frontend consulta su estado hasta notificar que la transcripción está lista.

`ec2.php` es un panel personal para revisar y operar recursos EC2. `ec2-cron.php` aplica la política horaria definida para evitar mantener recursos de prueba encendidos fuera del horario permitido.

### Herramienta AWS personal

`aws.php` es una herramienta privada separada de las funciones familiares del Drive.

- con sesión del Drive, sólo `user_id = 1` puede utilizarla;
- cualquier otro usuario autenticado recibe HTTP 403;
- sin sesión se requiere la contraseña privada configurada fuera del repositorio;
- las semillas TOTP permanecen en un archivo privado del servidor y no se envían al navegador.

Consulta `drive/docs/PERSONAL_AWS_TOOL.md`.

## Arquitectura OOP

El código de aplicación vive principalmente bajo `drive/src/`:

```text
drive/src/
├── Activity/
├── Application/
├── Aws/
├── Console/
├── Core/
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

El antiguo monolito de acceso S3 ya no forma parte del runtime. Las responsabilidades están distribuidas entre repositories, services, gateways y utilidades de infraestructura específicas.

## Estructura principal

```text
s3/
├── .github/
│   ├── scripts/
│   └── workflows/
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
    ├── up.php
    ├── aws.php
    ├── ec2.php
    ├── ec2-cron.php
    ├── ARCHITECTURE.md
    ├── api/
    │   └── upload.php
    ├── bin/
    │   ├── sync_worker.php
    │   └── upload_cleanup.php
    ├── config/
    ├── css/
    ├── js/
    ├── docs/
    ├── src/
    └── upload/
```

## Configuración privada

Las credenciales y secretos no deben almacenarse en Git ni dentro del DocumentRoot. La producción utiliza configuración privada del servidor para MySQL, AWS y herramientas personales.

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

La herramienta TOTP personal utiliza por defecto:

```text
/etc/arcadecloud-drive/personal-aws.json
```

## Dependencias

Composer administra las dependencias PHP, incluyendo AWS SDK y OTPHP.

```bash
composer install --no-dev --optimize-autoloader
```

## Documentación

- `drive/ARCHITECTURE.md`: arquitectura y reglas obligatorias.
- `drive/docs/RELEASE_V1_OOP.md`: cierre de la migración y baseline estable.
- `drive/docs/ACTIVITY_COSTS.md`: auditoría de operaciones, atribución de costos y reconciliación con Cost Explorer.
- `drive/docs/DB_FIRST_NAVIGATION.md`: navegación y consultas del catálogo.
- `drive/docs/KEY_ROTATION.md`: rotación de key física.
- `drive/docs/UPLOAD_CLEANUP.md`: limpieza segura de subidas abandonadas.
- `drive/docs/PERSONAL_AWS_TOOL.md`: herramienta personal y configuración privada.
- `drive/docs/RUNTIME_ENDPOINTS.md`: inventario de entrypoints runtime.
- `drive/upload/LEEME.md`: API y drivers de subida.

## CI

`.github/workflows/` valida sintaxis PHP, fronteras OOP, referencias, seguridad, subida, sincronización, sharing, navegación, multimedia, actividad/costos y `git diff --check`.

## Flujo de trabajo después de v1.0-oop

Los cambios nuevos deben partir de `main` en una rama de funcionalidad o mantenimiento, validarse y volver a `main` mediante merge. La migración OOP ya no es una rama de trabajo activa.

## Licencia

GPL-3.0. Consulta `LICENSE`.
