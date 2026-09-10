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

### Extensión posterior: actividad y costos

El 10 de septiembre de 2026 se añadió, como funcionalidad posterior al baseline y sin cambiar el significado del tag `v1.0-oop`, el módulo documentado en `drive/docs/ACTIVITY_COSTS.md`.

La extensión mantiene las reglas del baseline:

```text
activity_costs.php
  -> ActivityCostController
     -> ActivityCostService
        -> ActivityCostRepository / CostExplorerGateway
```

El módulo conserva MySQL como fuente de verdad, filtra toda actividad por `user_id_`, reutiliza la integración existente de Cost Explorer y distingue costo **ESTIMADO** de costo **REAL AWS**. El registro de telemetría es best effort y no introduce secretos ni keys S3 en su tabla de auditoría.

### Extensión posterior: FederationCloud y ArcadeLink

El 10 de septiembre de 2026 ArcadeCloud Drive añadió una segunda evolución importante: pasó de ser únicamente un Drive web sobre S3 a incorporar una **capa de cloud federado**.

Esta extensión tampoco modifica el significado histórico del tag `v1.0-oop`; utiliza precisamente ese baseline OOP como base estable.

FederationCloud incorpora:

- identidad Ed25519 independiente por nodo;
- `node_id` y `node_name` firmados;
- descubrimiento de nodos mediante seed;
- validación HTTPS y protección SSRF;
- solicitudes de proveedores;
- aprobación, rechazo y revocación sólo por `Users.system_role = 'superadmin'`;
- autorizaciones origen→proveedor firmadas;
- archivos `.arcadelink` portables;
- payload privado XChaCha20-Poly1305;
- resolución local y remota de recursos;
- creación de ArcadeLink directamente desde el botón **Compartir** del Drive;
- dropzone FederationCloud con validación automática y apertura del recurso cuando la política lo permite.

La arquitectura fue probada con dos instalaciones distintas: `drive.esforzados.com` como nodo origen y `fastdrive.esforzados.com` como nodo proveedor autorizado.

La federación no convierte a los nodos en una base de datos o bucket compartido. Cada instalación conserva su autoridad local sobre MySQL, S3 y usuarios; FederationCloud añade identidad, confianza y resolución entre nodos.

Todavía quedan fuera de esta etapa la selección automática de proveedor por recurso, replicación automática, buscador federado global, mirror lookup por SHA-256 y P2P.

El estado actual se documenta en:

- `drive/docs/FEDERATED_CLOUD_STATUS.md`;
- `drive/docs/FEDERATIONCLOUD.md`;
- `drive/docs/FEDERATION_PROVIDER_APPROVALS.md`;
- `drive/docs/FEDERATION_NODE_RECOVERY.md`.
