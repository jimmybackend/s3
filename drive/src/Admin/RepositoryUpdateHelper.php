<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use RuntimeException;

final class RepositoryUpdateHelper
{
    public const HELPER_PATH = '/usr/local/sbin/arcadecloud-drive-updater';
    private const SUDO_PATH = '/usr/bin/sudo';

    public function available(): bool
    {
        if (!is_file(self::HELPER_PATH) || !is_executable(self::HELPER_PATH) || !is_executable(self::SUDO_PATH)) return false;
        try {
            $status = $this->status();
            return ($status['ok'] ?? false) === true
                && (bool)($status['capabilities']['repository_check'] ?? false)
                && (bool)($status['capabilities']['repository_update'] ?? false);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function status(): array
    {
        return $this->runJson(['status']);
    }

    public function check(): array
    {
        return $this->runJson(['check']);
    }

    public function update(string $expectedRemoteSha): array
    {
        $expectedRemoteSha = strtolower(trim($expectedRemoteSha));
        if (!preg_match('/\A[a-f0-9]{40}\z/', $expectedRemoteSha)) {
            throw new RuntimeException('SHA remoto esperado inválido. Vuelve a buscar actualizaciones.');
        }
        return $this->runJson(['update', $expectedRemoteSha]);
    }

    private function runJson(array $args): array
    {
        $result = $this->run($args);
        $decoded = json_decode((string)$result['stdout'], true);
        if (!is_array($decoded)) throw new RuntimeException('El updater no devolvió una respuesta JSON válida.');
        return $decoded;
    }

    private function run(array $args): array
    {
        if (!function_exists('proc_open')) throw new RuntimeException('proc_open no está disponible; no se puede usar ArcadeCloud Updater.');
        if (!is_executable(self::SUDO_PATH) || !is_executable(self::HELPER_PATH)) {
            throw new RuntimeException('ArcadeCloud Updater no está instalado en este nodo.');
        }

        $command = array_merge([self::SUDO_PATH, '-n', self::HELPER_PATH], array_values(array_map('strval', $args)));
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar ArcadeCloud Updater.');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 65537);
        $stderr = stream_get_contents($pipes[2], 16385);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if (!is_string($stdout) || strlen($stdout) > 65536 || !is_string($stderr) || strlen($stderr) > 16384) {
            throw new RuntimeException('Respuesta de ArcadeCloud Updater demasiado grande.');
        }
        if ($exit !== 0) {
            $message = trim($stderr);
            throw new RuntimeException($message !== '' ? $message : 'ArcadeCloud Updater rechazó la operación.');
        }
        return ['stdout' => trim($stdout), 'stderr' => trim($stderr), 'exit_code' => $exit];
    }
}
