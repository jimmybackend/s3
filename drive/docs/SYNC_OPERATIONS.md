# Sincronización S3 -> MySQL

ArcadeCloud Drive mantiene MySQL como fuente de verdad para la navegación normal. La sincronización existe para reconciliar el catálogo con los objetos físicos que ya están en S3; no convierte a S3 en el navegador diario.

## Sincronización de un usuario

El botón **Sincronizar S3** inicia un proceso CLI desacoplado del request HTTP:

```text
usuario autenticado
  -> SyncController
  -> setsid + PHP CLI
  -> bin/sync_worker.php USER_ID JOB_ID [PREFIX]
  -> S3SyncService
  -> ListObjectsV2 Prefix=DataN/
  -> FileS3 / S3Folders sólo con user_id=N
```

El navegador sólo consulta el estado del job. No espera a que termine el recorrido S3 y cerrar la pestaña no detiene el worker.

La raíz depende del usuario:

```text
user 1 -> Data/
user 2 -> Data2/
user N -> DataN/
```

Una sincronización de usuario nunca debe listar la raíz completa del bucket ni modificar filas de otro `user_id_`.

## Sincronización de una carpeta concreta

Cada carpeta del árbol puede solicitar una reconciliación limitada a su propio prefijo. Por ejemplo:

```text
usuario 1
carpeta: Data/Docs/

ListObjectsV2 Prefix=Data/Docs/
```

El request sigue validando el usuario autenticado y `UserStoragePath` impide saltar a `Data2/`, `Data3/` u otra raíz. El worker continúa siendo asíncrono y usa el mismo lock por usuario, así que no puede correr simultáneamente una sincronización completa y otra de carpeta para el mismo usuario.

Cuando termina una sincronización de carpeta, las eliminaciones se reconcilian **sólo dentro de ese prefijo**. Las filas de otras carpetas y de otros usuarios permanecen intactas.

Esto permite recuperar rápidamente objetos que fueron añadidos directamente en S3 sin recorrer los miles de objetos de todo `DataN/`.

## Referencia física FileS3

La key S3 se guarda de forma normalizada:

```text
S3 key:      Data/carpeta/f_abc-documento.pdf
Ruta:        Data/carpeta/
Encriptado:  f_abc-documento.pdf
```

`Encriptado` es el nombre físico, no la key completa. Esto mantiene compatibilidad con `varchar(255)` y evita que una ruta profunda provoque `Data too long for column 'Encriptado'`.

La identidad física es:

```text
user_id_ + Ruta + Encriptado
```

por lo que el mismo nombre físico puede existir en dos carpetas diferentes del mismo usuario.

El reconciliador sigue reconociendo temporalmente registros históricos que hayan almacenado la key completa en `Encriptado` y los normaliza cuando vuelve a observar el objeto.

## Subidas grandes mediante up.php

`up.php` envía los bytes directamente del navegador a Amazon S3 mediante multipart y URLs prefirmadas. PHP autoriza, firma y completa la operación, pero no transporta el archivo grande.

Después de completar S3, `AdminMultipartUploadService` ejecuta `HeadObject` y registra inmediatamente:

- el archivo en `FileS3`;
- la ruta física `DataN/uploads/`;
- la carpeta `uploads` en `S3Folders` si todavía no estaba catalogada;
- tamaño real;
- fecha de subida basada en `LastModified` de S3, normalizada a UTC;
- metadata `source=up.php` y usuario que realizó la subida.

Por tanto, una subida completada correctamente no debe necesitar una sincronización completa para aparecer en MySQL. La sincronización de la carpeta `uploads` queda como mecanismo de reconciliación si existen objetos históricos o creados fuera de ArcadeCloud.

## Sincronización completa del nodo

Para mantenimiento administrativo existe:

```text
drive/bin/sync_node_worker.php
```

No ejecuta `ListObjectsV2` sobre todo el bucket. Primero obtiene los `Users.id` locales y procesa cada usuario por separado utilizando el mismo `S3SyncService` y el mismo lock por usuario.

Esto conserva el aislamiento multiusuario incluso durante una sincronización completa del nodo.

### Instalar el servicio systemd

Ejemplo de producción cuando PHP-FPM usa `nginx`:

```bash
cd /var/www/arcadecloud-drive
sudo bash drive/bin/install_node_sync_service.sh \
  --run-user=nginx \
  --app-root=/var/www/arcadecloud-drive
```

El instalador crea:

```text
arcadecloud-drive-sync-migrate.service
arcadecloud-drive-node-sync.service
```

La migración de sincronización se ejecuta antes del servicio de nodo para garantizar la identidad `user_id_ + Ruta + Encriptado`.

No se habilita un timer global automáticamente: una reconciliación completa puede ser costosa y debe ser una operación administrativa explícita.

### Ejecutar en segundo plano

```bash
sudo systemctl start --no-block arcadecloud-drive-node-sync.service
```

Consultar estado:

```bash
sudo systemctl status arcadecloud-drive-node-sync.service --no-pager -l
```

Consultar log sin dejar la terminal abierta:

```bash
sudo journalctl -u arcadecloud-drive-node-sync.service -n 100 --no-pager
```

El worker procesa usuarios secuencialmente. Si un usuario ya tiene una sincronización individual activa, ese usuario se omite como `busy` en vez de ejecutar dos reconciliaciones simultáneas sobre su catálogo.

## Requisitos operativos

La sincronización individual necesita:

- PHP CLI;
- `setsid`;
- `exec()` disponible para lanzar el worker desde PHP-FPM;
- credenciales DB/S3 accesibles al proceso CLI.

La sincronización de nodo mediante systemd necesita además que la unidad vea el mismo entorno privado que la aplicación. El instalador admite:

```text
--drive-env=/ruta/absoluta/drive.env
--federation-env=/ruta/absoluta/federation.env
```

`app_bootstrap.php` también carga el entorno administrado de ArcadeCloud. Nunca deben copiarse secretos al repositorio.
