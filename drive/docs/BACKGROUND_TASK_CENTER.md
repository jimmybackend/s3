# Centro unificado de Tareas

## Estado

**Validado en producción el 16-Sep-2026.**

El centro **Tareas** quedó comprobado con jobs reales de Polly y con los controles persistentes de Sync/Move. Las acciones se consideran parte válida del flujo estable: iniciar/reintentar cuando corresponde, detener/cancelar de forma segura, revisar proveedores externos y eliminar del centro sin borrar archivos reales ni telemetría histórica de costos.

## Objetivo

El botón flotante del Drive se llama **Tareas** y no depende del nombre de un proveedor cloud. Su contador muestra únicamente trabajos activos. Al abrirlo se muestran trabajos activos, en cola, terminados, fallidos y cancelados recientes.

El panel usa las variables visuales del Drive (`--panel-solid`, `--panel-bg`, `--panel-bg2`, `--text`, `--text-soft`, `--accent`, etc.), por lo que respeta el tema claro/oscuro, el color de acento y los perfiles de accesibilidad seleccionados por el usuario.

El centro no crea una fuente de verdad paralela. `background_tasks.php` normaliza y controla los estados persistentes que ya existen en el servidor.

## Fuentes actuales

| Tipo | Fuente del servidor | Estado persistente |
| --- | --- | --- |
| Sincronización de datos | `SyncJobStore` | archivos de estado de `sync_worker.php` |
| Traslado de archivos/carpetas | `MoveJobStore` | archivos de estado del worker de movimiento |
| Amazon Transcribe | `DriveActivityEvents` | correlación `transcribe:*` |
| Amazon Polly | `DriveActivityEvents` | correlación `polly:*` |

Las tareas de Polly y Transcribe continúan siendo reconciliadas por sus timers del servidor. El navegador sólo visualiza y solicita acciones; no es necesario mantenerlo abierto.

## Estados normalizados

```text
queued      en cola
pending     pendiente
running     procesando
stopping    detención solicitada
completed   terminada
failed      fallida
cancelled   cancelada
```

No se inventan porcentajes. Cuando un proveedor no publica progreso real, la UI usa una barra indeterminada. Los traslados de varios archivos sí pueden calcular avance usando elementos procesados / elementos totales. Una tarea terminada se muestra como 100%.

## Acciones por job

Cada tarjeta muestra únicamente las acciones que el backend considera seguras para ese tipo y estado. No existe un botón decorativo que cambie sólo la UI: las acciones actúan sobre la fuente persistente real.

### Sincronización

- **Iniciar ahora**: relanza una tarea en cola, fallida o cancelada cuando el estado lo permite.
- **Cancelar**: una tarea en cola/pendiente puede cancelarse antes de comenzar.
- **Detener**: una tarea en ejecución solicita detención cooperativa entre lotes; no corta una operación a mitad.
- **Eliminar de Tareas**: elimina únicamente el archivo de estado del job. En cola/pendiente se permite cuando el backend puede impedir que el worker reviva la tarea; en estados terminales elimina el registro de control.

### Traslado de archivos y carpetas

Los nuevos traslados se ejecutan mediante `bin/move_job_worker.php`, un proceso CLI desacoplado de la petición HTTP. Cerrar el navegador no detiene el trabajo.

- **Iniciar ahora**: recupera tareas que quedaron en cola cuando es seguro.
- **Cancelar antes de empezar**: evita iniciar el movimiento.
- **Detener archivos**: se aplica entre archivos; nunca corta una copia S3 a mitad.
- **Mover carpeta**: una carpeta que ya empezó a mutar no se interrumpe a mitad porque podría quedar parcialmente aplicada. Sí puede cancelarse antes de comenzar.
- **Eliminar de Tareas**: sólo elimina el registro de control y nunca los archivos reales del usuario.

El worker conserva el conteo de operaciones S3 para `activity_costs.php`, incluso cuando un lote se detiene después de haber procesado parte del trabajo.

### Polly y Transcribe

Mientras el job no es terminal:

- **Revisar ahora** consulta/reconcilia el estado real del proveedor;
- **Cancelar** registra la cancelación segura según las capacidades reales del proveedor.

Cuando el job queda `completed`, `failed` o `cancelled`:

- **Eliminar de Tareas** oculta la tarjeta del centro;
- no elimina `DriveActivityEvents`;
- no elimina los costos históricos.

