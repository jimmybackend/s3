# Centro unificado de Tareas

## Objetivo

El botón flotante del Drive se llama **Tareas** y no depende del nombre de un proveedor cloud. Su contador muestra únicamente trabajos activos (`queued`, `running` o `pending`). Al abrirlo se muestran los trabajos activos y los terminados/fallidos recientes.

El centro no crea una nueva fuente de verdad. `background_tasks.php` normaliza los estados que ya existen en el servidor.

## Fuentes actuales

| Tipo | Fuente del servidor | Estado persistente |
| --- | --- | --- |
| Sincronización de datos | `SyncJobStore` | archivos de estado de `sync_worker.php` |
| Traslado de archivos/carpetas | `MoveJobStore` | archivos de estado del servicio de movimiento |
| Amazon Transcribe | `DriveActivityEvents` | correlación `transcribe:*` |
| Amazon Polly | `DriveActivityEvents` | correlación `polly:*` |

Las tareas de Polly y Transcribe continúan siendo reconciliadas por sus timers del servidor. El navegador sólo visualiza el estado; no es necesario mantenerlo abierto.

## Contrato normalizado

`background_tasks.php` devuelve cada trabajo con un contrato neutral respecto del proveedor:

```json
{
  "id": "tipo:identificador",
  "kind": "sync|move|transcribe|polly",
  "category": "descripción visible",
  "service": "servicio concreto",
  "provider": "Drive|Amazon",
  "title": "nombre visible",
  "status": "queued|running|completed|failed|pending",
  "progress": null,
  "progress_mode": "indeterminate|determinate",
  "detail": "detalle breve",
  "created_at": "...",
  "updated_at": "...",
  "estimated_cost": null,
  "currency": "USD"
}
```

No se inventan porcentajes. Cuando un proveedor no publica progreso real, el UI usa una barra indeterminada. Una tarea terminada se muestra como 100%.

## Costos

Cuando la tarea tiene telemetría en `DriveActivityEvents`, el centro suma el costo atribuible conocido de las filas que comparten `CorrelationId`. El detalle financiero completo continúa en `activity_costs.php`.

Sincronización no recibe un costo inventado. Los costos que no puedan atribuirse con una unidad tarifada permanecen pendientes/no tasados.

## Historial visible

- trabajos activos: siempre visibles;
- completados o fallidos: visibles durante 24 horas;
- el contador del botón sólo incluye trabajos activos.

## Extensión a otras nubes

Para integrar Azure, Google Cloud u otro proveedor no se cambia el botón ni el JavaScript. Se agrega una nueva fuente en `BackgroundTaskController` que traduzca el estado nativo al contrato normalizado. El nombre del proveedor puede aparecer dentro del detalle de la tarea, pero el centro continúa llamándose **Tareas**.

## Archivos principales

```text
drive/background_tasks.php
drive/src/Http/Controller/BackgroundTaskController.php
drive/js/background-tasks.js
drive/src/Sync/SyncJobStore.php
drive/src/Storage/MoveJobStore.php
drive/js/move-tasks.js
drive/js/polly-background.js
drive/js/transcribe-background.js
```

No requiere migración SQL.
