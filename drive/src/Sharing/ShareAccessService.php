<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sharing;

use DateTimeImmutable;
use Throwable;

final class ShareAccessService
{
    public function __construct(
        private ShareFileRepository $files,
        private ShareTokenStore $tokens,
        private ShareObjectStorage $storage
    ) {
    }

    public function privateUrl(int $userId, string $requestedKey): array
    {
        $file = $this->files->requireOwnedByKey($userId, $requestedKey);
        $key = (string)$file['_key'];

        return [
            'key' => $key,
            'nombre' => (string)($file['Nombre'] ?? basename($key)),
            'url' => $this->storage->presignedUrl($key),
        ];
    }

    public function privateContent(int $userId, string $requestedKey): array
    {
        $file = $this->files->requireOwnedByKey($userId, $requestedKey);
        $key = (string)$file['_key'];

        return [
            'key' => $key,
            'nombre' => (string)($file['Nombre'] ?? basename($key)),
            'contenido' => $this->storage->read($key),
        ];
    }

    public function publicToken(string $token): array
    {
        $payload = $this->tokens->find($token);
        if ($payload === null) {
            throw new ShareException('Token inválido o inexistente.', 404);
        }

        $key = trim((string)($payload['archivo_key'] ?? ''));
        if ($key === '') {
            throw new ShareException('Token sin archivo asociado.', 500);
        }

        $this->assertNotExpired($payload);

        $userId = (int)($payload['user_id'] ?? 0);
        $name = (string)($payload['nombre'] ?? basename($key));

        if ($userId > 0) {
            $file = $this->files->requireOwnedByKey($userId, $key);
            $key = (string)$file['_key'];
            $name = (string)($file['Nombre'] ?? $name);
        }

        return [
            'key' => $key,
            'nombre' => $name,
            'tipo' => strtolower(trim((string)($payload['tipo'] ?? 'otro'))),
            'url' => $this->storage->presignedUrl($key),
            'expira' => (string)($payload['expira'] ?? ''),
            'legacy' => $userId <= 0,
        ];
    }

    public function readPublicContent(array $share): string
    {
        $key = trim((string)($share['key'] ?? ''));
        if ($key === '') {
            throw new ShareException('Archivo compartido inválido.', 500);
        }

        return $this->storage->read($key);
    }

    private function assertNotExpired(array $payload): void
    {
        $expiresRaw = trim((string)($payload['expira'] ?? ''));
        if ($expiresRaw === '') {
            return;
        }

        try {
            $expires = new DateTimeImmutable($expiresRaw);
        } catch (Throwable $e) {
            // Compatibilidad: los tokens antiguos con una fecha no interpretable
            // se siguen tratando como válidos, igual que el código heredado.
            return;
        }

        if (new DateTimeImmutable('now') > $expires) {
            throw new ShareException('El enlace ha expirado.', 410);
        }
    }
}
