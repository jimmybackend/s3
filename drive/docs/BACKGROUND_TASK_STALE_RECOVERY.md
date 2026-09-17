# Recuperación de tareas interrumpidas

El centro **Tareas** usa archivos de estado persistentes para Sync y Move. Para evitar que un job quede eternamente en `queued`, `running` o `cancel_requested` cuando su proceso CLI murió, los workers mantienen ahora un lease de vida mediante `flock`.

## Reglas

- `queued` / `pending` sin worker vivo y sin actualización durante 120 segundos: pasa a `failed`.
- `running` / `cancel_requested` sin worker vivo y sin actualización durante 1800 segundos: pasa a `failed`.
- Mientras el worker conserva su lease, el job no se considera huérfano aunque dure más tiempo.
- Sync conserva además compatibilidad con el lock por usuario de workers anteriores al lease.
- Al pasar a `failed`, el centro Tareas permite quitar el registro. Sync también puede reintentarse con `Ejecutar ahora` según las reglas existentes.
- Quitar una tarea sólo elimina su registro de control; no borra archivos reales de S3 ni registros de navegación de MySQL.

La reconciliación se ejecuta al consultar las tareas recientes, por lo que una tarea huérfana deja de mostrarse como activa cuando el usuario vuelve a abrir/refrescar Tareas.

No requiere migración SQL ni un nuevo timer systemd.
