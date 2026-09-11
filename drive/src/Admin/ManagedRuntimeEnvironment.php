<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use JsonException;
use RuntimeException;

final class ManagedRuntimeEnvironment
{
    public const DEFAULT_PATH = '/etc/arcadecloud-drive/runtime-env.json';

    /**
     * Variables de runtime que el superadmin puede consultar y actualizar.
     * Los grupos atómicos se guardan juntos para evitar configuraciones parciales.
     */
    public const DEFINITIONS = [
        'ARCADECLOUD_PUBLIC_URL' => ['secret' => false, 'group' => 'FederationCloud'],
        'ARCADECLOUD_FEDERATION_URL' => ['secret' => false, 'group' => 'FederationCloud'],
        'ARCADECLOUD_FEDERATION_ENABLED' => ['secret' => false, 'group' => 'FederationCloud'],
        'ARCADECLOUD_FEDERATION_SEED_URL' => ['secret' => false, 'group' => 'FederationCloud'],

        'ARCADECLOUD_SMTP_HOST' => ['secret' => false, 'group' => 'SMTP'],
        'ARCADECLOUD_SMTP_PORT' => ['secret' => false, 'group' => 'SMTP'],
        'ARCADECLOUD_SMTP_SECURE' => ['secret' => false, 'group' => 'SMTP'],
        'ARCADECLOUD_SMTP_USERNAME' => ['secret' => false, 'group' => 'SMTP'],
        'ARCADECLOUD_SMTP_PASSWORD' => ['secret' => true, 'group' => 'SMTP', 'required' => true],
        'ARCADECLOUD_SMTP_FROM_EMAIL' => ['secret' => false, 'group' => 'SMTP'],
        'ARCADECLOUD_SMTP_FROM_NAME' => ['secret' => false, 'group' => 'SMTP'],
        'ARCADECLOUD_SMTP_REPLY_TO' => ['secret' => false, 'group' => 'SMTP'],
        'ARCADECLOUD_SMTP_TIMEOUT' => ['secret' => false, 'group' => 'SMTP'],
        'ARCADECLOUD_SMTP_DEBUG' => ['secret' => false, 'group' => 'SMTP'],

        'DB_HOST' => ['secret' => false, 'group' => 'Base de datos', 'atomic_group' => 'database', 'required' => true],
        'DB_PORT' => ['secret' => false, 'group' => 'Base de datos', 'atomic_group' => 'database'],
        'DB_USER' => ['secret' => false, 'group' => 'Base de datos', 'atomic_group' => 'database', 'required' => true],
        'DB_PASSWORD' => ['secret' => true, 'group' => 'Base de datos', 'atomic_group' => 'database', 'required' => true],
        'DB_NAME' => ['secret' => false, 'group' => 'Base de datos', 'atomic_group' => 'database', 'required' => true],

        'AWS_REGION' => ['secret' => false, 'group' => 'AWS', 'atomic_group' => 'aws'],
        'AWS_S3_BUCKET' => ['secret' => false, 'group' => 'AWS', 'atomic_group' => 'aws'],
        'AWS_ACCESS_KEY_ID' => ['secret' => true, 'group' => 'AWS', 'atomic_group' => 'aws', 'required' => true],
        'AWS_SECRET_ACCESS_KEY' => ['secret' => true, 'group' => 'AWS', 'atomic_group' => 'aws', 'required' => true],
        'AWS_SESSION_TOKEN' => ['secret' => true, 'group' => 'AWS', 'atomic_group' => 'aws', 'allow_empty' => true],
        'AWS_CONTROL_ACCESS_KEY_ID' => ['secret' => true, 'group' => 'AWS', 'atomic_group' => 'aws', 'allow_empty' => true],
        'AWS_CONTROL_SECRET_ACCESS_KEY' => ['secret' => true, 'group' => 'AWS', 'atomic_group' => 'aws', 'allow_empty' => true],
        'AWS_CONTROL_SESSION_TOKEN' => ['secret' => true, 'group' => 'AWS', 'atomic_group' => 'aws', 'allow_empty' => true],
    ];

    public static function loadIntoProcess(?string $path = null): void
    {
        $path = $path ?: self::DEFAULT_PATH;
        if (!is_file($path) || !is_readable($path)) return;

        foreach (self::read($path) as $name => $value) {
            if (!isset(self::DEFINITIONS[$name]) || !is_string($value)) continue;
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }

    public static function read(?string $path = null): array
    {
        $path = $path ?: self::DEFAULT_PATH;
        if (!is_file($path)) return [];
        if (!is_readable($path)) {
            throw new RuntimeException('La configuración administrada existe pero PHP no puede leerla: ' . $path);
        }

        try {
            $decoded = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('La configuración administrada no contiene JSON válido.');
        }

        // json_decode('{}', true) produce [] en PHP. Ese valor representa
        // correctamente un objeto de configuración vacío y debe aceptarse.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException('La configuración administrada tiene un formato inválido.');
        }

        $safe = [];
        foreach ($decoded as $name => $value) {
            if (!is_string($name) || !isset(self::DEFINITIONS[$name]) || !is_string($value)) continue;
            $safe[$name] = $value;
        }
        return $safe;
    }

