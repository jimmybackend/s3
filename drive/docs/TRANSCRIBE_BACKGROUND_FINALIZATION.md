# Amazon Transcribe: seguimiento persistente y costo final

## Problema corregido

Antes, el navegador iniciaba el trabajo en Amazon Transcribe y `polly.js` consultaba `transcribir_estado.php` cada pocos segundos. Si el usuario cambiaba de página antes de que AWS terminara, el polling se perdía. AWS podía completar la transcripción y escribir el JSON, pero el Drive no ejecutaba su finalización: el archivo generado podía no quedar catalogado en `FileS3` y el evento de `DriveActivityEvents` seguía como `transcribe.job_started`, sin costo atribuido.

## Nuevo flujo

`drive/js/transcribe-background.js` sustituye únicamente el botón de inicio de Transcribe y conserva el resto del modal existente.

1. Envía el trabajo a `transcribir_iniciar.php`.
2. Guarda `jobName`, archivo origen, ruta y fecha en `localStorage` bajo `arcadecloud.transcribeJobs.v1`.
3. Cierra el modal inmediatamente para que el usuario continúe usando el Drive.
4. Consulta `transcribir_estado.php` cada 5 segundos.
5. Al recibir `COMPLETED`, el endpoint existente:
   - descarga/normaliza el resultado de Amazon Transcribe;
   - registra JSON/SRT/VTT en la misma carpeta;
   - actualiza `FileS3`;
   - calcula duración facturable desde los timestamps;
   - actualiza el mismo evento correlacionado en `DriveActivityEvents` con `transcribe.standard_batch_second` y su costo;
   - registra por separado los PUT/bytes S3 de los archivos derivados.
6. El job se elimina de `localStorage` sólo después de completar o fallar.

## Navegación

El módulo se carga desde `move-tasks.js` en la pantalla principal del Drive y también se inyecta en `activity_costs.php` mediante `ActivityCostController`.

Por eso un trabajo pendiente continúa verificándose al cerrar el modal, permanecer en el Drive o abrir la página de Actividad y costos. Si se cierra completamente el navegador o se abandona el sitio, el trabajo sigue ejecutándose en AWS y el seguimiento se reanuda al volver a una pantalla que cargue el módulo. No se mantiene un proceso PHP-FPM bloqueado durante toda la transcripción.

## Persistencia

- Clave local: `arcadecloud.transcribeJobs.v1`
- Retención máxima local: 30 días
- Máximo local: 30 trabajos
- No se guarda el audio, video ni la transcripción en `localStorage`; sólo identificadores necesarios para consultar el estado.

## Costos

El costo se finaliza únicamente cuando `transcribir_estado.php` observa `COMPLETED`. La correlación existente evita duplicar el evento de Transcribe. La tarifa atribuida continúa viniendo del catálogo versionado `drive/config/activity-cost-pricing.json`; Cost Explorer sigue siendo la reconciliación de la factura real de la cuenta.

## Prueba manual recomendada

1. Iniciar una transcripción desde un MP3/MP4.
2. Confirmar que el modal se cierra y aparece el aviso de trabajo en segundo plano.
3. Abrir `activity_costs.php` mientras AWS sigue procesando.
4. Esperar a que el job termine.
5. Confirmar que la página se recarga una sola vez y el evento de Transcribe deja de estar no tasado.
6. Volver a la carpeta origen y confirmar que el JSON/SRT/VTT aparece registrado.
