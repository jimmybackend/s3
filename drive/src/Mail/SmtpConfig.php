<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Mail;

use RuntimeException;

final class SmtpConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $secure,
        public readonly string $username,
        public readonly string $password,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $replyTo,
        public readonly int $timeout,
        public readonly bool $debug
    ) {
        if ($this->host === '') {
            throw new RuntimeException('ARCADECLOUD_SMTP_HOST es obligatorio.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new RuntimeException('ARCADECLOUD_SMTP_PORT no es válido.');
        }
        if (!in_array($this->secure, ['', 'tls', 'ssl'], true)) {
            throw new RuntimeException('ARCADECLOUD_SMTP_SECURE debe ser tls, ssl o vacío.');
        }
        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('Configura ARCADECLOUD_SMTP_USERNAME y ARCADECLOUD_SMTP_PASSWORD.');
        }
        if (!filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('ARCADECLOUD_SMTP_FROM_EMAIL no contiene un correo válido.');
        }
        if (!filter_var($this->replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('ARCADECLOUD_SMTP_REPLY_TO no contiene un correo válido.');
        }
        if ($this->timeout < 1 || $this->timeout > 120) {
            throw new RuntimeException('ARCADECLOUD_SMTP_TIMEOUT debe estar entre 1 y 120 segundos.');
        }
    }

    public static function fromEnvironment(): self
    {
        $host = self::env('ARCADECLOUD_SMTP_HOST', 'smtp.titan.email');
        $port = self::envInt('ARCADECLOUD_SMTP_PORT', 587);
        $secure = strtolower(self::env('ARCADECLOUD_SMTP_SECURE', 'tls'));
        $username = self::env('ARCADECLOUD_SMTP_USERNAME');
        $password = self::env('ARCADECLOUD_SMTP_PASSWORD');
        $fromEmail = self::env('ARCADECLOUD_SMTP_FROM_EMAIL', $username);
        $fromName = self::env('ARCADECLOUD_SMTP_FROM_NAME', 'ArcadeCloud Drive');
        $replyTo = self::env('ARCADECLOUD_SMTP_REPLY_TO', $fromEmail);
        $timeout = self::envInt('ARCADECLOUD_SMTP_TIMEOUT', 20);
        $debug = self::envBool('ARCADECLOUD_SMTP_DEBUG', false);

        return new self(
            $host,
            $port,
            $secure,
            $username,
            $password,
            $fromEmail,
            $fromName,
            $replyTo,
            $timeout,
            $debug
        );
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        $value = trim((string)$value);
        return $value === '' ? $default : $value;
    }

    private static function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }
        if (!preg_match('/^\d+$/', trim((string)$value))) {
            throw new RuntimeException($name . ' debe ser numérico.');
        }

        return (int)$value;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new RuntimeException($name . ' debe ser true o false.');
        }

        return $parsed;
    }
}
