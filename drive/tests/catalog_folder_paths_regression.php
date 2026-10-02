<?php
declare(strict_types=1);
if (getenv('ARCADECLOUD_ISOLATED_TEST') !== '1') throw new RuntimeException('Isolated test opt-in required.');
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'ArcadeCloud\\Drive\\';
    if (str_starts_with($class, $prefix)) require_once dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
});
use ArcadeCloud\Drive\Application\FolderMutationService;
use ArcadeCloud\Drive\Application\FolderQueryService;
use ArcadeCloud\Drive\Storage\FolderMutationRepository;
use ArcadeCloud\Drive\Storage\FolderRepository;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use Aws\S3\S3Client;
function pathCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', 'fixture-only', '', (int)(getenv('TEST_DB_PORT') ?: 3306));
$database = 'paths_regression_' . bin2hex(random_bytes(6));
$db->query("CREATE DATABASE `$database`"); $db->select_db($database); $db->set_charset('utf8mb4');
try {
    $schema = (string)file_get_contents(dirname(__DIR__, 2) . '/adbbmis1_Cloud.sql');
    if (!preg_match('/CREATE TABLE IF NOT EXISTS `S3Folders` \(.*?;\s/s', $schema, $match)) throw new RuntimeException('Canonical schema unavailable');
    $db->query($match[0]);
    $paths = new UserStoragePath();
    $repository = new FolderMutationRepository($db);
    $query = new FolderQueryService(new FolderRepository($db), $paths);
    $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'],
        'handler' => static function () { throw new LogicException('Catalog rename must never access S3'); }]);
    $mutation = new FolderMutationService($repository, $paths, new StorageObjectNameCodec(), $client, 'fixture');
    $repository->upsert(2, 'Data2/d_opaque/', 'Anterior', 'Data2/');
    $repository->upsert(2, 'Data2/d_opaque/d_child/', 'Facturas', 'Data2/d_opaque/');
    pathCheck($query->displayPathForUser(2, 'Data2/') === 'Mi Drive/', 'missing root catalog label never exposes Data2');
    $mutation->rename(2, 'Data2/d_opaque/', 'Contabilidad');
    pathCheck($query->displayPathForUser(2, 'Data2/d_opaque/d_child/') === 'Mi Drive/Contabilidad/Facturas/', 'descendant visible path reflects catalog rename');
    $row = $repository->requireActive(2, 'Data2/d_opaque/');
    pathCheck($row['Nombre'] === 'Contabilidad' && $row['Prefix'] === 'Data2/d_opaque/', 'physical prefix remains unchanged');
    $crumbs = $query->breadcrumbsForUser(2, 'Data2/d_opaque/d_child/');
    pathCheck(array_column($crumbs, 'label') === ['Mi Drive','Contabilidad','Facturas'], 'breadcrumbs contain catalog labels');
    pathCheck(array_column($crumbs, 'route') === ['Data2/','Data2/d_opaque/','Data2/d_opaque/d_child/'], 'breadcrumbs keep physical navigation routes');
    $destinations = $query->destinationsForUser(2);
    pathCheck(in_array(['value' => 'Data2/d_opaque/', 'label' => 'Mi Drive/Contabilidad/'], $destinations, true), 'move selector separates value and label');
    try { $mutation->rename(3, 'Data2/d_opaque/', 'Unauthorized'); throw new LogicException('Other owner accepted'); }
    catch (RuntimeException) { pathCheck($repository->requireActive(2, 'Data2/d_opaque/')['Nombre'] === 'Contabilidad', 'other owner cannot rename folder'); }
} finally {
    $db->query("DROP DATABASE `$database`");
}
