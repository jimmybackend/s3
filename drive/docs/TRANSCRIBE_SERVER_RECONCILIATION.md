# Amazon Transcribe: reconciliación automática en servidor

## Problema resuelto

Amazon Transcribe puede terminar correctamente y escribir su JSON en S3 aunque el navegador se cierre o deje de consultar `transcribir_estado.php`.

Antes de este cambio, `DriveActivityEvents` sólo pasaba de `transcribe.job_started` a segundos facturables cuando el navegador consultaba el estado después de `COMPLETED`. El resultado podía quedar así:

```text
AWS Transcribe: COMPLETED
JSON en S3: sí
DriveActivityEvents: IN_PROGRESS / unpriced
```

## Fuente de verdad

La reconciliación ya no depende de `localStorage` ni de mantener una pestaña abierta.

El servidor usa dos hechos que ya existen:

1. `DriveActivityEvents.CorrelationId` se calcula como `transcribe:` + SHA-256 del `jobName`.
2. Amazon Transcribe conserva el `jobName` durante su periodo de retención.

El worker lista los jobs de AWS, vuelve a calcular el mismo `CorrelationId` y lo compara con los eventos `Transcribe` todavía `unpriced`.

No se agrega una tabla nueva.

## Flujo

```text
transcribir_iniciar.php
        -> Amazon Transcribe
        -> DriveActivityEvents = unpriced

systemd timer
        -> drive/bin/transcribe_reconcile.php
        -> ListTranscriptionJobs
        -> correlation(jobName)
        -> encuentra evento pendiente
        -> GetTranscriptionJob

QUEUED / IN_PROGRESS
        -> actualiza el estado pendiente

COMPLETED
        -> obtiene JSON final
        -> registra/cataloga derivados
        -> calcula unidades Transcribe
        -> actualiza el mismo CorrelationId
        -> registra costo atribuido

FAILED
        -> marca el evento como error
```

## Instalación en EC2

El instalador crea:

```text
arcadecloud-transcribe-reconcile.service
arcadecloud-transcribe-reconcile.timer
```

Ejemplo para un pool PHP-FPM que corre como `apache`:

```bash
cd /var/www/arcadecloud-drive
sudo bash drive/bin/install_transcribe_reconcile_timer.sh \
  --run-user=apache \
  --app-root=/var/www/arcadecloud-drive \
  --interval-sec=60
```

El servicio carga `/etc/arcadecloud-drive/drive.env` mediante `EnvironmentFile`. `app_bootstrap.php` sigue aplicando después `/etc/arcadecloud-drive/runtime-env.json`.

## Verificación

```bash
sudo systemctl status arcadecloud-transcribe-reconcile.timer --no-pager
sudo systemctl status arcadecloud-transcribe-reconcile.service --no-pager
sudo journalctl -u arcadecloud-transcribe-reconcile.service -n 80 --no-pager
sudo systemctl list-timers arcadecloud-transcribe-reconcile.timer --no-pager
```

También puede ejecutarse una reconciliación manual de diagnóstico sin abrir el navegador:

```bash
sudo systemctl start arcadecloud-transcribe-reconcile.service
sudo journalctl -u arcadecloud-transcribe-reconcile.service -n 80 --no-pager
```

## Seguridad e idempotencia

- No se guardan credenciales en el repositorio.
- No se listan objetos S3 para navegación.
- Cada job se identifica por el `CorrelationId` ya existente.
- `DriveActivityEvents` conserva su UPSERT por correlación, por lo que la finalización no duplica el costo Transcribe.
- Los eventos ya tasados dejan de ser candidatos del worker.
