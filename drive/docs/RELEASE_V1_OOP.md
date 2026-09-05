# ArcadeCloud Drive v1.0-oop

Fecha de cierre: **5 de septiembre de 2026**.

## Propósito

`v1.0-oop` marca el cierre de la migración incremental del backend heredado de ArcadeCloud Drive hacia una arquitectura orientada a objetos mantenible sin reescribir la aplicación completa ni romper sus contratos existentes.

## Arquitectura estable

```text
PHP entrypoint
  -> Controller
     -> Service
        -> Repository / Infrastructure
```

La lógica de negocio vive principalmente bajo `drive/src/`. Los entrypoints públicos conservan compatibilidad y delegan en los módulos de aplicación.

## Decisiones que definen la versión

- MySQL es la fuente de verdad para la navegación normal.
- Amazon S3 es el almacenamiento físico.
- No se lista S3 durante la navegación diaria.
- Cada usuario opera dentro de su raíz `DataN/` derivada del ID autenticado.
- La raíz del usuario no puede renombrarse, moverse ni eliminarse.
- `FileS3.Nombre` representa el nombre visible.
- `FileS3.Encriptado` representa el nombre o key física.
- Las mutaciones y consultas se limitan por `user_id_` y, cuando corresponde, `Found=1`.
- Las credenciales, hashes y semillas TOTP permanecen fuera del repositorio.

## Módulos consolidados

La versión estable incluye separación OOP para:

- autenticación y sesión;
- navegación, búsqueda y uso de almacenamiento;
- archivos y carpetas;
- descargas y ZIP;
- editor de texto;
- seguridad y rotación de key;
- sharing y acceso público por token;
- miniaturas y multimedia;
- subidas simples, multipart y públicas;
- limpieza de subidas abandonadas;
- sincronización S3 -> MySQL;
- Amazon Rekognition, Textract, Polly, Translate, Comprehend y Cost Explorer;
- Amazon Transcribe con trabajo asíncrono y seguimiento de estado;
- panel EC2 y protección horaria de costos;
- herramienta TOTP personal con configuración privada del servidor.

## Cierre técnico

Al cerrar esta versión:

- producción ejecuta `main`;
- la rama `refactor/oop-drive-migration` fue fusionada y eliminada;
- no quedan consumidores runtime del antiguo monolito S3;
- las acciones AWS fueron verificadas también desde móvil;
- los scripts JavaScript críticos usan versionado derivado del archivo para evitar cache obsoleta;
- el flujo de Transcribe informa inicio en segundo plano y notifica al completar;
- la documentación de arquitectura y runtime corresponde al sistema vigente.

## Configuración privada

Los secretos no forman parte de esta versión Git. La herramienta AWS personal lee su configuración desde:

```text
/etc/arcadecloud-drive/personal-aws.json
```

El archivo debe permanecer fuera del DocumentRoot y fuera de Git.

## Validación mínima para cambios posteriores

```text
php -l
node --check   (cuando aplique)
git diff --check
```

Además deben preservarse las fronteras multiusuario, DB-first y los contratos HTTP existentes.

## Desarrollo posterior

`v1.0-oop` es un punto de referencia estable, no una rama de desarrollo. Las nuevas funcionalidades deben partir de `main` en ramas independientes y fusionarse después de validación.