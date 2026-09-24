<?php
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$canonical = $repo . '/adbbmis1_Cloud.sql';
$startMarker = '-- ARCADECLOUD:FEDERATION_SCHEMA:BEGIN';
$endMarker = '-- ARCADECLOUD:FEDERATION_SCHEMA:END';

function schemaContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$sqlFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repo, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'sql') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $sqlFiles[] = $path;
}
sort($sqlFiles);

schemaContract($sqlFiles === [$canonical], 'adbbmis1_Cloud.sql es el único archivo SQL del repositorio');
schemaContract(is_readable($canonical), 'SQL canónico legible');

$content = (string)file_get_contents($canonical);
schemaContract(substr_count($content, $startMarker) === 1, 'existe un único marcador FederationCloud BEGIN');
schemaContract(substr_count($content, $endMarker) === 1, 'existe un único marcador FederationCloud END');

$start = strpos($content, $startMarker);
$end = strpos($content, $endMarker);
schemaContract($start !== false && $end !== false && $end > $start, 'sección FederationCloud bien delimitada');

$section = substr($content, $start + strlen($startMarker), $end - ($start + strlen($startMarker)));
schemaContract(!preg_match('/\bDROP\s+TABLE\b/i', $section), 'sección de migración FederationCloud no contiene DROP TABLE');

$requiredTables = [
    'FederationNodes',
    'FederationNodeAuthorizations',
    'FederationOriginCounters',
    'FederationEvents',
    'FederationClocks',
    'FederatedResources',
    'FederationResourceLocations',
    'FederationPeerSyncState',
    'FederationAccessRequests',
    'FederationShares',
    'FederationShareImportJobs',
    'FederationPublicImportJobs',
    'FederationReplicaJobs',
    'FederationReplicaObjects',
    'FederationIngressQueue',
    'FederationDropAccounts',
    'FederationDropIdentities',
    'FederationDrops',
    'FederationDropPaymentEvents',
    'FederationDropIngressObjects',
    'FederationCommercialProviders',
    'FederationDropPlacements',
];

foreach ($requiredTables as $table) {
    $pattern = '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+[\x60]?' . preg_quote($table, '/') . '[\x60]?/i';
    schemaContract(
        preg_match($pattern, $section) === 1,
        "tabla {$table} presente en la sección canónica"
    );
}

schemaContract(str_contains($content, 'CREATE TABLE IF NOT EXISTS `S3SyncSeen`'), 'SQL canónico incluye staging S3SyncSeen usado por SyncRepository');
schemaContract(str_contains($content, 'CREATE TABLE IF NOT EXISTS `FileS3`'), 'SQL canónico incluye FileS3 para registrar salidas generadas');
schemaContract(str_contains($content, 'CREATE TABLE IF NOT EXISTS `DriveActivityEvents`'), 'SQL canónico incluye DriveActivityEvents para tareas y costos');
schemaContract(str_contains($content, 'CREATE TABLE IF NOT EXISTS `MediaProcessingJobs`'), 'SQL canónico incluye la cola del worker multimedia');
schemaContract(str_contains($content, 'CREATE TABLE IF NOT EXISTS `MediaWorkerNodeSessions`'), 'SQL canónico incluye sesiones EC2 del nodo multimedia');
schemaContract(str_contains($content, 'UNIQUE KEY `uq_files3_user_path_key` (`user_id_`,`Ruta`,`Encriptado`)'), 'FileS3 usa identidad única por usuario+ruta+clave');
schemaContract(!str_contains($content, 'UNIQUE KEY `uq_files3_user_key` (`user_id_`,`Encriptado`)'), 'índice histórico FileS3 ya no aparece en DB limpia');
schemaContract(!str_contains($content, 'INSERT INTO `UserPipelineFeatures`'), 'DB limpia no incluye feature flags de un usuario existente');
schemaContract(!str_contains($content, 'INSERT INTO `UserPreferences`'), 'DB limpia no incluye preferencias de un usuario existente');
schemaContract(
    preg_match("/\\(\\d+,\\s*'user',\\s*\\d+,\\s*'voice_main'/i", $content) !== 1,
    'DB limpia no incluye configuración voice_main ligada a un usuario existente'
);

schemaContract(str_contains($content, 'SET @ARCADECLOUD_OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;'), 'dump completo conserva el estado previo de FOREIGN_KEY_CHECKS');
schemaContract(str_contains($content, 'SET FOREIGN_KEY_CHECKS = 0;'), 'dump completo desactiva temporalmente validación FK para recreación');
schemaContract(str_contains($content, 'SET FOREIGN_KEY_CHECKS = @ARCADECLOUD_OLD_FOREIGN_KEY_CHECKS;'), 'dump completo restaura FOREIGN_KEY_CHECKS al terminar');
schemaContract(!str_contains($section, 'FOREIGN_KEY_CHECKS'), 'sección runtime FederationCloud no altera FOREIGN_KEY_CHECKS');

schemaContract(!preg_match('/^\\s*CREATE\\s+DATABASE\\b/im', $content), 'SQL canónico no crea una base por nombre');
schemaContract(!preg_match('/^\\s*USE\\s+[`A-Za-z0-9_]+\\s*;/im', $content), 'SQL canónico no cambia la DB seleccionada por el operador');

schemaContract(str_contains($content, 'DROP TABLE IF EXISTS'), 'dump completo conserva semántica de recreación para DB limpia');

fwrite(STDOUT, "Canonical database schema contract: OK\n");
