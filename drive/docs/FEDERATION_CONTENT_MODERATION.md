# Moderación de contenido y huellas FederationCloud

## Objetivo

FederationCloud mantiene una defensa distribuida contra la redistribución de contenido confirmado como dañino, abusivo o prohibido. La regla base es que el nombre del archivo no importa: los bytes exactos se identifican por un Content ID SHA-256.

Ejemplo:

```text
sha256:<64 caracteres hexadecimales>
```

Renombrar `archivo.exe` a `foto.jpg` no cambia su SHA-256.

## Flujo de FederationDrop

1. Stripe confirma el pago.
2. El archivo se sube a S3 privado.
3. Antes de activarlo, el nodo calcula SHA-256 leyendo el objeto por stream.
4. Se consulta `FederationModerationBlocks`.
5. Si la huella está bloqueada, el FederationDrop no puede activarse.
6. Si está permitida, se registra en `FederationContentFingerprints` y continúa el flujo normal.

Los recursos públicos FederationCloud con Content ID conocido también se comprueban antes de publicarse o convertirse en FederationDrop.

## Reporte del cliente

Un cliente puede abrir:

```text
/federationcloud/report.php?type=drop&id=fdp_...
/federationcloud/report.php?type=resource&id=arl_...
```

Categorías disponibles:

- spam
- malware
- illegal
- abuse
- copyright
- other

El reporte no borra automáticamente contenido. Se registra como `pending` y requiere decisión humana. La pantalla conserva accesos visibles para volver al lector **ArcadeLink** o al **Drive**.

Los resultados del catálogo global incluyen un botón **Reportar abuso** que dirige el reporte al nodo de origen del recurso. Un FederationDrop activo también muestra el enlace de reporte en su panel de administración.

## Uso por el superusuario

Sólo `Users.system_role = 'superadmin'` puede decidir reportes.

Página:

```text
/federationcloud/moderation.php
```

El footer del Drive muestra:

```text
Moderación: N
```

para avisar que existen reportes pendientes.

El superusuario debe leer el reporte y escribir el motivo de su decisión.

### Rechazar / retirar

`Rechazar / retirar` marca el reporte como `rejected`, lo saca de la cola de pendientes y no modifica el archivo ni la lista de bloqueo. El registro se conserva como auditoría; no se borra el historial de moderación.

### Huellas faltantes en archivos legacy

Los archivos nuevos normalmente ya conservan `hash_sha256` en `FileS3.Metadatos`. Para archivos históricos que todavía no tengan esa huella:

- al crear un ArcadeLink no privado, ArcadeCloud calcula SHA-256 leyendo el objeto original de S3 por stream una sola vez;
- guarda la huella en `FileS3.Metadatos` para reutilizarla;
- el ArcadeLink nuevo incluye su `content_id` y el catálogo registra `FederationContentFingerprints`;
- si un reporte antiguo ya quedó pendiente con `ContentId=NULL`, **Confirmar y bloquear** recupera el ArcadeLink firmado del recurso, localiza el archivo original, calcula/persiste la huella y la adjunta al reporte antes de emitir el bloqueo;
- si el objeto original ya no existe o cambia de tamaño durante el cálculo, no se bloquea ninguna huella dudosa.

Los ArcadeLink `PRIVATE` no publican el Content ID dentro del documento. Si un caso de moderación autorizado requiere la huella, el nodo origen puede calcularla internamente durante la revisión.

### Confirmar y bloquear

`Confirmar y bloquear`:

1. exige una huella SHA-256 verificable;
2. marca el reporte como confirmado;
3. emite un evento Ed25519 `moderation.block`;
4. materializa la huella en `FederationModerationBlocks`;
5. ejecuta limpieza local;
6. sólo después marca el reporte como confirmado y lo retira de la cola de pendientes;
7. los demás nodos reciben el evento por el gossip normal y ejecutan su limpieza en el siguiente ciclo de sincronización;
8. futuras operaciones FederationDrop que produzcan la misma huella son rechazadas.

La limpieza local busca:

