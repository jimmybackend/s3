# ArcadeCloud Drive

ArcadeCloud Drive es un gestor de archivos multiusuario construido sobre **Amazon S3 + MySQL + PHP**, con herramientas de AWS para procesar, proteger, convertir y reproducir contenido.

El proyecto ya no trata a S3 como una carpeta que se lista en cada navegación. La arquitectura actual usa **MySQL como catálogo y fuente de verdad para la navegación diaria**, mientras que **S3 conserva el contenido físico** y se consulta de forma explícita cuando hace falta sincronizar, leer o modificar objetos.

> Estado actual: aplicación funcional en evolución. La navegación, archivos, carpetas, seguridad, multimedia, sincronización y varias integraciones AWS están operativas; aún quedan endpoints heredados por migrar a OOP y pruebas funcionales por consolidar.

---

## Principios de arquitectura

### DB-first

La navegación normal consulta MySQL, no hace `ListObjects` sobre S3.

```text
Navegador
   |
   v
PHP / servicios de aplicación
   |
   +----> MySQL: rutas, nombres visibles, permisos, estado
   |
   +----> S3: contenido físico y operaciones explícitas
```

Esto evita que una carpeta con miles de objetos dependa de una consulta completa a S3 en cada clic.

### Nombres visibles y nombres físicos separados

El usuario trabaja con nombres legibles guardados en MySQL, mientras que S3 puede conservar nombres físicos estables.

- `FileS3.Nombre`: nombre visible del archivo.
- `FileS3.Encriptado`: nombre físico/key asociado al objeto.
- `S3Folders.Nombre`: nombre visible de la carpeta.
- Renombrar un archivo cambia el nombre lógico en MySQL sin tener que copiar el objeto en S3.
- Mover sí puede cambiar la ubicación física, conservando el basename físico.

### Multiusuario

La raíz se deriva del ID real del usuario:

```text
user_id = 1  -> Data/
user_id = 2  -> Data2/
user_id = 3  -> Data3/
...
user_id = N  -> DataN/
```

La raíz de cada usuario está protegida: no se puede renombrar, mover ni eliminar.

---

## Funcionalidades actuales

### Archivos

- Listado paginado desde MySQL.
- Búsqueda global por nombre con ruta y fecha.
- Filtros por nombre, extensión y fechas.
- Ver imágenes y miniaturas.
- Ver PDF.
- Reproducir audio y video.
- Editor de texto/código para formatos compatibles.
- Renombrar.
- Descargar.
- Eliminar.
- Mover.
- Compartir.
- Acciones múltiples sobre archivos seleccionados.
- Compactar y descargar ZIP.
- Mostrar ruta lógica, fecha, tamaño y ubicación física S3.

### Carpetas

- Crear carpeta.
- Renombrar carpeta.
- Mover carpeta.
- Eliminar carpeta.
- Árbol de navegación.
- Raíz provisionada automáticamente por usuario.
- Vista responsive mediante panel lateral off-canvas en móvil/tableta.

### Seguridad

- Encriptar / transformar físicamente archivos cuando corresponde.
- Proteger archivo.
- Desbloquear.
- Volver a bloquear.
- Quitar protección.
- Separación de archivos por `user_id_`.
- Normalización de rutas por usuario.

### Multimedia

Existe un reproductor flotante persistente para audio y video:

- Puede moverse dentro de la página.
- Conserva su posición.
- Sigue reproduciendo aunque el usuario cambie de carpeta dentro del Drive.
- Playlist construida desde MySQL para la carpeta de origen.
- Anterior / play-pausa / siguiente.
- Avance automático al siguiente archivo.
- Audio y video comparten el mismo concepto de reproductor persistente.

### Subidas

El proyecto contiene más de una estrategia porque se han mantenido rutas compatibles mientras se migra la arquitectura.

#### Subida normal del Drive

La interfaz principal usa drivers de subida desacoplados mediante `UploadFactory` y servicios dedicados.

#### Subida grande `up.php`

`up.php` fue migrado a **multipart directo navegador -> S3 mediante URLs presignadas**.