    public static function publicState(?string $path = null): array
    {
        $values = self::read($path);
        $rows = [];
        foreach (self::DEFINITIONS as $name => $meta) {
            $env = getenv($name);
            $value = array_key_exists($name, $values) ? (string)$values[$name] : ($env !== false ? (string)$env : '');
            $secret = (bool)($meta['secret'] ?? false);
            $rows[] = [
                'name' => $name,
                'group' => (string)($meta['group'] ?? 'General'),
                'atomic_group' => (string)($meta['atomic_group'] ?? ''),
                'required' => (bool)($meta['required'] ?? false),
                'secret' => $secret,
                'configured' => $value !== '',
                'value' => $secret ? '' : $value,
                'source' => array_key_exists($name, $values) ? 'managed' : ($env !== false ? 'process' : 'unset'),
            ];
        }
        return $rows;
    }

    public static function isAllowed(string $name): bool
    {
        return isset(self::DEFINITIONS[$name]);
    }

    public static function namesForAtomicGroup(string $group): array
    {
        $names = [];
        foreach (self::DEFINITIONS as $name => $meta) {
            if (($meta['atomic_group'] ?? '') === $group) $names[] = $name;
        }
        return $names;
    }

    public static function validateValue(string $name, string $value): string
    {
        if (!self::isAllowed($name)) {
            throw new RuntimeException('Variable de entorno no permitida para administración web.');
        }
        if (strlen($value) > 8192 || str_contains($value, "\0")) {
            throw new RuntimeException('Valor de variable inválido o demasiado grande.');
        }

        $meta = self::DEFINITIONS[$name];
        $secret = (bool)($meta['secret'] ?? false);
        $required = (bool)($meta['required'] ?? false);
        $allowEmpty = (bool)($meta['allow_empty'] ?? false);

        if ($secret) {
            if (str_starts_with($name, 'AWS_')) {
                $value = trim($value);
                if ($value !== '' && preg_match('/\s/', $value)) {
                    throw new RuntimeException($name . ' no debe contener espacios.');
                }
            }
            if ($value === '' && $required && !$allowEmpty) {
                throw new RuntimeException($name . ' no puede quedar vacío.');
            }
            return $value;
        }

        $value = trim($value);
        if ($required && $value === '') {
            throw new RuntimeException($name . ' no puede quedar vacío.');
        }

        if (in_array($name, ['ARCADECLOUD_FEDERATION_ENABLED', 'ARCADECLOUD_SMTP_DEBUG'], true)) {
            $lower = strtolower($value);
            if (!in_array($lower, ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'], true)) {
                throw new RuntimeException($name . ' debe ser true/false.');
            }
            return $lower;
        }

        if (in_array($name, ['ARCADECLOUD_SMTP_PORT', 'DB_PORT'], true) && $value !== '') {
            $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false) throw new RuntimeException($name . ' debe ser un puerto entre 1 y 65535.');
            return (string)$port;
        }

        if ($name === 'ARCADECLOUD_SMTP_TIMEOUT') {
            $timeout = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
            if ($timeout === false) throw new RuntimeException('ARCADECLOUD_SMTP_TIMEOUT debe estar entre 1 y 120.');
            return (string)$timeout;
        }

        if ($name === 'ARCADECLOUD_SMTP_SECURE') {
            $lower = strtolower($value);
            if (!in_array($lower, ['', 'tls', 'ssl'], true)) throw new RuntimeException('ARCADECLOUD_SMTP_SECURE debe ser tls, ssl o vacío.');
            return $lower;
        }

        if ($name === 'AWS_REGION' && $value !== '' && !preg_match('/\A[a-z0-9-]{3,64}\z/i', $value)) {
            throw new RuntimeException('AWS_REGION contiene caracteres inválidos.');
        }

        if ($name === 'AWS_S3_BUCKET' && $value !== '') {
            if (strlen($value) < 3 || strlen($value) > 63 || !preg_match('/\A[a-z0-9][a-z0-9.-]*[a-z0-9]\z/', $value) || str_contains($value, '..')) {
                throw new RuntimeException('AWS_S3_BUCKET debe contener sólo el nombre válido del bucket.');
            }
        }

        if (in_array($name, ['DB_HOST', 'DB_USER', 'DB_NAME'], true) && preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new RuntimeException($name . ' contiene caracteres de control inválidos.');
        }

        if (in_array($name, ['ARCADECLOUD_PUBLIC_URL', 'ARCADECLOUD_FEDERATION_URL', 'ARCADECLOUD_FEDERATION_SEED_URL'], true) && $value !== '') {
            $parts = parse_url($value);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                throw new RuntimeException($name . ' debe contener una URL válida.');
            }
            if ($name !== 'ARCADECLOUD_PUBLIC_URL' && strtolower((string)$parts['scheme']) !== 'https') {
                throw new RuntimeException($name . ' debe usar HTTPS.');
            }
            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new RuntimeException($name . ' no debe contener credenciales, query ni fragmento.');
            }
        }
        return $value;
    }
}
