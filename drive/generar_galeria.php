<?php
declare(strict_types=1);

use ArcadeCloud\Drive\View\FileViewHelper;

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_bootstrap.php';

try {
    $app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
    $session = $app->session();
    $session->start();
    if (!$session->isAuthenticated() || $session->userId() <= 0) {
        http_response_code(401);
        echo json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = $session->userId();
    $requestedRoute = trim((string)($_GET['ruta'] ?? $_SESSION['ruta_actual'] ?? ''));
    $route = $app->userStoragePath()->normalizeForUser($requestedRoute, $userId);
    $_SESSION['ruta_actual'] = $route;

    $width = max(64, min(512, (int)($_GET['w'] ?? 384)));
    $height = max(64, min(512, (int)($_GET['h'] ?? 216)));

    $sql = "SELECT Nombre, Encriptado, Ruta, AccessType, PasswordHash
            FROM FileS3
            WHERE user_id_ = ?
              AND Ruta = ?
              AND Found = 1
              AND LOWER(SUBSTRING_INDEX(Nombre, '.', -1)) IN
                  ('jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff')
            ORDER BY Fecha DESC
            LIMIT 1000";

    $stmt = $app->db()->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la galería: ' . $app->db()->error);
    }
    $stmt->bind_param('is', $userId, $route);
    $stmt->execute();
    $result = $stmt->get_result();

    $out = [];
    while ($row = $result->fetch_assoc()) {
        // Conserva la misma regla del bloque: un archivo en estado secure no se previsualiza.
        if (FileViewHelper::isLocked($row)) {
            continue;
        }

        $key = FileViewHelper::buildS3Key(
            (string)($row['Ruta'] ?? ''),
            (string)($row['Encriptado'] ?? '')
        );
        if ($key === '') {
            continue;
        }

        $out[] = [
            'key' => $key,
            'nombre' => (string)($row['Nombre'] ?? basename($key)),
            'original' => 'ver_archivo.php?archivo=' . rawurlencode($key),
            'thumb' => 'thumb.php?key=' . rawurlencode($key)
                . '&w=' . $width . '&h=' . $height . '&fit=cover',
        ];
    }
    $stmt->close();

    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Galería no disponible: ' . $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
