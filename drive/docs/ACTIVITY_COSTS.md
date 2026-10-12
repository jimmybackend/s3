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

`DriveActivityEvents` forma parte del esquema maestro:

```text
adbbmis1_Cloud.sql
```

Una instalación nueva de ArcadeCloud Drive crea esta tabla al cargar el SQL general, junto con el resto del esquema. No existe un paso de migración incremental separado para Actividad y costos.

Los campos almacenan identidad de usuario/actor, operación, servicio, referencia opcional a `FileS3.id_`, unidades de consumo, costo estimado, moneda, origen de precio, estado de tasación, estado de la operación, duración opcional, correlación hash y metadatos mínimos.

Índices principales:

- `user_id_ + CreatedAt`;
- `user_id_ + Service + CreatedAt`;
- `user_id_ + Action + CreatedAt`;
- `actor_user_id_ + CreatedAt`.

La tabla no contiene la key física S3, presigned URLs, tokens, credenciales, contraseñas, TOTP ni IDs de sesión.

Para una instalación limpia se importa `adbbmis1_Cloud.sql` una sola vez. Las instalaciones existentes que ya tengan `DriveActivityEvents` no necesitan volver a crearla.

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

## Relación con el centro Tareas

Desde el centro unificado **Tareas**, Polly y Transcribe usan `DriveActivityEvents` como estado persistente y fuente de telemetría.

La acción **Eliminar de Tareas** no ejecuta `DELETE` sobre `DriveActivityEvents`. Sólo marca la tarea como oculta para el centro mediante metadata del job. Por tanto:

- desaparece la tarjeta operativa;
- permanece el historial de ejecución;
- permanecen `EstimatedCost`, unidades, correlación y metadata financiera;
- `activity_costs.php` continúa pudiendo incluir esos eventos en sus consultas normales.

Esto fue validado como comportamiento esperado en producción el **16-Sep-2026**.

Para Sync y Move, que usan archivos de estado propios, **Eliminar de Tareas** elimina únicamente ese pequeño registro de control; tampoco borra archivos reales del usuario.

### Polly asíncrono

Las tareas asíncronas Polly usan una correlación `polly:*` estable durante inicio y finalización. Cuando el reconciliador completa el trabajo puede añadir el componente S3 atribuible con la misma correlación, de modo que el centro **Tareas** puede mostrar un costo agregado sin inventar una segunda operación independiente.

La corrección de resolución del archivo origen (`FileS3.Ruta + FileS3.Encriptado`) no modifica ni migra los registros financieros existentes; únicamente permite que el job se finalice correctamente contra su `FileId + user_id_` persistente.

## Página

La interfaz muestra:

- costo atribuido **ESTIMADO**;
- costo **REAL AWS** cuando la autorización existente lo permite;
- diferencia de reconciliación;
- número de operaciones;
- costo por servicio;
- costo por operación;
- desglose por servicio y desglose por operación en paneles independientes, apilados verticalmente para evitar solapamientos con el layout flex global del Drive;
- actividad reciente inmediatamente debajo de esos dos paneles;
- paginación server-side de actividad reciente, 25 eventos por página, conservando período/servicio/operación;
- costo diario atribuido;
- filtros Hoy / 7 días / Mes actual / Mes anterior, servicio y operación.

La vista reutiliza los CSS del Drive, `vision-accessibility.css` y la preferencia `ui-theme-state`; no agrega una librería de gráficos.

## Desglose por usuario para el propietario (octubre de 2026)

La pantalla actual de `activity_costs.php` agrega, exclusivamente para el propietario
autorizado por `PersonalToolAccessService`, dos tablas de lectura de
`DriveActivityEvents`: total atribuido por `user_id_` y desglose por
`user_id_` + servicio AWS. Los filtros existentes de período, servicio y
operación se aplican también al desglose administrativo.

