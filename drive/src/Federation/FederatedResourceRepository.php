<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Federation;

use ArcadeCloud\Drive\View\FileViewHelper;
use mysqli;

final class FederatedResourceRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function requireOwnedFile(int $userId, int $fileId): array
    {
        $row = $this->findOwnedFile($userId, $fileId);
        if ($row === null) {
            throw new FederationException('Archivo no encontrado para este usuario.', 404);
        }
        return $row;
    }

    public function findOwnedFile(int $userId, int $fileId): ?array
    {
        if ($userId <= 0 || $fileId <= 0) return null;
        $stmt = $this->db->prepare(
            'SELECT id_, user_id_, Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, Fecha '
            . 'FROM FileS3 WHERE id_ = ? AND user_id_ = ? AND Found = 1 LIMIT 1'
        );
        if (!$stmt) {
            throw new FederationException('No se pudo consultar el recurso local.', 500);
        }
        $stmt->bind_param('ii', $fileId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    public function findOwnedFileByStorageRef(int $userId, string $storageRef): ?array
    {
        $key = $this->normalizeKey($storageRef);
        if ($userId <= 0 || $key === '' || strlen($key) > 1024) return null;

        $slash = strrpos($key, '/');
        $route = $slash === false ? '' : substr($key, 0, $slash + 1);
        $basename = $slash === false ? $key : substr($key, $slash + 1);

        $stmt = $this->db->prepare(
            'SELECT id_, user_id_, Nombre, Encriptado, Tamano, Metadatos, Ruta, Found, AccessType, Fecha '
            . 'FROM FileS3 '
            . 'WHERE user_id_ = ? AND Found = 1 '
            . 'AND ((Ruta = ? AND Encriptado = ?) OR Encriptado = ?) '
            . 'ORDER BY id_ DESC LIMIT 20'
        );
        if (!$stmt) {
            throw new FederationException('No se pudo consultar el recurso estable local.', 500);
        }

        $stmt->bind_param('isss', $userId, $route, $basename, $key);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $realKey = $this->normalizeKey($this->storageKey($row));
            if ($realKey !== $key) {
                continue;
            }
            $stmt->close();
            return $row;
        }

        $stmt->close();
        return null;
    }

    public function contentId(array $file): ?string
    {
        $metadata = FileViewHelper::metadataArray($file['Metadatos'] ?? null);
        foreach (['hash_sha256', 'sha256', 'checksum_sha256'] as $key) {
            $candidate = strtolower(trim((string)($metadata[$key] ?? '')));
            if (str_starts_with($candidate, 'sha256:')) {
                $candidate = substr($candidate, 7);
            }
            if (preg_match('/\A[a-f0-9]{64}\z/', $candidate)) {
                return 'sha256:' . $candidate;
            }
        }
        return null;
    }

    public function mediaType(array $file): string
    {
        $metadata = FileViewHelper::metadataArray($file['Metadatos'] ?? null);
        foreach (['mime_type', 'tipo', 'content_type'] as $key) {
            $candidate = strtolower(trim((string)($metadata[$key] ?? '')));
            if ($candidate !== '' && preg_match('~\A[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*\z~', $candidate)) {
                return substr($candidate, 0, 128);
            }
        }
        return 'application/octet-stream';
    }

    public function storageKey(array $file): string
    {
        return FileViewHelper::buildS3Key((string)($file['Ruta'] ?? ''), (string)($file['Encriptado'] ?? ''));
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        return ltrim($key, '/');
    }
}
