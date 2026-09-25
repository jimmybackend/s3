<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 2);

function freshInstallContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$installer = (string)file_get_contents($repo . '/drive/bin/install_arcadecloud.sh');
$server = (string)file_get_contents($repo . '/drive/bin/install_arcadecloud_server.sh');
$mediaInstaller = (string)file_get_contents($repo . '/drive/bin/install_media_processing_worker.sh');
$worker = (string)file_get_contents($repo . '/drive/src/Console/MediaProcessingWorkerCommand.php');
$setupApi = (string)file_get_contents($repo . '/drive/setup/api.php');
$setup = (string)file_get_contents($repo . '/drive/src/Setup/SetupConfigurationService.php');
$schemaService = (string)file_get_contents($repo . '/drive/src/Setup/CanonicalDatabaseSchemaService.php');
$sql = (string)file_get_contents($repo . '/adbbmis1_Cloud.sql');
$requirements = (string)file_get_contents($repo . '/drive/docs/MINIMUM_REQUIREMENTS.md');

freshInstallContract(str_contains($installer, '--node-role="' . '$' . '{NODE_ROLE:-web}"'), 'instalador pasa el rol al preparador del sistema');
freshInstallContract(str_contains($server, '--node-role=*'), 'preparador acepta rol de nodo');
freshInstallContract(str_contains($server, 'spal-release'), 'worker nuevo prepara SPAL');
freshInstallContract(str_contains($server, 'ffmpeg-free'), 'worker nuevo instala ffmpeg-free');
freshInstallContract(str_contains($server, 'lame-libs'), 'worker nuevo instala lame-libs');
freshInstallContract(str_contains($server, 'command_exists ffmpeg'), 'instalación valida ffmpeg');
freshInstallContract(str_contains($server, 'command_exists ffprobe'), 'instalación valida ffprobe');
freshInstallContract(str_contains($server, 'command_exists lame'), 'instalación valida lame');
freshInstallContract(str_contains($mediaInstaller, 'php ffmpeg ffprobe lame'), 'servicio multimedia exige todas sus herramientas');

freshInstallContract(str_contains($worker, "ffmpegHasEncoder('libmp3lame')"), 'MP3 usa libmp3lame cuando está disponible');
freshInstallContract(str_contains($worker, "$" . "this->findExecutable('lame')"), 'MP3 tiene fallback con lame');
freshInstallContract(str_contains($worker, "'pcm_s16le'"), 'fallback MP3 decodifica a PCM antes de LAME');

freshInstallContract(
    str_contains($setupApi, "/src/Setup/CanonicalDatabaseSchemaService.php"),
    'setup API carga explícitamente el servicio de esquema canónico'
);
freshInstallContract(str_contains($setup, 'initializeIfEmpty'), 'setup MySQL inicializa una base vacía');
freshInstallContract(str_contains($schemaService, 'objectCount'), 'bootstrap comprueba que la DB esté vacía');
freshInstallContract(str_contains($schemaService, 'if ($objectsBefore > 0)'), 'DB existente nunca recibe el dump completo');
freshInstallContract(str_contains($schemaService, 'adbbmis1_Cloud.sql'), 'bootstrap usa exclusivamente el SQL canónico');
freshInstallContract(str_contains($schemaService, 'multi_query'), 'bootstrap importa el SQL canónico en DB vacía');

foreach (['Users','FileS3','DriveActivityEvents','MediaProcessingJobs','MediaWorkerNodeSessions'] as $table) {
    freshInstallContract(
        str_contains($schemaService, "'{$table}'"),
        "bootstrap verifica {$table}"
    );
    freshInstallContract(
        str_contains($sql, "CREATE TABLE IF NOT EXISTS `{$table}`"),
        "SQL canónico contiene {$table}"
    );
}

freshInstallContract(str_contains($requirements, '2 vCPU'), 'repo documenta CPU mínima');
freshInstallContract(str_contains($requirements, '8 GiB de RAM'), 'repo documenta RAM mínima del worker');
freshInstallContract(str_contains($requirements, '40 GiB'), 'repo documenta almacenamiento del worker');
freshInstallContract(str_contains($requirements, '2.25'), 'repo documenta espacio temporal requerido');
freshInstallContract(str_contains($requirements, '8 GB'), 'repo documenta tamaño máximo soportado');

fwrite(STDOUT, "Fresh installation contract: OK\n");
