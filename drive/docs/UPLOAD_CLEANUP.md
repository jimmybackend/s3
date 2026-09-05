# Limpieza manual de subidas abandonadas

`up-clean.php` ya no realiza borrados directamente. La limpieza se separa en:

```text
up-clean.php
    -> UploadCleanupController
        -> UploadCleanupService
            -> MySQL (Users / FileS3)
            -> S3
            -> estado local de PublicMultipartUploadService

drive/bin/upload_cleanup.php
    -> UploadCleanupCommand
        -> UploadCleanupService
```

## Regla principal

La limpieza sólo trabaja dentro de las rutas reservadas por `up.php`:

```text
user 1 -> Data/uploads/
user 2 -> Data2/uploads/
user N -> DataN/uploads/
```

La edad predeterminada es **30 días**.

## Qué puede limpiar

1. Multipart uploads de S3 que nunca fueron completados y tienen más de 30 días.
2. Objetos completados dentro de `DataN/uploads/` con más de 30 días que **no tienen ningún registro correspondiente en `FileS3`** para ese usuario.
3. Archivos locales `.json` de estado de `PublicMultipartUploadService` con más de 30 días.

## Qué nunca debe borrar

- Un objeto que tenga registro en `FileS3`, aunque sea antiguo.
- Archivos fuera de `DataN/uploads/`.
- Archivos normales de las carpetas del Drive.
- Objetos recientes.
- Raíces `Data/`, `Data2/`, `DataN/`.

El control contra `FileS3` se realiza antes de considerar un objeto terminado como huérfano. Esta protección evita confundir un archivo válido de usuario con un temporal abandonado.

## Modo simulación

La simulación no modifica S3 ni elimina archivos locales.

Desde EC2:

```bash
cd /var/www/arcadecloud-drive
php drive/bin/upload_cleanup.php
```

Para probar otra edad sin borrar:

```bash
php drive/bin/upload_cleanup.php --days=30
```

`up-clean.php` por HTTP también es únicamente una simulación y requiere una sesión con rol `Administración` o `Soporte`.

## Ejecución real

La eliminación real se permite sólo desde CLI y requiere `--execute`:

```bash
cd /var/www/arcadecloud-drive
php drive/bin/upload_cleanup.php --days=30 --execute
```

Antes de usar `--execute`, se debe revisar primero la salida del mismo comando sin `--execute`.

## Resultado

El reporte indica, entre otros:

- `multipart_seen`
- `multipart_stale`
- `multipart_aborted`
- `objects_seen`
- `objects_old`
- `objects_registered`
- `objects_orphan`
- `objects_deleted`
- `states_seen`
- `states_stale`
- `states_deleted`

En modo simulación, cada candidato aparece como `would_abort`, `would_delete` o `would_delete_state`.
