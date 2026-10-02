<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
require_once __DIR__ . '/app_bootstrap.php';

$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');
$userId = $session->userId();

try {
    $root = $app->userStorageProvisioner()->ensureRoot($userId);
    // Validation also prevents currentRoute from escaping the authenticated user's root.
    $app->userStoragePath()->normalizeForUser((string)($_GET['currentRoute'] ?? $root), $userId);
    $query = trim(str_replace('\\', '/', (string)($_GET['query'] ?? '')));
    if (strlen($query) > 240 || preg_match('~(?:^|/)\.\.?/|\x00~', $query)) {
        throw new InvalidArgumentException('Consulta no válida.');
    }
    $limit = max(1, min(20, (int)($_GET['limit'] ?? 12)));
    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $rows = $query === '' ? [] : $app->folderRepository()->suggestPrefixes($userId, $rootPrefix, ltrim($query, '/'), $limit);
    $suggestions = [];
    foreach (array_values(array_unique($rows)) as $prefix) {
        $physical = $app->userStoragePath()->normalizeForUser((string)$prefix, $userId);
        $suggestions[] = [
            'route' => $physical,
            'label' => $app->folderQueryService()->displayPathForUser($userId, $physical),
        ];
    }
    echo json_encode($suggestions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    http_response_code(422); echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('[folder suggestions] ' . $error->getMessage());
    http_response_code(500); echo json_encode(['error' => 'No se pudieron consultar las carpetas.']);
}
