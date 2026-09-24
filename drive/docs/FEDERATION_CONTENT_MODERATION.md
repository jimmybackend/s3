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

El reporte no borra automáticamente contenido. Se registra como `pending` y requiere decisión humana.

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

### Rechazar

`Rechazar` marca el reporte como `rejected`. No modifica el archivo ni la lista de bloqueo.

### Confirmar y bloquear

`Confirmar y bloquear`:

1. exige una huella SHA-256 verificable;
2. marca el reporte como confirmado;
3. emite un evento Ed25519 `moderation.block`;
4. materializa la huella en `FederationModerationBlocks`;
5. ejecuta limpieza local;
6. los demás nodos reciben el evento por el gossip normal y ejecutan su limpieza en el siguiente ciclo de sincronización;
7. futuras operaciones FederationDrop que produzcan la misma huella son rechazadas.

La limpieza local busca:

- FederationDrops registrados con esa huella;
- objetos `FederationReplicaObjects`;
- archivos `FileS3` cuyo metadata contenga `hash_sha256`, `sha256` o `checksum_sha256`.

La evidencia de moderación conserva el hash, reporte, nodo, decisión y auditoría. No es necesario conservar los bytes del contenido retirado.

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

## Seguridad y límites

- SHA-256 bloquea coincidencias byte por byte.
- Cambiar el nombre no evade el bloqueo.
- Modificar cualquier byte produce otro SHA-256.
- Huellas fuzzy/perceptuales pueden añadirse después para detección asistida, pero no deben producir borrado automático sin revisión debido al riesgo de falsos positivos.
- Un reporte por sí solo nunca declara que un archivo sea ilegal.
- La decisión es humana y queda auditada.
- El sistema no sustituye procedimientos de preservación de evidencia u órdenes legales cuando sean aplicables.

## Migración

Después de actualizar el repositorio:

```bash
cd /var/www/arcadecloud-drive
php drive/bin/federation_catalog_migrate.php
```

El migrador usa la sección FederationCloud del SQL canónico y las nuevas tablas usan `CREATE TABLE IF NOT EXISTS`.

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
