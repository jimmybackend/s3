# Costo atribuido de Amazon Transcribe

Fecha de incorporación: **15-Sep-2026**.

## Objetivo

Cada trabajo de Amazon Transcribe iniciado desde ArcadeCloud Drive queda ligado al archivo de origen y al usuario autenticado mediante `DriveActivityEvents`.

Al iniciar el trabajo se conserva un evento no tasado. Cuando AWS informa `COMPLETED`, el mismo evento correlacionado se actualiza con una unidad observable de duración y el catálogo calcula el costo atribuido.

No se crea una tabla nueva y no se modifica el archivo de audio o video original.

## Unidad base

Para transcripción estándar por lotes desde S3 se usa:

```text
transcribe.standard_batch_second
```

Referencia de precio versionada para `us-east-1`:

```text
USD 0.024 / minuto
USD 0.0004 / segundo
```

Para redacción PII, cuando está habilitada, también se registra:

```text
transcribe.pii_redaction_second
```

Referencia Tier-1:

```text
USD 0.0024 / minuto
USD 0.00004 / segundo
```

Las referencias viven en:

```text
drive/config/activity-cost-pricing.json
```

## Cómo se obtiene la duración

Después de completar la transcripción, `TranscriptionCostAttribution` inspecciona los timestamps `end_time` devueltos por Amazon Transcribe en `results.audio_segments` y `results.items`.

Se toma el mayor timestamp observado, se redondea al segundo superior y se aplica el mínimo de referencia documentado por la página de precios.

La fuente se guarda como:

```text
transcribe_result_timestamps
```

La duración observada y los segundos de referencia quedan tanto en la respuesta de estado como en los metadatos del archivo de transcripción generado.

## Precisión financiera

Este valor es **costo atribuido / estimado**, no una copia de la factura AWS.

La factura real puede variar por:

- Free Tier;
- descuentos por volumen;
- región;
- impuestos;
- acuerdos de cuenta;
- duración real facturable cuando exista silencio final no representado por timestamps de voz;
- complementos con precios que todavía no estén versionados en el catálogo.

Por eso el panel mantiene por separado:

- `ESTIMADO / atribuido` por archivo y operación;
- `REAL AWS` obtenido con Cost Explorer para reconciliación de la cuenta.

Si se detecta un Custom Language Model o Toxicity Detection sin una tarifa verificada en el catálogo, la operación queda `partial`; el costo base sí se conserva y la interfaz advierte que faltan unidades por tasar.

## S3 generado por la transcripción

Al completar también se registra un componente `S3` con:

- número real de `PutObject` usados para JSON/SRT/VTT;
- bytes nuevos generados como `s3.storage_bytes_delta`.

Los PUT tienen tarifa atribuible. El almacenamiento por bytes permanece parcial porque depende de clase y tiempo de permanencia.

## Uso

No cambia el flujo del usuario:

1. Abrir el archivo de audio o video en Drive.
2. Elegir **Amazon Transcribe · Audio a texto**.
3. Crear el trabajo.
4. Continuar usando Drive mientras AWS procesa en segundo plano.
5. Cuando el trabajo termina, abrir **Actividad y costos**.
6. Filtrar por servicio `Transcribe` o acción `transcribe`.

El evento final muestra el `FileId`, costo atribuido, moneda, estado de tasación y fecha. El mismo `CorrelationId` evita duplicar el costo si el navegador consulta varias veces el estado de un mismo job.

## Archivos principales

```text
drive/src/Activity/TranscriptionCostAttribution.php
drive/src/Aws/TranscriptionFileService.php
drive/src/Http/Controller/TranscriptionController.php
drive/config/activity-cost-pricing.json
drive/tests/activity_costs_smoke.php
```
