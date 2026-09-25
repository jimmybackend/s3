# Requisitos mínimos de ArcadeCloud Drive

Este documento define el perfil mínimo soportado por rol. El instalador sigue siendo la fuente operativa: si una dependencia obligatoria falta y no puede instalarse de forma segura, debe detenerse en lugar de dejar un nodo parcialmente funcional.

## Sistema operativo

Automatización soportada:

- Amazon Linux 2023.
- Para nodos multimedia, una versión de AL2023 que disponga del repositorio SPAL (`spal-release`).
- Arquitectura x86_64 o aarch64 soportada por los paquetes de AL2023 utilizados.

## Rol web

Pensado para el coordinador, por ejemplo `drive.esforzados.com`, cuando el trabajo multimedia pesado se delega.

Mínimo operativo:

- 2 vCPU.
- 1 GiB de RAM.
- 1 GiB de swap cuando la RAM es de 1 GiB.
- 16 GiB de almacenamiento del sistema.
- salida HTTPS hacia AWS/GitHub/Composer según las funciones habilitadas.
- MySQL/MariaDB accesible.
- S3 accesible.

Recomendado si además aloja MySQL u otros servicios: 2 GiB de RAM o más.

El rol `web` no instala ni ejecuta el worker FFmpeg.

## Rol media-worker

Pensado para dividir audio/video y extraer MP3 con archivos fuente de hasta 8 GB.

Mínimo soportado para un worker de una tarea a la vez:

- 2 vCPU.
- 8 GiB de RAM.
- 40 GiB de volumen del sistema/temporal.
- al menos 18 GiB libres en el área temporal para aceptar un archivo fuente de 8 GB; el worker exige aproximadamente 2.25 veces el tamaño de origen.
- acceso a S3.
- acceso privado a la base MySQL/MariaDB compartida.
- rol IAM de instancia; no guardar claves AWS estáticas en el repositorio.

No necesita dominio para procesar. Si también funciona como réplica FederationCloud pública sin dominio, puede anunciar su IPv4 dinámica mediante el bootstrap del nodo.

## Rol combined

Debe cumplir como mínimo los requisitos del `media-worker`. No se recomienda para una EC2 pequeña destinada sólo al frontend.

## Dependencias instaladas por una instalación nueva

Todos los roles:

- PHP CLI y PHP-FPM de una única familia compatible;
- mysqlnd/PDO MySQL;
- mbstring, XML, GD y process;
- Nginx;
- Composer;
- Git;
- curl;
- Python 3;
- tar/unzip;
- sudo;
- util-linux (`runuser`, `flock`);
- procps-ng (`ps`, `pgrep`);
- coreutils (`timeout`);
- findutils, gawk, grep, sed y OpenSSL;
- Certbot compatible cuando se habilita HTTPS/FederationCloud.

`media-worker` y `combined` agregan desde SPAL:

- `ffmpeg-free` (provee `ffmpeg` y `ffprobe`);
- `lame`;
- `lame-libs`.

Para MP3, el worker usa `libmp3lame` si FFmpeg lo expone; de lo contrario usa el ejecutable `lame` como fallback.

## Esquema de base de datos

La única fuente SQL oficial es `/adbbmis1_Cloud.sql`.

Una base completamente vacía configurada desde `/setup/` se inicializa automáticamente con ese archivo. Una base que ya contiene cualquier tabla o vista nunca recibe el dump completo, porque el dump contiene `DROP TABLE`.

Tablas críticas para las funciones de procesamiento y reconciliación:

- `Users`;
- `FileS3`: catálogo visible y registro de archivos generados;
- `DriveActivityEvents`: tareas, reconciliación y costos;
- `MediaProcessingJobs`: cola de división/extracción;
- `MediaWorkerNodeSessions`: sesiones de encendido/costo de la EC2 multimedia.

CI comprueba que estas tablas sigan presentes en el SQL canónico.

## Espacio temporal

El worker descarga cada origen a `/var/lib/arcadecloud-media/tmp`. Antes de descargar verifica el tamaño real con S3 `HeadObject` y rechaza:

- archivos mayores de 8 GB;
- tareas para las que no exista aproximadamente 2.25× el tamaño de origen como espacio libre.

No existe tamaño mínimo de archivo.
