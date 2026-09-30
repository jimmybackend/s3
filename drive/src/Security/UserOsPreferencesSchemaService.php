<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use mysqli;
use RuntimeException;

final class UserOsPreferencesSchemaService
{
    public function __construct(private mysqli $db)
    {
    }

    public function ensure(): bool
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS total FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Users' AND COLUMN_NAME = 'os_preferences'"
        );
        if (!$result) {
            throw new RuntimeException('No se pudo verificar Users.os_preferences.');
        }
        $exists = (int)($result->fetch_assoc()['total'] ?? 0) > 0;
        if (!$exists && !$this->db->query(
            "ALTER TABLE Users ADD COLUMN os_preferences TEXT CHARACTER SET utf8mb4 "
            . "COLLATE utf8mb4_unicode_ci NULL AFTER profilepicture"
        )) {
            throw new RuntimeException('No se pudo agregar Users.os_preferences.');
        }
        return !$exists;
    }
}
