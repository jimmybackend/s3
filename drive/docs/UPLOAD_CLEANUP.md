# Limpieza de subidas abandonadas

## Superficies

`up-clean.php` → `UploadCleanupController` es una vista GET de diagnóstico,
con sesión Administración/Soporte y sin operaciones destructivas.
`bin/upload_cleanup.php` → `UploadCleanupCommand` ejecuta `UploadCleanupService`.
El CLI conserva simulación por defecto y requiere `--execute` para limpiar.

```bash
php drive/bin/upload_cleanup.php --days=30
# Revisar el reporte antes de ejecutar:
php drive/bin/upload_cleanup.php --days=30 --execute
```

## Estados chunked: ubicación autoritativa

`UploadStateStore::defaultDirectory()` define `drive/upload/storage/state`.
`DriveApplication::uploadFactory()` y `ChunkedUploadCleanupService` usan esa
misma fuente. No confundirla con el estado temporal de `PublicMultipartUploadService`.
Los uploads chunked pueden apuntar a cualquier carpeta física del usuario, no sólo
`DataN/uploads/`. La UI continúa usando nombres de catálogo; el cleaner trabaja
con las keys físicas internas.

## Reglas de ejecución

- Edad predeterminada 30 días; mínimo 1 día, mayor que la vigencia de 1 hora de las
  URLs firmadas por el uploader chunked.
- Antigüedad calculada usando el máximo de `created`, `updated` y mtime.
- Lease compartido con init/part/complete; una subida ocupada se omite sin esperar.
- Las escrituras de estado son atómicas; firmar una parte refresca `updated`.
- Se valida el estado, su identificador y que la key pertenezca a su usuario.
- Se consulta `ListParts` para el bucket/key/UploadId exactos, recorriendo todas
  las páginas. Partes recientes o de fecha desconocida impiden el aborto.
- Sólo después se aborta ese multipart y se elimina su estado local.
- `NoSuchUpload` permite retirar sólo el estado vencido. Un objeto terminado
  **nunca se elimina como consecuencia del estado local**.
- Errores de AWS, estado corrupto, symlinks o falta de permisos se omiten de forma
  conservadora. `skip_error` no expone mensajes SDK ni URLs firmadas.
- Los locks son sidecars persistentes agrupados en 256 nombres posibles. No
  eliminarlos mientras haya procesos activos: cambiar su inode rompería el lease.
- Dry-run no crea locks, no reescribe estados y no modifica S3.

La exclusión cubre procesos del mismo nodo que usan este directorio y esta versión
del uploader. Antes de ejecutar cleanup tras actualizar, deben haber terminado
las peticiones PHP de la versión anterior. No ejecutar dos instalaciones con
estado independiente sobre el mismo multipart. No se garantiza coordinación con
clientes externos que reutilicen un UploadId fuera del uploader.

## Cambio deliberado en el cleanup histórico

La inspección de `DataN/uploads/`, los candidatos huérfanos y los estados de
`arcadecloud-public-upload-state` sigue disponible. Ahora es **sólo reporte**, aun
con `--execute`: se devuelve `legacy_report_only: true` y acciones `review_*`.
La ausencia de una key en una captura de `FileS3` y su edad no constituyen prueba
suficiente para borrar: puede existir una carrera de registro, recuperación o
reanudación. Esta fase no inventa un protocolo de borrado seguro para esos casos.

No ejecutar scripts históricos esperando que borren huérfanos automáticamente.
La revisión y eventual eliminación de esos candidatos exige una fase independiente.

## Reporte

Se conservan los contadores históricos, que ya no ejecutan mutaciones. La sección
`chunked` incluye `states_seen`, `states_deleted`, `multipart_aborted` y `items`.
Las acciones distinguen simulación, estado reciente/ocupado/inválido, error,
metadata de multipart ausente y aborto exacto. No se incluyen UploadIds ni keys
en esa sección.

## Verificación aislada

```bash
composer install
php drive/tests/chunked_upload_cleanup_regression.php
node drive/tests/web_os_upload_context_functional.js
```

El transporte AWS se simula con el SDK; no se conectan S3 ni MySQL reales.
La CI `OOP upload cleanup validation` corre en PR y cambios relevantes a main.
