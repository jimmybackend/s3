<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Security\SuperAdminReauthenticationService;
use RuntimeException;
use Throwable;

final class ServerSettingsAdminService
{
    private PrivilegedServerHelper $helper;

    public function __construct(private DriveApplication $app)
    {
        $this->helper = new PrivilegedServerHelper();
    }

    public function state(): array
    {
        $runtime = $this->runtimeUser();
        $driveRoot = dirname(__DIR__, 2);
        return [
            'ok' => true,
            'helper_available' => $this->helper->available(),
            'helper_path' => PrivilegedServerHelper::HELPER_PATH,
            'runtime_user' => $runtime,
            'managed_env_path' => ManagedRuntimeEnvironment::DEFAULT_PATH,
            'settings' => ManagedRuntimeEnvironment::publicState(),
            'install_command' => 'sudo bash ' . $driveRoot . '/bin/install_arcadecloud_admin_helper.sh --php-user=' . $runtime,
            'security_boundary' => 'La UI sólo puede modificar variables ArcadeCloud incluidas en la allowlist; no ejecuta comandos arbitrarios ni edita credenciales MySQL/AWS.',
        ];
    }

    public function set(string $name, string $value, string $currentPassword): array
    {
        (new SuperAdminReauthenticationService($this->app))->verify($currentPassword);
        $value = ManagedRuntimeEnvironment::validateValue($name, $value);
        if (!$this->helper->available()) {
            $runtime = $this->runtimeUser();
            $driveRoot = dirname(__DIR__, 2);
            throw new RuntimeException(
                'El helper administrativo no está instalado. Preparación única requerida: sudo bash ' .
                $driveRoot . '/bin/install_arcadecloud_admin_helper.sh --php-user=' . $runtime
            );
        }

        $this->helper->setEnvironment($name, $value);
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $this->audit($name);

        return [
            'ok' => true,
            'message' => 'Variable ArcadeCloud actualizada. El nuevo valor se aplicará a las siguientes peticiones.',
            'name' => $name,
            'secret' => (bool)(ManagedRuntimeEnvironment::DEFINITIONS[$name]['secret'] ?? false),
        ];
    }

    private function audit(string $name): void
    {
        try {
            $userId = $this->app->session()->userId();
            if ($userId <= 0) return;
            $action = 'Otro';
            $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : '';
            $details = 'Superadmin actualizó variable administrada ' . $name . '. El valor no se registra.';
            $stmt = $this->app->db()->prepare(
                'INSERT INTO AccessControl (user_id, date_time, action, ip_address, action_details) VALUES (?, NOW(), ?, ?, ?)'
            );
            if (!$stmt) return;
            $stmt->bind_param('isss', $userId, $action, $ip, $details);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {}
    }

    private function runtimeUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && is_string($info['name'] ?? null) && $info['name'] !== '') return $info['name'];
        }
        return 'USUARIO_PHP_FPM';
    }
}
