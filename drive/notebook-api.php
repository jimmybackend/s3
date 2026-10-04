<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Core\ApplicationKernel;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Activity\ActivityCostRecorder;
use ArcadeCloud\Drive\Notebook\NotebookAiImproveService;
use ArcadeCloud\Drive\Notebook\NotebookService;

require_once __DIR__ . '/app_bootstrap.php';

$app = ApplicationKernel::app();
$session = $app->session();
$session->start();

try {
    $session->requireAuthenticated('index.php');
    $userId = $session->userId();
    if ($userId <= 0) throw new RuntimeException('Sesión inválida.');

    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'POST') {
        $expected = (string)$session->get('upload_csrf', '');
        $sent = trim((string)($_SERVER['HTTP_X_DRIVE_CSRF'] ?? ''));
        if ($expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            JsonResponse::error('Token CSRF inválido.', 403);
        }
    }

    $payload = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        $payload = is_array($decoded) ? $decoded : $_POST;
    }

    $service = new NotebookService($app);
    $aiImprove = new NotebookAiImproveService(
        $app,
        \Config::getBedrockRuntime(),
        ActivityCostRecorder::fromDatabase($app->db())
    );
    $result = match ($action) {
        'list' => $service->listNotebooks($userId),
        'create' => $service->createNotebook($userId, $payload),
        'save_page' => $service->savePage($userId, $payload, $session->userName()),
        'recognize_line' => $service->recognizeLine($userId, $payload),
        'improve_ai' => $aiImprove->improve($userId, $payload),
        'load_page' => $service->loadPage(
            $userId,
            (string)($_GET['notebook'] ?? ''),
            max(1, (int)($_GET['page'] ?? 1))
        ),
        'move_page' => $service->movePage(
            $userId,
            (string)($payload['notebook'] ?? ''),
            max(1, (int)($payload['page'] ?? 1)),
            (string)($payload['target'] ?? '')
        ),
        default => throw new RuntimeException('Acción Notebook no válida.'),
    };

    JsonResponse::send(['ok' => true] + $result);
} catch (Throwable $error) {
    JsonResponse::send(['ok' => false, 'error' => $error->getMessage()], 400);
}
