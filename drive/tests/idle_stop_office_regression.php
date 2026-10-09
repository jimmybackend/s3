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
    public static ?\DateTimeImmutable $launchOverride = null;
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
                        'LaunchTime' => self::$launchOverride,
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
$officeWorkspace = sys_get_temp_dir() . '/arcadecloud-office-idle-' . bin2hex(random_bytes(6));
if (!mkdir($officeWorkspace . '/sessions', 0770, true) && !is_dir($officeWorkspace . '/sessions')) {
    throw new RuntimeException('Could not create Office fixture workspace.');
}
putenv('ARCADECLOUD_OFFICE_WORKSPACE_ROOT=' . $officeWorkspace);

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . '/' . $item;
        if (is_dir($child) && !is_link($child)) $removeTree($child);
        else @unlink($child);
    }
    @rmdir($path);
};

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
        $db->query("UPDATE MediaWorkerNodeSessions SET Status='idle',IdleSince=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 21 MINUTE),StopRequestedAt=NULL");
        Config::$calls = []; Config::$onDescribe = null; Config::$launchOverride = null;
    };
    $noStop = static fn(): bool => !in_array('StopInstances', Config::$calls, true);
    foreach (['preparing','syncing','conflict'] as $status) {
        $reset();
        $documents->create(2, 1, 'i-12345678', 'Data2/physical.docx', 'Informe.docx');
        $stmt = $db->prepare('UPDATE OfficeDocumentSessions SET Status=?'); $stmt->execute([$status]); $stmt->close();
        $node->handleIdle($jobs);
        idleCheck($noStop() && $sessions->activeForInstance('i-12345678')['idle_since'] === '', "$status Office document cancels idle countdown");
        try { $node->requestIdleStop($jobs); throw new LogicException('Unsafe Office state accepted'); }
        catch (RuntimeException $e) { idleCheck(str_contains($e->getMessage(), 'Office') && $noStop(), 'interactive stop protects unsafe Office states'); }
    }

    $reset();
    $documents->create(2, 1, 'i-12345678', 'Data2/physical.docx', 'Informe.docx');
    $db->query("UPDATE OfficeDocumentSessions SET Status='ready'");
    $node->handleIdle($jobs);
    idleCheck($noStop(), 'ready without verifiable workspace fails closed');

    $reset(); $leases->claim(2, 'i-12345678', hash('sha256', 'fixture-key')); $node->handleIdle($jobs);
    idleCheck(!$noStop(), 'live desktop reservation does not freeze real idle shutdown');

    $reset();
    $documents->create(2, 1, 'i-87654321', 'Data2/physical.docx', 'Otro.docx');
    $db->query("UPDATE OfficeDocumentSessions SET Status='conflict'");
    idleCheck(!$probe->hasActiveSessions('i-12345678') && $probe->hasActiveSessions(), 'target scope and aggregate scope stay distinct for unsafe Office state');
    $node->handleIdle($jobs);
    idleCheck(count(array_filter(Config::$calls, static fn(string $c): bool => $c === 'StopInstances')) === 1, 'unsafe Office state on another node does not prevent valid idle stop');
    $node->handleIdle($jobs);
    idleCheck(count(array_filter(Config::$calls, static fn(string $c): bool => $c === 'StopInstances')) === 1, 'stopping session never emits duplicate stop');

    $reset(); $leases->claim(2, 'i-12345678', hash('sha256', 'expired-key'));
    $db->query('UPDATE OfficeSessionLeases SET ExpiresAt=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)');
    $documents->create(2, 1, 'i-12345678', 'Data2/physical.docx', 'Informe.docx');
    $db->query("UPDATE OfficeDocumentSessions SET Status='closed'");
    $node->requestIdleStop($jobs);
    idleCheck(!$noStop(), 'expired lease and closed document permit interactive idle stop');

    // Sesión preparing sin workspace que quedó abandonada: se marca failed
    // y deja de bloquear el apagado.
    $reset();
    $stalePreparing = $documents->create(
        2, 1, 'i-12345678', 'Data2/physical.docx', 'Preparando.docx'
    );
    $stalePreparingId = $stalePreparing['session_id'];
    $stmt = $db->prepare(
        "UPDATE OfficeDocumentSessions
         SET UpdatedAt=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 16 MINUTE)
         WHERE SessionId=?"
    );
    $stmt->bind_param('s', $stalePreparingId);
    $stmt->execute();
    $stmt->close();
    $node->handleIdle($jobs);
    $row = $db->query(
        "SELECT Status FROM OfficeDocumentSessions WHERE SessionId='"
        . $db->real_escape_string($stalePreparingId) . "'"
    )->fetch_assoc();
    idleCheck(
        ($row['Status'] ?? '') === 'failed' && !$noStop(),
        'stale preparing session is reconciled and no longer blocks shutdown'
    );

    $makeReadyFixture = static function (
        string $name,
        bool $managed,
        bool $active,
        bool $changeAfterSync
    ) use ($documents, $db, $officeWorkspace): string {
        $created = $documents->create(2, 1, 'i-12345678', 'Data2/physical.docx', $name);
        $sessionId = $created['session_id'];
        $directory = $officeWorkspace . '/sessions/' . $sessionId;
        if (!mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create document fixture directory.');
        }
        $path = $directory . '/' . $name;
        file_put_contents($path, 'synced-baseline');
        clearstatcache(true, $path);
        $mtime = (int)filemtime($path);
        $size = (int)filesize($path);
        $documents->markPrepared(
            $sessionId,
            'sessions/' . $sessionId . '/' . $name,
            'fixture-etag',
            $mtime,
            $size
        );
        file_put_contents($directory . '/.arcadecloud-office-synced-sha256', hash_file('sha256', $path));
        if ($managed) file_put_contents($directory . '/.arcadecloud-office-managed', '');
        if ($active) file_put_contents($directory . '/.arcadecloud-office-active', '');
        if ($changeAfterSync) {
            file_put_contents($path, '-local-change', FILE_APPEND);
            clearstatcache(true, $path);
        }
        $stmt = $db->prepare(
            "UPDATE OfficeDocumentSessions
             SET UpdatedAt=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 4 MINUTE)
             WHERE SessionId=?"
        );
        $stmt->bind_param('s', $sessionId);
        $stmt->execute();
        $stmt->close();
        return $sessionId;
    };

    // Nueva sesión monitorizada, proceso terminado y bytes idénticos al último sync:
    // se puede cerrar de forma demostrable.
    $reset();
    $safeReadyId = $makeReadyFixture('Seguro.docx', true, false, false);
    $node->handleIdle($jobs);
    $row = $db->query(
        "SELECT Status FROM OfficeDocumentSessions WHERE SessionId='"
        . $db->real_escape_string($safeReadyId) . "'"
    )->fetch_assoc();
    idleCheck(
        ($row['Status'] ?? '') === 'closed' && !$noStop(),
        'managed synced ready session is safely reconciled after process exit'
    );

    // Si los bytes del workspace cambiaron después del último sync, jamás se cierra.
    $reset();
    $changedReadyId = $makeReadyFixture('Cambios.docx', true, false, true);
    $node->handleIdle($jobs);
    $row = $db->query(
        "SELECT Status FROM OfficeDocumentSessions WHERE SessionId='"
        . $db->real_escape_string($changedReadyId) . "'"
    )->fetch_assoc();
    idleCheck(
        ($row['Status'] ?? '') === 'ready' && $noStop(),
        'unsynced ready workspace blocks shutdown without closing the session'
    );

    $reset();
    $sameMetadataId = $makeReadyFixture('Same.docx', true, false, false);
    $samePath = $officeWorkspace . '/sessions/' . $sameMetadataId . '/Same.docx';
    $mtime = filemtime($samePath);
    file_put_contents($samePath, 'SYNCED-baseline');
    touch($samePath, $mtime);
    $node->handleIdle($jobs);
    idleCheck($noStop(), 'same size and timestamp changes block shutdown by digest');

    // Las sesiones anteriores al monitor no aportan prueba de cierre del proceso.
    $reset();
    $legacyReadyId = $makeReadyFixture('Legado.docx', false, false, false);
    $node->handleIdle($jobs);
    $row = $db->query(
        "SELECT Status FROM OfficeDocumentSessions WHERE SessionId='"
        . $db->real_escape_string($legacyReadyId) . "'"
    )->fetch_assoc();
    idleCheck(
        ($row['Status'] ?? '') === 'ready' && $noStop(),
        'legacy ready session fails closed without proof of safe workspace'
    );

    // Un heartbeat de proceso reciente también mantiene protegido el documento.
    $reset();
    $liveReadyId = $makeReadyFixture('Vivo.docx', true, true, false);
    $node->handleIdle($jobs);
    $row = $db->query(
        "SELECT Status FROM OfficeDocumentSessions WHERE SessionId='"
        . $db->real_escape_string($liveReadyId) . "'"
    )->fetch_assoc();
    idleCheck(
        ($row['Status'] ?? '') === 'ready' && !$noStop(),
        'live LibreOffice process alone does not impersonate keyboard or pointer activity'
    );

    $reset();
    $ffmpegFixture = $officeWorkspace . '/ffmpeg';
    symlink('/bin/sleep', $ffmpegFixture);
    $process = proc_open([$ffmpegFixture, '30'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start process fixture.');
    try {
        $pid = proc_get_status($process)['pid'];
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if (trim((string)@file_get_contents('/proc/' . $pid . '/comm')) === 'ffmpeg') break;
            usleep(10000);
        }
        putenv('ARCADECLOUD_NODE_ROLE=combined');
        $node->handleIdle($jobs);
        idleCheck($noStop(), 'local FFmpeg process blocks automatic shutdown even outside the job queue');
    } finally {
        putenv('ARCADECLOUD_NODE_ROLE');
        proc_terminate($process);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
        unlink($ffmpegFixture);
    }

    foreach (['queued','running','cancel_requested'] as $status) {
        $reset(); $jobs->enqueue(2, ['id_' => 1, '_key' => 'Data2/video.mp4'], 'extract_mp3', 1, 0, 0);
        $stmt = $db->prepare('UPDATE MediaProcessingJobs SET Status=?'); $stmt->execute([$status]); $stmt->close();
        $node->handleIdle($jobs); idleCheck($noStop(), "$status media remains protected");
    }
    foreach (['heartbeat','media','office'] as $arrival) {
        $reset();
        Config::$onDescribe = static function () use ($arrival, $sessions, $jobs, $makeReadyFixture): void {
            if ($arrival === 'media') $jobs->enqueue(2, ['id_' => 1, '_key' => 'Data2/video.mp4'], 'extract_mp3', 1, 0, 0);
            elseif ($arrival === 'office') $makeReadyFixture('Late.docx', true, false, true);
            else $sessions->clearIdle($sessions->activeForInstance('i-12345678')['session_id']);
        };
        $node->handleIdle($jobs); idleCheck($noStop(), "$arrival arriving during AWS query cancels shutdown");
    }

    $reset();
    Config::$onDescribe = static function () use ($leases): void {
        $leases->claim(2, 'i-12345678', hash('sha256', 'late-key'));
    };
    $node->handleIdle($jobs);
    idleCheck(!$noStop(), 'late desktop reservation does not impersonate real input during shutdown');
    // The final database claim must reject a heartbeat after the last read,
    // concurrent stoppers, and work queued immediately before the claim.
    $reset();
    $before = $sessions->activeForInstance('i-12345678');
    $sessions->clearIdle($before['session_id']);
    idleCheck(!$sessions->claimIdleStop($before['session_id'], $before['idle_since'], 1230), 'atomic stop claim rejects late heartbeat');
    $reset();
    $before = $sessions->activeForInstance('i-12345678');
    $jobs->enqueue(2, ['id_' => 1, '_key' => 'Data2/video.mp4'], 'extract_mp3', 1, 0, 0);
    idleCheck(!$sessions->claimIdleStop($before['session_id'], $before['idle_since'], 1230), 'atomic stop claim rejects queued work');
    $reset();
    $before = $sessions->activeForInstance('i-12345678');
    idleCheck($sessions->claimIdleStop($before['session_id'], $before['idle_since'], 1230), 'first idle stop claim succeeds');
    idleCheck(!$sessions->claimIdleStop($before['session_id'], $before['idle_since'], 1230), 'second concurrent idle stop claim fails');
    $node->touchInteractiveActivity(2);
    idleCheck($sessions->activeForInstance('i-12345678')['status'] === 'stopping', 'late activity cannot resurrect an in-flight stopping session');
    $reset();
    $db->query("UPDATE MediaWorkerNodeSessions SET IdleSince=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1210 SECOND)");
    $before = $sessions->activeForInstance('i-12345678');
    idleCheck(!$sessions->claimIdleStop($before['session_id'], $before['idle_since'], 1230), 'automatic stop waits for complete warning interval');
    // The workstation systemd unit keeps a foreground docker run process.
    // It must NOT freeze 20-minute idle shutdown even when XFCE is open.
    $dockerClassifier = new \ReflectionMethod(MediaWorkerNodeService::class, 'isPersistentWorkstationDockerClient');
    idleCheck($dockerClassifier->invoke(null, "/usr/bin/docker\0run\0--rm\0--name\0arcadecloud-workstation\0") === true,
        'long-lived Docker CLI of XFCE does not block inactivity');
    idleCheck($dockerClassifier->invoke(null, "/usr/bin/docker\0run\0--name=arcadecloud-workstation\0") === true,
        'workstation name assignment style is recognized');
    idleCheck($dockerClassifier->invoke(null, "/usr/bin/docker\0build\0-t\0unrelated\0") === false,
        'real Docker build remains an auto-shutdown blocker');
    idleCheck($dockerClassifier->invoke(null, "/usr/bin/docker\0run\0--name\0other-container\0") === false,
        'non-workstation Docker jobs remain protected');

    // AWS Console/GitHub may start the compute EC2 without creating a DB
    // session. Only the physical node (IMDS verified + media-worker) may
    // initialize an idle timer. A remote web gateway must not invent a session.
    $reset();
    $db->query("UPDATE MediaWorkerNodeSessions SET Status='stopped',IdleSince=NULL WHERE InstanceId='i-12345678'");
    $gateway = new MediaWorkerNodeService($db, static fn(): array => [
        'instance_id' => 'i-12345678', 'region' => 'us-east-1'
    ]);
    $gateway->handleIdle($jobs);
    idleCheck($sessions->activeForInstance('i-12345678') === null, 'web gateway never creates missing idle sessions');

    putenv('ARCADECLOUD_MEDIA_WORKER=1');
    try {
        $wrongMachine = new MediaWorkerNodeService($db, static fn(): array => [
            'instance_id' => 'i-87654321', 'region' => 'us-east-1'
        ]);
        $wrongMachine->handleIdle($jobs);
        idleCheck($sessions->activeForInstance('i-12345678') === null, 'wrong physical EC2 identity cannot bootstrap idle');

        $physical = new MediaWorkerNodeService($db, static fn(): array => [
            'instance_id' => 'i-12345678', 'region' => 'us-east-1'
        ]);
        $physical->handleIdle($jobs);
        $recovered = $sessions->activeForInstance('i-12345678');
        idleCheck($recovered !== null && $recovered['status'] === 'idle'
            && $recovered['idle_since'] !== '', 'external boot recovers real 20-minute idle session');
        $physical->handleIdle($jobs);
        idleCheck($sessions->activeForInstance('i-12345678')['session_id'] === $recovered['session_id'],
            'repeated idle polling does not create duplicate sessions');

        // An earlier "stopping" session may belong to the prior boot.
        // Only a demonstrably newer EC2 launch permits closing/recreating it.
        $db->query("UPDATE MediaWorkerNodeSessions SET Status='stopping',"
            . " StopRequestedAt=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 120 SECOND)"
            . " WHERE SessionId='" . $db->real_escape_string($recovered['session_id']) . "'");
        $physical->handleIdle($jobs);
        idleCheck($sessions->activeForInstance('i-12345678')['session_id'] === $recovered['session_id'],
            'unknown launch time does not resurrect stopping session');
        Config::$launchOverride = new \DateTimeImmutable('+60 seconds');
        $physical->handleIdle($jobs);
        $afterBoot = $sessions->activeForInstance('i-12345678');
        idleCheck($afterBoot !== null && $afterBoot['session_id'] !== $recovered['session_id']
            && $afterBoot['status'] === 'idle',
            'verified new EC2 boot reconciles prior stopping session and re-arms idle');
    } finally {
        putenv('ARCADECLOUD_MEDIA_WORKER');
        Config::$launchOverride = null;
    }

    $reset(); $db->query('DROP TABLE OfficeDocumentSessions');
    foreach (['handleIdle','requestIdleStop'] as $method) {
        try { $node->$method($jobs); throw new LogicException('Missing Office schema accepted'); }
        catch (RuntimeException $e) { idleCheck($noStop() && str_contains($e->getMessage(), 'bloqueado'), "$method fails closed on unavailable Office schema"); }
    }
} finally {
    if (isset($dbPeer)) $dbPeer->close();
    $db->query("DROP DATABASE `$database`");
    $removeTree($officeWorkspace);
    putenv('ARCADECLOUD_OFFICE_WORKSPACE_ROOT');
}
