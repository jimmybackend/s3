# Recuperación de tareas interrumpidas

## Estado

**Validado en producción el 16-Sep-2026 como parte del centro unificado Tareas.**

El objetivo aceptado es que una tarea local no pueda permanecer eternamente como activa sólo porque su proceso CLI murió. La recuperación conserva como prioridad no borrar ni mutar datos reales del usuario por el simple hecho de limpiar una tarjeta del centro.

## Diseño

El centro **Tareas** usa archivos de estado persistentes para Sync y Move. Para evitar que un job quede eternamente en `queued`, `running` o `cancel_requested` cuando su proceso CLI murió, los workers mantienen un lease de vida mediante `flock`.

## Reglas

- `queued` / `pending` sin worker vivo y sin actualización durante 120 segundos: pasa a `failed`.
- `running` / `cancel_requested` sin worker vivo y sin actualización durante 1800 segundos: pasa a `failed`.
- Mientras el worker conserva su lease, el job no se considera huérfano aunque dure más tiempo.
- Sync conserva además compatibilidad con el lock por usuario de workers anteriores al lease.
- Al pasar a `failed`, el centro Tareas permite quitar el registro. Sync también puede reintentarse con **Iniciar ahora** según las reglas existentes.
- Quitar una tarea sólo elimina su registro de control; no borra archivos reales de S3 ni registros de navegación de MySQL.

La reconciliación se ejecuta al consultar las tareas recientes, por lo que una tarea huérfana deja de mostrarse como activa cuando el usuario vuelve a abrir/refrescar **Tareas**.

## Acciones y seguridad

La limpieza visual y la operación real están separadas:

- **Detener/Cancelar** cambia el estado operativo según las reglas del worker.
- **Eliminar de Tareas** elimina u oculta sólo el registro de control permitido por el backend.
- una tarea con worker vivo no se declara huérfana sólo por haber superado un tiempo fijo;
- un movimiento de carpeta ya iniciado no se corta arbitrariamente si eso puede dejar una mutación parcial inconsistente.

Para Polly y Transcribe la recuperación no usa estos leases locales: sus estados se reconcilian contra los proveedores y sus timers de servidor. Las tareas terminales pueden quitarse del centro sin borrar `DriveActivityEvents` ni costos históricos.

## Archivos relacionados

```text
drive/src/Core/BackgroundWorkerLease.php
drive/src/Sync/SyncJobStore.php
drive/src/Storage/MoveJobStore.php
drive/bin/sync_worker.php
drive/bin/move_job_worker.php
drive/src/Http/Controller/BackgroundTaskController.php
```

No requiere migración SQL ni un nuevo timer systemd para Sync/Move.
