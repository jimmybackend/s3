# Papelera y recuperación — diseño pendiente de implementación

## Semántica existente comprobada

FileMutationService::delete ejecuta deleteObject en S3 y después markFound(false).
deleteMany repite ese flujo. FolderMutationService elimina objetos del subárbol.
Found=0 no distingue papelera, ausencia física y reconciliación; no sirve como
registro de recuperación. Estas fases no cambian la semántica de esos endpoints.

No hay una papelera real implementada por esta consolidación. La recuperación
desde backups/versiones S3 depende de que existan y no equivale a un botón Deshacer.

## Diseño propuesto

Antes de cambiar la UI, implementar un servicio OOP de reciclaje con un registro
durable explícito y migración revisada. Ese registro sería nuevo: no se afirma que
haya una tabla, columna o endpoint de papelera actualmente. Debe relacionar
propietario, id de catálogo, nombre visible, key original, referencia retenida,
ETag/versionado cuando exista, fechas, estado y operación idempotente.

Flujo requerido:

1. Verificar autenticación, CSRF, ownership, bloqueo por hash y trabajos Office/
   copia/sync activos. Resolver la key desde el catálogo, nunca desde una etiqueta.
2. Retener los bytes de forma privada y verificable antes de retirar el acceso
   activo. Coordinar S3/DB con estados recuperables; no existe transacción conjunta.
   Si se usa Versioning, verificarlo explícitamente; no asumirlo ni habilitarlo
   automáticamente. Si se usa copia, preservar metadata y validar el resultado.
3. Registrar la transición del catálogo y revocar disponibilidad compartida
   mediante los servicios FederationCloud existentes. No permitir que sync o un
   peer atrasado vuelva a anunciar un objeto retirado.
4. Restaurar sólo con verificación de propiedad, huella/moderación, bytes y destino.
   Resolver colisión de nombre explícitamente sin sobrescribir otro archivo.
   No revivir automáticamente un recurso bloqueado o un enlace revocado.
5. Separar la purga definitiva de restaurar; exigir autorización y retención
   vencida comprobadas. La purga debe ser idempotente y registrar fallos parciales.

El diseño debe incluir carpetas completas, archivos ya compartidos/replicados,
referencias ArcadeLink históricas, cargas nuevas con mismo nombre y resultados de
jobs. El registro de papelera no reemplaza el centro de tareas ni su agregación.

## Condiciones para activar la nueva semántica

- Pruebas de comportamiento con MySQL/S3 aislados y fallo inyectado en cada paso.
- Casos de restore/purge repetidos, pérdida de respuesta, conflicto de ETag,
  permisos, falta de cuota y otro usuario.
- Nodo desconectado, réplica atrasada y hash bloqueado: recuperación no debe
  eludir moderación ni reaparecer vía sincronización.
- UI visible de Enviar a papelera, Restaurar y Eliminar definitivamente; contrato
  clásico/OS compatible y cambio explicado, nunca silencioso.
- Migración y rollback revisados antes de habilitar escrituras.

No se entrega una papelera parcial como si fuera segura. Su implementación y
aceptación siguen pendientes; los diez PRs de consolidación no cierran este frente.
