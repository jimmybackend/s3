<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use InvalidArgumentException;
use RuntimeException;

final class PasswordChangeService
{
    public function __construct(
        private UserProfileRepository $repository
    ) {
    }

    public function requestCode(int $userId): array
    {
        $profile = $this->verificationProfile($userId);

        return [
            'ok' => true,
            'message' => 'Datos listos. Usa tu código postal registrado como código de verificación.',
            'verification' => 'postalcode',
            'postalcode_length' => strlen((string)$profile['postalcode']),
        ];
    }

    public function changePassword(
        int $userId,
        string $code,
        string $newPassword,
        string $confirmation
    ): array {
        $profile = $this->verificationProfile($userId);
        $postalCode = trim((string)$profile['postalcode']);
        $code = trim($code);

        if ($code === '') {
            throw new InvalidArgumentException('Escribe tu código postal registrado.');
        }

        if (!hash_equals($postalCode, $code)) {
            throw new InvalidArgumentException('El código postal no coincide con el registrado en tu perfil.');
        }

        $password = UserProfileValidator::password($newPassword, $confirmation);

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException('No se pudo proteger la nueva contraseña.');
        }

        $this->repository->updatePassword($userId, $passwordHash);

        return [
            'ok' => true,
            'message' => 'Contraseña actualizada correctamente.',
        ];
    }

    private function verificationProfile(int $userId): array
    {
        $profile = $this->repository->findById($userId);
        if ($profile === null) {
            throw new RuntimeException('No se encontró el perfil del usuario.');
        }

        $address = trim((string)($profile['address'] ?? ''));
        $postalCode = trim((string)($profile['postalcode'] ?? ''));

        if ($address === '' || $postalCode === '') {
            throw new InvalidArgumentException(
                'Antes de cambiar la contraseña, completa y guarda tu dirección y código postal en Datos personales.'
            );
        }

        return $profile;
    }
}
