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
        $helperAvailable = $this->helper->available();
        $groupSupport = $helperAvailable && $this->helper->supportsEnvironmentGroups();
        return [
            'ok' => true,
            'helper_available' => $helperAvailable,
            'helper_group_settings' => $groupSupport,
            'helper_path' => PrivilegedServerHelper::HELPER_PATH,
            'runtime_user' => $runtime,
            'managed_env_path' => ManagedRuntimeEnvironment::DEFAULT_PATH,
            'settings' => ManagedRuntimeEnvironment::publicState(),
            'install_command' => 'sudo bash ' . $driveRoot . '/bin/install_arcadecloud_admin_helper.sh --php-user=' . $runtime,
            'security_boundary' => 'La UI administra únicamente variables de runtime declaradas por ArcadeCloud y no ejecuta comandos arbitrarios.',
        ];
    }

    public function set(string $name, string $value, string $currentPassword): array
    {
        (new SuperAdminReauthenticationService($this->app))->verify($currentPassword);
        if (!ManagedRuntimeEnvironment::isAllowed($name)) throw new RuntimeException('Variable no permitida.');
        if ((string)(ManagedRuntimeEnvironment::DEFINITIONS[$name]['atomic_group'] ?? '') !== '') {
            throw new RuntimeException('Esta variable pertenece a un grupo que debe guardarse completo desde el panel Servidor.');
        }

        $value = ManagedRuntimeEnvironment::validateValue($name, $value);
        $this->assertHelperAvailable();
        $this->helper->setEnvironment($name, $value);
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $this->audit([$name]);

        return [
            'ok' => true,
            'message' => 'Variable ArcadeCloud actualizada. El nuevo valor se aplicará a las siguientes peticiones.',
            'name' => $name,
            'secret' => (bool)(ManagedRuntimeEnvironment::DEFINITIONS[$name]['secret'] ?? false),
        ];
    }

    public function setGroup(string $group, array $values, string $currentPassword): array
    {
        (new SuperAdminReauthenticationService($this->app))->verify($currentPassword);
        $group = strtolower(trim($group));
        $allowedNames = ManagedRuntimeEnvironment::namesForAtomicGroup($group);
        if ($allowedNames === []) throw new RuntimeException('Grupo de configuración no permitido.');

        foreach ($values as $name => $value) {
            if (!is_string($name) || !in_array($name, $allowedNames, true) || !is_string($value)) {
                throw new RuntimeException('El grupo contiene una variable no permitida.');
            }
        }

        $stateByName = [];
        foreach (ManagedRuntimeEnvironment::publicState() as $row) {
            $stateByName[(string)$row['name']] = $row;
        }

        $validated = [];
        foreach ($allowedNames as $name) {
            if (!array_key_exists($name, $values)) continue;
            $raw = (string)$values[$name];
            $meta = ManagedRuntimeEnvironment::DEFINITIONS[$name];
            $required = (bool)($meta['required'] ?? false);
            $configured = (bool)($stateByName[$name]['configured'] ?? false);

            // Dejar vacío conserva un valor existente. Los opcionales vacíos no se escriben.
            if ($raw === '' && ($configured || !$required)) continue;
            $validated[$name] = ManagedRuntimeEnvironment::validateValue($name, $raw);
        }

        foreach ($allowedNames as $name) {
            $required = (bool)(ManagedRuntimeEnvironment::DEFINITIONS[$name]['required'] ?? false);
            if (!$required) continue;
            if (!$this->effectiveConfigured($name, $validated, $stateByName)) {
                throw new RuntimeException($name . ' es obligatorio para guardar este grupo.');
            }
        }

        if ($group === 'aws') {
            $controlKey = $this->effectiveConfigured('AWS_CONTROL_ACCESS_KEY_ID', $validated, $stateByName);
            $controlSecret = $this->effectiveConfigured('AWS_CONTROL_SECRET_ACCESS_KEY', $validated, $stateByName);
            if ($controlKey !== $controlSecret) {
                throw new RuntimeException('AWS_CONTROL_ACCESS_KEY_ID y AWS_CONTROL_SECRET_ACCESS_KEY deben configurarse juntos.');
            }
        }

        if ($validated === []) throw new RuntimeException('No hay cambios nuevos para guardar.');
        if ($group === 'database') $this->testDatabaseConnection($validated);

        $this->assertHelperAvailable(true);
        $this->helper->setEnvironmentMany($validated);
        foreach ($validated as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
        $this->audit(array_keys($validated));

        return [
            'ok' => true,
            'message' => $group === 'database'
                ? 'Conexión de base de datos verificada y configuración guardada.'
                : 'Configuración AWS guardada.',
            'group' => $group,
            'updated' => array_keys($validated),
        ];
    }

    private function effectiveConfigured(string $name, array $validated, array $stateByName): bool
    {
        if (array_key_exists($name, $validated)) return (string)$validated[$name] !== '';
        return (bool)($stateByName[$name]['configured'] ?? false);
    }

    private function effectiveValue(string $name, array $validated, string $default = ''): string
    {
        if (array_key_exists($name, $validated)) return (string)$validated[$name];
        $value = getenv($name);
        return $value !== false && (string)$value !== '' ? (string)$value : $default;
    }

    private function testDatabaseConnection(array $validated): void
    {
        $host = $this->effectiveValue('DB_HOST', $validated);
        $user = $this->effectiveValue('DB_USER', $validated);
        $password = $this->effectiveValue('DB_PASSWORD', $validated);
        $database = $this->effectiveValue('DB_NAME', $validated);
        $portRaw = $this->effectiveValue('DB_PORT', $validated, '3306');
        $port = filter_var($portRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($host === '' || $user === '' || $password === '' || $database === '' || $port === false) {
            throw new RuntimeException('Completa HOST, USER, PASSWORD, NAME y un PORT válido antes de guardar la base de datos.');
        }

        $probe = \mysqli_init();
        if (!$probe) throw new RuntimeException('No se pudo inicializar la prueba MySQL.');
        \mysqli_options($probe, MYSQLI_OPT_CONNECT_TIMEOUT, 8);
        $connected = @\mysqli_real_connect($probe, $host, $user, $password, $database, (int)$port);
        if (!$connected) {
            @\mysqli_close($probe);
            throw new RuntimeException('No se guardó ningún cambio: no fue posible conectar con la base de datos indicada.');
        }
        @\mysqli_set_charset($probe, 'utf8mb4');
        @\mysqli_close($probe);
    }

    private function assertHelperAvailable(bool $requireGroups = false): void
    {
        $available = $this->helper->available();
        $supportsGroups = !$requireGroups || ($available && $this->helper->supportsEnvironmentGroups());
        if ($available && $supportsGroups) return;

        $runtime = $this->runtimeUser();
        $driveRoot = dirname(__DIR__, 2);
        $reason = $available && $requireGroups ? 'El helper instalado necesita actualizarse.' : 'El helper administrativo no está instalado.';
        throw new RuntimeException(
            $reason . ' Ejecuta: sudo bash ' . $driveRoot . '/bin/install_arcadecloud_admin_helper.sh --php-user=' . $runtime
        );
    }

    private function audit(array $names): void
    {
        try {
            $userId = $this->app->session()->userId();
            if ($userId <= 0 || $names === []) return;
            $action = 'Otro';
            $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : '';
            $details = 'Superadmin actualizó configuración administrada: ' . implode(', ', $names) . '. Los valores no se registran.';
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
