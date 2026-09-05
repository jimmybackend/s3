<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Storage;

use mysqli;
use RuntimeException;

final class FileRecordRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function requireByRef(int $userId, int|string $ref, bool $onlyFound = false): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario inválido.');
        }

        if (is_int($ref) || ctype_digit((string)$ref)) {
            $id = (int)$ref;
            $sql = 'SELECT * FROM FileS3 WHERE id_ = ? AND user_id_ = ?'
                . ($onlyFound ? ' AND Found = 1' : '') . ' LIMIT 1';
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('No se pudo localizar el archivo: ' . $this->db->error);
            }
            $stmt->bind_param('ii', $id, $userId);
        } else {
            $key = $this->normalizeKey((string)$ref);
            if ($key === '') {
                throw new RuntimeException('Referencia de archivo inválida.');
            }
            $sql = 'SELECT * FROM FileS3 WHERE user_id_ = ? '
                . 'AND (CONCAT(Ruta, Encriptado) = ? OR Encriptado = ?)'
                . ($onlyFound ? ' AND Found = 1' : '')
                . ' ORDER BY id_ DESC LIMIT 1';
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('No se pudo localizar el archivo: ' . $this->db->error);
            }
            $stmt->bind_param('iss', $userId, $key, $key);
        }

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo localizar el archivo: ' . $error);
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('Archivo no encontrado.');
        }
        $row['_key'] = $this->buildKey($row);
        return $row;
    }

    public function renameVisible(int $userId, int $id, string $name): void
    {
        $stmt = $this->db->prepare('UPDATE FileS3 SET Nombre = ? WHERE id_ = ? AND user_id_ = ?');
        if (!$stmt) throw new RuntimeException('No se pudo preparar el renombrado: ' . $this->db->error);
        $stmt->bind_param('sii', $name, $id, $userId);
        $this->execute($stmt, 'No se pudo renombrar el archivo');
    }

    public function markFound(int $userId, int $id, bool $found): void
    {
        $value = $found ? 1 : 0;
        $stmt = $this->db->prepare('UPDATE FileS3 SET Found = ? WHERE id_ = ? AND user_id_ = ?');
        if (!$stmt) throw new RuntimeException('No se pudo preparar la actualización FileS3: ' . $this->db->error);
        $stmt->bind_param('iii', $value, $id, $userId);
        $this->execute($stmt, 'No se pudo actualizar FileS3');
    }

    public function move(int $userId, int $id, string $route, string $encrypted): void
    {
        $stmt = $this->db->prepare(
            'UPDATE FileS3 SET Ruta = ?, Encriptado = ?, Found = 1 WHERE id_ = ? AND user_id_ = ?'
        );
        if (!$stmt) throw new RuntimeException('No se pudo preparar el movimiento: ' . $this->db->error);
        $stmt->bind_param('ssii', $route, $encrypted, $id, $userId);
        $this->execute($stmt, 'No se pudo actualizar FileS3 al mover');
    }

    public function buildKey(array $row): string
    {
        $route = $this->normalizePrefix((string)($row['Ruta'] ?? ''));
        $encrypted = trim((string)($row['Encriptado'] ?? ''));
        if ($encrypted === '') throw new RuntimeException('El registro no contiene Encriptado.');
        if ($route !== '' && str_starts_with($encrypted, $route)) {
            return $this->normalizeKey($encrypted);
        }
        return $this->normalizeKey($route . $encrypted);
    }

    public function normalizePrefix(string $prefix): string
    {
        $prefix = $this->normalizeKey($prefix);
        return $prefix === '' ? '' : rtrim($prefix, '/') . '/';
    }

    public function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('~/+~', '/', $key) ?? $key;
        return ltrim($key, '/');
    }

    private function execute(\mysqli_stmt $stmt, string $context): void
    {
        if (!$stmt->execute()) {
            $error = $stmt->error ?: $this->db->error;
            $stmt->close();
            throw new RuntimeException($context . ': ' . $error);
        }
        $stmt->close();
    }
}
