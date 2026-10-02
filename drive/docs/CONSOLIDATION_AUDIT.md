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
| Mi nodo | `NodeStatusController`, `NodeRuntimeStatusService`, `NodeCapabilityService` | Endpoint local; fase 3 añade evidencia de workstation local por socket Unix, con estados separados de host/contenedor/detenido/no verificable |
| Uploads/temporales | `UploadFactory`, `Chunked15MBUploader`, `UploadStateStore`, `UploadCleanupService` | Ruta chunked distinta del estado público histórico; corregido en fase 1 descrita abajo |
| Actividad | `ActivityCostRepository`, `DriveActivityEvents`, reconciliadores Polly/Transcribe | Fase 4 añade archivo/retención conservadora de telemetría síncrona; registros correlacionados y operativos se conservan |
| Office | `OfficeDocumentStorageService`, repositorios de sesiones/leases, gateway y cliente workstation | Preparación, sync, catálogo, conflicto y cierre existen. `sync()` verificaba HEAD y luego PUT sin `IfMatch`: corregido en fase 2; aceptación real de escritorio pendiente |
| Multimedia | `MediaProcessingService`, `MediaProcessingJobRepository`, `MediaWorkerNodeService` | Existe cola, procesamiento y documentación de integración; validación completa pendiente |
| Tareas | `BackgroundTaskController`, Sync/Move stores, actividad, media y mantenimiento | Agregación existente; no crear tabla universal |
| FastDrive | `FastDriveControlService`, `MediaWorkerNodeService`, `ServerTaskActivityProbe` | Hay autorización y protección de actividad; cobertura y errores requieren revisión adicional |
| FederationCloud | `drive/src/Federation/`, documentación de réplicas, permisos, recuperación y moderación | Implementado; no se ha validado extremo a extremo entre nodos en esta auditoría |
| ArcadeLink | Colecciones y pruebas en `arcadelink_collection_regression.php` / `ARCADELINK_PRODUCTION_VALIDATION.md` | Existen v1/v2/v3; la propia matriz enumera validaciones multinodo pendientes |
| Seguridad | Controllers y pruebas de hardening existentes | CSRF/ownership de upload confirmados en código; auditoría transversal pendiente |
| Rutas y búsqueda | `FileSearchService`, `AiFileSearchService`, `so-search.js`, `FileListService` | Mantener contrato catálogo visible/key física; regresión normal/IA localizar/nueva ventana/página/selección validada en fase 3; cobertura transversal pendiente |
| Papelera | `FileMutationService::delete()` llama a `deleteObject` | Borrado actual definitivo; no presentar `Found=0` como papelera. Requiere diseño de recuperación y propagación federada |
| Notificaciones | Shell/EventBus y fuentes de tareas existentes | Cobertura por tipo de tarea pendiente |
| Recuperación | Scripts `federation_identity_backup.php` / `federation_identity_restore.php` | Identidad cubierta parcialmente; runbook integral MySQL/config/catálogo pendiente |
| Instalación | Instalador y documentación de nodos/identidad | Auditar tercer nodo sin hardcoding; no ejecutado en servidores. CI confirmó que composer.lock exige PHP >=8.4.1 (symfony/filesystem); revisar compatibilidad declarada por instalador |
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

## Fase 1 — resultado

PR #273 fusionado tras siete workflows satisfactorios en el commit final. Incluye
lint PHP y pruebas de comportamiento del cleanup; las comprobaciones JS de
contexto de upload también pasaron localmente. Main obtenido de nuevo:
`157260c2551c236991b8f5d51c91561421b5ba83`.

## Fase 2 — guardado Office

Base: main anterior. Añade GET/PUT condicional, reutiliza la copia de conflicto
existente y conserva el ETag del propio PUT. Prueba nueva del servicio/repositories
con MySQL 8 desechable y S3 simulado. No valida todavía toda la edición desde
Guacamole ni modifica infraestructura. Detalle en `ARCADECLOUD_REMOTE_WORKSTATION.md`.

## Fase 2 — resultado

PR #274 fusionado tras cuatro workflows satisfactorios, incluida regresión Office
con MySQL 8 y S3 simulado. Se obtuvo main `5988523a5fbedca353cd9c2edac0f60ffc1e9e94`.
Los commits automáticos intermedios sólo actualizaron inventarios/documentación.

## Fase 3 — capacidades locales

