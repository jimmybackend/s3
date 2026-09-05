<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Sharing;

use ArcadeCloud\Drive\View\FileViewHelper;
use mysqli;

final class ShareFileRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function requireOwnedByKey(int $userId, string $requestedKey): array
    {
        if ($userId <= 0) {
            throw new ShareException('Usuario inválido.', 401);
        }

        $key = $this->normalizeKey($requestedKey);
        if ($key === '') {
            throw new ShareException('Falta el archivo.', 400);
        }

        $slash = strrpos($key, '/');
        $route = $slash === false ? '' : substr($key, 0, $slash + 1);
        $basename = $slash === false ? $key : substr($key, $slash + 1);

        $stmt = $this->db->prepare(
            'SELECT id_, user_id_, Nombre, Ruta, Encriptado, Tamano, Fecha, AccessType, Found '
            . 'FROM FileS3 '
            . 'WHERE user_id_ = ? AND Found = 1 '
            . 'AND ((Ruta = ? AND Encriptado = ?) OR Encriptado = ?) '
            . 'ORDER BY id_ DESC LIMIT 20'
        );

        if (!$stmt) {
            throw new ShareException('No se pudo validar el archivo.', 500);
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
            $row['_key'] = $realKey;
            return $row;
        }

        $stmt->close();
        throw new ShareException('Archivo no encontrado para este usuario.', 404);
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        return ltrim($key, '/');
    }
}
