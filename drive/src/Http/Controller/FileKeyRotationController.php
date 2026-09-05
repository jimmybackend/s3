<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Http\JsonResponse;
use RuntimeException;
use Throwable;

final class FileKeyRotationController extends AbstractJsonController
{
    public function rotate(): never
    {
        try {
            $this->requirePost();
            $userId = $this->guardAuthenticated();

            $raw = (string)file_get_contents('php://input');
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new RuntimeException('Solicitud JSON inválida.');
            }

            $key = trim((string)($data['key'] ?? ''));
            $this->requireNonEmpty($key, 'Falta el nombre del archivo.');

            JsonResponse::send(
                $this->app->fileKeyRotationService()->rotate($userId, $key)
            );
        } catch (Throwable $e) {
            $this->fail($e, 400);
        }
    }
}
