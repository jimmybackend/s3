<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Federation\FederationSchemaMigrationService;
use ArcadeCloud\Drive\Security\SuperAdminReauthenticationService;
use RuntimeException;
use Throwable;

final class ArcadeCloudUpdaterService
{
    private const HELPER = '/usr/local/sbin/arcadecloud-drive-updater';
    private const SUDO = '/usr/bin/sudo';

    public function __construct(private DriveApplication $app) {}

    public function check(): array
    {
        $state = $this->run('check');
        $state['helper_available'] = true;
        return $this->withFederationSchemaState($state, true);
    }

    public function apply(string $currentPassword): array
    {
        (new SuperAdminReauthenticationService($this->app))->requireRecent($currentPassword);
        $result = $this->run('apply');
        $result = $this->withFederationSchemaState($result, true);
        $this->audit((string)($result['previous_commit'] ?? ''), (string)($result['local_commit'] ?? ''));
        return $result;
    }

    private function withFederationSchemaState(array $state, bool $reconcileMissing): array
    {
        try {
            $migrator = new FederationSchemaMigrationService($this->app->db());
            $schema = $migrator->status();
            if ($reconcileMissing && ($schema['ready'] ?? false) !== true) {
                $schema = $migrator->reconcile();
            }
            $state['federation_schema_ready'] = (bool)($schema['ready'] ?? false);
            $state['federation_schema_reconciled'] = (bool)($schema['reconciled'] ?? false);
            $state['federation_schema_message'] = (string)($schema['message'] ?? (
                ($schema['ready'] ?? false)
                    ? 'Esquema FederationCloud preparado.'
                    : 'Esquema FederationCloud pendiente.'
            ));
        } catch (Throwable $e) {
            $state['federation_schema_ready'] = false;
            $state['federation_schema_reconciled'] = false;
            $state['federation_schema_message'] = $e->getMessage();
        }
        return $state;
    }

    private function run(string $action): array
    {
        if (!in_array($action, ['check', 'apply'], true)) throw new RuntimeException('Acción de actualización no permitida.');
        if (!function_exists('proc_open') || !is_executable(self::SUDO) || !is_executable(self::HELPER)) {
            throw new RuntimeException($this->installMessage());
        }

        $cmd = [self::SUDO, '-n', self::HELPER, $action];
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) throw new RuntimeException('No se pudo iniciar ArcadeCloud Updater.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 131073);
        $stderr = stream_get_contents($pipes[2], 32769);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($proc);

        if (!is_string($stdout) || strlen($stdout) > 131072 || !is_string($stderr) || strlen($stderr) > 32768) {
            throw new RuntimeException('Respuesta del updater demasiado grande.');
        }
        if ($exit !== 0) {
            $message = trim($stderr);
            $lower = strtolower($message);
            if (str_contains($lower, 'sudo')
                && (str_contains($lower, 'password is required')
                    || str_contains($lower, 'a password is required')
                    || str_contains($lower, 'not allowed to execute')
                    || str_contains($lower, 'no tty present'))) {
                throw new RuntimeException(
                    'ArcadeCloud Updater perdió la autorización NOPASSWD del usuario php-fpm-drive. '
                    . 'Ejecuta en el servidor: sudo bash drive/bin/install_arcadecloud.sh --reconcile '
                    . '--app-root=/var/www/arcadecloud-drive'
                );
            }
            throw new RuntimeException($message !== '' ? $message : 'ArcadeCloud Updater rechazó la operación.');
        }

        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) throw new RuntimeException('Respuesta inválida de ArcadeCloud Updater.');
        return $decoded;
    }

    private function installMessage(): string
    {
        $root = dirname(__DIR__, 2);
        return 'ArcadeCloud Updater no está instalado. Ejecuta: sudo bash ' . $root . '/bin/install_arcadecloud_updater.sh --php-user=USUARIO_REAL_PHP_FPM';
    }

    private function audit(string $before, string $after): void
    {
        try {
            $userId = $this->app->session()->userId();
            if ($userId <= 0) return;
            $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : '';
            $details = 'Superadmin actualizó ArcadeCloud mediante updater web: ' . substr($before, 0, 12) . ' -> ' . substr($after, 0, 12) . '.';
            $action = 'Otro';
            $stmt = $this->app->db()->prepare('INSERT INTO AccessControl (user_id, date_time, action, ip_address, action_details) VALUES (?, NOW(), ?, ?, ?)');
            if (!$stmt) return;
            $stmt->bind_param('isss', $userId, $action, $ip, $details);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {}
    }
}
