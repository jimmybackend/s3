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
    'FederationReplicaJobs',
    'FederationReplicaObjects',
    'FederationIngressQueue',
];

foreach ($requiredTables as $table) {
    $pattern = '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+[\x60]?' . preg_quote($table, '/') . '[\x60]?/i';
    schemaContract(
        preg_match($pattern, $section) === 1,
        "tabla {$table} presente en la sección canónica"
    );
}

schemaContract(str_contains($content, 'DROP TABLE IF EXISTS'), 'dump completo conserva semántica de recreación para DB limpia');

fwrite(STDOUT, "Canonical database schema contract: OK\n");
