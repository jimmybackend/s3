# Nodo dedicado de procesamiento multimedia

## Objetivo

ArcadeCloud Drive puede encolar trabajos pesados sin ejecutar FFmpeg en el servidor web. Un nodo EC2 más potente reclama los trabajos y procesa los objetos almacenados en S3.

Operaciones iniciales:

- dividir video en 2 a 50 partes;
- extraer el audio de un video a MP3;
- dividir audio en 2 a 50 partes;
- conservar siempre el archivo original;
- guardar todos los resultados en la misma carpeta lógica del origen;
- usar por defecto 10 segundos antes y 10 segundos después de cada frontera de división.

Ejemplo:

```text
audiencia.mp4
audiencia-parte1.mp4
audiencia-parte2.mp4
audiencia.mp3
```

El solapamiento evita perder palabras durante una transcripción. Por diseño, los segmentos vecinos comparten hasta 20 segundos alrededor de la frontera: 10 segundos por cada lado.

## Arquitectura

```text
Drive web
   |
   | INSERT MediaProcessingJobs
   v
MariaDB  <----- worker dedicado
                    |
                    | GetObject
                    v
                   S3
                    |
              ffprobe / ffmpeg
                    |
                    | PutObject
                    v
                   S3
                    |
                    +--> FileS3
```

S3 almacena; no ejecuta FFmpeg. El trabajo de CPU/disco ocurre únicamente en el nodo worker autorizado.

## Cola

`MediaProcessingJobRepository` crea idempotentemente la tabla `MediaProcessingJobs` cuando el flujo se usa por primera vez. Estados:

- `queued`
- `running`
- `completed`
- `failed`

Los archivos de salida se registran también en `FileS3`, respetando DB-first.

## Seguridad

El worker se niega a arrancar salvo que exista:

```bash
ARCADECLOUD_MEDIA_WORKER=1
```

No debe ponerse esta variable en el servidor web si se desea reservar el procesamiento para el nodo potente.

La instancia worker debe usar preferentemente un IAM Role de EC2. No guardar claves AWS dentro del repositorio.

Permisos mínimos sobre el bucket del Drive:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:PutObject"
      ],
      "Resource": "arn:aws:s3:::BUCKET_DEL_DRIVE/*"
    }
  ]
}
```

El worker no necesita `s3:DeleteObject` para este flujo y nunca elimina el original.

## Base de datos

El nodo worker debe poder conectarse a la misma MariaDB usada por Drive. Si la base está en otra EC2:

1. mantener MariaDB en red privada;
2. permitir TCP/3306 únicamente desde el Security Group o IP privada del worker;
3. usar un usuario de BD con los permisos requeridos por ArcadeCloud Drive;
4. no abrir 3306 a Internet.

## Espacio temporal

Por defecto:

```text
/var/lib/arcadecloud-media/tmp
```

Antes de descargar un objeto el worker exige aproximadamente 2.25 veces el tamaño del archivo como espacio libre cuando el tamaño es conocido. Los temporales se eliminan al terminar o fallar.

## Instalación del nodo

Después de clonar/actualizar el repositorio y configurar el mismo entorno de Drive:

```bash
sudo bash drive/bin/install_media_processing_worker.sh /var/www/arcadecloud-drive
```

El script exige que ya existan:

```text
php
ffmpeg
ffprobe
```

y crea:

```text
arcadecloud-media-worker.service
```

Comprobación:

```bash
sudo systemctl status arcadecloud-media-worker
sudo journalctl -u arcadecloud-media-worker -f
```

## Nombres y preservación

Video `predicacion.mp4` dividido en tres:

```text
predicacion.mp4
predicacion-parte1.mp4
predicacion-parte2.mp4
predicacion-parte3.mp4
```

La extracción de audio produce:

```text
predicacion.mp3
```

Si un destino ya existe, el trabajo falla antes de sobrescribirlo. El objeto fuente nunca se modifica ni se elimina.

## Calidad

Dividir video/audio usa `-c copy`: no recodifica y mantiene la calidad original. Los puntos reales pueden ajustarse a keyframes según el contenedor/códec.

Extraer MP3 usa `libmp3lame` a 128 kbps, suficiente como formato de trabajo para voz y transcripción.
