<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;

if ((string)($_SERVER['ARCADECLOUD_WORKSTATION_GATE'] ?? '') !== '1') {
    http_response_code(404);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$action = strtolower(trim((string)($_SERVER['HTTP_X_ARCADECLOUD_WORKSTATION_ACTION'] ?? 'status')));
if (!in_array($action, ['status', 'start'], true)) {
    $respond(['ok' => false, 'error' => 'Acción Workstation no permitida.'], 400);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($action === 'status' && $method !== 'GET') {
    $respond(['ok' => false, 'error' => 'Método no permitido.'], 405);
}
if ($action === 'start' && $method !== 'POST') {
    $respond(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

try {
    $helper = new PrivilegedServerHelper();
    if (!$helper->supportsWorkstationControl()) {
        throw new RuntimeException(
            'El helper administrativo del nodo necesita actualizarse para controlar Workstation.'
        );
    }

    $result = match ($action) {
        'start' => $helper->startWorkstation(),
        default => $helper->workstationStatus(),
    };

    $respond(array_merge(['ok' => true], $result));
} catch (Throwable $e) {
    error_log('[Workstation control] ' . $e->getMessage());
    $respond(['ok' => false, 'error' => $e->getMessage()], 503);
}
