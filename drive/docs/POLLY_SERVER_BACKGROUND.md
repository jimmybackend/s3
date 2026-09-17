# Amazon Polly en segundo plano desde el servidor

## Estado

**Validado en producción el 16-Sep-2026.**

El flujo servidor de Amazon Polly quedó comprobado en `drive.esforzados.com`: el job continúa sin depender del navegador, `Revisar ahora` puede reconciliarlo de forma inmediata y el timer del servidor finaliza el audio en S3 y registra `FileS3` cuando Amazon termina.

Los PR #79 y #80 forman parte del flujo estable documentado aquí.

## Objetivo

La generación larga de texto a audio debe continuar aunque el usuario cierre el modal, cambie de página, cierre el navegador o pierda conexión. El servidor EC2 es responsable de reconciliar la tarea, colocar el audio final en la misma carpeta del texto origen y registrar los costos atribuibles.

## Prefijo temporal seguro

`StartSpeechSynthesisTask` restringe `OutputS3KeyPrefix` a un conjunto ASCII. Las rutas normales de ArcadeCloud Drive pueden contener espacios, acentos y otros caracteres válidos para S3, por lo que no deben enviarse directamente como prefijo temporal de Polly.

El temporal usa:

```text
.arcadecloud/polly/u<user_id>/<sha256-clave-origen>
```

Amazon agrega el `TaskId` y la extensión al objeto de salida. Cuando AWS termina, Drive copia el temporal al destino real, conserva la carpeta/nombre originales y elimina el temporal.

## Resolución del archivo origen

La tarea persistida mantiene `FileId`, `user_id_` y `task_id`.

El reconciliador no debe tratar `FileS3.Encriptado` aislado como si fuera la clave completa. Para archivos situados dentro de carpetas, la clave física correcta se reconstruye mediante:

```text
FileViewHelper::buildS3Key(FileS3.Ruta, FileS3.Encriptado)
```

Esto corrige el caso en que un archivo como `Data/.../ja1.txt` se intentaba resolver sólo como `ja1.txt`, provocando el mensaje:

```text
Archivo no encontrado para este usuario.
```

La búsqueda continúa limitada por `FileId + user_id_`; no se relaja el aislamiento multiusuario.

## Flujo

```text
TXT/MD/JAS en Drive
    -> polly_tts.php
    -> StartSpeechSynthesisTask
    -> DriveActivityEvents (FileId + task_id + correlación + costo Polly)
    -> centro unificado Tareas

Servidor systemd cada ~60 s
    -> polly_reconcile.php
    -> PollyTaskReconciler
    -> reconstruye Ruta + Encriptado desde FileS3
    -> GetSpeechSynthesisTask
    -> completed
       -> HEAD temporal/destino
       -> COPY temporal a nombre final
       -> DELETE temporal
       -> UPSERT FileS3
       -> costos S3 atribuibles
       -> estado completed
```

El navegador sólo inicia, visualiza y solicita acciones. No es necesario mantenerlo abierto.

## Centro unificado Tareas

Polly aparece en el panel **Tareas**, junto con Transcribe, Sync, Move y futuros proveedores. Ya no se usa el nombre histórico **Tareas AWS**.

Los estados normalizados son:

- `queued` / `pending`: en cola;
- `running`: procesando;
- `completed`: terminado;
- `failed`: falló;
- `cancelled`: cancelado.

Mientras la tarea está activa:

- **Revisar ahora** ejecuta la reconciliación en la petición actual y devuelve el estado/error observado;
- **Cancelar** marca la tarea cancelada dentro de Drive y evita publicar el resultado final cuando la cancelación requiere esperar a que Amazon produzca el temporal.

En estado terminal:

- **Eliminar de Tareas** oculta la tarjeta mediante metadata del centro;
- no elimina `DriveActivityEvents`;
- no elimina costos históricos.

## `Revisar ahora`

`Revisar ahora` no se limita a lanzar un worker y responder que se revisará después. Para Polly, ejecuta `PollyTaskReconciler` antes de devolver la respuesta.

El resultado puede informar que:

- sigue en cola;
- sigue procesándose;
- terminó y Drive finalizó el audio;
- Amazon reportó fallo;
- ocurrió un error concreto de reconciliación/S3/archivo origen.

El mensaje se muestra dentro del modal **Tareas**, arriba de los jobs, y el panel refresca su estado después de la acción.

## Prevención de doble submit

El flujo actual evita que `polly.js` heredado y `polly-background.js` creen dos tareas a partir de una sola pulsación.

`polly-background.js` es el dueño efectivo del submit asíncrono y bloquea una segunda pulsación mientras la solicitud inicial está en curso. Esto también elimina el falso error histórico:

```text
El servidor no devolvió audio válido.
```

Ese mensaje ocurría porque el handler síncrono esperaba `audioBase64` aun cuando el backend había respondido correctamente con `mode=task`.

## Costos

El evento principal `Action=polly, Service=Polly` usa una correlación determinística basada en `task_id`, de modo que inicio y finalización representan la misma operación.

Unidades Polly:

- `polly.standard_character`
- `polly.neural_character`
- `polly.long-form_character`
- `polly.generative_character`

Al finalizar se registra además telemetría S3 con la misma correlación para las operaciones atribuibles, entre ellas:

- escritura del temporal producida por Polly en el bucket;
- COPY al destino final;
- DELETE del temporal;
- HEAD de comprobación/idempotencia;
- bytes incorporados al almacenamiento.

`activity_costs.php` conserva el detalle financiero. Quitar una tarea del centro **Tareas** no borra estos registros.

## Idempotencia

- La clave existente de `DriveActivityEvents` (`user_id_`, `Action`, `Service`, `CorrelationId`) evita duplicar la misma correlación.
- El archivo final incluye metadata `polly-task-id`.
- Si el timer o `Revisar ahora` consultan de nuevo una tarea terminada, el archivo no se vuelve a copiar si ya está finalizado con el mismo `task_id`.
- La UI evita doble submit durante el arranque del job.

No se añade ninguna tabla nueva.

## Instalación en EC2

Desde la raíz del repositorio:

```bash
sudo bash drive/bin/install_polly_reconcile_timer.sh \
  --app-root=/var/www/arcadecloud-drive \
  --drive-env=/etc/arcadecloud-drive/drive.env \
  --interval-sec=60
```

Comprobación:

```bash
sudo systemctl status arcadecloud-polly-reconcile.timer --no-pager -l
sudo systemctl start arcadecloud-polly-reconcile.service
sudo journalctl -u arcadecloud-polly-reconcile.service -n 80 --no-pager
```

El instalador obtiene de forma segura el usuario real de `php-fpm-drive`: primero desde un worker hijo y, si el pool está en `pm=ondemand`, desde la configuración PHP-FPM efectiva.

## Prueba funcional validada

1. Seleccionar un archivo de texto dentro de una carpeta normal del usuario.
2. Elegir una voz compatible y `Guardar en S3`.
3. Generar el audio y confirmar que aparece un solo job en **Tareas**.
4. Cerrar el modal o el navegador si se desea.
5. Esperar al timer o usar **Revisar ahora**.
6. Confirmar que el origen se resuelve con `Ruta + Encriptado` y no aparece `Archivo no encontrado para este usuario`.
7. Confirmar que el audio queda en la misma carpeta y con el mismo nombre base.
8. Confirmar que el job pasa a terminal y puede quitarse del centro sin perder costos.
