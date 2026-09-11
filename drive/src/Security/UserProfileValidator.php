<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use DateTimeImmutable;
use InvalidArgumentException;

final class UserProfileValidator
{
    public static function personal(array $input): array
    {
        $gender = trim((string)($input['gender'] ?? ''));
        if (!in_array($gender, ['Masculino', 'Femenino', 'Otro'], true)) {
            throw new InvalidArgumentException('Selecciona un género válido.');
        }

        $curp = strtoupper(trim((string)($input['curp'] ?? '')));
        if ($curp !== '' && !preg_match('/^[A-Z0-9]{18}$/', $curp)) {
            throw new InvalidArgumentException('La CURP debe contener exactamente 18 caracteres alfanuméricos.');
        }

        $birthdate = trim((string)($input['birthdate'] ?? ''));
        if ($birthdate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthdate);
            if (!$date || $date->format('Y-m-d') !== $birthdate) {
                throw new InvalidArgumentException('La fecha de nacimiento no es válida.');
            }
            if ($date > new DateTimeImmutable('today')) {
                throw new InvalidArgumentException('La fecha de nacimiento no puede estar en el futuro.');
            }
        }

        $data = [
            'firstname' => self::text($input, 'firstname', 255, 'Nombre'),
            'lastname' => self::text($input, 'lastname', 255, 'Apellidos'),
            'curp' => $curp,
            'gender' => $gender,
            'birthdate' => $birthdate,
            'address' => self::text($input, 'address', 255, 'Dirección'),
            'neighborhood' => self::text($input, 'neighborhood', 255, 'Colonia'),
            'postalcode' => self::text($input, 'postalcode', 10, 'Código postal'),
            'state' => self::text($input, 'state', 255, 'Estado'),
            'country' => self::text($input, 'country', 255, 'País'),
            'homephone' => self::phone($input, 'homephone', 'Teléfono de casa'),
            'mobilephone' => self::phone($input, 'mobilephone', 'Teléfono móvil'),
        ];

        return $data;
    }

    public static function password(string $password, string $confirmation): string
    {
        if (!hash_equals($password, $confirmation)) {
            throw new InvalidArgumentException('La confirmación de contraseña no coincide.');
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($password, 'UTF-8')
            : strlen($password);

        if ($length < 12) {
            throw new InvalidArgumentException('La nueva contraseña debe tener al menos 12 caracteres.');
        }
        if ($length > 128) {
            throw new InvalidArgumentException('La nueva contraseña es demasiado larga.');
        }

        return $password;
    }

    private static function text(array $input, string $key, int $max, string $label): string
    {
        $value = trim((string)($input[$key] ?? ''));
        $length = function_exists('mb_strlen')
            ? mb_strlen($value, 'UTF-8')
            : strlen($value);

        if ($length > $max) {
            throw new InvalidArgumentException($label . ' excede el tamaño permitido.');
        }

        return $value;
    }

    private static function phone(array $input, string $key, string $label): string
    {
        $value = self::text($input, $key, 15, $label);
        if ($value !== '' && !preg_match('/^[0-9+() .-]+$/', $value)) {
            throw new InvalidArgumentException($label . ' contiene caracteres no permitidos.');
        }

        return $value;
    }
}
