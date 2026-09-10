# Actividad y costos de ArcadeCloud Drive

Fecha de incorporación: **10 de septiembre de 2026**.

## Objetivo

`activity_costs.php` agrega una vista sencilla para responder qué operaciones realiza cada usuario, cuándo ocurrieron, qué servicios utilizaron y qué costo puede atribuirse razonablemente a esas operaciones.

La función separa dos conceptos que no deben confundirse:

- **ESTIMADO / atribuido**: suma unidades observables en el código del Drive y las multiplica por referencias de precio versionadas.
- **REAL AWS**: `UnblendedCost` obtenido mediante la integración existente con AWS Cost Explorer. Es un costo de la cuenta AWS para el período, no un costo exacto por archivo ni por clic.

La diferencia entre ambos importes sirve para reconciliar conceptos que no pueden atribuirse de forma exacta, por ejemplo almacenamiento por tiempo, transferencia según destino, descuentos, free tier, capas de volumen, impuestos u otros servicios de la cuenta.

## Arquitectura

```text
activity_costs.php
  -> ActivityCostController
     -> ActivityCostService
        -> ActivityCostRepository -> MySQL
        -> CostExplorerGateway    -> AWS Cost Explorer
     -> ActivityCostPageRenderer

operación existente
  -> Controller / Service existente
     -> operación real
     -> ActivityCostRecorder (best effort)
        -> AwsUnitPriceCatalog
        -> ActivityCostRepository
```

Los entrypoints permanecen delgados. No se agregó un nuevo objeto monolítico y la navegación del Drive continúa siendo DB-first.

## Base de datos

La migración incremental es:

```text
drive/database/migrations/20260910_activity_costs.sql
```

Crea `DriveActivityEvents`. Los campos almacenan identidad de usuario/actor, operación, servicio, referencia opcional a `FileS3.id_`, unidades de consumo, costo estimado, moneda, origen de precio, estado de tasación, estado de la operación, duración opcional, correlación hash y metadatos mínimos.

Índices principales:

- `user_id_ + CreatedAt`;
- `user_id_ + Service + CreatedAt`;
- `user_id_ + Action + CreatedAt`;
- `actor_user_id_ + CreatedAt`.

La tabla no contiene la key física S3, presigned URLs, tokens, credenciales, contraseñas, TOTP ni IDs de sesión.

El repositorio histórico no tenía un runner incremental de migraciones. Para este cambio se incorpora `drive/bin/db_migrate.php`, que sólo lee SQL versionado desde `drive/database/migrations/`. En producción debe invocarse con el nombre exacto de esta migración. La migración es idempotente mediante `CREATE TABLE IF NOT EXISTS`.

## Multiusuario y autorización

Toda consulta de actividad usa el `user_id_` autenticado obtenido desde `SessionManager`; no acepta un `user_id` enviado por el navegador. `ActivityCostRepository` vuelve a filtrar por `user_id_` en totales, desgloses, filtros y actividad reciente.

No se creó un rol administrador nuevo. La cifra de **REAL AWS**, que representa toda la cuenta, sólo se muestra cuando el mecanismo existente `PersonalToolAccessService` identifica al usuario autenticado como `owner` (actualmente la herramienta privada ya reserva ese estado para `user_id = 1`). Incluso para el owner, las filas de actividad continúan siendo sólo las del usuario autenticado: no se inventó una vista global de actividad.

## Operaciones instrumentadas

La primera versión registra las rutas runtime que ya existen:

- subida principal (`local_put`, `remote_url`, `dropbox`, `chunked`), respetando la fase real en que cada driver completa el trabajo;
- inicio/finalización y reanudación de multipart cuando el flujo existente llama a S3;
- subida simple/heredada y subida múltiple heredada;
- descarga directa, PDF/vista previa y descarga ZIP;
- renombrar, mover y eliminar archivos;
- crear, mover y eliminar carpetas cuando el flujo real toca S3; renombrar carpeta se registra como operación lógica MySQL sin cargo AWS directo;
- creación de enlace compartido;
- miniaturas únicamente cuando realmente llegan a S3 o se generan; los hits de caché local no generan costo AWS;
- Textract, Translate, Rekognition, Polly y Comprehend, separando componentes S3/Textract cuando una misma acción utiliza más de un servicio;
- consultas reales a Cost Explorer, únicamente cuando no provienen de caché;
- inicio/finalización de Transcribe sin inventar duración ni precio.

Los movimientos de carpetas registran únicamente los `LIST`, `COPY` y lotes `DELETE` que el servicio existente ejecuta de verdad; esos contadores salen del propio `FolderMutationService`, no de una estimación de filas MySQL.

Los componentes de una misma acción comparten una correlación hash. Así el total de operaciones no se multiplica porque Translate, por ejemplo, haya utilizado Translate + Textract o Translate + S3, mientras el desglose por servicio conserva el costo de cada componente.

