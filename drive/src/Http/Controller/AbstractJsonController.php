<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Http\Controller;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Http\JsonResponse;
use ArcadeCloud\Drive\Http\Request;
use RuntimeException;

abstract class AbstractJsonController
{
    public function __construct(
        protected DriveApplication $app,
        protected Request $request
    ) {
    }

    protected function guardAuthenticated(): int
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || $session->userId() <= 0) {
            JsonResponse::error('Sesión inválida.', 401);
        }
        return $session->userId();
    }

    protected function requirePost(): void
    {
        if ($this->request->method() !== 'POST') {
            JsonResponse::error('Método no permitido.', 405, [
                'estado' => 'error',
                'mensaje' => 'Método no permitido'
            ]);
        }
    }

    protected function keysFromRequest(): array
    {
        $keys = $this->request->postArray('archivos');
        if (!$keys) {
            $keys = $this->request->postJsonArray('archivos_json');
        }
        return array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            $keys
        ))));
    }

    protected function fail(\Throwable $error, int $status = 500): never
    {
        JsonResponse::send([
            'ok' => false,
            'estado' => 'error',
            'mensaje' => $error->getMessage(),
            'error' => $error->getMessage(),
        ], $status);
    }

    protected function requireNonEmpty(string $value, string $message): string
    {
        if ($value === '') {
            throw new RuntimeException($message);
        }
        return $value;
    }
}
