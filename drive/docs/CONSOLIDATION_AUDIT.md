# Consolidación de ArcadeCloud OS

## Base y alcance comprobado

Revisión iniciada sobre `main` en `5b5c4f1f505843268164d9f708553d945f20ad2d`.
Es una auditoría incremental de código: no certifica producción ni afirma haber
completado los 21 frentes. No se ejecutaron servicios, cleanup, migraciones ni
operaciones S3 reales. Cada fase requiere PR, pruebas y revisión de CI.

## Inventario y hallazgos iniciales

| Frente | Implementación existente / evidencia | Estado de revisión |
| --- | --- | --- |
| Arquitectura | `DriveApplication`, Controller/Service/Repository, esquema canónico `adbbmis1_Cloud.sql` | Conservar composición OOP, ownership y backend clásico |
| Mi nodo | `NodeStatusController`, `NodeRuntimeStatusService`, `NodeCapabilityService` | Endpoint local; `cachedPrograms()` sólo busca ejecutables del host. Falta distinguir contenedores locales |
| Uploads/temporales | `UploadFactory`, `Chunked15MBUploader`, `UploadStateStore`, `UploadCleanupService` | Ruta chunked distinta del estado público histórico; corregido en fase 1 descrita abajo |
| Actividad | `ActivityCostRepository`, `DriveActivityEvents`, reconciliadores Polly/Transcribe | No se encontró retención en repositorio/bin; contiene estado operativo, no debe purgarse sólo por fecha |
| Office | `OfficeDocumentStorageService`, repositorios de sesiones/leases, gateway y cliente workstation | Preparación, sync, catálogo, conflicto y cierre existen. `sync()` verifica HEAD y luego PUT sin `IfMatch`: carrera comprobada pendiente |
| Multimedia | `MediaProcessingService`, `MediaProcessingJobRepository`, `MediaWorkerNodeService` | Existe cola, procesamiento y documentación de integración; validación completa pendiente |
| Tareas | `BackgroundTaskController`, Sync/Move stores, actividad, media y mantenimiento | Agregación existente; no crear tabla universal |
| FastDrive | `FastDriveControlService`, `MediaWorkerNodeService`, `ServerTaskActivityProbe` | Hay autorización y protección de actividad; cobertura y errores requieren revisión adicional |
| FederationCloud | `drive/src/Federation/`, documentación de réplicas, permisos, recuperación y moderación | Implementado; no se ha validado extremo a extremo entre nodos en esta auditoría |
| ArcadeLink | Colecciones y pruebas en `arcadelink_collection_regression.php` / `ARCADELINK_PRODUCTION_VALIDATION.md` | Existen v1/v2/v3; la propia matriz enumera validaciones multinodo pendientes |
| Seguridad | Controllers y pruebas de hardening existentes | CSRF/ownership de upload confirmados en código; auditoría transversal pendiente |
| Rutas y búsqueda | `FileSearchService`, `AiFileSearchService`, `so-search.js`, `FileListService` | Mantener contrato catálogo visible/key física; prueba integral de localizar/seleccionar pendiente |
| Papelera | `FileMutationService::delete()` llama a `deleteObject` | Borrado actual definitivo; no presentar `Found=0` como papelera. Requiere diseño de recuperación y propagación federada |
| Notificaciones | Shell/EventBus y fuentes de tareas existentes | Cobertura por tipo de tarea pendiente |
| Recuperación | Scripts `federation_identity_backup.php` / `federation_identity_restore.php` | Identidad cubierta parcialmente; runbook integral MySQL/config/catálogo pendiente |
| Instalación | Instalador y documentación de nodos/identidad | Auditar tercer nodo sin hardcoding; no ejecutado en servidores |
| Pruebas | Tests PHP/JS y workflows por subsistema | Mezcla de contratos por texto, comportamiento JS y MariaDB. Falta una aceptación integral aislada |
| Móvil | CSS y shell existentes | Sin validación visual teléfono/tablet todavía |
| Rendimiento | DB-first, paginación, caché de programas | No se han medido cuellos de botella; no añadir índices a ciegas |
| Documentación | `ARCHITECTURE.md`, `docs/` | Actualización incremental; distinguir funciones existentes de validaciones realizadas |
| App móvil | Web OS existente | No iniciar empaquetado hasta cerrar recuperación, conflictos, seguridad y aceptación móvil |

## Fase 1 — cleanup chunked

- Fuente autoritativa: `UploadStateStore::defaultDirectory()`; uploader y cleaner
  usan `drive/upload/storage/state` sin trasladar estados existentes.
- `UploadStateStore` escribe JSON atómico y heartbeat `updated`. El uploader
  mantiene lease durante init/part/complete; firmar una parte renueva actividad.
- El cleaner respeta ese lease, edad de creación/heartbeat/mtime y partes recientes
  en todas las páginas de `ListParts`. Usa bucket configurado, key y UploadId
  exactos del estado validado por raíz de usuario.
- `NoSuchUpload` elimina únicamente metadata local vencida; no interpreta que
  deba borrar el objeto terminado. Errores de permisos/red conservan el estado.
- La limpieza histórica pública pasa a reporte: una captura antigua del catálogo
  no basta para autorizar borrado de objetos. Consultar `UPLOAD_CLEANUP.md`.
- Pruebas nuevas ejecutan el servicio real con un cliente SDK cuyo transporte S3
  está simulado; no requieren AWS ni una DB de producción. Cubren leases, dry-run,
  partes recientes/paginadas, ownership, errores, estado completado y firma de
  parte real del uploader.

## Orden de continuación

1. Cerrar fase 1 únicamente con CI verde y revisar el main actualizado.
2. Corregir guardado condicional Office y detección local de capacidades.
3. Política de retención que preserve correlaciones activas; guardas de apagado.
4. Auditoría de rutas/seguridad y aceptación de búsqueda, tareas y multimedia.
5. Diseñar papelera con recuperación antes de cambiar el contrato de eliminación;
   contrastar con catálogo y federación.
6. Aceptación multinodo, móvil, rendimiento medido y recuperación aislada; sólo
   después evaluar el contenedor híbrido Android/iOS sobre el mismo backend.