La autorización no depende de un ID de usuario enviado por el navegador; el
backend añade `owner_breakdown` solamente cuando la sesión tiene el estado
`owner`. Los demás usuarios siguen viendo exclusivamente sus propios datos.
No se crean cargos, no se modifica la facturación ni se asumen costos exactos
por usuario. Los eventos sin tasa y parciales permanecen identificables.
Los recuentos de operaciones son distintos por correlación dentro de cada
usuario; los costos sí suman componentes de servicio.

El desglose muestra sólo usuarios que tienen eventos en el período. No asigna
automáticamente costos de EC2, almacenamiento por tiempo, transferencias ni
otros rubros compartidos de AWS. El dato REAL de Cost Explorer conserva su
alcance de toda la cuenta.

## Contabilidad AWS por usuario: directo, compartido y diferencia (octubre 2026)

`activity_costs.php` incluye ahora **Contabilidad AWS por usuario**, visible sólo
para la cuenta propietaria ya autorizada. Se conservan los informes anteriores.

El informe presenta por separado:

1. **Directo estimado**: suma de `EstimatedCost` de todos los usuarios
   presentes en `DriveActivityEvents` dentro del período, no sólo del usuario
   actualmente autenticado. Conserva la distinción parcial/no tasado.
2. **Compartido estimado**: tamaño actual por usuario registrado en `FileS3`
   con `Found=1`, siguiendo la misma condición que
   `StorageUsageService`, y un presupuesto mensual EC2 explícito dividido
   **en partes iguales** entre usuarios con eventos o archivos registrados.
   Incluye usuarios sin eventos pero con archivos.
3. **Diferencia**: `UnblendedCost` real de toda la cuenta AWS menos todo el
   costo estimado asignado (directo + compartidos disponibles), **con signo**.
   Puede ser negativa y no implica un error de AWS ni una deuda.

### Tarifas compartidas configurables

Definir mediante el entorno seguro que ya utiliza la aplicación (por ejemplo
`/etc/arcadecloud-drive/drive.env` y el entorno efectivo de PHP-FPM):

```ini
ARCADECLOUD_AWS_S3_USD_GB_MONTH=VALOR_ELEGIDO_POR_EL_PROPIETARIO
ARCADECLOUD_AWS_EC2_USD_MONTH=CUOTA_MENSUAL_DE_LA_INFRAESTRUCTURA_COMPARTIDA
```

Estas cadenas son **marcadores explicativos, no valores válidos**: sustituir
por números decimales en USD y comprobar que PHP-FPM vea las variables.
No publicar secretos. La cifra EC2 debe ser una **política** aprobada por el
propietario, no confundirse con la factura real de AWS. Ambas variables son
opcionales: si faltan, la interfaz muestra *Pendiente de configurar* y deja
el subtotal calculable como **parcial**, sin convertir desconocido en cero.

La tarifa S3 es USD por GiB-mes (1 GiB = 1,073,741,824 bytes) del catálogo
actual, para fines de aproximación; no representa todas las clases, mínimos,
versionado u objetos huérfanos en S3. La cuota EC2 es USD/mes para un
**presupuesto conjunto de infraestructura**; no calcula horas efectivamente
encendidas ni asigna una instancia separada a cada usuario. Ambas se prorratean
por días naturales del período; el día actual se cuenta completo.

Para mes anterior, **no** se atribuye un costo S3 retroactivo utilizando
el tamaño actual: se deja pendiente por falta de instantáneas históricas.
La asignación EC2 histórica también es una aproximación sujeta a usuarios
observables actualmente y en los eventos del período.

No se efectúan llamadas extra a S3, ninguna escritura SQL, ningún cambio a
precios actuales del catálogo y ningún cargo real al usuario. Para obtener
facturación verdaderamente precisa por cliente harían falta mediciones
temporales de almacenamiento, política de reparto aprobada, consumo de
instancias por horas y reconciliación específica de rubros facturados.
Los filtros por servicio/acción ocultan esta conciliación integral.

## Perfil familiar y preparación empresarial (octubre 2026)

