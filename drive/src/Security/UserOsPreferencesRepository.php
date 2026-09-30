<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use mysqli;
use RuntimeException;

final class UserOsPreferencesRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function find(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT os_preferences FROM Users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar la configuración del usuario.');
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $decoded = json_decode((string)($row['os_preferences'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function save(int $userId, array $preferences): void
    {
        $json = json_encode($preferences, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('La configuración del usuario no es válida.');
        }
        $stmt = $this->db->prepare('UPDATE Users SET os_preferences = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la configuración del usuario.');
        }
        $stmt->bind_param('si', $json, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo guardar la configuración del usuario.');
        }
        $stmt->close();
    }
}
