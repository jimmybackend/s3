<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use mysqli;
use RuntimeException;

final class UserProfileRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findById(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, firstname, lastname, curp, gender, birthdate, email, address, neighborhood, '
            . 'postalcode, state, country, homephone, mobilephone, role, system_role, registrationdate, '
            . 'profilepicture, chat, userstatus FROM Users WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la consulta del perfil.');
        }

        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo consultar el perfil.');
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    public function updatePersonal(int $userId, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE Users SET firstname = ?, lastname = ?, curp = ?, gender = ?, birthdate = ?, '
            . 'address = ?, neighborhood = ?, postalcode = ?, state = ?, country = ?, homephone = ?, mobilephone = ? '
            . 'WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la actualización del perfil.');
        }

        $firstname = (string)$data['firstname'];
        $lastname = (string)$data['lastname'];
        $curp = (string)$data['curp'];
        $gender = (string)$data['gender'];
        $birthdate = $data['birthdate'] !== '' ? (string)$data['birthdate'] : null;
        $address = (string)$data['address'];
        $neighborhood = (string)$data['neighborhood'];
        $postalcode = (string)$data['postalcode'];
        $state = (string)$data['state'];
        $country = (string)$data['country'];
        $homephone = (string)$data['homephone'];
        $mobilephone = (string)$data['mobilephone'];

        $stmt->bind_param(
            'ssssssssssssi',
            $firstname,
            $lastname,
            $curp,
            $gender,
            $birthdate,
            $address,
            $neighborhood,
            $postalcode,
            $state,
            $country,
            $homephone,
            $mobilephone,
            $userId
        );

        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo actualizar el perfil.');
        }
        $stmt->close();
    }

    public function updateProfilePicture(int $userId, ?string $key): void
    {
        $stmt = $this->db->prepare('UPDATE Users SET profilepicture = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la actualización de la imagen.');
        }

        $stmt->bind_param('si', $key, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo actualizar la imagen de perfil.');
        }
        $stmt->close();
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $stmt = $this->db->prepare('UPDATE Users SET password = ? WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar el cambio de contraseña.');
        }

        $stmt->bind_param('si', $passwordHash, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo cambiar la contraseña.');
        }
        $stmt->close();
    }
}
