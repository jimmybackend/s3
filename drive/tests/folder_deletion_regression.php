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
    foreach (['S3Folders', 'FileS3'] as $table) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS `' . $table . '` \(.*?;\s/s', $schema, $match)) throw new RuntimeException('Canonical schema missing');
        $db->query($match[0]);
    }
    $repository = new FolderMutationRepository($db);
    $paths = new UserStoragePath();
    $seed = static function (string $route) use ($repository, $db): void {
        $repository->upsert(2, $route, 'Visible', 'Data2/');
        $key = $route . 'opaque.bin';
        $stmt = $db->prepare("INSERT INTO FileS3 (Nombre, Encriptado, Tamano, Ruta, Found, user_id_) VALUES ('Visible.txt', ?, 1, ?, 1, 2)");
        $stmt->bind_param('ss', $key, $route); $stmt->execute(); $stmt->close();
    };
    // An underscore and percent in a physical prefix are literal bytes.
    foreach (['Data2/a_/', 'Data2/ab/', 'Data2/p%/', 'Data2/percent/', 'Data2/a!/'] as $route) $seed($route);
    $repository->copyTree(2, 'Data2/a_/', 'Data2/copied/', 'Copia', 'Data2/');
    pathCheck($repository->requireActive(2, 'Data2/ab/')['Prefix'] === 'Data2/ab/', 'copy preserves wildcard sibling');
    $count = (int)$db->query("SELECT COUNT(*) AS n FROM FileS3 WHERE Ruta = 'Data2/copied/'")->fetch_assoc()['n'];
    pathCheck($count === 1, 'copy selects exactly one literal subtree');
    $moved = $repository->moveTree(2, 'Data2/p%/', 'Data2/moved/');
    pathCheck($moved['foldersUpdated'] === 1 && $moved['filesUpdated'] === 1, 'move selects literal percent subtree');
    pathCheck($repository->requireActive(2, 'Data2/percent/')['Prefix'] === 'Data2/percent/', 'move preserves percent sibling');
    $deleted = $repository->deleteTree(2, 'Data2/a_/');
    pathCheck($deleted === ['files' => 1, 'folders' => 1], 'delete selects literal underscore subtree');
    $repository->requireActive(2, 'Data2/ab/');
    pathCheck($repository->deleteTree(2, 'Data2/a!/') === ['files' => 1, 'folders' => 1], 'escape character is literal too');

    $seed('Data2/partial/');
    $calls = [];
    $fail = true;
    $client = new S3Client([
        'version' => 'latest', 'region' => 'us-east-1',
        'credentials' => ['key' => 'fixture', 'secret' => 'fixture'],
        'handler' => static function ($command) use (&$calls, &$fail) {
            $calls[] = $command->getName();
            if ($command->getName() === 'ListObjectsV2') {
                return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result([
                    'Contents' => [['Key' => 'Data2/partial/opaque.bin']],
                    'IsTruncated' => false,
                ]));
            }
            if ($command->getName() !== 'DeleteObjects') throw new LogicException('Unexpected S3 operation');
            pathCheck($command['Delete']['Quiet'] === true, 'quiet batch request preserved');
            return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result($fail ? [
                'Errors' => [['Key' => 'Data2/partial/opaque.bin', 'Code' => 'AccessDenied', 'Message' => 'internal-detail']],
                '@metadata' => ['statusCode' => 200],
            ] : ['@metadata' => ['statusCode' => 200]]));
        },
    ]);
    $service = new FolderMutationService($repository, $paths, new StorageObjectNameCodec(), $client, 'fixture');
    $caught = false;
    try { $service->delete(2, 'Data2/partial/'); }
    catch (RuntimeException $e) {
        $caught = true;
        pathCheck(!str_contains($e->getMessage(), 'opaque') && !str_contains($e->getMessage(), 'internal-detail'), 'error hides physical keys and provider details');
    }
    pathCheck($caught, 'HTTP 200 with per-object error is not success');
    $repository->requireActive(2, 'Data2/partial/');
    pathCheck((int)$db->query("SELECT COUNT(*) AS n FROM FileS3 WHERE Ruta = 'Data2/partial/'")->fetch_assoc()['n'] === 1, 'failed batch retains catalog for retry');
    $fail = false;
    $result = $service->delete(2, 'Data2/partial/');
    pathCheck($result['files_deleted'] === 1 && $result['folders_deleted'] === 1, 'successful retry finalizes catalog');
    pathCheck($calls === ['ListObjectsV2', 'DeleteObjects', 'ListObjectsV2', 'DeleteObjects'], 'no hidden S3 requests');
} finally {
    $db->query("DROP DATABASE `$database`");
}
