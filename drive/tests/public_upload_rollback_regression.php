<?php
declare(strict_types=1);
if (getenv('ARCADECLOUD_ISOLATED_TEST') !== '1') throw new RuntimeException('Isolated test opt-in required.');
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'ArcadeCloud\\Drive\\';
    if (str_starts_with($class, $prefix)) require_once dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
});
use ArcadeCloud\Drive\Upload\PublicDropzoneUploadService;
use ArcadeCloud\Drive\Upload\UploadCatalogRepository;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\S3\S3Client;
function uploadCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', 'fixture-only', '', (int)(getenv('TEST_DB_PORT') ?: 3306));
$database = 'upload_regression_' . bin2hex(random_bytes(6));
$db->query("CREATE DATABASE `$database`"); $db->select_db($database); $db->set_charset('utf8mb4');
$tmp = tempnam(sys_get_temp_dir(), 'upload-fixture-');
file_put_contents($tmp, 'fixture content');
try {
    $schema = (string)file_get_contents(dirname(__DIR__, 2) . '/adbbmis1_Cloud.sql');
    foreach (['FileS3', 'FederationModerationBlocks'] as $table) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS `?' . $table . '`? \\(.*?;\\s/s', $schema, $match)) throw new RuntimeException('Canonical schema missing');
        // MariaDB fixture uses its equivalent collation; production schema is untouched.
        $sql = str_contains($db->server_info, 'MariaDB') ? str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $match[0]) : $match[0];
        $db->query($sql);
    }
    $calls = []; $objects = []; $denyDelete = false;
    $s3 = new S3Client(['version'=>'latest','region'=>'us-east-1','credentials'=>['key'=>'fixture','secret'=>'fixture'],
        'handler'=>static function ($command) use (&$calls, &$objects, &$denyDelete) {
            $name=$command->getName(); $key=(string)$command['Key']; $calls[] = [$name,$key];
            if ($name === 'PutObject') $objects[$key] = (string)$command['Body'];
            elseif ($name === 'DeleteObject') {
                if ($denyDelete) throw new RuntimeException('cleanup fixture failure');
                unset($objects[$key]);
            } else throw new LogicException('Unexpected S3 operation');
            return \GuzzleHttp\Promise\Create::promiseFor(new \Aws\Result([]));
        }]);
    $service = new PublicDropzoneUploadService($s3, 'fixture', 'Shared/', new StorageObjectNameCodec(), new UploadCatalogRepository($db));
    $upload = static fn() => $service->upload(['tmp_name'=>$tmp,'name'=>'Informe á.txt'], 'Shared/folder/', [], 2);
    $result = $upload();
    $row = $db->query('SELECT * FROM FileS3')->fetch_assoc();
    uploadCheck($row['Nombre'] === 'Informe á.txt' && $row['Ruta'] === 'Shared/folder/' && (int)$row['user_id_'] === 2, 'visible name, route and owner preserved');
    uploadCheck(isset($objects[$row['Ruta'].$row['Encriptado']]) && (int)$row['Tamano'] === filesize($tmp), 'catalog resolves to uploaded bytes');
    uploadCheck(json_decode($row['Metadatos'],true)['hash_sha256'] === hash_file('sha256',$tmp), 'hash derived from actual bytes');
    $calls=[]; $objects=[];
    $hash='sha256:'.hash_file('sha256',$tmp);
    $stmt=$db->prepare("INSERT INTO FederationModerationBlocks (ContentId,OriginNodeId,ReasonCode,EventId) VALUES (?, 'fixture', 'test', 'fixture')");
    $stmt->bind_param('s',$hash); $stmt->execute(); $stmt->close();
    try { $upload(); throw new LogicException('Blocked bytes accepted'); }
    catch (RuntimeException $e) { uploadCheck(str_contains($e->getMessage(),'bloqueado'), 'blocked hash rejected'); }
    uploadCheck($calls === [], 'blocked content never reaches S3');
    $db->query('DELETE FROM FederationModerationBlocks');
    $db->query("CREATE TRIGGER reject_upload BEFORE INSERT ON FileS3 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture catalog failure'");
    try { $upload(); throw new LogicException('Catalog failure hidden'); }
    catch (RuntimeException $e) { uploadCheck(str_contains($e->getMessage(),'fixture catalog failure'), 'original catalog error preserved'); }
    uploadCheck(array_column($calls,0) === ['PutObject','DeleteObject'] && $calls[0][1] === $calls[1][1] && $objects === [], 'failed registration cleans only its uploaded object');
    $calls=[]; $denyDelete=true;
    try { $upload(); throw new LogicException('Catalog failure hidden'); }
    catch (RuntimeException $e) { uploadCheck(str_contains($e->getMessage(),'fixture catalog failure'), 'cleanup failure does not hide catalog error'); }
    uploadCheck((int)$db->query('SELECT COUNT(*) n FROM FileS3')->fetch_assoc()['n'] === 1, 'failures add no catalog rows');
} finally {
    unlink($tmp); $db->query("DROP DATABASE `$database`");
}