Detección por helper de metadata de un contenedor workstation fijo, sobre socket
Unix y sólo GET/HEAD. No ejecuta comandos Docker ni consulta peers. Mantiene
compatibilidad de `installed` (host) y añade estados explícitos para la UI.
Ver `LOCAL_NODE_CAPABILITIES.md`, incluido el requisito de actualizar el helper
instalado mediante el procedimiento existente y el alcance de programas cubiertos.

CI de fase 3 detectó dos fallos de los contratos por texto: interpolación accidental
`$app` en el test de sugerencias y falso positivo de `exec(` sobre `curl_exec()`.
Se corrigen los tests conservando la comprobación de nombres visibles y la
prohibición de funciones shell completas, también con espacios antes de `(`.

También se retiró una expectativa obsoleta del test que exigía reutilizar el
Explorer con `refreshExplorer`, contraria al contrato actual. Se reemplaza por
una regresión JS que ejecuta búsqueda normal/IA, ruta visible separada, relocaliza
por id, abre ventana nueva en página 7, espera `ready` y selecciona el archivo.
El runtime de búsqueda no se modificó.

## Fase 3 — resultado

PR #275 fusionado tras diez workflows satisfactorios. Regresiones PHP del helper,
JS de presentación y búsqueda normal/IA aprobadas. Main obtenido:
`15dca1d8cefb492fac96f3d446d461a25b9ea5aa`.

## Fase 4 — retención de actividad

Comando CLI nuevo con dry-run, archivo durable obligatorio, transacción y lotes
por índice primario. Sólo telemetría síncrona comprobada, sin correlación ni
metadata operativa. No modifica esquema ni instala cron/timers. La reducción de
filas de control terminales queda pendiente; no afirmar tamaño total acotado.
Consultar `ACTIVITY_RETENTION.md` para pérdida de visibilidad histórica en reportes,
archivo privado y necesidad de probar recuperación aislada antes de automatizar.

Hallazgo adicional para la próxima fase: `MediaWorkerNodeService::handleIdle()` y
`requestIdleStop()` sólo consultan `MediaProcessingJobRepository::hasActiveJobs()`;
la ruta manual superadmin sí consulta sesiones Office. Auditar la guarda del
apagado por inactividad para no depender únicamente de heartbeats del navegador.

## Fase 4 — resultado

PR #276 fusionado tras tres checks satisfactorios, incluyendo MySQL aislado,
archivo/rollback y PHP lint. Main actualizado a `477d8b5` después de inventarios
automáticos. No se ejecutó el comando de retención contra producción.

## Fase 5 — Office y autoapagado

`OfficeActivityProbe` reúne las consultas existentes de leases/documentos para
apagado manual, actividad agregada y apagado por inactividad. El autoapagado ahora
protege Office aunque falte el heartbeat del navegador, acota la consulta a su
InstanceId y falla cerrado si no puede comprobar el esquema. Revalida actividad y
contador después de consultar AWS. Ver `FASTDRIVE_IDLE_GUARDS.md` para límites:
no se certifica exclusión atómica con todas las admisiones ni cobertura de cada
worker/transferencia. No se modifican infraestructura ni apagado forzado explícito.

## Fase 5 — resultado

PR #277 fusionado tras siete checks satisfactorios, con regresión MySQL/EC2
simulado. Main recuperado `9d99744011394cf4ca0f7192b277e9e9bd1fe397`.

## Fase 6 — renombrado y separación de rutas

Hallazgo comprobado: `FolderMutationService::rename()` sólo actualiza `Nombre`,
pero el listener `drive:folder-mutated` de `os-window-manager.js` construía un
Prefix nuevo concatenando el nombre visible. Ahora recarga cada Explorer de la
carpeta y descendientes en su mismo Prefix y página, para actualizar breadcrumbs.
También saca del subárbol eliminado las ventanas descendientes. El fallback de
`so-folders.js` conserva la ruta al renombrar.

Se eliminan fallbacks de presentación que mostraban Prefix/key al faltar metadata:
raíz sin registro usa `Mi Drive`, búsqueda usa `Ubicación no disponible`, carpeta
sin etiqueta usa `Carpeta`, y multimedia sin nombre usa `Archivo`. No se modifica
la ruta física enviada a endpoints ni los datasets necesarios para operar.

Regresiones: listener real de ventanas múltiples; búsqueda normal/IA y metadata
visible ausente; servicios/repositories con S3 prohibido durante rename y MySQL
canónico aislado para ownership, breadcrumbs y destinos de mover. No representa
una auditoría completa de todas las pantallas de `so.php`.

