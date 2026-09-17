# Amazon Polly TTS en segundo plano

## Estado

**Validado en producción el 16-Sep-2026.**

La generación asíncrona de texto a audio quedó comprobada de extremo a extremo en `drive.esforzados.com`: una tarea Polly se crea una sola vez, permanece visible en **Tareas**, puede revisarse desde el servidor y, al completar Amazon Polly, el audio se normaliza en S3 y queda registrado en `FileS3` en la misma carpeta lógica del texto origen.

Los cambios de los PR #79 y #80 se consideran parte válida del flujo estable.

## Objetivo

La generación de voz desde archivos TXT/MD/JAS no debe bloquear el modal ni depender del navegador para terminar.

El flujo usa dos modos:

1. **Guardar en S3 activado**: `StartSpeechSynthesisTask` genera el audio de forma asíncrona y el servidor EC2 lo finaliza.
2. **Guardar en S3 desactivado**: se conserva `SynthesizeSpeech` para texto corto y reproducción/descarga inmediata.

## Problemas corregidos

- `TextLengthExceededException` al enviar textos mayores al límite de `SynthesizeSpeech`.
- selección incompatible entre voz y motor `standard`/`neural`.
- modal bloqueado mientras se genera el audio.
- dependencia del navegador para finalizar el audio.
- salida temporal de Polly con `TaskId` en lugar del nombre visible esperado por Drive.
- rutas con espacios/acentos usadas como `OutputS3KeyPrefix`.
- doble envío desde `polly.js` + `polly-background.js`, que podía crear dos jobs con una sola pulsación.
- falso mensaje `El servidor no devolvió audio válido` cuando la respuesta correcta era una tarea asíncrona.
- reconciliación que usaba sólo `FileS3.Encriptado` y perdía `FileS3.Ruta`, produciendo `Archivo no encontrado para este usuario` en archivos dentro de carpetas.

## Flujo asíncrono actual

```text
TXT/MD/JAS
  -> botón Texto a audio
  -> polly-background.js (único dueño del submit)
  -> polly_tts.php
  -> AwsFileController
  -> PollyFileService::synthesize()
  -> StartSpeechSynthesisTask
  -> S3 temporal: .arcadecloud/polly/u<user_id>/<sha256-origen>.<task-id>.<ext>
  -> DriveActivityEvents (task_id + FileId + costos)
  -> centro unificado Tareas

Servidor EC2
  -> arcadecloud-polly-reconcile.timer
  -> polly_reconcile.php
  -> PollyTaskReconciler
  -> reconstruye clave origen desde FileS3.Ruta + FileS3.Encriptado
  -> PollyFileService::taskStatus()
  -> GetSpeechSynthesisTask

cuando completed:
  -> HEAD destino/temporal
  -> COPY temporal al nombre final
  -> DELETE temporal
  -> UPSERT FileS3
  -> registrar costos S3 atribuibles
  -> estado completed
```

MySQL sigue siendo la fuente de verdad para navegación. S3 es el almacenamiento físico. No se agregó una tabla nueva para este flujo.

## Persistencia y navegador

El navegador **no es la fuente de verdad del job**.

`DriveActivityEvents` conserva la identidad de la tarea (`task_id`, correlación y `FileId`) y el timer del servidor continúa reconciliándola aunque el usuario:

- cierre el modal;
- cambie de carpeta;
- cierre el navegador;
- pierda conexión;
- abra Drive después desde otro dispositivo.

`polly-background.js` sólo inicia el trabajo y actualiza la UI. No se depende de `localStorage` para decidir si una tarea existe o terminó.

## Resolución correcta del archivo origen

La identidad persistente del origen es `FileId + user_id_`.

Al reconciliar, la clave física se reconstruye con:

```text
FileViewHelper::buildS3Key(FileS3.Ruta, FileS3.Encriptado)
```

No debe usarse únicamente `FileS3.Encriptado`, porque un basename como `ja1.txt` no identifica de forma completa un archivo situado dentro de `Data/.../` o `DataN/.../`.

Este criterio mantiene el aislamiento multiusuario y reutiliza la misma construcción de claves usada por el resto del Drive.

## Prevención de jobs duplicados

El botón `#btnPollyGenerar` tiene un único flujo asíncrono efectivo.

