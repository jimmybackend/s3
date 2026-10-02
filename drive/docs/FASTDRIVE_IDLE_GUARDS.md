# Guardas de inactividad de FastDrive

## Problema corregido

El control manual ya verificaba leases y documentos Office. En cambio,
`MediaWorkerNodeService::handleIdle()` y `requestIdleStop()` sólo consultaban
`MediaProcessingJobs`. Una pestaña suspendida podía dejar de emitir heartbeats
sin que hubiera terminado la sesión de edición.

La guarda compartida `OfficeActivityProbe` consulta, sin modificar esquema:

- `OfficeSessionLeases`: `ExpiresAt > UTC_TIMESTAMP()`.
- `OfficeDocumentSessions`: `preparing`, `ready`, `syncing`, `conflict`.

El apagado por inactividad usa exclusivamente el `InstanceId` configurado o
identificado por el servicio existente. Una sesión de otro nodo no cuenta como
Office local del destino. El probe agregado conserva su alcance global original.
No se introducen llamadas a peers, SSH, Docker ni nuevas fuentes de tareas.

Con Office o multimedia activos, el worker cancela el contador. Tras la consulta
a AWS vuelve a leer sesiones, cola y contador, evitando apagar con una captura
anterior a un heartbeat o trabajo nuevo. Un fallo de consulta Office bloquea el
apagado; no se interpreta como ausencia de trabajo ni se devuelve SQL al cliente.
Los documentos no se cierran por antigüedad: una sesión abandonada necesita
reconciliación explícita antes de permitir el apagado.

## Validación aislada

`idle_stop_office_regression.php` ejecuta los servicios/repositorios reales con
MySQL desechable y transporte EC2 simulado. Comprueba los cuatro estados Office,
lease sin documento, lease vencido, documento cerrado, separación entre nodos,
cola multimedia, llegada de actividad durante DescribeInstances, sesión stopping
sin repetir StopInstances y tabla Office ausente. Nunca contacta AWS.

Ejecutar únicamente en el entorno de pruebas opt-in del workflow
`office-document-regression.yml`; no cargar configuración de producción.

## Límites pendientes

Esto corrige la omisión comprobada de Office, pero no constituye una exclusión
atómica entre StopInstances y todas las admisiones. Queda una ventana entre la
última consulta y la orden AWS. Cerrar esa ventana requiere coordinar los puntos
de entrada de multimedia, Office y demás trabajos con una guarda común de ciclo
de nodo; no basta añadir un lock que los productores no respeten.

También sigue pendiente verificar transferencias, réplicas, uploads y workers
adicionales que puedan ejecutarse realmente en el destino. Los stores locales
del gateway no prueban por sí solos la actividad del nodo remoto. No afirmar que
la protección de *todas* las tareas está terminada.

El apagado forzado superadmin continúa siendo una operación explícita distinta,
con reautenticación, que omite bloqueos funcionales. No se ha invocado en esta
auditoría. Ningún procedimiento de esta fase instala, reinicia o apaga servicios.
