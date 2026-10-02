<?php
declare(strict_types=1);

// Isolated CI database only. Never source the application's production bootstrap/config.
if (getenv('ARCADECLOUD_ISOLATED_TEST') !== '1') throw new RuntimeException('Isolated test opt-in required.');
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'ArcadeCloud\\Drive\\';
    if (str_starts_with($class, $prefix)) require_once dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
});

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Office\OfficeDocumentSessionRepository;
use ArcadeCloud\Drive\Office\OfficeDocumentStorageService;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;

final class Config {
    public static S3Client $client;
    public static function getS3(): S3Client { return self::$client; }
    public static function getBucket(): string { return 'fixture-office'; }
}
function officeCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', 'fixture-only', '', (int)(getenv('TEST_DB_PORT') ?: 3306));
$testDatabase = 'office_regression_' . bin2hex(random_bytes(6));
$db->query("CREATE DATABASE `$testDatabase`");
$db->select_db($testDatabase);
$db->set_charset('utf8mb4');
$workspace = sys_get_temp_dir() . '/' . $testDatabase;
mkdir($workspace . '/sessions', 0700, true);
putenv('ARCADECLOUD_OFFICE_WORKSPACE_ROOT=' . $workspace);
$calls = [];
$mode = 'success';
$originalKey = '';
Config::$client = new S3Client([
    'version' => 'latest', 'region' => 'us-east-1', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'],
    'handler' => static function (CommandInterface $command) use (&$calls, &$mode, &$originalKey) {
        $name = $command->getName();
        $calls[] = [$name, $command->toArray()];
        if ($name === 'HeadObject') return new FulfilledPromise(new Result(['ETag' => $mode === 'head_conflict' ? '"other"' : '"expected"', 'ContentType' => 'application/test', 'Metadata' => ['fixture' => 'preserved']]));
        if ($name === 'GetObject') {
            officeCheck($command['IfMatch'] === '"expected"', 'prepare pins GET to the ETag observed by HEAD');
            return new RejectedPromise(new AwsException('fixture', $command, ['code' => 'PreconditionFailed', 'response' => new Response(412)]));
        }
        if ($name !== 'PutObject') throw new RuntimeException('Unexpected S3 command: ' . $name);
        if ($command['Key'] === $originalKey) {
            officeCheck($command['IfMatch'] === '"expected"', 'original PUT uses the expected ETag');
            officeCheck($command['Metadata']['fixture'] === 'preserved', 'original metadata is preserved');
            if (in_array($mode, ['race412','race409','race404','denied'], true)) {
                $codes = ['race412' => ['PreconditionFailed',412], 'race409' => ['ConditionalRequestConflict',409], 'race404' => ['NoSuchKey',404], 'denied' => ['AccessDenied',403]];
                [$code,$status] = $codes[$mode];
                return new RejectedPromise(new AwsException('fixture', $command, ['code' => $code, 'response' => new Response($status)]));
            }
            return new FulfilledPromise(new Result(['ETag' => '"our-write"']));
        }
        officeCheck(str_starts_with($command['Key'], 'Data2/d_fixture/'), 'conflict retains the physical destination');
        return new FulfilledPromise(new Result(['ETag' => '"conflict-write"']));
    },
]);
try {
    $schema = (string)file_get_contents(dirname(__DIR__, 2) . '/adbbmis1_Cloud.sql');
    if (!preg_match('/CREATE TABLE IF NOT EXISTS `FileS3` \(.*?;\s/s', $schema, $match)) throw new RuntimeException('Canonical FileS3 schema not found');
    $db->query($match[0]); // Only canonical CREATE; never execute the production dump.
    $app = DriveApplication::boot($db);
    $sessions = new OfficeDocumentSessionRepository($db);
    $service = new OfficeDocumentStorageService($app);
    $fixture = static function () use ($db, $sessions, $workspace, &$originalKey): array {
        $name = 'Informe.docx'; $physical = 'f_' . bin2hex(random_bytes(16)) . '-Informe.docx';
        $originalKey = 'Data2/d_fixture/' . $physical;
        $stmt = $db->prepare("INSERT INTO FileS3 (Nombre,Encriptado,Tamano,Ruta,Found,user_id_) VALUES (?,?,3,'Data2/d_fixture/',1,2)");
        $stmt->bind_param('ss', $name, $physical); $stmt->execute(); $id = $stmt->insert_id; $stmt->close();
        $session = $sessions->create(2, $id, 'i-12345678', $originalKey, $name);
        $relative = 'sessions/' . $session['session_id'] . '/' . $name;
        mkdir(dirname($workspace . '/' . $relative), 0700);
        file_put_contents($workspace . '/' . $relative, 'edited-content');
        $sessions->markPrepared($session['session_id'], $relative, 'expected', time() - 60, 3);
        return $session + ['file_id' => $id, 'path' => $workspace . '/' . $relative];
    };
    foreach (['success','head_conflict','race412','race409','race404'] as $mode) {
        $f = $fixture(); $calls = [];
        $result = $service->sync($f['session_id'], $f['control_token']);
        $state = $sessions->requireAuthorized($f['session_id'], $f['control_token']);
        $conflict = $mode !== 'success';
        officeCheck(($result['conflict'] ?? false) === $conflict, "$mode selects the correct save path");
        officeCheck($state['expected_etag'] === ($conflict ? 'conflict-write' : 'our-write'), 'session adopts the ETag returned by its own PUT');
        officeCheck(count(array_filter($calls, static fn(array $call): bool => $call[0] === 'HeadObject')) === 1, 'no post-write HEAD can adopt another writer');
        $row = $app->fileRecordRepository()->requireByRef(2, (int)$state['file_id'], true);
        officeCheck((int)$row['Tamano'] === strlen('edited-content'), 'catalog size is updated by the actual service');
        if ($conflict) {
            $original = $app->fileRecordRepository()->requireByRef(2, $f['file_id'], true);
            officeCheck((int)$original['Tamano'] === 3 && $state['file_id'] !== $f['file_id'], 'conflict creates a separate catalog entry and preserves original');
        }
    }
    $mode = 'denied'; $f = $fixture(); $calls = [];
    try { $service->sync($f['session_id'], $f['control_token'], true); throw new RuntimeException('Denied write accepted'); }
    catch (AwsException $e) { officeCheck($e->getAwsErrorCode() === 'AccessDenied', 'permission failure is propagated without pretending to save'); }
    officeCheck(count(array_filter($calls, static fn(array $c): bool => $c[0] === 'PutObject')) === 1, 'permission failure does not trigger a conflict upload');
    officeCheck($sessions->requireAuthorized($f['session_id'], $f['control_token'])['status'] !== 'closed', 'failed write never closes workspace');
    $mode = 'success'; $calls = [];
    try { $service->sync($f['session_id'], str_repeat('0',64)); throw new RuntimeException('Unauthorized token accepted'); }
    catch (RuntimeException $e) { officeCheck(str_contains($e->getMessage(), 'autorizada') && $calls === [], 'invalid control token is rejected before S3'); }
    $f = $fixture(); $calls = [];
    $result = $service->sync($f['session_id'], $f['control_token'], true);
    officeCheck($result['closed'] === true && $result['etag'] === 'our-write', 'successful close saves before closing');

    $f = $fixture(); $calls = [];
    try { $service->prepare($f['session_id'], $f['control_token']); throw new RuntimeException('Changed download accepted'); }
    catch (AwsException $e) { officeCheck($e->getAwsErrorCode() === 'PreconditionFailed', 'prepare aborts when object changes during download'); }
    officeCheck(file_get_contents($f['path']) === 'edited-content', 'failed prepare does not replace workspace');
} finally {
    foreach (glob($workspace . '/sessions/*/*') ?: [] as $file) unlink($file);
    foreach (glob($workspace . '/sessions/*') ?: [] as $dir) rmdir($dir);
    rmdir($workspace . '/sessions'); rmdir($workspace);
    $db->query("DROP DATABASE `$testDatabase`");
}
