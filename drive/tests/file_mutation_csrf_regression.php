<?php
declare(strict_types=1);

// Executes real controllers in child processes because JsonResponse exits.
// No application bootstrap, DB connection, credentials or AWS calls are used.
if (getenv('ARCADECLOUD_ISOLATED_TEST') !== '1') throw new RuntimeException('Isolated test opt-in required.');
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'ArcadeCloud\\Drive\\';
    if (str_starts_with($class, $prefix)) require_once dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
});
use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\Controller\AbstractJsonController;
use ArcadeCloud\Drive\Http\Controller\FileMutationController;
use ArcadeCloud\Drive\Http\Controller\FolderMutationController;
use ArcadeCloud\Drive\Http\Controller\MoveJobController;
use ArcadeCloud\Drive\Http\Controller\BackgroundTaskController;
use ArcadeCloud\Drive\Http\Controller\FileSecurityController;
use ArcadeCloud\Drive\Http\Controller\FileKeyRotationController;
use ArcadeCloud\Drive\Http\Controller\SyncController;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use Aws\S3\S3Client;

final class Config {
    public static function getS3(): S3Client {
        return new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'credentials' => ['key' => 'fixture', 'secret' => 'fixture'],
            'handler' => static function () { throw new LogicException('Unexpected AWS request'); }]);
    }
    public static function getBucket(): string { return 'fixture'; }
}
final class CsrfGuardFixture extends AbstractJsonController {
    public function run(): never {
        $this->requirePost(); $this->guardAuthenticated(); $this->requireDriveCsrf();
        JsonResponse::send(['ok' => true, 'accepted' => true]);
        exit;
    }
}

if (($argv[1] ?? '') === '--case') {
    $case = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
    $app = DriveApplication::boot(new mysqli()); // Deliberately not connected.
    $app->session()->start();
    $_SESSION = ['usuario' => 'fixture', 'user_id' => 2, 'upload_csrf' => 'fixture-token'];
    if (isset($case['session'])) $_SESSION = $case['session'];
    register_shutdown_function(static function (): void {
        fwrite(STDERR, 'STATUS=' . http_response_code());
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    });
    $request = new Request($case['server'] + ['REQUEST_METHOD' => 'POST'], $case['query'] ?? [], $case['post'] ?? []);
    $controller = new ($case['class'])($app, $request);
    $controller->{$case['method']}();
    throw new LogicException('Controller did not terminate');
}

function csrfCase(string $class, string $method, array $case, int $status): void {
    $case += ['class' => $class, 'method' => $method, 'server' => []];
    $process = proc_open([PHP_BINARY, __FILE__, '--case', json_encode($case, JSON_THROW_ON_ERROR)],
        [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch isolated fixture');
    fclose($pipes[0]); $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]); $code = proc_close($process);
    $response = json_decode($body, true);
    if ($code !== 0 || !is_array($response) || !str_contains($stderr, 'STATUS=' . $status)) {
        throw new RuntimeException("Unexpected controller response for $class::$method: $body $stderr");
    }
    if ($status === 403 && (($response['ok'] ?? true) !== false || !str_contains($response['error'] ?? '', 'CSRF'))) throw new RuntimeException('CSRF rejection missing');
    if ($status === 200 && ($response['accepted'] ?? false) !== true) throw new RuntimeException('Valid token rejected');
    if (str_contains($body, 'fixture-token')) throw new RuntimeException('Token leaked in response');
}
foreach ([FileMutationController::class => ['deleteOne','deleteMany','move','moveMany','rename'],
    FolderMutationController::class => ['create','delete','move','rename'],
    MoveJobController::class => ['start'], BackgroundTaskController::class => ['action'],
    FileSecurityController::class => ['setMode','unlock','relock'],
    FileKeyRotationController::class => ['rotate'], SyncController::class => ['run']] as $class => $methods) {
    foreach ($methods as $method) {
        foreach ([[], ['server' => ['HTTP_X_DRIVE_CSRF' => 'wrong']], ['post' => ['upload_csrf' => ['fixture-token']]],
            ['query' => ['upload_csrf' => 'fixture-token']], ['session' => ['usuario' => 'fixture','user_id' => 2]]] as $case) {
            csrfCase($class, $method, $case, 403);
        }
        csrfCase($class, $method, ['session' => []], 401);
        csrfCase($class, $method, ['server' => ['REQUEST_METHOD' => 'GET']], 405);
        echo "OK: $class::$method rejects missing/wrong/array/query/empty-session tokens before storage; authentication/method enforced\n";
    }
}
csrfCase(CsrfGuardFixture::class, 'run', ['server' => ['HTTP_X_DRIVE_CSRF' => 'fixture-token']], 200);
csrfCase(CsrfGuardFixture::class, 'run', ['post' => ['upload_csrf' => 'fixture-token']], 200);
csrfCase(CsrfGuardFixture::class, 'run', ['server' => ['HTTP_X_DRIVE_CSRF' => 'wrong'], 'post' => ['upload_csrf' => 'fixture-token']], 403);
echo "OK: header and classic form token accepted; bad header cannot be hidden by form fallback\n";