## Fase 6 — resultado

PR #278 fusionado tras cinco checks satisfactorios: MySQL canónico aislado,
regresiones JS normal/IA/múltiples ventanas y lint. Main recuperado:
`f89b077c86ffe270cd1b47357256a17e1fdb955f`.

## Fase 7 — CSRF de mutaciones y controles de tareas

Hallazgo: `requirePost()` sólo validaba método. FileMutationController,
FolderMutationController, MoveJobController::start y BackgroundTaskController::action
no verificaban token. El bootstrap no añadía esa protección. Se incorpora una
guarda compartida usando `upload_csrf` / `X-Drive-CSRF` existentes y se actualizan
clientes clásicos/OS y fallback de formulario. Detalle: `FILE_MUTATION_CSRF.md`.
No se extrapola este resultado a todos los endpoints del repositorio.

## Fase 7 — resultado

PR #279 fusionado tras seis checks satisfactorios. Los controladores reales
rechazan peticiones sin token antes de almacenamiento/workers; handlers clásicos
probados en Node. Main actualizado `4ea0bc7d5bd87bd7a4223c7acefaa45f602ef6a1`.

## Fase 8 — extensión de CSRF a sincronización y protección de archivos

La inspección de los demás botones del explorador confirmó el mismo hueco en
FileSecurityController, FileKeyRotationController y SyncController::run. Se aplica
la guarda existente a esas acciones y se actualizan `file-security.js`, helper
clásico de formularios en `archivos.js` y `sincronizar.js`. La consulta de estado
sigue siendo de lectura. Se amplían las regresiones de controladores y handlers
reales; ver mapa de endpoints en `FILE_MUTATION_CSRF.md`.

## Fase 8 — resultado

PR #280 fusionado tras siete checks satisfactorios, incluidas pruebas MySQL 5.7 y
MariaDB existentes además de controladores CSRF. Main recuperado:
`22fdfe74466faaa710b09235853c096c9ac91886`.

## Fase 9 — consultas de rutas visibles en búsqueda

Cuello concreto: `FileSearchController::withVisibleRoutes()` invocaba una consulta
completa de jerarquía por resultado (hasta 200 en búsqueda normal). Ahora pide un
lote a FolderQueryService: una lectura del catálogo, resolución deduplicada de
prefixes, sin caché persistente ni cambios de esquema/índices. Se conserva `ruta`
para operaciones y `ruta_visible` para UI, en búsqueda normal y AI.

La regresión MySQL mide Com_stmt_execute: 200 rutas de resultados requieren una
consulta, lote vacío ninguna; un rename posterior produce etiqueta actualizada.
No se atribuye una mejora porcentual de latencia sin medir datos de producción.
El resto de consultas, polling y miniaturas requiere medición independiente.

Filtros de búsqueda: el servicio actual admite patrones (`*.pdf`, `fact*`, `?`),
por lo que ya cubre extensión mediante patrón sin nueva UI. Fecha/tamaño están en
FileS3; añadir controles explícitos requiere validar paginación/localizar y UX.
Metadata semántica no se debe tratar como columna universal: AI usa su pipeline.
Se posponen filtros nuevos hasta medir utilidad, sin alterar resultados actuales.

## Fase 9 — resultado

PR #281 fusionado tras cuatro checks satisfactorios. MySQL confirmó una consulta
para 200 rutas, cero para lote vacío y ausencia de etiquetas obsoletas tras rename.
Main recuperado `72a200be4047a15b1730d2f21c43c7ed4629706e`.

## Fase 10 — requisito PHP de instalación

El instalador aceptaba familias 8.1–8.3 incompatibles con el lock actual. Ahora
un nodo nuevo selecciona 8.5/8.4 y verifica CLI/FPM >=8.4.1 antes de configurar
servicios. Un runtime existente incompatible/no identificable bloquea el flujo
antes de instalar paquetes; no se migra su familia silenciosamente.

Prueba shell ejecuta la función real de selección con comandos simulados, cubre
8.4.0 rechazado, 8.4.1 aceptado, preservación de familia y ausencia de paquetes
compatibles. No ejecuta entrypoint, DNF ni systemctl. Instalación de un tercer nodo
real, DNS/TLS y conectividad MySQL siguen requiriendo aceptación aislada.
