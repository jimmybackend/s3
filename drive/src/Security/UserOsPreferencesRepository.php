<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use mysqli;
use RuntimeException;
use Throwable;

final class UserOsPreferencesRepository
{
    private const FORMAT = 'arcadecloud-os-preferences';
    private const VERSION = 2;

    public function __construct(private mysqli $db)
    {
    }

    public function find(int $userId, string $nodeKey = ''): array
    {
        $decoded = $this->readStored($userId);
        if ($nodeKey === '' || !$this->isNodeScoped($decoded)) {
            return $decoded;
        }

        $nodes = is_array($decoded['nodes'] ?? null) ? $decoded['nodes'] : [];
        $nodePreferences = $nodes[$nodeKey] ?? null;
        if (is_array($nodePreferences)) {
            return $nodePreferences;
        }

        $fallback = $decoded['default'] ?? [];
        return is_array($fallback) ? $fallback : [];
    }

    public function save(int $userId, array $preferences, string $nodeKey = ''): void
    {
        if ($nodeKey === '') {
            $this->writeStored($userId, $preferences);
            return;
        }

        $this->db->begin_transaction();
        try {
            $stored = $this->readStored($userId, true);
            if ($this->isNodeScoped($stored)) {
                $container = $stored;
            } else {
                $container = [
                    '_format' => self::FORMAT,
                    'version' => self::VERSION,
                    'default' => $stored,
                    'nodes' => [],
                ];
            }

            if (!is_array($container['nodes'] ?? null)) {
                $container['nodes'] = [];
            }
            $container['nodes'][$nodeKey] = $preferences;
            $this->writeStored($userId, $container);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function readStored(int $userId, bool $forUpdate = false): array
    {
        $sql = 'SELECT os_preferences FROM Users WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
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

    private function writeStored(int $userId, array $preferences): void
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

    private function isNodeScoped(array $decoded): bool
    {
        return ($decoded['_format'] ?? null) === self::FORMAT
            && (int)($decoded['version'] ?? 0) === self::VERSION
            && is_array($decoded['nodes'] ?? null);
    }
}
