# Nodo dedicado de procesamiento multimedia

## Objetivo

ArcadeCloud Drive puede encolar trabajos pesados sin ejecutar FFmpeg en el servidor web. Un nodo EC2 más potente reclama los trabajos y procesa los objetos almacenados en S3.

Operaciones iniciales:

- dividir video en 2 a 50 partes;
- extraer el audio de un video a MP3;
- dividir audio en 2 a 50 partes;
- conservar siempre el archivo original;
- guardar todos los resultados en la misma carpeta lógica del origen;
- usar por defecto 10 segundos antes y 10 segundos después de cada frontera de división;
- aceptar archivos pequeños sin tamaño mínimo y limitar cada origen a 8 GB.

Ejemplo:

```text
audiencia.mp4
audiencia-parte1.mp4
audiencia-parte2.mp4
audiencia.mp3
```

El solapamiento evita perder palabras durante una transcripción. Por diseño, los segmentos vecinos comparten hasta 20 segundos alrededor de la frontera: 10 segundos por cada lado.

La interfaz usa un modal Bootstrap del Drive para elegir entre 2 y 50 partes. Ya no depende de `window.prompt`, de modo que funciona también en navegadores móviles/WebView donde esos diálogos pueden bloquearse.

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

Antes de descargar un objeto el worker consulta `HeadObject` en S3, rechaza cualquier origen real de más de 8 GB y exige aproximadamente 2.25 veces el tamaño del archivo como espacio libre. No existe tamaño mínimo. Los temporales se eliminan al terminar o fallar.

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


## Avisos al superadmin

El worker clasifica la ausencia de `ffmpeg`, `ffprobe` o del codificador MP3 requerido como `[DEPENDENCY_MISSING]`. El error queda en `MediaProcessingJobs` y el Centro de Tareas.

Además, cuando entra un superadmin al Drive:

- si hubo una falla reciente por dependencias, aparece un aviso para instalar FFmpeg/FFprobe y reiniciar `arcadecloud-media-worker.service`;
- si una tarea permanece en `queued` más de 2 minutos sin que ningún worker la reclame, aparece un aviso para comprobar que el nodo esté encendido y el servicio activo.

Esto cubre tanto un worker incompleto como el caso en que todavía no se ha instalado/arrancado el nodo multimedia.


## EC2 bajo demanda con autorización del usuario

El Drive puede controlar una única EC2 multimedia configurada. El usuario no envía un Instance ID arbitrario; el servidor usa exclusivamente:

```text
ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID=i-xxxxxxxxxxxxxxxxx
ARCADECLOUD_MEDIA_WORKER_REGION=us-east-1
ARCADECLOUD_MEDIA_WORKER_HOURLY_USD=0.000000
ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS=300
```

`ARCADECLOUD_MEDIA_WORKER_HOURLY_USD` debe contener la tarifa horaria de referencia de la instancia elegida. Si la EC2 está apagada y falta esa tarifa, Drive no permite un encendido pagado bajo demanda.

Flujo:

1. Drive consulta el estado de la EC2.
2. Si está `stopped`, el modal pide autorización explícita al usuario.
3. Al autorizar, se crea una sesión `MediaWorkerNodeSessions` y se llama a `Ec2Gateway::start()`.
4. La tarea queda en `MediaProcessingJobs` mientras la EC2 inicia.
5. El worker reclama y procesa la tarea.
6. Cuando la cola queda vacía comienza el período de gracia.
7. Después del período de gracia, el propio nodo llama a `Ec2Gateway::stop()`.
8. La sesión registra segundos de uso mediante `ActivityCostRecorder` con la unidad `ec2.media_worker_second`.

Los segundos se convierten a costo estimado a partir de `ARCADECLOUD_MEDIA_WORKER_HOURLY_USD`. El mínimo de referencia por sesión es 60 segundos.

### IAM del Drive que enciende el nodo

La identidad AWS usada por el Drive necesita, limitada a la EC2 multimedia cuando sea posible:

```text
ec2:DescribeInstances
ec2:StartInstances
```

### IAM de la EC2 multimedia

El rol de la instancia worker necesita:

```text
s3:GetObject
s3:PutObject
ec2:DescribeInstances
ec2:StopInstances
```

`ec2:StopInstances` debe limitarse a su propio Instance ID. El flujo multimedia no requiere `s3:DeleteObject`.

## Réplica FederationCloud sin dominio

La EC2 multimedia también puede ser una réplica FederationCloud. Para un nodo sin dominio:

```text
ARCADECLOUD_FEDERATION_ENABLED=1
ARCADECLOUD_FEDERATION_DYNAMIC_IP=1
ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL=https://drive.esforzados.com/federationcloud/
```

Al arrancar, `media_worker_node_bootstrap.sh` consulta la IPv4 pública actual con IMDSv2 y actualiza:

```text
ARCADECLOUD_PUBLIC_URL=http://IP_ACTUAL
ARCADECLOUD_FEDERATION_URL=http://IP_ACTUAL/federationcloud/
```

Después ejecuta `federation_endpoint_refresh.php`. La identidad del nodo no cambia: conserva su Node ID y clave pública; sólo se actualiza su endpoint. Si FederationCloud no logra anunciarse, el worker multimedia puede seguir procesando S3/DB.

El bootstrap de endpoint se ejecuta en un servicio systemd separado con privilegios administrativos. `arcadecloud-media-worker.service` continúa ejecutándose como usuario no privilegiado.


## Qué acciones despiertan la EC2 potente

En el diseño actual el nodo de alto rendimiento se solicita únicamente al crear un `MediaProcessingJob` para:

- `split_video`;
- `split_audio`;
- `extract_mp3`.

Amazon Transcribe no despierta esta EC2: Transcribe sigue siendo un servicio administrado de AWS y el servidor web sólo inicia/reconcilia el job. Las cargas y descargas normales tampoco encienden el worker multimedia.

Para un nodo web pequeño como una t3.micro se recomienda:

```text
ARCADECLOUD_NODE_ROLE=web
ARCADECLOUD_MEDIA_WORKER=false
```

y apuntar al worker remoto mediante:

```text
ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID=i-...
ARCADECLOUD_MEDIA_WORKER_REGION=...
ARCADECLOUD_MEDIA_WORKER_HOURLY_USD=...
ARCADECLOUD_MEDIA_WORKER_IDLE_GRACE_SECONDS=300
```

En la EC2 potente:

```text
ARCADECLOUD_NODE_ROLE=media-worker
ARCADECLOUD_MEDIA_WORKER=true
ARCADECLOUD_FEDERATION_DYNAMIC_IP=true
```

Toda esta configuración vive en `/etc/arcadecloud-drive/runtime-env.json` y puede administrarse mediante el instalador o la Configuración avanzada del superusuario.


## Instalación limpia de dependencias

En Amazon Linux 2023, una instalación con `--node-role=media-worker` o `--node-role=combined`
prepara automáticamente SPAL e instala `ffmpeg-free`, `lame` y `lame-libs`. El servicio no se
activa si faltan `ffmpeg`, `ffprobe` o `lame`.

La extracción MP3 prefiere el encoder `libmp3lame` de FFmpeg cuando existe. Si la build
`ffmpeg-free` no lo expone, el worker genera PCM WAV temporal y ejecuta `lame` a 128 kbps.
