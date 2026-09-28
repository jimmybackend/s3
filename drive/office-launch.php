<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Office\OfficeLaunchTokenRepository;
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

$officeUrl = trim((string)(getenv('ARCADECLOUD_OFFICE_URL') ?: 'https://office.esforzados.com/'));
$parts = parse_url($officeUrl);
if (
    !is_array($parts)
    || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
    || trim((string)($parts['host'] ?? '')) === ''
) {
    http_response_code(503);
    exit('La URL de Office no está configurada correctamente.');
}

$repo = new OfficeLaunchTokenRepository($app->db());
$fileId = max(0, (int)($_GET['file_id'] ?? 0));

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

        $token = $repo->issueForFile($userId, $fileId, 120);
    } catch (Throwable $e) {
        http_response_code(400);
        exit(htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
} else {
    $token = $repo->issue($userId, 120);
}

$separator = str_contains($officeUrl, '?') ? '&' : '?';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $officeUrl . $separator . 'launch=' . rawurlencode($token), true, 302);
exit;
