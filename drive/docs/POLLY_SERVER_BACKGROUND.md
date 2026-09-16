# Amazon Polly en segundo plano desde el servidor

## Objetivo

La generación larga de texto a audio debe continuar aunque el usuario cierre el modal, cambie de página, cierre el navegador o pierda conexión. El servidor EC2 es responsable de reconciliar la tarea, colocar el audio final en la misma carpeta del texto origen y registrar los costos atribuibles.

## Error corregido: `OutputS3KeyPrefix`

`StartSpeechSynthesisTask` restringe `OutputS3KeyPrefix` a un conjunto ASCII. Las rutas normales de ArcadeCloud Drive pueden contener espacios, acentos y otros caracteres válidos para S3, por lo que no deben enviarse directamente como prefijo temporal de Polly.

Antes, una ruta como:

```text
Data/Docs/.../Juan Avila/.polly/archivo
```

podía provocar `ValidationException`.

Ahora Polly usa exclusivamente un prefijo temporal seguro:

```text
.arcadecloud/polly/u<user_id>/<sha256-clave-origen>
```

Cuando AWS termina, Drive copia el temporal al destino real calculado desde `FileS3.Encriptado`, conserva la carpeta/nombre originales y elimina el temporal.

## Flujo

```text
TXT/MD/JAS en Drive
    -> polly_tts.php
    -> StartSpeechSynthesisTask
    -> DriveActivityEvents (task_id + costo Polly)
    -> servidor systemd cada ~60 s
       -> polly_reconcile.php
       -> GetSpeechSynthesisTask
       -> completed
          -> COPY temporal a nombre final
          -> DELETE temporal
          -> UPSERT FileS3
          -> costos S3 atribuibles
          -> estado completed
```

El navegador sólo muestra estado. No es necesario que permanezca abierto.

## Tareas AWS

`polly_tasks.php` devuelve al usuario autenticado sus tareas Polly recientes persistidas en `DriveActivityEvents`. `polly-background.js` las integra en el panel existente **Tareas AWS** junto con Transcribe.

Estados mostrados:

- `scheduled`: en cola.
- `inProgress`: procesando.
- `completed`: terminado.
- `failed`: falló.

## Costos

El evento principal `Action=polly, Service=Polly` usa una correlación determinística basada en `task_id`, de modo que el inicio y la finalización representan la misma operación.

Unidades Polly:

- `polly.standard_character`
- `polly.neural_character`
- `polly.long-form_character`
- `polly.generative_character`

Al finalizar se registra además un evento `Action=polly, Service=S3` con la misma correlación para las operaciones atribuibles:

- `s3.put_request`: escritura temporal producida por Polly en el bucket.
- `s3.copy_request`: normalización al destino final.
- `s3.delete_request`: limpieza del temporal.
- `s3.head_request`: comprobaciones de existencia/idempotencia.
- `s3.storage_bytes_delta`: bytes incorporados al almacenamiento.

`activity_costs.php` muestra los importes conocidos según el catálogo versionado. `s3.storage_bytes_delta` se conserva como medida de almacenamiento, pero el costo real de almacenamiento depende del tiempo/clase/región y se reconcilia con Cost Explorer; no se inventa un cargo único por byte.

## Idempotencia

- La clave única existente de `DriveActivityEvents` (`user_id_`, `Action`, `Service`, `CorrelationId`) evita duplicar la misma tarea.
- El archivo final incluye metadata `polly-task-id`.
- Si el timer o el navegador consultan de nuevo una tarea terminada, no vuelve a copiarse si ya está finalizada con el mismo `task_id`.

No se añade ninguna tabla nueva.

## Instalación en EC2

Desde la raíz del repositorio:

```bash
sudo bash drive/bin/install_polly_reconcile_timer.sh \
  --app-root=/var/www/arcadecloud-drive \
  --interval-sec=60
```

Comprobación:

```bash
sudo systemctl status arcadecloud-polly-reconcile.timer --no-pager
sudo journalctl -u arcadecloud-polly-reconcile.service --since "10 minutes ago" --no-pager
```

El instalador obtiene de forma segura el usuario real de `php-fpm-drive`: primero desde un worker hijo y, si el pool está en `pm=ondemand`, desde la configuración PHP-FPM efectiva.

## Prueba manual recomendada

1. Seleccionar un texto que esté en una carpeta con espacios y/o acentos.
2. Elegir una voz compatible y `Guardar en S3`.
3. Generar el audio.
4. Confirmar que el modal se cierra y la tarea aparece en **Tareas AWS**.
5. Cerrar el navegador.
6. Esperar a que el timer la termine.
7. Volver al Drive y comprobar el audio en la misma carpeta y con el mismo nombre base.
8. Revisar `activity_costs.php` para confirmar costo Polly y componente S3.