```text
Navegador
   |
   +---- init/sign/complete ----> PHP
   |
   +==== partes grandes ========> S3
                                  |
                                  +--> FileS3 al completar
```

El archivo pesado ya no necesita atravesar PHP-FPM o Nginx. PHP autoriza, firma, completa el multipart y registra el archivo en MySQL.

El tamaño de las partes puede adaptarse al dispositivo/conexión y el servicio soporta partes grandes sin depender de `upload_max_filesize` para el cuerpo del archivo.

> Pendiente: completar una batería de pruebas reales de `up.php` con archivos pequeños, medianos y de varios cientos de MB/GB, incluyendo pausa, reanudación, cancelación, CORS y fallos de red.

---

## Sincronización S3 -> MySQL

La sincronización manual no se ejecuta como una petición HTTP larga.

La arquitectura actual utiliza:

- `SyncController`
- `S3SyncService`
- `SyncRepository`
- `SyncJobStore`
- `drive/bin/sync_worker.php`

El navegador crea el trabajo y consulta su estado; un worker independiente procesa S3 por lotes en segundo plano. De esta forma CloudFront/Nginx no necesitan mantener abierta una petición durante miles de objetos.

La sincronización intenta preservar nombres visibles existentes en MySQL y reconstruye filas ausentes sin convertir S3 en el mecanismo normal de navegación.

---

## Servicios AWS utilizados

Dependiendo del tipo de archivo y la acción elegida, el Drive integra o contiene soporte para:

- **Amazon S3**: almacenamiento, multipart, URLs presignadas y contenido.
- **Amazon Rekognition**: análisis de imágenes.
- **Amazon Textract**: extracción de texto de documentos/imágenes.
- **Amazon Transcribe**: audio/video a texto.
- **Amazon Polly**: texto a voz.
- **Amazon Translate**: traducción.
- **Amazon Comprehend**: análisis de texto.
- **AWS Cost Explorer**: consulta de costos desde la interfaz administrativa.
- Funciones de administración EC2/RDS existentes en el repositorio.

---

## Estructura principal

```text
s3/
├── .github/workflows/        # auditorías y controles CI
├── composer.json
├── composer.lock
├── Config-s3.php             # compatibilidad/configuración del proyecto
├── db.php                    # compatibilidad DB
├── README.md
└── drive/
    ├── app_bootstrap.php
    ├── index.php
    ├── s3.php
    ├── up.php
    ├── S3Manager.php
    ├── ARCHITECTURE.md
    ├── css/
    │   ├── styles.css
    │   └── responsive.css
    ├── js/
    │   ├── archivos.js
    │   ├── carpetas.js
    │   ├── audiovideo.js
    │   └── media-floating.js
    ├── api/
    ├── bin/
    │   └── sync_worker.php
    ├── docs/
    │   ├── OOP_AUDIT.md
    │   ├── RUNTIME_ENDPOINTS.md
    │   ├── oop_inventory.json
    │   └── runtime_endpoints.json
    ├── src/
    │   ├── Application/
    │   ├── Aws/
    │   ├── Core/
    │   ├── Http/
    │   ├── Media/
    │   ├── Security/
    │   ├── Storage/
    │   ├── Sync/
    │   ├── Upload/
    │   └── View/
    └── upload/
        ├── drivers/
        ├── repositories/
        └── storage/
```

`drive/ARCHITECTURE.md` contiene las reglas internas de diseño y `drive/docs/` mantiene inventarios/auditorías del código heredado y de los endpoints.

---

## Orientación a objetos

La dirección del proyecto es:

```text
entrypoint PHP
    -> Controller
        -> Service
            -> Repository / Infrastructure
```

Las nuevas funciones deben evitar agregar lógica SQL o AWS directamente a los entrypoints públicos.

Ya existen, entre otros:

