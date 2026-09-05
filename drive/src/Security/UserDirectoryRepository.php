<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use mysqli;
use RuntimeException;

final class UserDirectoryRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /** @return array<int,array{id:int,email:string,userstatus:string}> */
    public function all(): array
    {
        $result = $this->db->query(
            'SELECT id, email, userstatus FROM Users ORDER BY email ASC'
        );

        if (!$result) {
            throw new RuntimeException('No se pudo consultar la lista de usuarios: ' . $this->db->error);
        }

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = [
                'id' => (int)$row['id'],
                'email' => (string)$row['email'],
                'userstatus' => (string)($row['userstatus'] ?? ''),
            ];
        }
        return $users;
    }

    /** @return array{id:int,email:string,userstatus:string} */
    public function requireById(int $userId): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Usuario destino inválido.');
        }

        $stmt = $this->db->prepare(
            'SELECT id, email, userstatus FROM Users WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta del usuario destino: ' . $this->db->error);
        }

        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo consultar el usuario destino: ' . $error);
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('Usuario destino no encontrado.');
        }

        return [
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'userstatus' => (string)($row['userstatus'] ?? ''),
        ];
    }
}
