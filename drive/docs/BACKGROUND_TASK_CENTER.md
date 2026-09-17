# Centro unificado de Tareas

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

No se inventan porcentajes. Cuando un proveedor no publica progreso real, el UI usa una barra indeterminada. Los traslados de varios archivos sí pueden calcular avance usando elementos procesados / elementos totales. Una tarea terminada se muestra como 100%.

## Controles

Las acciones se sirven desde el backend y sólo aparecen cuando son seguras para ese tipo de tarea.

### Sincronización

- **Ejecutar ahora**: relanza una tarea en cola, fallida o cancelada.
- **Detener**: una tarea en cola se cancela inmediatamente; una tarea en ejecución se detiene entre lotes para no cortar una operación a mitad.
- **Quitar de Tareas**: elimina el archivo de estado sólo cuando la tarea ya terminó, falló o fue cancelada.

### Traslado de archivos y carpetas

Los nuevos traslados se ejecutan mediante `bin/move_job_worker.php`, un proceso CLI desacoplado de la petición HTTP. Cerrar el navegador no detiene el trabajo.

- **Ejecutar ahora**: recupera tareas que quedaron en cola.
- **Detener archivos**: se aplica entre archivos; nunca corta una copia S3 a mitad.
- **Mover carpeta**: una carpeta que ya empezó a moverse no se interrumpe a mitad porque podría quedar parcialmente aplicada. Sí puede cancelarse antes de comenzar.
- **Quitar de Tareas**: sólo para estados terminales.

El worker de traslado conserva el conteo de operaciones S3 para `activity_costs.php`, incluso cuando un lote de archivos se detiene después de haber procesado parte del trabajo.

### Polly y Transcribe

- **Revisar ahora** lanza el reconciliador del servidor para consultar el estado real del proveedor sin depender del navegador.
- **Quitar de Tareas** sólo oculta una tarea terminal del centro; no borra `DriveActivityEvents` ni su historial de costos.

No se ofrece un botón genérico de “marcar terminada” porque eso mentiría sobre el estado real del trabajo. Una tarea en cola debe ejecutarse; una tarea externa debe reconciliarse contra su proveedor.

## Persistencia

Los controles actúan sobre estado del servidor, no sobre `localStorage`. El usuario puede:

1. lanzar una tarea;
2. cambiar de carpeta o página;
3. cerrar el navegador;
4. abrir el Drive desde otro dispositivo;
5. volver a **Tareas** y continuar viendo/administrando el mismo trabajo mientras el estado siga retenido por el servidor.

Los trabajos activos son siempre visibles. Los terminales se conservan en el panel durante 24 horas salvo que el usuario los quite antes.

## Costos

Cuando la tarea tiene telemetría en `DriveActivityEvents`, el centro suma el costo atribuible conocido de las filas que comparten `CorrelationId`. El detalle financiero completo continúa en `activity_costs.php`.

Quitar una tarea de Polly/Transcribe del centro no borra la telemetría de costos. En Sync y Move sólo se elimina el pequeño archivo de estado del job cuando ya es terminal.

## Extensión a otras nubes

Para integrar Azure, Google Cloud u otro proveedor no se cambia el botón ni el JavaScript. Se agrega una nueva fuente en `BackgroundTaskController` que traduzca el estado nativo al contrato normalizado y exponga únicamente acciones seguras.

## Archivos principales

```text
drive/background_tasks.php
drive/src/Http/Controller/BackgroundTaskController.php
drive/src/Application/BackgroundWorkerLauncher.php
drive/src/Application/MoveJobService.php
drive/bin/move_job_worker.php
drive/bin/sync_worker.php
drive/src/Sync/SyncJobStore.php
drive/src/Storage/MoveJobStore.php
drive/js/background-tasks.js
drive/js/move-tasks.js
drive/js/polly-background.js
drive/js/transcribe-background.js
```

No requiere migración SQL.
