# Navegación DB-first y consultas del Drive

Este documento registra los módulos de lectura y navegación extraídos de los entrypoints heredados.

## Principio

La navegación autenticada del Drive usa MySQL como fuente de verdad. S3 conserva los objetos físicos, pero no se recorre para construir la navegación normal.

## Carpetas

```text
listar_carpetas.php
    -> FolderQueryController
        -> FolderQueryService
            -> FolderRepository
                -> S3Folders
            -> UserStoragePath
```

Reglas:

- requiere sesión autenticada;
- usa el `user_id_` real de sesión;
- consulta únicamente `S3Folders` con `Found=1`;
- limita los prefijos a la raíz calculada por `UserStoragePath` (`Data/`, `Data2/`, `DataN/`);
- no consulta S3;
- conserva el contrato JSON `ok` + `carpetas` consumido por `js/carpetas.js`.

La vista grande `s3.php` todavía conserva una llamada legacy a `S3Manager::listarCarpetasDesdeDb()` para construir destinos de movimiento. La nueva `FolderQueryService` ya permite reemplazarla, pero ese cambio se mantiene como una modificación aislada futura de la vista para evitar reemplazar innecesariamente un archivo HTML/PHP grande durante esta fase.

## Ruta actual

```text
actualizar_ruta.php
    -> NavigationController
        -> UserStoragePath
        -> SessionManager
```

Consumidores confirmados:

- `js/carpetas.js`
- `js/obtenerFiltros.js`

El controlador acepta los nombres heredados `ruta` y `rutaNueva`, normaliza siempre contra la raíz del usuario y guarda `ruta_actual` mediante `SessionManager`.

## Búsqueda global

```text
buscar_archivo.php
    -> FileSearchController
        -> FileSearchService
            -> FileS3
```

La búsqueda:

- es POST;
- requiere sesión;
- está limitada por `user_id_` y `Found=1`;
- conserva comodines `*` y `?`;
- mantiene el máximo usado por el endpoint en 200 resultados;
- nunca lista S3;
- conserva `estado`, `termino`, `total` y `resultados` para `js/archivos.js`.

## Uso de almacenamiento

```text
storage_usage.php
    -> StorageUsageController
        -> StorageUsageService
            -> FileS3
            -> SessionManager (cache)
```

El total se obtiene de `SUM(FileS3.Tamano)` limitado por `user_id_` y `Found=1`. El cache de cinco minutos continúa viviendo en la sesión, pero `StorageUsageService` ya no usa `$_SESSION` directamente: la dependencia se inyecta mediante `SessionManager` desde `DriveApplication`.

El parámetro `?refresh=1` conserva el comportamiento de forzar recálculo utilizado por `js/storage-usage.js`.

## Validación automática

Los módulos se cubren con:

- `.github/workflows/oop-folder-query.yml`
- `.github/workflows/oop-navigation.yml`
- `.github/workflows/oop-file-search.yml`
- `.github/workflows/oop-storage-usage.yml`

Cada workflow comprueba, según corresponda:

- `PHP_OK`
- `OOP_OK`
- `REFERENCIAS_OK`
- `DIFF_OK`

Los entrypoints no contienen SQL, acceso S3 ni manipulación directa de superglobals de sesión.
