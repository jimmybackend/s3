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
$awsRouter = (string)file_get_contents($repo . '/drive/js/aws-comprehend.js');
$locator = (string)file_get_contents($repo . '/drive/src/Aws/FileRecordLocator.php');
$generated = (string)file_get_contents($repo . '/drive/src/Aws/GeneratedFileRepository.php');
$installer = (string)file_get_contents($repo . '/drive/bin/install_arcadecloud_server.sh');

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

// Regresión del click: el router AWS no debe secuestrar botones multimedia que sólo comparten estilo.
mediaContract(
    !str_contains($awsRouter, "event.target.closest('.aws-file-action')"),
    'router AWS no captura genéricamente todos los botones .aws-file-action'
);
foreach (['.js-textract','.js-rekognition','.js-traducir','.js-polly','.js-transcribir','.js-comprehend'] as $selector) {
    mediaContract(str_contains($awsRouter, $selector), 'router AWS conserva acción propia ' . $selector);
}
mediaContract(str_contains($js, "this.endpoint = 'media_processing.php'"), 'click multimedia apunta al endpoint real');
mediaContract(str_contains($js, "operation: operation"), 'cliente envía operación multimedia');
mediaContract(str_contains($js, "new URLSearchParams({archivo: key})"), 'modal consulta metadatos del archivo autorizado');
mediaContract(str_contains($page, 'id="mediaSplitDuration"'), 'modal muestra duración');
mediaContract(str_contains($js, "modal('hide')"), 'modal se cierra después de crear la tarea');

// Creación/validación del job.
mediaContract(str_contains($jobs, 'INSERT INTO MediaProcessingJobs'), 'división/extracción crean job persistente');
mediaContract(str_contains($service, "if (\$operation === 'split_video')"), 'backend acepta división de video');
mediaContract(str_contains($service, "} elseif (\$operation === 'extract_mp3')"), 'backend acepta extracción MP3');
mediaContract(str_contains($service, 'Operación multimedia no soportada.'), 'operación inválida es rechazada');
mediaContract(str_contains($service, 'La cantidad de partes debe estar entre 2 y 50.'), 'cantidad de partes inválida es rechazada');

// Seguridad y aislamiento.
mediaContract(str_contains($service, 'requireReadableByKey($userId, $key)'), 'servicio valida archivo contra propietario autenticado');
mediaContract(str_contains($locator, 'WHERE user_id_ = ? AND Found = 1'), 'localizador filtra FileS3 por usuario');
mediaContract(str_contains($controller, 'guardAuthenticated()'), 'endpoint multimedia exige sesión autenticada');
mediaContract(str_contains($controller, 'requireCsrf()'), 'endpoint multimedia conserva CSRF');
mediaContract(str_contains($worker, 'proc_open($command'), 'FFmpeg usa argv controlado mediante proc_open');
mediaContract(!str_contains($worker, 'shell_exec('), 'worker no usa shell_exec');
mediaContract(!str_contains($worker, 'passthru('), 'worker no usa passthru');

// Dependencias, capacidad, publicación y registro final.
mediaContract(str_contains($node, 'dependency_missing'), 'preflight local informa FFmpeg/FFprobe ausentes');
mediaContract(str_contains($node, 'insufficient_capacity'), 'preflight local informa capacidad insuficiente');
mediaContract(str_contains($worker, '[CAPACITY_INSUFFICIENT]'), 'worker revalida CPU/RAM antes de procesar');
mediaContract(str_contains($worker, "'-c','copy'"), 'división intenta stream copy sin recomprimir');
mediaContract(str_contains($worker, "probeDuration(\$sourcePath)"), 'duración real de división se obtiene con FFprobe');
mediaContract(str_contains($worker, "'ACL' => 'private'"), 'resultados se guardan privados en S3');
mediaContract(str_contains($worker, '$this->generated->upsert('), 'worker registra resultados en FileS3');
mediaContract(str_contains($generated, "INSERT INTO FileS3"), 'repositorio usa FileS3 real para archivos generados');
mediaContract(str_contains($installer, 'install_media_dependencies'), 'instalador prepara dependencias multimedia por rol');
mediaContract(str_contains($installer, 'command_exists ffmpeg'), 'instalador verifica ffmpeg');
mediaContract(str_contains($installer, 'command_exists ffprobe'), 'instalador verifica ffprobe');

fwrite(STDOUT, "Media processing UI/worker contract: OK\n");
