# Identidad FileS3 durante sincronización

`FileS3` representa la referencia física mediante dos columnas:

```text
Ruta + Encriptado
```

Ejemplo:

```text
Ruta       = Data/proyecto-a/
Encriptado = manifest.json
```

Por tanto, dos archivos con el mismo nombre físico son válidos si están en carpetas distintas del mismo usuario:

```text
Data/proyecto-a/manifest.json
Data/proyecto-b/manifest.json
```

La restricción histórica `UNIQUE(user_id_, Encriptado)` impedía este caso y producía errores como:

```text
Duplicate entry '1-manifest.json' for key 'FileS3.uq_files3_user_key'
```

La migración `drive/bin/sync_schema_migrate.php` cambia la identidad a:

```text
UNIQUE(user_id_, Ruta, Encriptado)
```

El migrador es idempotente y se detiene antes de alterar índices si ya existen duplicados exactos de `user_id_ + Ruta + Encriptado`.

`install_node_sync_service.sh` debe ejecutar esta migración con el mismo usuario y EnvironmentFile del worker antes de instalar/usar la sincronización completa del nodo.
