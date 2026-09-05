# Limpieza manual de subidas abandonadas

La limpieza de subidas está separada en dos superficies:

```text
up-clean.php
  -> UploadCleanupController
     -> UploadCleanupService
        -> MySQL
        -> S3
        -> estado local

bin/upload_cleanup.php
  -> UploadCleanupCommand
     -> UploadCleanupService
```

## Alcance

La limpieza sólo trabaja dentro de:

```text
user 1 -> Data/uploads/
user 2 -> Data2/uploads/
user N -> DataN/uploads/
```

La edad predeterminada es **30 días**.

## Candidatos

1. Multipart uploads de S3 incompletos con más de 30 días.
2. Objetos dentro de `DataN/uploads/` con más de 30 días que no tienen registro correspondiente en `FileS3` para ese usuario.
3. Archivos locales `.json` de estado con más de 30 días.

## Protecciones

Nunca se elimina:

- un objeto registrado en `FileS3`;
- un objeto fuera de `DataN/uploads/`;
- una raíz de usuario;
- un objeto reciente;
- un archivo normal de otra carpeta del Drive.

## Simulación

El modo predeterminado no modifica S3 ni elimina archivos locales.

```bash
cd /var/www/arcadecloud-drive
php drive/bin/upload_cleanup.php --days=30
```

`up-clean.php` por HTTP también es sólo simulación y requiere una sesión con rol `Administración` o `Soporte`.

## Ejecución

La eliminación real sólo está disponible por CLI y requiere `--execute`:

```bash
cd /var/www/arcadecloud-drive
php drive/bin/upload_cleanup.php --days=30 --execute
```

La salida de simulación debe revisarse antes de ejecutar borrados.

## Reporte

El resultado incluye:

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

En simulación los candidatos aparecen como `would_abort`, `would_delete` o `would_delete_state`.
