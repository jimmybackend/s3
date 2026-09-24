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

mediaContract(str_contains($service, 'MAX_SOURCE_BYTES = 8 * 1024 * 1024 * 1024'), 'límite de origen fijado en 8 GB');
mediaContract(!str_contains($service, 'MIN_SOURCE_BYTES'), 'no existe tamaño mínimo para procesar');
mediaContract(str_contains($worker, "headObject(["), 'worker valida tamaño real con S3 HeadObject');
mediaContract(str_contains($worker, '[DEPENDENCY_MISSING]'), 'worker clasifica dependencias faltantes');
mediaContract(str_contains($jobs, 'latestOperationalWarning'), 'superadmin puede detectar worker ausente o incompleto');
mediaContract(str_contains($page, 'id="modalMediaSplit"'), 'existe modal Bootstrap para dividir multimedia');
mediaContract(str_contains($page, 'id="mediaSplitParts"'), 'modal pregunta cantidad de partes');
mediaContract(!str_contains($js, 'prompt('), 'flujo de división ya no usa window.prompt');
mediaContract(str_contains($js, "jQuery('#modalMediaSplit').modal('show')"), 'botón abre el modal del Drive');
mediaContract(substr_count($block, 'data-bytes="<?= (int)$tamano ?>"') >= 3, 'acciones multimedia publican tamaño del archivo');

fwrite(STDOUT, "Media processing UI/worker contract: OK\n");
