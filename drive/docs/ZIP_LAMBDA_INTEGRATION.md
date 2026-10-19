# ZIP Explorer via Lambda (opt-in)

El explorador heredado permanece activo por defecto. Cuando ARCADECLOUD_ZIP_LAMBDA_ENABLED=1 y ARCADECLOUD_ZIP_LAMBDA_FUNCTION=arcadecloud-zip-explorer-test, zip_explorer.php valida la sesión y la propiedad del ZIP en MySQL y luego invoca Lambda desde PHP.

## Runtime
- La instancia EC2/PHP necesita un rol IAM con **lambda:InvokeFunction** exclusivamente sobre arn:aws:lambda:us-east-1:084375544416:function:arcadecloud-zip-explorer-test. Sin autorización al rol de EC2, no se activa el interruptor.
- Configurar ARCADECLOUD_ZIP_LAMBDA_REGION=us-east-1 y ARCADECLOUD_ZIP_LAMBDA_MEMORY_MB=128, actualizándolo cuando cambie la memoria real. Nunca incrustar claves IAM ni publicar una Function URL.
- La Lambda mantiene el rol de ejecución S3 GetObject + PutObject restringido a arcadecloud-lambda-results/*. El bucket debe tener regla de expiración de objetos temporales.
- Lambda y cliente PHP invocan en modo síncrono. Limitar duración para evitar agotar los workers PHP-FPM; extracción larga precisa cola asíncrona en fase posterior.
- El servidor compara el hostname de download_url con el bucket configurado antes de redirigir al navegador. La URL firmada tiene tiempo de vida limitado.
- **No activar** si falta la política IAM de InvokeFunction o si no se ha probado la descarga real desde Lambda. Sin el flag, el ZIP tradicional sigue igual.

## Actividad y costos
Usa DriveActivityEvents, a través de ActivityCostRecorder, con usuario de la sesión autenticada y FileId del archivo original. Servicio Lambda y acciones zip.lambda.list / zip.lambda.download_file / zip.lambda.download_folder. Correlación por ejecución, sin almacenar S3 keys ni enlaces firmados.

El catálogo incluye lambda.request y lambda.gb_second_estimate; duración aproximada wall clock del cliente PHP * memoria configurada de Lambda, **no** Duration facturado por AWS. Costos estimados en USD y NO cargos al usuario. Las tarifas representan x86 en us-east-1; no incluyen almacenamiento temporal adicional, S3, transferencia, capas gratuitas ni descuentos. Si una invocación falla se registra el fallo sin atribuir costo preciso.

## Seguridad pendiente antes de encender para todos los usuarios
La Lambda de laboratorio acepta cualquier key de m41717c71ck de quien tenga permiso InvokeFunction. Nunca exponerla por URL pública ni conceder InvokeFunction a clientes. Limitar el permiso a EC2 autorizado; restringir el S3 GetObject del rol Lambda a prefijos necesarios si la arquitectura y los usuarios lo permiten. Autorización por usuario ocurre solo en PHP. El ZIP original nunca se modifica.
