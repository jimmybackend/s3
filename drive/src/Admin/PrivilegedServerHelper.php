<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use RuntimeException;

final class PrivilegedServerHelper
{
    public const HELPER_PATH = '/usr/local/sbin/arcadecloud-drive-admin';
    private const SUDO_PATH = '/usr/bin/sudo';

    public function available(): bool
    {
        if (!is_file(self::HELPER_PATH) || !is_executable(self::HELPER_PATH) || !is_executable(self::SUDO_PATH)) return false;
        try {
            $result = $this->run(['status']);
            return ($result['exit_code'] ?? 1) === 0;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function status(): array
    {
        $result = $this->run(['status']);
        $decoded = json_decode((string)$result['stdout'], true);
        return is_array($decoded) ? $decoded : ['ok' => false];
    }

    public function supportsEnvironmentGroups(): bool
    {
        try {
            $status = $this->status();
            return ($status['ok'] ?? false) === true
                && (int)($status['version'] ?? 0) >= 2
                && (bool)($status['capabilities']['env_set_many'] ?? false);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function supportsBootstrapSetup(): bool
    {
        try {
            $status = $this->status();
            return ($status['ok'] ?? false) === true
                && (int)($status['version'] ?? 0) >= 3
                && (bool)($status['capabilities']['web_setup'] ?? false)
                && (bool)($status['capabilities']['bootstrap_complete'] ?? false);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function setEnvironment(string $name, string $value): void
    {
        if (!ManagedRuntimeEnvironment::isAllowed($name)) throw new RuntimeException('Variable no permitida.');
        $this->run(['env-set', $name], $value . "\n");
    }

    public function setEnvironmentMany(array $values): void
    {
        if ($values === []) throw new RuntimeException('No hay variables para actualizar.');
        $payload = [];
        foreach ($values as $name => $value) {
            if (!is_string($name) || !ManagedRuntimeEnvironment::isAllowed($name) || !is_string($value)) {
                throw new RuntimeException('Grupo de variables inválido.');
            }
            $payload[$name] = $value;
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->run(['env-set-many'], $json);
    }

    public function completeBootstrapSetup(): void
    {
        if (!$this->supportsBootstrapSetup()) {
            throw new RuntimeException('El helper administrativo no soporta cierre de setup; reinstálalo desde el repositorio actual.');
        }
        $this->run(['bootstrap-complete']);
    }

    public function createIdentity(string $nodeName): array
    {
        $result = $this->run(['identity-create', $nodeName]);
        $decoded = json_decode((string)$result['stdout'], true);
        if (!is_array($decoded) || ($decoded['ok'] ?? null) !== true) throw new RuntimeException('El helper no confirmó la creación de identidad.');
        return $decoded;
    }

    public function renameIdentity(string $nodeName): void
    {
        $this->run(['identity-rename', $nodeName]);
    }

    private function run(array $args, string $stdin = ''): array
    {
        if (!function_exists('proc_open')) throw new RuntimeException('proc_open no está disponible; no se puede usar el helper administrativo.');
        if (!is_executable(self::SUDO_PATH) || !is_executable(self::HELPER_PATH)) throw new RuntimeException('El helper administrativo de ArcadeCloud no está instalado.');

        $command = array_merge([self::SUDO_PATH, '-n', self::HELPER_PATH], array_values(array_map('strval', $args)));
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar el helper administrativo.');

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 65537);
        $stderr = stream_get_contents($pipes[2], 8193);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if (!is_string($stdout) || strlen($stdout) > 65536 || !is_string($stderr) || strlen($stderr) > 8192) throw new RuntimeException('Respuesta administrativa demasiado grande.');
        if ($exit !== 0) {
            $message = trim($stderr);
            throw new RuntimeException($message !== '' ? $message : 'El helper administrativo rechazó la operación.');
        }
        return ['exit_code' => $exit, 'stdout' => trim($stdout), 'stderr' => trim($stderr)];
    }
}
