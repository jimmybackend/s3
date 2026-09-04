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
