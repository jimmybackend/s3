<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Admin\FastDriveControlService;
use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\Request;
use Aws\Exception\AwsException;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$app = ApplicationKernel::app();
$request = Request::fromGlobals();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || !$session->isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acceso reservado al superadmin.']);
    exit;
}
if ($request->method() !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$csrf = (string)$session->get('fastdrive_control_csrf', '');
$postedCsrf = $request->postRawString('csrf');
if ($csrf === '' || $postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido. Recarga ArcadeCloud OS.']);
    exit;
}

if ($request->postString('action') !== 'force-stop') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Acción no permitida.']);
    exit;
}

try {
    $result = (new FastDriveControlService($app))->forceStop(
        $request->postRawString('current_password')
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (AwsException $e) {
    error_log('[FastDrive power] AWS force-stop error: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => ($e->getAwsErrorCode() ?: 'AWS') . ': '
            . ($e->getAwsErrorMessage() ?: 'No se pudo apagar FastDrive.'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[FastDrive power] force-stop error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
