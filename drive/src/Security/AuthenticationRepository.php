<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use mysqli;
use RuntimeException;

final class AuthenticationRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, password, userstatus, role, system_role FROM Users WHERE email = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta de autenticación.');
        }

        $stmt->bind_param('s', $email);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo consultar el usuario.');
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    /** @return array<int,array{id:int,password:string}> */
    public function findActiveSuperAdmins(): array
    {
        $result = $this->db->query(
            "SELECT id, password
             FROM Users
             WHERE system_role = 'superadmin'
               AND userstatus = 'Activo'
             ORDER BY id ASC"
        );
        if (!$result) {
            throw new RuntimeException('No se pudo consultar a los superadministradores activos.');
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id' => (int)($row['id'] ?? 0),
                'password' => (string)($row['password'] ?? ''),
            ];
        }
        $result->free();

        return $rows;
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $stmt = $this->db->prepare('UPDATE Users SET password = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la actualización segura de contraseña.');
        }

        $stmt->bind_param('si', $passwordHash, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo actualizar la contraseña segura del usuario.');
        }

        $stmt->close();
    }

    public function recordLogin(int $userId, string $role, string $ipAddress): void
    {
        $action = 'Inicio de Sesión';
        $details = 'Usuario AWS OTP con el rol de ' . $role . ' accedió.';

        $stmt = $this->db->prepare(
            'INSERT INTO AccessControl (user_id, date_time, action, ip_address, action_details) '
            . 'VALUES (?, NOW(), ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar el registro de acceso.');
        }

        $stmt->bind_param('isss', $userId, $action, $ipAddress, $details);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo registrar el acceso.');
        }

        $stmt->close();
    }
}
