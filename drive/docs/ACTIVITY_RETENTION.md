# Retención conservadora de DriveActivityEvents

## Por qué no usar DELETE por fecha

`DriveActivityEvents` es telemetría y también fuente de control/correlación para
Polly y Transcribe. Sus reconciliadores consultan registros antiguos pendientes;
una fila `Status=ok` no significa que la tarea haya terminado. `CreatedAt` no se
actualiza al reconciliar. La antigüedad sola no autoriza purga de tareas.

## Política implementada

`ActivityRetentionService` archiva telemetría síncrona con más de **365 días** por
defecto (mínimo 90). Allowlist comprobada en controllers actuales:

- S3: `delete`, `move`, `upload`;
- Drive: `rename`;
- FederationCloud: `arcadelink_create`, `arcadelink_resolve`, `arcadelink_open`.

Sólo estados `ok`/`error`, sin correlación y sin metadata operativa. Se conservan
**todas** las filas correlacionadas, Polly/Transcribe, servicios/acciones nuevos,
estados desconocidos, JSON inválido y metadata con phase/status/task/job/cleanup.
No se borran tareas, catálogo, colas, objetos S3 ni eventos propios FederationCloud.

Esta primera política reduce el historial de operaciones síncronas. No promete
un límite absoluto al tamaño de la tabla: el diario operativo protegido puede
seguir creciendo y requiere otra política basada en terminación verificable y
respaldo. No ampliar la allowlist sin revisar sus consumidores.

## CLI y lotes

El entrypoint rechaza HTTP antes de cargar configuración. No hay endpoint nuevo
para usuarios ni timer instalado automáticamente.

```bash
php drive/bin/activity_retention.php --days=365 --limit=500 --after-id=0
```

La salida muestra contadores, cutoff UTC, `next_after_id` y `has_more`; no muestra
metadata ni nombres de archivos. Continuar con el cursor informado hasta que no
haya más filas. En cada ciclo de mantenimiento nuevo, comenzar en `after-id=0`
para reconsiderar filas antes protegidas o recientes. La exploración usa el
índice primario existente y examina un máximo de 1.000 filas por invocación.

Para ejecutar, después de revisar la simulación y confirmar un respaldo MySQL:

```bash
php drive/bin/activity_retention.php --days=365 --limit=500 --after-id=0 \
  --execute --archive=/ruta/privada/fuera-del-webroot/actividad-lote-001.jsonl
```

El directorio debe existir; sustituir el ejemplo por una ruta privada real. El
servicio rechaza rutas dentro del repositorio y nunca sobrescribe un archivo
existente. Para cada lote usar un nombre nuevo. El operador debe verificar que el
directorio tampoco sea publicado por otro virtual host.

## Garantías y consecuencias

1. Abre archivo nuevo modo 0600 y confirma su entrada de directorio en disco.
2. Bloquea las filas del lote dentro de una transacción MySQL.
3. Reevalúa la política sobre las filas bloqueadas.
4. Escribe cada fila completa en JSONL, con header y cantidad final.
5. Hace fflush/fsync antes del primer DELETE.
6. Elimina únicamente las filas archivadas y confirma la transacción.

Si falla escritura, fsync o DELETE, la transacción revierte. Un archivo presente
puede corresponder a un intento revertido; **su existencia no prueba que se hayan
borrado filas**. Se conserva para revisión. No usar archivado en un filesystem
que no garantice persistencia de fsync. La retención puede bloquear brevemente
escrituras del lote; mantener los límites pequeños y ejecutarla fuera del pico.

Los costos históricos archivados dejan de aparecer en los reportes que consultan
sólo MySQL. Conservar los JSONL y respaldos según la política del operador; no
subirlos al repositorio ni publicarlos como archivos del Drive. Contienen datos
privados del usuario, aunque la telemetría filtre credenciales.

## Recuperación

El formato `arcadecloud-activity-archive-v1` contiene filas con los nombres y
valores originales de `DriveActivityEvents`, incluidos IDs, tipos nulos y costos.
Antes de recuperar, restaurar el respaldo MySQL en una base aislada y contrastar
las filas JSONL con los IDs/correlaciones existentes. No sobrescribir filas nuevas
ni ejecutar el dump canónico destructivo sobre producción. La herramienta no
realiza restauración automática ni afirma resolver conflictos entre un respaldo
y escrituras posteriores. Validar el procedimiento de recuperación aislado antes
de programar ejecución recurrente.

## Pruebas

La CI usa MySQL 8 desechable y extrae sólo el CREATE canónico de DriveActivityEvents.
Prueba selección, límite/cursor, dry-run sin archivos, archivo obligatorio y
privado, preservación del control operativo, protección ante sobrescritura y
rollback ante error de DELETE. No usa configuración ni base de producción.
