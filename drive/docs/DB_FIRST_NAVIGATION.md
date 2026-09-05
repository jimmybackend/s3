# Navegación DB-first

La navegación autenticada del Drive usa MySQL como fuente de verdad. S3 conserva los objetos físicos, pero no se recorre para construir carpetas, listados o búsquedas normales.

## Carpetas

```text
listar_carpetas.php
  -> FolderQueryController
     -> FolderQueryService
        -> FolderRepository
        -> UserStoragePath
```

Reglas:

- sesión autenticada;
- `user_id_` real de sesión;
- `S3Folders.Found=1`;
- prefijos limitados a `Data/`, `Data2/`, `DataN/` según usuario;
- sin `ListObjects` de S3;
- respuesta JSON `ok` + `carpetas`.

`s3.php` utiliza el catálogo MySQL para construir navegación y destinos de movimiento.

## Ruta actual

```text
actualizar_ruta.php
  -> NavigationController
     -> UserStoragePath
     -> SessionManager
```

El controlador normaliza la ruta contra la raíz del usuario y guarda `ruta_actual` mediante `SessionManager`.

Consumidores:

- `js/carpetas.js`
- `js/obtenerFiltros.js`

## Búsqueda global

```text
buscar_archivo.php
  -> FileSearchController
     -> FileSearchService
        -> FileS3
```

La búsqueda:

- usa POST;
- requiere sesión;
- limita por `user_id_` y `Found=1`;
- soporta comodines `*` y `?`;
- limita el resultado a 200 filas;
- nunca lista S3;
- devuelve `estado`, `termino`, `total` y `resultados`.

## Uso de almacenamiento

```text
storage_usage.php
  -> StorageUsageController
     -> StorageUsageService
        -> FileS3
        -> SessionManager
```

El total se calcula con `SUM(FileS3.Tamano)` limitado por `user_id_` y `Found=1`. El cache de cinco minutos se administra mediante `SessionManager`. `?refresh=1` fuerza el recálculo.

## Validación

Los workflows asociados comprueban sintaxis PHP, frontera OOP, referencias activas y `git diff --check`.