- `DriveApplication`
- `DrivePageService`
- `DrivePageViewModel`
- `SessionManager`
- `FileListService`
- `FileSearchService`
- `UploadDestinationService`
- `UserStoragePath`
- `UserStorageProvisioner`
- servicios AWS y de seguridad
- servicios de sincronización
- servicios y drivers de subida
- `FileViewHelper`
- `FolderTreeRenderer`

La auditoría actual del repositorio sigue detectando una cantidad importante de PHP y JavaScript heredado pendiente de migración, por lo que la transición se está haciendo por módulos para no romper producción.

---

## Dependencias PHP

Composer instala actualmente:

- `aws/aws-sdk-php`
- `spomky-labs/otphp`
- `bacon/bacon-qr-code`

Instalación:

```bash
composer install --no-dev --optimize-autoloader
```

---

## Configuración

Las credenciales no deben vivir en Git ni quedar escritas en los archivos públicos.

La aplicación espera configuración de entorno para MySQL y AWS, por ejemplo:

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

En producción estas variables deben inyectarse mediante el servicio/entorno del servidor. No se deben subir archivos `.env`, claves privadas ni credenciales al repositorio.

---

## Responsive

La aplicación tiene una capa específica `drive/css/responsive.css` que se carga después de los estilos generales.

Incluye:

- móvil y tableta reales;
- sidebar off-canvas;
- navbar compacto;
- acciones táctiles;
- modales adaptativos;
- tarjetas de archivo con nombres/rutas largas contenidas;
- footer responsive;
- reproductor multimedia flotante.

---

## CI y auditorías

`.github/workflows/` contiene verificaciones relacionadas con:

- migración OOP del core;
- servicios AWS;
- acceso a archivos;
- controladores HTTP;
- seguridad;
- sincronización;
- editor de texto;
- Transcribe;
- inventario OOP;
- mapa de referencias de runtime;
- recuperación/consistencia de keys.

También se mantienen:

- `drive/docs/OOP_AUDIT.md`
- `drive/docs/RUNTIME_ENDPOINTS.md`
- inventarios JSON generados.

---

## Qué falta / roadmap

Prioridades actuales:

1. **Probar completamente el multipart Direct-to-S3 de `up.php`** con diferentes tamaños, redes y reanudación.
2. **Persistir visualmente los jobs de sincronización** para que la interfaz pueda recuperar el `job_id` después de refrescar el navegador.
3. **Limpieza automática de multipart abandonados y temporales** después del periodo definido.
4. **Continuar la migración OOP** de endpoints heredados que todavía contienen SQL, sesión o llamadas AWS directas.
5. **Reducir JavaScript global/heredado** y encapsular módulos manteniendo una fachada de compatibilidad cuando sea necesaria.
6. **Pruebas funcionales automatizadas** de navegación, permisos, subida, movimiento, renombrado, seguridad, sincronización y multimedia.
7. **Pruebas de recuperación**: reconstruir catálogo desde S3 + backups de MySQL y verificar nombres/rutas.
8. **Documentar despliegue reproducible** de Nginx, PHP-FPM, variables de entorno, permisos runtime y workers.
9. **Office/documentos avanzados**: decidir si DOCX/XLSX/PPTX se editan mediante un servicio Office externo separado o solo se convierten; no conviene cargar un editor Office completo dentro de una instancia mínima del Drive.
10. **Retirar archivos/endpoints heredados** únicamente cuando el mapa de referencias confirme que ya no tienen consumidores.

---

## Estado de madurez

ArcadeCloud Drive ya dispone de una base funcional bastante más amplia que un simple explorador S3:

- catálogo DB-first;
- almacenamiento S3;
- multiusuario;
- seguridad;
- búsqueda y filtros;
- multimedia persistente;
- subida multipart directa;
- sincronización en background;
- herramientas AWS;
- responsive móvil/tableta/escritorio;
- arquitectura OOP en migración;
- inventarios y controles CI.

El trabajo pendiente está concentrado principalmente en **robustez, pruebas, limpieza de legado y despliegue reproducible**, no en demostrar el concepto básico del Drive.

---

## Licencia

Este proyecto se distribuye bajo **GPL-3.0**. Consulta `LICENSE` para los términos completos.
