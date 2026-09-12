# Sincronización S3 -> MySQL

ArcadeCloud Drive mantiene MySQL como fuente de verdad para la navegación normal. La sincronización existe para reconciliar el catálogo con los objetos físicos que ya están en S3; no convierte a S3 en el navegador diario.

## Sincronización de un usuario

El botón **Sincronizar S3** inicia un proceso CLI desacoplado del request HTTP:

```text
usuario autenticado
  -> SyncController
  -> setsid + PHP CLI
  -> bin/sync_worker.php USER_ID JOB_ID
  -> S3SyncService
  -> ListObjectsV2 Prefix=DataN/
  -> FileS3 / S3Folders sólo con user_id=N
```

El navegador sólo consulta el estado del job. No espera a que termine el recorrido S3.

La raíz depende del usuario:

```text
user 1 -> Data/
user 2 -> Data2/
user N -> DataN/
```

Una sincronización de usuario nunca debe listar la raíz completa del bucket ni modificar filas de otro `user_id_`.

## Referencia física FileS3

La key S3 se guarda de forma normalizada:

```text
S3 key:      Data/carpeta/f_abc-documento.pdf
Ruta:        Data/carpeta/
Encriptado:  f_abc-documento.pdf
```

`Encriptado` es el nombre físico, no la key completa. Esto mantiene compatibilidad con `varchar(255)` y evita que una ruta profunda provoque `Data too long for column 'Encriptado'`.

El reconciliador sigue reconociendo temporalmente registros históricos que hayan almacenado la key completa en `Encriptado` y los normaliza cuando vuelve a observar el objeto.

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
arcadecloud-drive-node-sync.service
```

No se habilita un timer global automáticamente: una reconciliación completa puede ser costosa y debe ser una operación administrativa explícita.

### Ejecutar en segundo plano

```bash
sudo systemctl start --no-block arcadecloud-drive-node-sync.service
```

Consultar estado:

```bash
sudo systemctl status arcadecloud-drive-node-sync.service --no-pager -l
```

Consultar log:

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