En Polly, **Revisar ahora** ejecuta la reconciliación en la misma petición antes de responder. Si Amazon ya terminó, Drive intenta finalizar el objeto temporal, copiarlo al destino real y registrar `FileS3`; si falla, el mensaje muestra el error concreto del reconciliador.

Polly no ofrece una API para cancelar una `StartSpeechSynthesisTask` ya aceptada. Por eso `Cancelar` significa cancelar la publicación dentro de Drive: el reconciliador puede esperar el temporal y eliminarlo sin convertirlo en archivo final.

No se ofrece un botón genérico de “marcar terminada” porque eso mentiría sobre el estado real del trabajo.

## Feedback y refresco después de una acción

Los mensajes de resultado se muestran **dentro del modal Tareas**, inmediatamente debajo del encabezado, para evitar que queden ocultos detrás del panel.

Después de una acción:

1. el POST devuelve el resultado real;
2. Tareas refresca inmediatamente;
3. se realizan repasos cortos adicionales para capturar cambios realizados por workers/reconciliadores;
4. los botones de la tarjeta se vuelven a calcular según el nuevo estado.

Esto permite, por ejemplo:

```text
PENDIENTE
  -> Cancelar
CANCELADA
  -> Eliminar de Tareas
```

sin esperar indefinidamente al polling normal.

## Persistencia

Los controles actúan sobre estado del servidor, no sobre `localStorage` como fuente de verdad. El usuario puede:

1. lanzar una tarea;
2. cambiar de carpeta o página;
3. cerrar el navegador;
4. abrir el Drive desde otro dispositivo;
5. volver a **Tareas** y continuar viendo/administrando el mismo trabajo mientras el estado siga retenido por el servidor.

Los trabajos activos son siempre visibles. Los terminales se conservan en el panel durante 24 horas salvo que el usuario los quite antes.

## Tareas huérfanas

Sync y Move mantienen un lease de vida del worker mediante `flock`. Si el proceso desaparece y el job deja de actualizarse, el servidor puede sacarlo de estados eternos `queued/running/stopping` y llevarlo a `failed` según las ventanas documentadas en `BACKGROUND_TASK_STALE_RECOVERY.md`.

El objetivo es que ningún job local quede pendiente para siempre sólo porque un proceso murió.

## Costos

Cuando la tarea tiene telemetría en `DriveActivityEvents`, el centro suma el costo atribuible conocido de las filas que comparten `CorrelationId`. El detalle financiero completo continúa en `activity_costs.php`.

Quitar una tarea de Polly/Transcribe del centro **no borra la telemetría de costos**. En Sync y Move sólo se elimina el pequeño archivo de estado del job; no se borran datos del usuario ni objetos S3.

## Polly: correcciones validadas

El flujo Polly integrado en Tareas incorpora además dos correcciones importantes:

1. una pulsación en **Generar audio** produce un solo job; se evita el doble submit entre `polly.js` heredado y `polly-background.js` y se protege el doble toque en móvil;
2. el reconciliador reconstruye la clave origen con `FileS3.Ruta + FileS3.Encriptado` mediante `FileViewHelper::buildS3Key()`, evitando el falso `Archivo no encontrado para este usuario` en archivos dentro de carpetas.

El audio generado fue localizado correctamente en producción después de estas correcciones.

## Extensión a otras nubes

Para integrar Azure, Google Cloud u otro proveedor no se cambia el botón ni el JavaScript principal. Se agrega una nueva fuente en `BackgroundTaskController` que traduzca el estado nativo al contrato normalizado y exponga únicamente acciones seguras.

## Archivos principales

```text
drive/background_tasks.php
drive/src/Http/Controller/BackgroundTaskController.php
drive/src/Http/Controller/BackgroundTaskCompatibilityController.php
drive/src/Application/BackgroundWorkerLauncher.php
drive/src/Core/BackgroundWorkerLease.php
drive/src/Application/MoveJobService.php
drive/src/Activity/PollyTaskReconciler.php
drive/bin/move_job_worker.php
drive/bin/sync_worker.php
drive/bin/polly_reconcile.php
drive/src/Sync/SyncJobStore.php
drive/src/Storage/MoveJobStore.php
drive/js/background-tasks.js
drive/js/background-task-feedback.js
drive/js/move-tasks.js
drive/js/polly-background.js
drive/js/transcribe-background.js
```

No requiere migración SQL.
