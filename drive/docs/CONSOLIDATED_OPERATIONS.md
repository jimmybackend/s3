# Operación y recuperación de ArcadeCloud OS

Estado: consolidación incremental, PRs #273–#282. No certifica los 21 frentes ni
un despliegue en producción. Ver [CONSOLIDATION_AUDIT.md](CONSOLIDATION_AUDIT.md).

## Arquitectura real

OS y Drive clásico comparten DriveApplication, controladores, servicios OOP,
repositorios mysqli y gateways. MySQL gobierna catálogo/ownership; S3 contiene
bytes. Mi nodo describe el host actual y su workstation local, no un peer remoto.

| Área | Fuente existente |
| --- | --- |
| Archivos/carpetas | FileS3 y S3Folders; Nombre visible separado de Encriptado/Ruta/Prefix físicos |
| Upload chunked | UploadStateStore, drive/upload/storage/state, escrituras atómicas y leases |
| Copiar/mover | MoveJobStore, MoveJobService y worker existente |
| Sincronización | SyncJobStore y worker existente |
| Multimedia | MediaProcessingJobs, FFmpeg/FFprobe, MediaProcessingWorkerCommand |
| Actividad e IA | DriveActivityEvents, incluidos registros operativos Polly/Transcribe |
| Office | OfficeSessionLeases, OfficeDocumentSessions y workspace local |
| Centro de tareas | BackgroundTaskController agrega fuentes; EventBus comunica cambios |

[Arquitectura completa](../ARCHITECTURE.md).
La búsqueda normal/IA conserva ruta física, publica ruta_visible, relocaliza por id
y abre una ventana nueva en la página correcta con el archivo seleccionado.
Renombrar una carpeta cambia su Nombre, no su Prefix. La resolución de etiquetas
de búsqueda lee la jerarquía una vez por lote, sin caché persistente.

Office usa GET/PUT condicionados por ETag, copia de conflicto y actualización del
catálogo. Un guardado fallido no cierra como exitoso el workspace. Multimedia
mantiene su preflight, cola, progreso y resultados existentes. La aceptación de
Guacamole real, archivos grandes y failover no queda probada por fixtures de CI.

## Guías específicas

- [Capacidades locales](LOCAL_NODE_CAPABILITIES.md): helper v16; un merge no prueba
  que el helper privilegiado instalado haya sido actualizado.
- [Office](ARCADECLOUD_REMOTE_WORKSTATION.md) y
  [guardas FastDrive](FASTDRIVE_IDLE_GUARDS.md): Office bloquea el autoapagado,
  pero falta exclusión atómica con todas las admisiones y otros workers.
- [CSRF de archivos y tareas](FILE_MUTATION_CSRF.md): reutiliza token existente;
  recargar pestañas después de actualizar. CSRF no sustituye ownership.
- [Federación](FEDERATION_OPERATIONS.md),
  [réplicas](FEDERATION_REPLICAS.md),
  [moderación](FEDERATION_CONTENT_MODERATION.md) y
  [ArcadeLink](ARCADELINK_PRODUCTION_VALIDATION.md): conservar la distinción entre
  pruebas de firma/protocolo y validaciones reales multinodo aún pendientes.

## Mantenimiento

Dry-run desde el contexto local correcto, por el operador; tratar los reportes
como privados:

~~~bash
php drive/bin/upload_cleanup.php --days=30
php drive/bin/activity_retention.php --days=365 --limit=500 --after-id=0
~~~

Cleanup verifica el multipart exacto y respeta actividad/leases. Un estado antiguo
no autoriza borrar un objeto terminado; el cleanup histórico público queda en
reporte. Retención de actividad exige un archivo privado durable antes del borrado
transaccional y conserva correlaciones/registros operativos. No se instaló cron.
Ver [UPLOAD_CLEANUP.md](UPLOAD_CLEANUP.md) y
[ACTIVITY_RETENTION.md](ACTIVITY_RETENTION.md).

## Material de respaldo

