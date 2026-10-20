# ZIP Explorer via Lambda (obligatorio; sin fallback local)

**La EC2 no puede abrir ni almacenar temporalmente ZIP existentes durante la exploración.** `zip_explorer.php` delega exclusivamente en `ZipLambdaExplorerService`. Si `ARCADECLOUD_ZIP_LAMBDA_ENABLED=1` y `ARCADECLOUD_ZIP_LAMBDA_FUNCTION` están configuradas, PHP valida sesión/propiedad del ZIP y lo procesa desde Lambda. Si no lo están, responde HTTP 503 **sin descargar el ZIP a EC2**. No existe fallback local. El cambio en Git no habilita por sí solo el entorno de PHP-FPM.

## Runtime
- La instancia EC2/PHP necesita un rol IAM con **lambda:InvokeFunction** exclusivamente sobre arn:aws:lambda:us-east-1:084375544416:function:arcadecloud-zip-explorer-test. Sin autorización al rol de EC2, no se activa el interruptor.
- Configurar ARCADECLOUD_ZIP_LAMBDA_REGION=us-east-1 y ARCADECLOUD_ZIP_LAMBDA_MEMORY_MB=128, actualizándolo cuando cambie la memoria real. Nunca incrustar claves IAM ni publicar una Function URL.
- La Lambda mantiene el rol de ejecución S3 GetObject + PutObject restringido a arcadecloud-lambda-results/*. El bucket debe tener regla de expiración de objetos temporales.
- Lambda y cliente PHP invocan en modo síncrono. Limitar duración para evitar agotar los workers PHP-FPM; extracción larga precisa cola asíncrona en fase posterior.
- El servidor compara el hostname de download_url con el bucket configurado antes de redirigir al navegador. La URL firmada tiene tiempo de vida limitado.
- **No activar** si falta la política IAM de InvokeFunction o si no se ha probado la descarga real desde Lambda. Sin el flag, el explorador devuelve HTTP 503; **nunca** utiliza `ZipArchive` ni archivos temporales en EC2.

## Actividad y costos
Usa DriveActivityEvents, a través de ActivityCostRecorder, con usuario de la sesión autenticada y FileId del archivo original. Servicio Lambda y acciones zip.lambda.list / zip.lambda.download_file / zip.lambda.download_folder. Correlación por ejecución, sin almacenar S3 keys ni enlaces firmados.

El catálogo incluye lambda.request y lambda.gb_second_estimate; duración aproximada wall clock del cliente PHP * memoria configurada de Lambda, **no** Duration facturado por AWS. Costos estimados en USD y NO cargos al usuario. Las tarifas representan x86 en us-east-1; no incluyen almacenamiento temporal adicional, S3, transferencia, capas gratuitas ni descuentos. Si una invocación falla se registra el fallo sin atribuir costo preciso.

## Seguridad pendiente antes de encender para todos los usuarios
La Lambda de laboratorio acepta cualquier key de m41717c71ck de quien tenga permiso InvokeFunction. Nunca exponerla por URL pública ni conceder InvokeFunction a clientes. Limitar el permiso a EC2 autorizado; restringir el S3 GetObject del rol Lambda a prefijos necesarios si la arquitectura y los usuarios lo permiten. Autorización por usuario ocurre solo en PHP. El ZIP original nunca se modifica.

## Alcance de esta protección

Protege **explorar/abrir un ZIP ya existente**, navegar por sus entradas y descargar archivos o carpetas desde dicho ZIP. `FileAccessController::zip()` y `ZipDownloadService` son otra función: **creación de un ZIP nuevo a partir de varios archivos seleccionados**. Esa ruta aún utiliza temporales en EC2 y no está migrada a Lambda. Debe implementarse aparte una Lambda de empaquetado antes de declarar que *todas* las operaciones ZIP son independientes del disco EC2. Este cambio no rompe silenciosamente la descarga múltiple.
