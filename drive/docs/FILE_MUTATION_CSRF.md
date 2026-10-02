# CSRF en archivos, carpetas y tareas

## Alcance comprobado

Los controladores exigían sesión y POST, pero no token CSRF. `requirePost()` no
era una protección CSRF y `app_bootstrap.php` tampoco añadía una comprobación.
Se reutiliza el token `upload_csrf` emitido por `s3.php` y `so.php`, sin crear
otro sistema de sesiones o tokens.

| Controlador / acción | Entradas existentes protegidas |
| --- | --- |
| FileMutationController | `eliminar_archivo.php`, `delete_multiple.php`, `mover_archivo.php`, `move_multiple.php`, `renombrar_archivo.php` |
| FolderMutationController | `crear_carpeta.php`, `eliminar_carpeta.php`, `mover_carpeta.php`, `renombrar_carpeta.php` |
| MoveJobController::start | `move_task.php` (copiar/mover asíncrono) |
| BackgroundTaskController::action | POST `background_tasks.php` (acciones sobre fuentes existentes) |

Orden: método POST, autenticación, token, validación de entrada y servicio.
Un rechazo devuelve HTTP 403 con JSON compatible con `ok/error/estado/mensaje`,
antes de tocar almacenamiento, colas o lanzar workers. No devuelve el token.

La cabecera es `X-Drive-CSRF`. Sólo si está vacía se acepta el campo POST
`upload_csrf` para formularios nativos; un header incorrecto no se puede ocultar
con un campo correcto. No se acepta token desde query string ni arrays.
Ownership, permisos y reglas físicas permanecen responsabilidad de los servicios
existentes: CSRF no sustituye esas comprobaciones.

## Compatibilidad y actualización

Clientes revisados: `archivos.js`, `carpetas.js`, `file-block.js`,
`elimina-uno.js`, `elimina-multiple.js`, `filesystem-operations.js`,
`move-tasks.js`, `background-tasks.js`. Los helpers combinan las cabeceras
conservando Content-Type y los límites de multipart de FormData. El formulario
clásico `multiDeleteForm` lleva el campo oculto. Se actualiza la versión del
loader clásico de background-tasks; el resto usa filemtime en las páginas.

Después de desplegar mediante el updater habitual, recargar las pestañas
abiertas de OS/Drive clásico. Un cliente antiguo o integración propia sin token
recibirá 403 y debe actualizarse; no hay excepción temporal que desactive la
protección. No registrar tokens ni enviarlos a URLs externas.

## Pruebas

- `file_mutation_csrf_regression.php`: invoca los controladores reales en
  subprocesos porque JsonResponse termina la ejecución. Casos sin token,
  incorrecto, array, query, sesión sin token, sin autenticación y GET. DB sin
  conectar y transporte AWS prohibido; ningún bootstrap de producción.
- La misma prueba verifica que la guarda acepta header o formulario válido y
  rechaza el header incorrecto aunque haya un campo válido.
- `classic_mutation_csrf_functional.js`: handlers reales de carpetas/archivos,
  comprueba token y conservación de Content-Type/FormData.
- `web_os_filesystem_operations_functional.js`: petición real del coordinador OS,
  además del ciclo de tareas/EventBus y prevención de doble envío ya existentes.

Workflow `security-hardening.yml`: ejecución aislada opt-in, PHP lint completo,
JS syntax y auditoría de dependencias. No requiere MySQL ni acceso AWS.

La cobertura transversal de otros endpoints de seguridad, enlaces, sincronización
y configuración sigue pendiente de revisión individual. Esta fase no certifica
ausencia de CSRF en todo el repositorio ni cambia la semántica de eliminación.
