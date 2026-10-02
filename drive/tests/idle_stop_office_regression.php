<?php
declare(strict_types=1);

if (getenv('ARCADECLOUD_ISOLATED_TEST') !== '1') throw new RuntimeException('Isolated test opt-in required.');
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'ArcadeCloud\\Drive\\';
    if (str_starts_with($class, $prefix)) require_once dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
});

use ArcadeCloud\Drive\Media\MediaProcessingJobRepository;
use ArcadeCloud\Drive\Media\MediaWorkerNodeService;
use ArcadeCloud\Drive\Media\MediaWorkerNodeSessionRepository;
use ArcadeCloud\Drive\Office\OfficeActivityProbe;
use ArcadeCloud\Drive\Office\OfficeDocumentSessionRepository;
use ArcadeCloud\Drive\Office\OfficeSessionLeaseRepository;
use ArcadeCloud\Drive\System\ComputeNodeAdmissionLock;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\FulfilledPromise;

final class Config {
    public static array $calls = [];
    public static ?Closure $onDescribe = null;
    public static function getAwsControlClientConfig(array $options): array {
        return $options + [
            'version' => 'latest', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'],
            'handler' => static function (CommandInterface $command) {
                self::$calls[] = $command->getName();
                if ($command->getName() === 'DescribeInstances') {
                    $callback = self::$onDescribe; self::$onDescribe = null;
                    if ($callback) $callback();
                    return new FulfilledPromise(new Result(['Reservations' => [['Instances' => [[
                        'InstanceId' => 'i-12345678', 'State' => ['Name' => 'running'], 'InstanceType' => 'fixture',
                    ]]]]]));
                }
                if ($command->getName() !== 'StopInstances' || $command['Force'] !== false
                    || $command['InstanceIds'] !== ['i-12345678']) throw new RuntimeException('Unexpected EC2 operation');
                return new FulfilledPromise(new Result([]));
            },
        ];
    }
}
function idleCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "OK: $message\n";
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', 'fixture-only', '', (int)(getenv('TEST_DB_PORT') ?: 3306));
$database = 'idle_regression_' . bin2hex(random_bytes(6));
$db->query("CREATE DATABASE `$database`"); $db->select_db($database);
$dbPeer = new mysqli('127.0.0.1', 'root', 'fixture-only', $database, (int)(getenv('TEST_DB_PORT') ?: 3306));
putenv('ARCADECLOUD_MEDIA_WORKER_INSTANCE_ID=i-12345678');
putenv('ARCADECLOUD_MEDIA_WORKER_REGION=us-east-1');
try {
    $lock = new ComputeNodeAdmissionLock($db);
    $peerLock = new ComputeNodeAdmissionLock($dbPeer);
    $blocked = false;
    $lock->synchronized('i-12345678', static function () use ($peerLock, &$blocked): void {
        try {
            $peerLock->synchronized('i-12345678', static fn(): bool => true, 0);
        } catch (RuntimeException) {
            $blocked = true;
        }
    });
    idleCheck($blocked, 'shared DB mutex excludes concurrent admission/stop sections');

    $jobs = new MediaProcessingJobRepository($db);
    $leases = new OfficeSessionLeaseRepository($db);
    $documents = new OfficeDocumentSessionRepository($db);
    $sessions = new MediaWorkerNodeSessionRepository($db);
    $node = new MediaWorkerNodeService($db);
    $probe = new OfficeActivityProbe($db);
    $sessions->create(0, 'i-12345678', 'us-east-1', 'fixture', null);
    $reset = static function () use ($db): void {
        $db->query('DELETE FROM OfficeSessionLeases');
        $db->query('DELETE FROM OfficeDocumentSessions');
        $db->query('DELETE FROM MediaProcessingJobs');
        $db->query("UPDATE MediaWorkerNodeSessions SET Status='idle',IdleSince=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE),StopRequestedAt=NULL");
        Config::$calls = []; Config::$onDescribe = null;
    };
    $noStop = static fn(): bool => !in_array('StopInstances', Config::$calls, true);
    foreach (['preparing','ready','syncing','conflict'] as $status) {
        $reset();
        $documents->create(2, 1, 'i-12345678', 'Data2/physical.docx', 'Informe.docx');
        $stmt = $db->prepare('UPDATE OfficeDocumentSessions SET Status=?'); $stmt->execute([$status]); $stmt->close();
        $node->handleIdle($jobs);
        idleCheck($noStop() && $sessions->activeForInstance('i-12345678')['idle_since'] === '', "$status Office document cancels idle countdown");
        try { $node->requestIdleStop($jobs); throw new LogicException('Active Office accepted'); }
        catch (RuntimeException $e) { idleCheck(str_contains($e->getMessage(), 'Office') && $noStop(), 'interactive stop protects Office'); }
    }
    $reset(); $leases->claim(2, 'i-12345678', hash('sha256', 'fixture-key')); $node->handleIdle($jobs);
    idleCheck($noStop(), 'live desktop lease blocks stop without a document');

    $reset(); $leases->claim(2, 'i-87654321', hash('sha256', 'other-key'));
    idleCheck(!$probe->hasActiveSessions('i-12345678') && $probe->hasActiveSessions(), 'target scope and aggregate scope stay distinct');
    $node->handleIdle($jobs);
    idleCheck(count(array_filter(Config::$calls, static fn(string $c): bool => $c === 'StopInstances')) === 1, 'other node lease does not prevent valid idle stop');
    $node->handleIdle($jobs);
    idleCheck(count(array_filter(Config::$calls, static fn(string $c): bool => $c === 'StopInstances')) === 1, 'stopping session never emits duplicate stop');

    $reset(); $leases->claim(2, 'i-12345678', hash('sha256', 'expired-key'));
    $db->query('UPDATE OfficeSessionLeases SET ExpiresAt=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)');
    $documents->create(2, 1, 'i-12345678', 'Data2/physical.docx', 'Informe.docx');
    $db->query("UPDATE OfficeDocumentSessions SET Status='closed'");
    $node->requestIdleStop($jobs);
    idleCheck(!$noStop(), 'expired lease and closed document permit interactive idle stop');

    foreach (['queued','running','cancel_requested'] as $status) {
        $reset(); $jobs->enqueue(2, ['id_' => 1, '_key' => 'Data2/video.mp4'], 'extract_mp3', 1, 0, 0);
        $stmt = $db->prepare('UPDATE MediaProcessingJobs SET Status=?'); $stmt->execute([$status]); $stmt->close();
        $node->handleIdle($jobs); idleCheck($noStop(), "$status media remains protected");
    }
    $reset(); $peerBlockedDuringStop = false;
    Config::$onDescribe = static function () use ($peerLock, &$peerBlockedDuringStop): void {
        try {
            $peerLock->synchronized('i-12345678', static fn(): bool => true, 0);
        } catch (RuntimeException) {
            $peerBlockedDuringStop = true;
        }
    };
    $node->handleIdle($jobs);
    idleCheck($peerBlockedDuringStop && !$noStop(), 'idle stop holds the shared admission mutex through AWS stop decision');

    foreach (['office','heartbeat','media'] as $arrival) {
        $reset();
        Config::$onDescribe = static function () use ($arrival, $leases, $sessions, $jobs): void {
            if ($arrival === 'office') $leases->claim(2, 'i-12345678', hash('sha256', 'late-key'));
            elseif ($arrival === 'media') $jobs->enqueue(2, ['id_' => 1, '_key' => 'Data2/video.mp4'], 'extract_mp3', 1, 0, 0);
            else $sessions->clearIdle($sessions->activeForInstance('i-12345678')['session_id']);
        };
        $node->handleIdle($jobs); idleCheck($noStop(), "$arrival arriving during AWS query cancels shutdown");
    }
    $reset(); $db->query('DROP TABLE OfficeDocumentSessions');
    foreach (['handleIdle','requestIdleStop'] as $method) {
        try { $node->$method($jobs); throw new LogicException('Missing Office schema accepted'); }
        catch (RuntimeException $e) { idleCheck($noStop() && str_contains($e->getMessage(), 'bloqueado'), "$method fails closed on unavailable Office schema"); }
    }
} finally {
    if (isset($dbPeer)) $dbPeer->close();
    $db->query("DROP DATABASE `$database`");
}
