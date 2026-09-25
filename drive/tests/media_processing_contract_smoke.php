<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 2);

function mediaContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$service = (string)file_get_contents($repo . '/drive/src/Media/MediaProcessingService.php');
$worker = (string)file_get_contents($repo . '/drive/src/Console/MediaProcessingWorkerCommand.php');
$jobs = (string)file_get_contents($repo . '/drive/src/Media/MediaProcessingJobRepository.php');
$js = (string)file_get_contents($repo . '/drive/js/media-processing.js');
$page = (string)file_get_contents($repo . '/drive/s3.php');
$block = (string)file_get_contents($repo . '/drive/bloque_archivos.php');
$node = (string)file_get_contents($repo . '/drive/src/Media/MediaWorkerNodeService.php');
$sessions = (string)file_get_contents($repo . '/drive/src/Media/MediaWorkerNodeSessionRepository.php');
$bootstrap = (string)file_get_contents($repo . '/drive/bin/media_worker_node_bootstrap.sh');
$tasksJs = (string)file_get_contents($repo . '/drive/js/background-tasks.js');
$controller = (string)file_get_contents($repo . '/drive/src/Http/Controller/MediaProcessingController.php');

mediaContract(str_contains($service, 'MAX_SOURCE_BYTES = 8 * 1024 * 1024 * 1024'), 'límite de origen fijado en 8 GB');
mediaContract(!str_contains($service, 'MIN_SOURCE_BYTES'), 'no existe tamaño mínimo para procesar');
mediaContract(str_contains($worker, "headObject(["), 'worker valida tamaño real con S3 HeadObject');
mediaContract(str_contains($worker, '[DEPENDENCY_MISSING]'), 'worker clasifica dependencias faltantes');
mediaContract(str_contains($worker, "ffmpegHasEncoder('libmp3lame')"), 'worker usa libmp3lame cuando está disponible');
mediaContract(str_contains($worker, "findExecutable('lame')"), 'worker conserva fallback LAME para MP3');
mediaContract(str_contains($jobs, 'latestOperationalWarning'), 'superadmin puede detectar worker ausente o incompleto');
mediaContract(str_contains($page, 'id="modalMediaSplit"'), 'existe modal Bootstrap para dividir multimedia');
mediaContract(str_contains($page, 'id="mediaSplitParts"'), 'modal pregunta cantidad de partes');
mediaContract(!str_contains($js, 'prompt('), 'flujo de división ya no usa window.prompt');
mediaContract(str_contains($js, "jQuery('#modalMediaSplit').modal('show')"), 'botón abre el modal del Drive');
mediaContract(substr_count($block, 'data-bytes="<?= (int)$tamano ?>"') >= 3, 'acciones multimedia publican tamaño del archivo');
mediaContract(str_contains($service, 'authorize_node_start'), 'backend exige autorización explícita para encendido bajo demanda');
mediaContract(str_contains($node, 'ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID'), 'nodo EC2 se limita a una instancia configurada');
mediaContract(str_contains($node, 'ARCADECLOUD_MEDIA_WORKER_HOURLY_USD'), 'encendido pagado requiere tarifa de referencia');
mediaContract(str_contains($node, '->start($this->instanceId)'), 'servicio puede encender la EC2 configurada');
mediaContract(str_contains($node, '->stop($this->instanceId, false)'), 'servicio puede apagar la EC2 tras inactividad');
mediaContract(str_contains($node, 'ec2.media_worker_second'), 'sesión EC2 registra segundos facturables');
mediaContract(str_contains($sessions, 'MediaWorkerNodeSessions'), 'sesiones de encendido quedan persistidas');
mediaContract(str_contains($bootstrap, 'latest/meta-data/public-ipv4'), 'réplica sin dominio refresca IPv4 por IMDSv2');
mediaContract(str_contains($bootstrap, 'federation_endpoint_refresh.php'), 'réplica vuelve a anunciar FederationCloud al arrancar');
mediaContract(str_contains($js, 'mediaNodeAuthorization'), 'modal pide consentimiento antes de encender el nodo');
mediaContract(str_contains($controller, 'HTTP_X_DRIVE_CSRF'), 'acciones multimedia pagadas exigen CSRF');
mediaContract(str_contains($js, "'X-Drive-CSRF'"), 'cliente multimedia envía token CSRF');
mediaContract(str_contains($tasksJs, 'refreshDriveIfRelevant'), 'Drive refresca la carpeta visible al terminar salidas');

fwrite(STDOUT, "Media processing UI/worker contract: OK\n");