- FederationDrops registrados con esa huella;
- objetos `FederationReplicaObjects`;
- archivos `FileS3` cuyo metadata contenga `hash_sha256`, `sha256` o `checksum_sha256`.

La evidencia de moderación conserva el hash, reporte, nodo, decisión y auditoría. No es necesario conservar los bytes del contenido retirado.

### Revocar un bloqueo

El superusuario puede revocar una huella activa emitida por **su propio nodo**. La acción exige motivo y CSRF, emite el evento firmado `moderation.unblock`, cambia el bloqueo a `revoked` y permite que esos mismos bytes vuelvan a subirse.

Revocar una huella **no restaura** archivos ya eliminados de S3 o retirados de MySQL. Si fue una prueba o falso positivo, el propietario debe volver a subir una copia legítima después del desbloqueo. El reporte y la auditoría histórica se conservan.

## Propagación

Se agregan dos tipos de eventos firmados:

```text
moderation.block
moderation.unblock
```

La propagación utiliza el mismo registro de eventos, firmas Ed25519, vector clocks y sincronización peer-to-peer del catálogo FederationCloud. No existe una base central obligatoria para la denylist.

El cleanup se ejecuta después del gossip dentro de `FederationSyncCycleService`.

Con el timer recomendado de 120 segundos más jitter, la convergencia es eventual y normalmente ocurre en pocos minutos.

## Tablas

### FederationContentFingerprints

Relaciona una huella exacta con un sujeto local:

- drop
- resource
- file
- replica

Puede guardar la S3 key conocida para eliminar el objeto sin depender del nombre visible.

### FederationAbuseReports

Conserva el reporte, categoría, objetivo, nodo responsable, estado y decisión humana.

### FederationModerationBlocks

Denylist distribuida de Content IDs SHA-256 activos o revocados.

### FederationModerationActions

Auditoría de creación de reporte, confirmación/rechazo y acciones de limpieza.

## Política de pago

El pago no concede derecho a alojar material prohibido. Un pago cubre procesamiento, transferencia, validación, almacenamiento temporal y demás costos operativos ya incurridos.

Cuando un reporte es confirmado y el contenido viola la política del servicio, la aplicación puede retirar el contenido y aplicar una política de **sin reembolso automático** por esa retirada.

Esto no intenta anular obligaciones legales, contracargos, devoluciones exigidas por el procesador de pagos ni otros derechos que correspondan por ley. Stripe continúa siendo la fuente de verdad del estado del pago.

## Bloqueo en todas las rutas de subida

La denylist SHA-256 se aplica a todas las entradas activas de ArcadeCloud, no sólo a FederationDrop.

- **Subida local / Dropzone (`local_put`)**: para archivos de hasta 256 MiB, el navegador calcula SHA-256 antes de pedir la URL presignada y el servidor puede rechazar el hash conocido antes del PUT. Al completar, ArcadeCloud vuelve a calcular autoritativamente SHA-256 desde S3 antes de registrar `FileS3`; si está bloqueado, elimina el objeto y no lo registra.
- **Multipart / chunked**: las partes viajan directo navegador -> S3. Tras completar el objeto, ArcadeCloud calcula SHA-256 desde S3 y consulta la denylist antes de registrar el archivo. Si está bloqueado, elimina el objeto y descarta el estado reanudable.
- **Multipart administrativo (`up.php`)**: después de completar S3 y antes de `upsertCompletedMultipart`, calcula SHA-256, consulta la denylist y elimina el objeto si está bloqueado.
- **URL remota (`remote_url`)**: SHA-256 se calcula durante el streaming. Para archivos pequeños la denylist se consulta antes de `PutObject`; para multipart se consulta antes de `CompleteMultipartUpload`, abortando las partes si el contenido está bloqueado.
- **Dropbox legacy**: calcula SHA-256 desde el archivo temporal y consulta la denylist antes de `PutObject`.
- **SingleUploadService / PublicDropzoneUploadService**: calculan SHA-256 del temporal y consultan la denylist antes de escribir en S3.
- **FederationDrop**: después de la transferencia calcula SHA-256 desde S3; si está bloqueado elimina el objeto y no activa el Drop.

