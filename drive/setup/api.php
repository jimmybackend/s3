<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Admin\ManagedRuntimeEnvironment;
use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use ArcadeCloud\Drive\Setup\BootstrapSetupAuth;
use ArcadeCloud\Drive\Setup\SetupConfigurationService;
use ArcadeCloud\Drive\Setup\SuperAdminBootstrapService;

$driveRoot = dirname(__DIR__);
require_once $driveRoot . '/src/Admin/ManagedRuntimeEnvironment.php';
require_once $driveRoot . '/src/Admin/PrivilegedServerHelper.php';
require_once $driveRoot . '/src/Setup/BootstrapSetupAuth.php';
require_once $driveRoot . '/src/Setup/SetupConfigurationService.php';
require_once $driveRoot . '/src/Setup/SuperAdminBootstrapService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function setupJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function setupPost(string $name, string $default = ''): string
{
    $value = $_POST[$name] ?? $default;
    return is_string($value) ? $value : $default;
}

try {
    $auth = new BootstrapSetupAuth();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = strtolower(trim(setupPost('action', $method === 'GET' ? 'status' : '')));

    if ($method === 'GET' || $action === 'status') {
        $status = $auth->status();
        $response = ['ok' => true, 'setup' => $status, 'csrf' => $auth->csrfToken()];
        if (($status['authenticated'] ?? false) === true) {
            $config = new SetupConfigurationService();
            $helper = new PrivilegedServerHelper();
            $response['settings'] = $config->state();
            $response['database_ready'] = $config->databaseReady();
            $response['helper'] = $helper->status();
        }
        setupJson($response);
    }

    if ($method !== 'POST') setupJson(['ok' => false, 'error' => 'Método no permitido.'], 405);

    if ($action === 'login') {
        $ok = $auth->login(
            setupPost('username'),
            setupPost('password'),
            setupPost('csrf')
        );
        if (!$ok) setupJson(['ok' => false, 'error' => 'Credenciales bootstrap incorrectas.'], 403);
        setupJson(['ok' => true, 'message' => 'Supervisor temporal autenticado.', 'csrf' => $auth->csrfToken()]);
    }

    $csrf = (string)($_SERVER['HTTP_X_ARCADECLOUD_SETUP_CSRF'] ?? '');
    $auth->requireAuthenticated($csrf);

    if ($action === 'save_group') {
        $decoded = json_decode(setupPost('values_json', '{}'), true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            setupJson(['ok' => false, 'error' => 'Payload de configuración inválido.'], 400);
        }
        $service = new SetupConfigurationService();
        setupJson($service->saveGroup(setupPost('group'), $decoded));
    }

    if ($action === 'create_superadmin') {
        $service = new SuperAdminBootstrapService();
        $result = $service->create([
            'firstname' => setupPost('firstname'),
            'lastname' => setupPost('lastname'),
            'email' => setupPost('email'),
            'password' => setupPost('password'),
        ]);
        $auth->logout();
        setupJson($result);
    }

    if ($action === 'logout') {
        $auth->logout();
        setupJson(['ok' => true, 'message' => 'Sesión de setup cerrada.']);
    }

    setupJson(['ok' => false, 'error' => 'Acción no permitida.'], 400);
} catch (RuntimeException $e) {
    setupJson(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('[ArcadeCloud setup] ' . $e->getMessage());
    setupJson(['ok' => false, 'error' => 'No se pudo completar la operación de instalación.'], 500);
}