La búsqueda normal no se registra como costo AWS porque es DB-first y no lista S3. No se agregó ningún `LIST` de S3 sólo para medir costos. Cuando un flujo existente ya usa `ListParts` para reanudar multipart, esa solicitud sí puede atribuirse.

## Unidades y precios

El catálogo desacoplado vive en:

```text
drive/config/activity-cost-pricing.json
```

`AwsUnitPriceCatalog` lo carga en runtime. Actualizar una referencia de precio no requiere modificar los controladores.

La versión inicial contiene referencias verificadas el 10-Sep-2026 para unidades que el Drive puede observar con suficiente confianza: solicitudes S3, Rekognition Group 2, Textract DetectDocumentText, caracteres Polly por motor, caracteres Translate, unidades NLP de Comprehend y solicitudes de la API de Cost Explorer. Las acciones puramente lógicas del Drive pueden declarar explícitamente que no tuvieron un cargo AWS directo medido; eso no pretende repartir costos fijos de infraestructura. Fuentes oficiales de referencia:

- https://aws.amazon.com/s3/pricing/
- https://aws.amazon.com/rekognition/pricing/
- https://aws.amazon.com/textract/pricing/
- https://aws.amazon.com/polly/pricing/
- https://aws.amazon.com/translate/pricing/
- https://aws.amazon.com/comprehend/pricing/
- https://aws.amazon.com/aws-cost-management/aws-cost-explorer/pricing/

Estas tasas son referencias de atribución de primer nivel, no una réplica del motor de facturación de AWS.

### Tasación parcial

Si una operación contiene unidades conocidas y otras cuya tarifa depende de contexto no disponible, `PricingState=partial`. Si no existe una unidad fiable para tasar, `PricingState=unpriced` y `EstimatedCost=NULL`.

Ejemplos deliberadamente no inventados:

- bytes transferidos: el precio depende de destino, región y reglas de transferencia;
- almacenamiento: depende de clase, tiempo, tamaño, mínimos y tier;
- Transcribe: el código actual no devuelve de forma fiable la duración facturable final y se mantiene no tasado por acción.

Comprehend sí conserva ahora las solicitudes que realmente terminaron correctamente y calcula sus unidades de 100 caracteres con el mínimo de tres unidades por solicitud; por ello esa parte puede atribuirse sin convertir intentos fallidos en consumo exitoso.

Esto permite mostrar la actividad sin presentar falsa precisión financiera.

## Cost Explorer y caché

Se reutiliza `CostExplorerGateway`; no existe una segunda integración AWS. `ActivityCostService` solicita `UnblendedCost` sólo para el período visible y guarda una caché privada temporal de hasta una hora en el directorio temporal del servidor. El resumen histórico `costos_aws.php` también reutiliza su servicio existente y ahora evita repetir sus consultas durante una hora.

Una llamada nueva a la API de Cost Explorer se registra como costo atribuido; un hit de caché no genera ese evento. El endpoint histórico de costo real queda restringido al `owner` existente porque expone el gasto de toda la cuenta AWS.

La caché contiene únicamente importe, moneda y fecha de consulta. No contiene credenciales ni URLs firmadas. Si Cost Explorer falla, la página continúa mostrando la actividad y los costos atribuidos.

Cuando se filtra la página por servicio u operación, la cifra real AWS se oculta porque compararla contra un subconjunto del Drive sería engañoso.

## Registro best effort

`ActivityCostRecorder` captura sus propios errores. Una falla de auditoría no convierte una descarga, subida o procesamiento AWS válido en una operación fallida para el usuario.

Las correlaciones que parten de identificadores técnicos se guardan como SHA-256 con prefijo de tipo. El valor original de upload ID, token o job identifier no se almacena en la tabla de actividad.

## Página

La interfaz muestra:

- costo atribuido **ESTIMADO**;
- costo **REAL AWS** cuando la autorización existente lo permite;
- diferencia de reconciliación;
- número de operaciones;
- costo por servicio;
- costo por operación;
- costo diario atribuido;
- hasta 100 eventos recientes;
- filtros Hoy / 7 días / Mes actual / Mes anterior, servicio y operación.

La vista reutiliza los CSS del Drive, `vision-accessibility.css` y la preferencia `ui-theme-state`; no agrega una librería de gráficos.

## Extensión futura

Nuevos workers no deben escribir SQL directamente. Deben expresar unidades mediante `ActivityCostRecorder`, por ejemplo cuando existan datos reales para:

- OfficeWorker EC2;
- StartInstances / StopInstances;
- procesamiento Office;
- MediaWorker.

Antes de agregar una tarifa debe definirse una unidad observable y una fuente de precio. Para costos que sólo pueden conocerse a nivel de cuenta o recurso agregado, se debe conservar la reconciliación con Cost Explorer en lugar de forzar una atribución por archivo.
