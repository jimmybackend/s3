# Consolidación posterior al PR #308 — evidencia y límites

Base inspeccionada: `ff1969647452d8e9560b9d8a3e22005c7b94e704`.
Revisados PR #294–#308, README, arquitectura, esquema canónico y documentación
operativa. Este documento registra una consolidación **parcial**: no certifica
las doce fases completas ni un despliegue. No se accedió a servidores, secretos,
AWS real ni base productiva. No se cambió esquema ni infraestructura.

## Cambios independientes

| PR | Resultado comprobado |
| --- | --- |
| #309 | PublicDropzone limpia el objeto recién subido si falla FileS3; conserva el error SQL si también falla cleanup |
| #310 | Copiar/mover reutiliza MultipartCopy del SDK para objetos >5 GiB; keys codificadas, metadata y ETag; abort de multipart fallido |
| #311 | Regresión de métodos reales del worker con FFmpeg/FFprobe; fixture de overlap corregido a 3 s y ejemplos idle a 1200 s; runtime conservado |
| #312 | Centro de Tareas comparte peticiones simultáneas y evita respuestas desordenadas; reutiliza sus avisos |
| #313 | vendor y estado multipart real excluidos de dirty/stash; código y cambios reales siguen visibles |

#309/#310/#312 pasaron seis workflows cada uno; #311/#313, cuatro. Se fusionaron
sólo tras resultados satisfactorios en sus respectivos HEAD. Los commits
posteriores de inventarios son automáticos; el HEAD final se informa al entregar.

## Matriz de las doce fases

| Fase | Evidencia existente y estado | Pendiente real |
| --- | --- | --- |
| 1. Multimedia | MediaProcessingService/JobRepository/WorkerCommand, FFmpeg/FFprobe, capacidades y colas; 3 s antes/después confirmado en ejecución; MP3, cancelación, catálogo y origen intacto probados | Aceptación multigigabyte real, continuidad en códecs/keyframes variados, fallos durante publicación de varias salidas y todas las carreras de admisión/apagado |
| 2. Subidas | UploadFactory, local PUT, chunked, URL, Dropbox, SingleUpload, PublicDropzone y multipart admin. Guards SHA-256 presentes; rutas por Explorer probadas; rollback público corregido | No hay prueba conductual transversal de todos los drivers ante fallos SQL/S3 ni de todas las carreras de finalize/reintento. No declarar cerrado el requisito de cero huérfanos |
| 3. Copiar/mover/pegar | Servicios y MoveJobStore existentes; multiselección/clipboard/navigation probados; nuevo fixture 6 GiB sin descargar bytes; fallos multipart, SQL y delete conservan origen o destino válido | Exclusión entre escritores concurrentes, colisiones simultáneas, recuperación de movimiento de árbol parcialmente copiado y pruebas IAM reales |
| 4. Rutas | Búsqueda normal/IA abre carpeta/página correcta y selecciona archivo; renombrado preserva Prefix y actualiza etiquetas; DB-first conservado | Revisión completa de metadatos, mensajes excepcionales de proveedores, multimedia, Office y todas las vistas federadas; no certificar ausencia universal de keys visibles |
| 5. OS | Diez pruebas JS preexistentes aprobadas; ventanas, clipboard, búsqueda y aplicaciones; FileListService usa WEB_OS_PAGE_SIZE=30 | Aceptación integral del escritorio autenticado con medios, thumbnails, terminal y errores reales |
| 6. Updater | Integración Git temporal aprobada: fast-forward, dirty, ahead, rama, remoto incorrecto y fetch fallido. Runtime privado fuera del checkout; #313 protege estado multipart | Validar helper instalado, NOPASSWD/reconciliación y una actualización desde OS/Acerca de en cada nodo |
| 7. Papelera | No existe implementación adecuada. FileMutationService borra S3; Found=0 no conserva bytes. FileVersions pertenece a versiones de proyectos/chat, no a FileS3 | Implementación pendiente; ver barreras de seguridad abajo. No se añadió una UI que prometa recuperar bytes ya borrados |
| 8. Notificaciones | BackgroundTaskCenter ya notifica completed/failed/cancelled; shell/EventBus y avisos Office/Federation existentes. #312 corrige superposición sin otro sistema | Verificar recepción en sesiones largas y distintas pestañas; cobertura global uniforme de Office/federación/moderación no implementada por estos PR |
| 9. Recuperación | CONSOLIDATED_OPERATIONS y scripts cifrados de identidad existentes; separación Git/DB/S3/runtime/Office documentada | Ensayo integral de restauración en nodo aislado, RPO/RTO medidos y automatización de backup DB/config con permisos y destino verificados |
| 10. Rendimiento | Cuello reproducido: 25 eventos => 25 peticiones; corregido a una. Fixture 10.000 archivos: dos SQL por página, límites y ownership; 200 rutas de búsqueda requieren una lectura de jerarquía | Medir PHP-FPM/RAM, thumbnails, IA y concurrencia en hardware equivalente. No se alteraron pools/índices sin evidencia |
| 11. Responsive | Chromium con renderizadores y scripts reales, servicios simulados: 320/360/768/1440 px; ampliado a 1920 px para panel EC2 | No equivale a ensayo táctil de todas las apps/Explorer/subidas; quedan teléfonos y escritorio autenticado. Guacamole sigue en su flujo existente |
| 12. DB local/réplica | Análisis en LOCAL_DATABASE_ASSESSMENT.md; mysqli y una única DB compartida; compatibilidad parcial comprobada con MariaDB aislada | Ninguna migración automática. Verificar esquema/versión/tamaño reales y restauración completa antes de decidir |

