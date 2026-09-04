<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
require_once $root . '/app_bootstrap.php';
require_once $root . '/upload/core/UploaderInterface.php';
require_once $root . '/upload/core/UploadResponse.php';
require_once $root . '/upload/UploadFactory.php';

$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
$session = $app->session();
$session->start();

if (!$session->isAuthenticated() || $session->userId() <= 0) {
    UploadResponse::fail('Sesión inválida.', 401);
}

$action = trim((string) ($_REQUEST['action'] ?? ''));
$mode = trim((string) ($_REQUEST['mode'] ?? ''));
if ($action === '' || $mode === '') {
    UploadResponse::fail('Faltan parámetros action/mode', 400);
}

try {
    $req = array_merge($_GET, $_POST);
    $req['_files'] = $_FILES;
    $req['_user_id'] = $session->userId();
    $req['_usuario'] = $session->userName();

    if ($action === 'init') {
        $requestedRoute = trim((string) ($req['ruta_objetivo'] ?? ''));
        if ($requestedRoute === '') {
            UploadResponse::fail('Falta ruta_objetivo. La subida debe fijar su destino al iniciar.', 422);
        }
        $req['ruta_objetivo'] = $app->uploadDestinationService()->resolve(
            $session->userId(),
            $requestedRoute
        );
    }

    $uploader = UploadFactory::make($mode);

    // local_put usa la sesión unos milisegundos para guardar/consumir el intent.
    // Los demás modos pueden tardar mucho: liberamos el lock de sesión antes de S3.
    $keepSessionOpen = ($mode === 'local_put' && in_array($action, ['init', 'complete'], true));
    if (!$keepSessionOpen && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($action === 'init') {
        $result = $uploader->init($req);
    } elseif ($action === 'part') {
        $result = $uploader->part($req);
    } elseif ($action === 'complete') {
        $result = $uploader->complete($req);
    } else {
        UploadResponse::fail('Acción inválida', 400);
    }

    if ($keepSessionOpen && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    UploadResponse::ok($result);
} catch (Throwable $e) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    UploadResponse::fail($e->getMessage(), 500);
}