En rutas directas navegador -> S3, algunos bytes pueden existir temporalmente en S3 antes de que el servidor pueda verificar autoritativamente el archivo completo. La garantía es que una coincidencia bloqueada **no queda registrada, activa ni disponible**: se elimina antes de completar el flujo de ArcadeCloud.

Todos los archivos aceptados guardan `hash_sha256` en sus metadatos cuando esa ruta dispone del hash, para reutilizarlo en ArcadeLink y moderación.

## Seguridad y límites

- SHA-256 bloquea coincidencias byte por byte.
- Cambiar el nombre no evade el bloqueo.
- Modificar cualquier byte produce otro SHA-256.
- Huellas fuzzy/perceptuales pueden añadirse después para detección asistida, pero no deben producir borrado automático sin revisión debido al riesgo de falsos positivos.
- Un reporte por sí solo nunca declara que un archivo sea ilegal.
- La decisión es humana y queda auditada.
- El sistema no sustituye procedimientos de preservación de evidencia u órdenes legales cuando sean aplicables.

## Migración

El actualizador integrado ejecuta automáticamente `drive/bin/federation_catalog_migrate.php` durante la reconciliación posterior al fast-forward. La migración se ejecuta como el **usuario PHP-FPM real** y reconstruye su entorno desde el proceso `php-fpm-drive`, las directivas `env[...]` del pool que escucha en `127.0.0.1:9075` y finalmente `runtime-env.json` como override.

Antes de ejecutar PHP verifica `DB_HOST`, `DB_USER`, `DB_PASSWORD` y `DB_NAME`. El updater muestra únicamente de qué fuente obtuvo cada variable, nunca el valor ni la contraseña. Si una sigue ausente, la reconciliación se detiene con un diagnóstico explícito antes de entrar a `db.php`.

El migrador usa la sección FederationCloud del SQL canónico y crea las tablas de forma idempotente.

Para diagnóstico o una instalación administrada manualmente también puede ejecutarse:

```bash
cd /var/www/arcadecloud-drive
php drive/bin/federation_catalog_migrate.php
```

Después:

```bash
sudo systemctl restart php-fpm-drive
sudo systemctl restart nginx
php drive/bin/federation_sync.php
```

Para comprobar manualmente:

```text
Cliente:
https://TU-DOMINIO/federationcloud/report.php

Superusuario:
https://TU-DOMINIO/federationcloud/moderation.php
```


## Migración aislada de moderación

Las cuatro tablas de moderación tienen una ruta de migración propia e idempotente mediante `FederationModerationSchemaService`. Esto evita que un `ALTER` o una migración histórica de otra parte de FederationCloud impida crear:

- `FederationContentFingerprints`
- `FederationAbuseReports`
- `FederationModerationBlocks`
- `FederationModerationActions`

`Reportar abuso` comprueba estas cuatro tablas. Si falta alguna, intenta crear únicamente el esquema de moderación con la conexión MySQL web ya activa y continúa el mismo reporte si la reparación termina correctamente. Si el usuario MySQL no tiene permiso para crear la tabla, se devuelve el nombre de la tabla y el error de MariaDB sin exponer credenciales.

El updater también ejecuta esta migración aislada antes de intentar reconciliar el esquema FederationCloud completo, por lo que la moderación puede quedar operativa aunque una migración histórica no relacionada falle después.

## Reconciliación desde el actualizador web

En **Acerca de -> Actualizaciones**, la migración de tablas FederationCloud se ejecuta desde el runtime web autenticado usando la conexión MySQL ya abierta por ArcadeCloud. El reconciliador privilegiado detecta ese contexto y no exige reconstruir `DB_*` para lanzar el migrador CLI.

Después de actualizar código, al recargar y pulsar **Buscar actualizaciones**, el servicio verifica el esquema y crea de forma idempotente las tablas faltantes desde la sección canónica de `adbbmis1_Cloud.sql`. La misma clase de migración sigue disponible para CLI y timers cuando esos procesos sí disponen de su entorno DB.
