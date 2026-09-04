# Arquitectura OOP de ArcadeCloud Drive

## Estructura

```text
raiz_proyecto/
├── vendor/
├── Config-s3.php
├── db.php
└── drive/
    ├── app_bootstrap.php
    ├── s3.php
    ├── S3Manager.php
    ├── src/
    │   ├── Core/
    │   ├── Application/
    │   ├── Security/
    │   └── Http/
    └── upload/
```

`drive/` es el DocumentRoot de la aplicación. `vendor/`, `Config-s3.php` y `db.php`
están exactamente una carpeta arriba y no deben exponerse por HTTP.

## Regla de diseño

El desarrollo nuevo y las refactorizaciones del Drive se implementan orientados a objetos:

- Los archivos PHP públicos son controladores o entrypoints delgados.
- La lógica de negocio vive en clases bajo `src/` o en módulos OOP existentes.
- `DriveApplication` actúa como composition root y centraliza dependencias compartidas.
- `SessionManager` encapsula sesión y autenticación.
- `DrivePageService` y `DrivePageViewModel` contienen la lógica de la página principal.
- `S3Manager` es un servicio de infraestructura y acepta inyección de S3, mysqli y bucket.
- `upload/` conserva Factory, drivers, repositories y storage orientados a objetos.
- Los endpoints JSON nuevos deben reutilizar `Http\JsonResponse`.
- Los endpoints heredados se migran al patrón Controller -> Service -> Repository/Infrastructure.
- No debe agregarse nueva lógica de negocio procedural a los entrypoints.

## Compatibilidad

`s3.php` conserva temporalmente variables para alimentar el HTML heredado, pero filtros,
paginación, sesión y construcción de estado ya se obtienen mediante objetos. Esto permite
migrar los endpoints restantes por módulos sin romper de golpe la aplicación en producción.


## Provisionamiento multiusuario

La raíz física/lógica del Drive se deriva exclusivamente del `Users.id` autenticado:

```text
user_id = 1  -> Data/
user_id = 2  -> Data2/
user_id = 3  -> Data3/
user_id = N  -> DataN/
```

`UserStoragePath` es la única clase autorizada para calcular y normalizar esas raíces.
`UserStorageProvisioner` se ejecuta al entrar a `s3.php` y es idempotente:

1. consulta `S3Folders` por `user_id_ + Prefix`;
2. si la raíz ya está registrada, no consulta ni escribe S3;
3. si es el primer acceso, crea el objeto vacío `DataN/` en S3;
4. registra la raíz en `S3Folders` con `Found=1`;
5. la sesión se normaliza de nuevo contra la raíz del usuario antes de construir la página.

Esto permite que un usuario creado por cualquier sistema de registro quede provisionado en su
primer acceso al Drive, siempre usando el ID real asignado por MySQL. La separación lógica en
BD sigue siendo obligatoria mediante `user_id_` y ningún endpoint debe aceptar una raíz de otro usuario.

## Nombres visibles vs. nombres físicos en S3

El nombre que ve el usuario es un dato lógico de MySQL. Renombrar no renombra objetos en S3.

- Archivo nuevo: `f_<32hex>-<nombre-de-creacion.ext>`.
- Carpeta nueva: `d_<32hex>-<nombre-de-creacion>/`.
- Raíz: `Data/` para `user_id=1`; `DataN/` para los demás usuarios. La raíz no se puede renombrar, mover ni eliminar.
- `FileS3.Nombre` y `S3Folders.Nombre` son los nombres visibles y pueden cambiar sin modificar la key/prefix físico.
- Mover sí cambia la ubicación física, pero conserva el basename físico y el nombre visible.
- La sincronización nunca sobrescribe un `Nombre` visible existente. Si reconstruye una fila ausente, `StorageObjectNameCodec` recupera el nombre de creación desde el sufijo de la key.
- Para archivos históricos `f_*` que no incorporaban nombre, la sincronización manual intenta `headObject` y metadata `original-name`; si tampoco existe, solo puede recuperar el basename físico.

Esta separación evita colisiones de nombres y permite reconstruir el catálogo desde S3. Como consecuencia deliberada, un nombre cambiado únicamente en MySQL después de la creación no puede recuperarse desde S3 si se pierde completamente la base de datos; se recuperará el nombre de creación. Para preservar también los renombrados posteriores hace falta respaldar MySQL o un manifiesto independiente.