## Pruebas y medición

Antes de cambios pasaron 48 smoke tests PHP, diez pruebas funcionales JS y FFmpeg
shell. Tras preparar PHP 8.4 se ejecutaron regresiones de Office, idle, cleanup,
registro de upload, CSRF, rutas y borrado parcial. Los fixtures de rutas necesitaban
MySQL 8 por `utf8mb4_0900_ai_ci`; se añade adaptación de collation **sólo en tests**
para MariaDB, como ya hacía la regresión Office. El dump productivo permanece igual.

Nuevas regresiones:

- `public_upload_rollback_regression.php`: propietario/key/metadata/hash bloqueado,
  SQL rechazado, limpieza exacta y limpieza fallida.
- `file_copy_regression.php`: 6 GiB simulados, rangos, metadata, ETag, key especial,
  fallo de partes/SQL/delete y otro propietario. No transfiere 6 GiB reales.
- `media_worker_behavior_regression.php`: fixture de 12 s; intervalos solicitados
  0–7, 1–11, 5–12 s; tolerancia 0,35 s de contenedor, MP3, DB y cancelación.
- `background_tasks_refresh_functional.js`: 25 llamadas simultáneas, una petición,
  una notificación, recuperación HTTP 503 y lista actualizada sin reload.
- `updater_runtime_ignore.sh`: dirty/stash con Git real temporal; conserva estado
  y locks de subidas activas.
- `large_folder_regression.php`: 10.000 filas canónicas, primeras/intermedias/últimas
  páginas, clamp, ownership y búsqueda limitada. Muestra tiempos sin umbral frágil.

En un ensayo local, las cuatro páginas costaron 11–16 ms y el pico PHP reportado
fue 2 MiB. Esto excluye el servidor DB y sus buffers, navegador, FPM, S3 y red;
**no es una estimación de consumo ni de latencia en t3.micro**.

## Barreras antes de implementar papelera

Se revisaron FileS3, S3Folders, FileVersions, servicios de borrado, sync y acceso
federado. No existe un registro durable de contenido retenido/restaurable. Una
migración nueva sería necesaria; reutilizar Found como si fuera una papelera
rompería su significado de disponibilidad/reconciliación.

El diseño existente en TRASH_RECOVERY_DESIGN.md sigue vigente. Antes de integrar
el cambio hacen falta transiciones recuperables frente a fallos DB/S3 y concurrencia
con sync, Office, compartir, réplicas y moderación. Retener una copia sin coordinar
estos consumidores puede reactivar contenido retirado o restaurar bytes antiguos.
Esta implementación **no se realizó**; es trabajo de código pendiente, no solamente
una prueba manual ni un requisito de autorización adicional del usuario.

## Aceptación real obligatoria

Actualizar mediante el updater web cuando el administrador decida desplegar.
Un merge no prueba que ambos nodos estén actualizados. En una ventana controlada:
validar uploads normal/grande/URL/clipboard e imágenes en dos Explorers; copiar
un archivo grande y una carpeta; buscar/localizar; reproducir medios; abrir,
editar y guardar Writer/Calc/Impress; comprobar solicitudes de moderación y paneles.
Observar que multimedia/Office inseguro bloquean idle y que el apagado sólo ocurre
al quedar seguro y transcurrir 20 min + 30 s. Esas operaciones no se ejecutaron aquí.

Sigue pendiente inyección transversal de fallos de conexión y concurrencia antes
de afirmar que todas las mutaciones son recuperables. Nunca importar el dump
canónico sobre la DB existente para validar estas pruebas.
