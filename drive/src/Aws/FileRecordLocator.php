<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use ArcadeCloud\Drive\View\FileViewHelper;
use mysqli;
use RuntimeException;

final class FileRecordLocator
{
    public function __construct(private mysqli $db)
    {
    }

    public function requireReadableByKey(int $userId, string $requestedKey): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        $key = $this->normalizeKey($requestedKey);
        if ($key === '') {
            throw new RuntimeException('Falta la clave del archivo.');
        }

        $slash = strrpos($key, '/');
        $route = $slash === false ? '' : substr($key, 0, $slash + 1);
        $basename = $slash === false ? $key : substr($key, $slash + 1);

        $stmt = $this->db->prepare(
            'SELECT id_, user_id_, Nombre, Ruta, Encriptado, Tamano, Fecha, Metadatos, AccessType, PasswordHash, SecureHint, Found\n'
            . 'FROM FileS3\n'
            . 'WHERE user_id_ = ? AND Found = 1\n'
            . '  AND ((Ruta = ? AND Encriptado = ?) OR Encriptado = ?)\n'
            . 'ORDER BY id_ DESC LIMIT 20'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo validar el archivo: ' . $this->db->error);
        }

        $stmt->bind_param('isss', $userId, $route, $basename, $key);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $realKey = $this->normalizeKey(FileViewHelper::buildS3Key(
                (string)($row['Ruta'] ?? ''),
                (string)($row['Encriptado'] ?? '')
            ));
            if ($realKey !== $key) {
                continue;
            }

            $stmt->close();
            if (FileViewHelper::isLocked($row)) {
                throw new RuntimeException('El archivo está protegido. Desbloquéalo antes de usar servicios AWS.');
            }

            $row['_key'] = $realKey;
            return $row;
        }

        $stmt->close();
        throw new RuntimeException('Archivo no encontrado para este usuario.');
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        return ltrim($key, '/');
    }
}