El uso actual de ArcadeCloud es **familiar**, con factura de AWS cubierta
por el propietario. Los desgloses son exclusivamente informativos: no crean
deudas, cuotas obligatorias, facturas, pagos ni márgenes de ganancia.
El propietario puede acordar aportaciones voluntarias, sin exceder el gasto
real compartido del período, y sin convertir las estimaciones en cobros.

`AccountingProfile` aplica por defecto el perfil `family`. La configuración
opcional `ARCADECLOUD_ACCOUNTING_PROFILE=business_preview` permite que el
propietario vea un **catálogo de preparación**, sin habilitar facturación ni
dar acceso a terceros. Entradas previstas:

- Digitalización y cotejo documental por página.
- Transcripción de audiencias y videos por minuto de audio/video.
- Revisión humana de transcripciones por minuto revisado.
- Organización e índice de expedientes por expediente.

El catálogo **no** cotiza, procesa trabajos nuevos, crea clientes ni altera
permisos. Una futura operación profesional necesita gestión de clientes,
autorizaciones, confidencialidad de expedientes y datos personales,
consentimiento para tratamiento de audios/video, tarifas aprobadas,
revisión humana, cotizaciones y comprobantes fiscales cuando proceda.

La configuración sólo se consulta del entorno efectivo del proceso PHP.
No hay interruptores públicos ni parámetros de URL para cambiar el perfil,
y nunca se realizan cobros automáticamente.

## Precisión de microcostos (octubre 2026)

En el catálogo de precios hay operaciones S3 con cargos de referencia
de fracciones minúsculas de USD (por ejemplo, GET: 0.0000004 USD).
La interfaz muestra ahora **hasta 16 decimales significativos**, sin
recortar automáticamente a 8 para cifras menores de un centavo.
Los importes exactamente cero siguen mostrándose como $0.00; los
no tasados siguen siendo **sin precio**, no costo cero.

El esquema maestro usa `DriveActivityEvents.EstimatedCost DECIMAL(28,16)`
para instalaciones nuevas. Los servidores con la columna anterior
`DECIMAL(20,10)` **requieren una migración independiente**; editar
el esquema maestro NO modifica por sí solo la tabla instalada.

Ruta: `drive/migrations/20261012_drive_activity_microcost_precision.sql`.

**Procedimiento de producción controlado:**

1. Respaldar previamente la base de datos del Drive y confirmar el respaldo.
2. Comprobar la definición efectiva con
   `SHOW COLUMNS FROM DriveActivityEvents LIKE 'EstimatedCost';`.
3. Ejecutar el ALTER del archivo de migración usando la DB correcta en
   una ventana de mantenimiento (puede bloquear escrituras brevemente).
4. Repetir la consulta de columna y ejecutar
   `php drive/tests/activity_costs_smoke.php`.

**Importante:** el cambio preserva valores guardados, pero no recupera
fracciones ya redondeadas antes de la migración. No crea precio para
unidades desconocidas y no convierte estimaciones AWS en factura real.
Los totales de la pantalla pueden tener hasta 16 decimales de precisión
visible; sumas PHP con coma flotante pueden mostrar diferencias ínfimas
para valores extremadamente pequeños.

## Extensión futura

Nuevos workers no deben escribir SQL directamente. Deben expresar unidades mediante `ActivityCostRecorder`, por ejemplo cuando existan datos reales para:

- OfficeWorker EC2;
- StartInstances / StopInstances;
- procesamiento Office;
- MediaWorker.

Antes de agregar una tarifa debe definirse una unidad observable y una fuente de precio. Para costos que sólo pueden conocerse a nivel de cuenta o recurso agregado, se debe conservar la reconciliación con Cost Explorer en lugar de forzar una atribución por archivo.

## Retención y archivo

`bin/activity_retention.php` ofrece simulación por defecto y archivo privado
obligatorio antes de eliminar telemetría síncrona antigua. Conserva registros
correlacionados y operativos. Consulte `ACTIVITY_RETENTION.md`: los costos
archivados dejan de estar en reportes MySQL; no se instala una purga automática.