| Material | Necesidad |
| --- | --- |
| Commit y composer.lock | Reproducir código/dependencias; no sustituyen configuración |
| Configuración local efectiva | /etc/arcadecloud-drive, overrides confiables, unidades propias, Nginx; cifrar porque contiene secretos |
| MySQL completo | Catálogo, IDs, usuarios, permisos, enlaces, moderación, sesiones y tareas; no sólo FileS3 |
| Identidad FederationCloud | Respaldo cifrado existente, frase custodiada por separado |
| S3 | Bytes y políticas/versiones reales; no asumir Versioning habilitado |
| Office | /var/lib/arcadecloud-office: workspace y perfil, incluidas ediciones no sincronizadas |
| Guacamole | /var/lib/arcadecloud-guacamole y backup coherente de DB del volumen arcadecloud-guac-mysql |
| Stores de trabajos/uploads | Copia privada para reconciliar, nunca reanudar ciegamente |

Registrar UTC, commit, nodo, alcance, checksum y ubicación cifrada. Mantener
propietario/grupo/modos/ACL y resolver el usuario PHP-FPM real al restaurar.
No almacenar dumps, identidad privada, semillas TOTP ni credenciales en Git,
webroot o capturas. No copiar un volumen MySQL en caliente como backup consistente.

Usar backup del proveedor o dump lógico compatible con la versión/motores reales,
privilegios, rutinas/triggers y DDL concurrente. Un snapshot SQL no vuelve atómicos
MySQL y S3: registrar cambios posteriores y reconciliarlos. Probar descifrado y
restauración aislada antes de confiar en el respaldo. El SQL canónico del repo
contiene DROP: es estructura, no un backup ni una actualización para importar
sobre producción.

La identidad ya tiene herramientas:

~~~text
php drive/bin/federation_identity_backup.php --path=<identidad-local> --out=<archivo-cifrado-nuevo> --passphrase-file=<archivo-privado>
php drive/bin/federation_identity_restore.php --backup=<respaldo-cifrado> --path=<destino-aislado-nuevo> --passphrase-file=<archivo-privado>
~~~

Son plantillas con rutas absolutas a sustituir, no comandos ejecutados. No usar
--force como rutina. Ver [FEDERATION_NODE_RECOVERY.md](FEDERATION_NODE_RECOVERY.md).

## Ensayo de recuperación

1. Preparar DB/bucket aislados, sin escritura a producción, correo saliente,
   registro federado ni workers/autoapagado. No publicar dos nodos con la misma
   identidad. Mantener la restauración de identidad offline durante el ensayo.
2. Verificar integridad; restaurar MySQL aislado conservando IDs y relaciones.
   Restaurar configuración cifrada con destinos de ensayo y permisos correctos.
3. Reproducir commit/dependencias. Restaurar Office/Guacamole de forma coherente;
   conservar copia de ediciones locales antes de reconciliar sesiones/ETags.
4. Probar fixtures: subir, visualizar, buscar/localizar/seleccionar, copiar/mover,
   Office/conflicto, multimedia, compartir y permisos. Medir duración y pérdida
   observada. RPO/RTO son objetivos por definir y medir, no garantías actuales.
5. Para un corte real coordinar todos los escritores de una DB/S3 compartidos.
   Validar el reemplazo antes de cambiar tráfico y habilitar productores de uno
   en uno. No restaurar la DB compartida como si perteneciera a una sola EC2.

Volver de commit no revierte S3/DB ni migraciones. Conservar respaldo y entorno
anterior hasta validar. No usar hard reset, git clean ni reconstruir Office para
probar recuperación. Estos procedimientos no fueron ejecutados en producción.

## Instalar y actualizar

[Preparación](INSTALLATION_PREPARATION.md),
[instalador](AUTOMATED_INSTALLER.md) y
[nodo nuevo](FEDERATION_NODE_REPLICA_INSTALL.md): PHP CLI/FPM >=8.4.1 con el lock
actual, identidad propia y rol/URLs de configuración local. Una réplica nueva no
copia identidad del origen; un reemplazo sí necesita restaurar la identidad del
nodo perdido bajo un corte controlado.

Usar el updater web existente: comprobar rama/commit y preflight, respetar cambios
locales, actualizar y recargar pestañas. Actualizar el helper instalado por el
procedimiento existente cuando corresponda. Un PR merged no demuestra despliegue,
ni autoriza inferir que todos los servicios del host cambiaron.

Papelera: [diseño pendiente](TRASH_RECOVERY_DESIGN.md).
Aplicación móvil: [evaluación y propuesta](MOBILE_APP_PROPOSAL.md).
