<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Office\OfficeLaunchTokenRepository;
use ArcadeCloud\Drive\Office\OfficeSchemaMigrationService;
use ArcadeCloud\Drive\View\FileViewHelper;

$app = ApplicationKernel::app();
$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');

$userId = $session->userId();
if ($userId <= 0) {
    http_response_code(403);
    exit('No se pudo identificar al usuario actual.');
}

// El launcher sólo acepta el gateway dedicado de Office. No redirigir
// tokens de acceso a FastDrive ni a otros dominios por variables heredadas
// del nodo. Mantener el intercambio autenticado por token de un solo uso.
$officeUrl = 'https://office.esforzados.com/';
$configuredOfficeUrl = trim((string)(getenv('ARCADECLOUD_OFFICE_URL') ?: ''));
if ($configuredOfficeUrl !== '' && rtrim($configuredOfficeUrl, '/') !== rtrim($officeUrl, '/')) {
    error_log('[Office launch] ARCADECLOUD_OFFICE_URL no coincide con el gateway Office; se usa el dominio dedicado.');
}

try {
    (new OfficeSchemaMigrationService($app->db()))->ensure();
    $repo = new OfficeLaunchTokenRepository($app->db());
} catch (Throwable $e) {
    error_log('[Office launch] schema/token repository error: ' . $e->getMessage());
    http_response_code(503);
    exit('No se pudo preparar el esquema de ArcadeCloud Office. Revisa la base de datos.');
}

$fileId = max(0, (int)($_GET['file_id'] ?? 0));
$target = strtolower(trim((string)($_GET['target'] ?? 'novnc')));
if (!in_array($target, ['novnc', 'guacamole', 'kde'], true)) {
    $target = 'novnc';
}
// KDE se ejecuta por noVNC en Workstation; nunca debe abrir Guacamole RDP.
// El selector de escritorio está en ARCADECLOUD_DESKTOP=plasma.
if ($target === 'kde') {
    $target = 'novnc';
}

if ($fileId > 0) {
    try {
        $file = $app->fileRecordRepository()->requireByRef($userId, $fileId, true);
        if (FileViewHelper::isLocked($file)) {
            throw new RuntimeException('Desbloquea el archivo antes de abrirlo con Office.');
        }

        $extension = FileViewHelper::extension((string)($file['Nombre'] ?? ''));
        $allowed = ['doc','docx','odt','rtf','xls','xlsx','ods','ppt','pptx','odp'];
        if (!in_array($extension, $allowed, true)) {
            throw new RuntimeException('Este tipo de archivo no se puede abrir con ArcadeCloud Office.');
        }
    } catch (Throwable $e) {
        http_response_code(400);
        exit(htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}

try {
    $token = $fileId > 0
        ? $repo->issueForFile($userId, $fileId, 120)
        : $repo->issue($userId, 120);
} catch (Throwable $e) {
    error_log('[Office launch] token issue error: ' . $e->getMessage());
    http_response_code(503);
    exit('No se pudo crear el enlace temporal de Office. Inténtalo nuevamente.');
}

$separator = str_contains($officeUrl, '?') ? '&' : '?';
$destination = $officeUrl . $separator . 'launch=' . rawurlencode($token) . '&target=' . rawurlencode($target);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// Los accesos KDE/Guacamole del OS pueden pedir el destino autenticado
// sin mostrar una pestaña intermedia de FastDrive. Conservamos el 302
// original para usuarios sin JavaScript, archivos Office y reautenticación.
if ((string)($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => true, 'url' => $destination], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
header('Location: ' . $destination, true, 302);
exit;