El módulo `polly-background.js` captura el submit antes de que un handler heredado pueda iniciar una segunda solicitud y además aplica protección contra doble toque/clic mientras la petición inicial está en curso.

Una pulsación debe producir **un solo job Polly**.

## Nombre y carpeta final

El audio final usa:

- la **misma carpeta** del archivo origen;
- el **mismo nombre visible sin la extensión de texto**;
- la extensión elegida: `.mp3`, `.ogg` o `.pcm`;
- una clave física compatible con el modelo actual de `FileS3`.

Ejemplo:

```text
ja1.txt
ja1.mp3
```

## Acciones desde Tareas

Mientras la tarea no es terminal:

- **Revisar ahora** ejecuta una reconciliación real antes de responder. Si Amazon ya terminó, Drive intenta finalizar el audio y registrar `FileS3` en esa misma acción.
- **Cancelar** cierra la tarea dentro de Drive. Polly no ofrece cancelación real para una `StartSpeechSynthesisTask` ya aceptada; por eso, cuando corresponda, el reconciliador espera el resultado y elimina el temporal sin publicarlo como archivo final.

Cuando la tarea queda `completed`, `failed` o `cancelled`:

- **Eliminar de Tareas** sólo la oculta del centro visual;
- no elimina el evento histórico de `DriveActivityEvents`;
- no elimina los costos ya registrados.

## Voz y motor

La lista de voces usa `SupportedEngines` devuelto por Polly.

El cliente intenta mantener una combinación válida:

- al cambiar motor, busca una voz compatible;
- al cambiar voz, selecciona `neural` o `standard` si corresponde;
- el backend vuelve a validar la combinación antes de llamar a Polly.

No existe fallback silencioso de Neural a Standard en el backend.

## Límites aplicados

- modo inmediato (`SynthesizeSpeech`): hasta 3,000 caracteres facturables;
- modo asíncrono (`StartSpeechSynthesisTask`): hasta 100,000 caracteres facturables.

Para texto largo, el cliente activa automáticamente **Guardar en S3** cuando estaba desactivado.

## Costos

La solicitud inicial registra caracteres Polly con el motor realmente utilizado.

Al finalizar se registran las operaciones S3 atribuibles a la normalización del archivo, entre ellas:

- HEAD;
- COPY;
- DELETE;
- bytes incorporados al almacenamiento.

Los costos permanecen en `DriveActivityEvents` aunque el usuario quite la tarjeta del centro **Tareas**.

## Permisos AWS necesarios

La identidad usada por la aplicación debe conservar los permisos existentes y permitir, como mínimo:

```text
polly:DescribeVoices
polly:SynthesizeSpeech
polly:StartSpeechSynthesisTask
polly:GetSpeechSynthesisTask
```

Además necesita acceso al bucket para las operaciones de lectura de metadatos, copia y eliminación usadas en la finalización.

## Archivos principales

```text
drive/src/Aws/PollyFileService.php
drive/src/Activity/PollyTaskReconciler.php
drive/src/Http/Controller/AwsFileController.php
drive/src/Http/Controller/BackgroundTaskController.php
drive/src/Http/Controller/BackgroundTaskCompatibilityController.php
drive/background_tasks.php
drive/bin/polly_reconcile.php
drive/bin/install_polly_reconcile_timer.sh
drive/js/polly-background.js
drive/js/background-tasks.js
drive/js/background-task-feedback.js
drive/js/move-tasks.js
.github/workflows/activity-costs.yml
```

## Verificación en EC2

```bash
sudo systemctl status arcadecloud-polly-reconcile.timer --no-pager -l
sudo systemctl start arcadecloud-polly-reconcile.service
sudo journalctl -u arcadecloud-polly-reconcile.service -n 80 --no-pager
```

## Prueba funcional validada

1. Abrir un TXT/MD/JAS existente desde Drive.
2. Generar con **Guardar en S3** activo.
3. Confirmar que se crea un solo job en **Tareas**.
4. Cerrar el modal y continuar trabajando.
5. Usar **Revisar ahora** o esperar al timer.
6. Confirmar que el audio aparece en la misma carpeta con el mismo nombre base.
7. Confirmar que la tarea cambia a terminal y puede quitarse de **Tareas** sin perder su historial de costos.
