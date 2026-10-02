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

    public function supportsAutomaticSetupFinalize(): bool
    {
        try {
            $status = $this->status();
            return ($status['ok'] ?? false) === true
                && (int)($status['version'] ?? 0) >= 5
                && (bool)($status['capabilities']['setup_finalize'] ?? false);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function supportsServerConsole(): bool
    {
        try {
            $status = $this->status();
            return ($status['ok'] ?? false) === true
                && (int)($status['version'] ?? 0) >= 11
                && (bool)($status['capabilities']['server_console'] ?? false);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function supportsDiskCleanup(): bool
    {
        try {
            $status = $this->status();
            return ($status['ok'] ?? false) === true
                && (int)($status['version'] ?? 0) >= 12
                && (bool)($status['capabilities']['disk_cleanup'] ?? false);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function supportsNodeServiceControl(): bool
    {
        try {
            $status = $this->status();
            return ($status['ok'] ?? false) === true && (int)($status['version'] ?? 0) >= 15
                && (bool)($status['capabilities']['node_service_control'] ?? false);
        } catch (RuntimeException) { return false; }
    }

    public function controlNodeComponent(string $componentId, string $action): array
    {
        if (!$this->supportsNodeServiceControl()) throw new RuntimeException('Actualiza el helper administrativo para controlar componentes.');
        if (!preg_match('/\A[a-z0-9-]{1,40}\z/', $componentId) || !in_array($action, ['start', 'stop', 'restart', 'enable', 'disable', 'run-now'], true)) {
            throw new RuntimeException('Acción de componente no permitida.');
        }
        $result = $this->run(['node-service', $componentId, $action]);
        $decoded = json_decode((string)$result['stdout'], true);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) throw new RuntimeException('El helper no confirmó la acción.');
        return $decoded;
    }

    public function localContainerPrograms(): array
    {
        $status = $this->status();
        if (($status['capabilities']['local_container_programs'] ?? false) !== true) {
            throw new RuntimeException('Actualiza el helper para consultar contenedores locales.');
        }
        $result = $this->run(['local-container-programs']);
        $data = json_decode((string)$result['stdout'], true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true || ($data['scope'] ?? '') !== 'local') {
            throw new RuntimeException('Inventario local no disponible.');
        }
        return (array)($data['programs'] ?? []);
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

    public function finalizeBootstrapInstallation(): array
    {
        if (!$this->supportsAutomaticSetupFinalize()) {
            throw new RuntimeException(
                'El helper administrativo no soporta finalización automática; reinstálalo desde el repositorio actual.'
            );
        }
        $result = $this->run(['bootstrap-finalize']);
        $decoded = json_decode((string)$result['stdout'], true);
        if (!is_array($decoded) || ($decoded['ok'] ?? null) !== true || ($decoded['finalized'] ?? null) !== true) {
            throw new RuntimeException('El helper no confirmó la finalización automática.');
        }
        return $decoded;
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


    public function supportsWorkstationControl(): bool
    {
        try {
            $status = $this->status();
            return ($status['ok'] ?? false) === true
                && (int)($status['version'] ?? 0) >= 13
                && (bool)($status['capabilities']['workstation_control'] ?? false);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function workstationStatus(): array
    {
        return $this->workstationControl('workstation-status');
    }

    public function startWorkstation(): array
    {
        return $this->workstationControl('workstation-start');
    }

    public function supportsWorkstationDocumentOpen(): bool
    {
        try {
            $status = $this->status();
            return ($status['ok'] ?? false) === true
                && (int)($status['version'] ?? 0) >= 14
                && (bool)($status['capabilities']['workstation_document_open'] ?? false);
        } catch (RuntimeException) {
            return false;
        }
    }

    public function openWorkstationDocument(string $workspaceRelative): void
    {
        $workspaceRelative = trim(str_replace('\\', '/', $workspaceRelative));
        if (
            !$this->supportsWorkstationDocumentOpen()
            || !preg_match('/\Asessions\/[a-f0-9]{32}\/[^\/\x00-\x1F\x7F]{1,220}\z/u', $workspaceRelative)
        ) {
            throw new RuntimeException('Documento Workstation no permitido.');
        }

        $this->run(['workstation-open-document', $workspaceRelative]);
    }

    private function workstationControl(string $action): array
    {
        if (!$this->supportsWorkstationControl()) {
            throw new RuntimeException(
                'El helper administrativo no soporta control Workstation; reinstálalo desde el repositorio actual.'
            );
        }
        if (!in_array($action, ['workstation-status', 'workstation-start'], true)) {
            throw new RuntimeException('Acción Workstation no permitida.');
        }

        $result = $this->run([$action]);
        $decoded = json_decode((string)$result['stdout'], true);
        if (!is_array($decoded) || ($decoded['ok'] ?? null) !== true) {
            throw new RuntimeException('El helper no confirmó el estado de Workstation.');
        }

        return $decoded;
    }

    public function runServerConsole(string $commandId): string
    {
        if (!$this->supportsServerConsole()) {
            throw new RuntimeException(
                'El helper administrativo necesita actualizarse para habilitar la terminal del servidor.'
            );
        }

        if (!preg_match('/\A[a-z0-9-]{1,40}\z/', $commandId)) {
            throw new RuntimeException('Comando administrativo no permitido.');
        }

        $result = $this->run(['server-console', $commandId]);
        $decoded = json_decode((string)$result['stdout'], true);
        if (!is_array($decoded) || ($decoded['ok'] ?? null) !== true) {
            throw new RuntimeException('El helper no confirmó la ejecución del diagnóstico.');
        }

        return trim((string)($decoded['output'] ?? ''));
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
