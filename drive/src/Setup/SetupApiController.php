<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Setup;

use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use RuntimeException;
use Throwable;

final class SetupApiController
{
    public function run(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');

        try {
            $auth = new BootstrapSetupAuth();
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $action = strtolower(trim($this->post('action', $method === 'GET' ? 'status' : '')));

            if ($method === 'GET' || $action === 'status') {
                $status = $auth->status();
                $response = ['ok' => true, 'setup' => $status, 'csrf' => $auth->csrfToken()];
                if (($status['authenticated'] ?? false) === true) {
                    $config = new SetupConfigurationService();
                    $helper = new PrivilegedServerHelper();
                    $response['settings'] = $config->state();
                    $response['database_ready'] = $config->databaseReady();
                    $response['aws_ready'] = $config->awsReady();
                    $response['basic_ready'] = $config->basicReady();
                    $response['helper'] = $helper->status();
                }
                $this->json($response);
            }

            if ($method !== 'POST') {
                $this->json(['ok' => false, 'error' => 'Método no permitido.'], 405);
            }

            if ($action === 'login') {
                $ok = $auth->login(
                    $this->post('username'),
                    $this->post('password'),
                    $this->post('csrf')
                );
                if (!$ok) {
                    $this->json(['ok' => false, 'error' => 'Credenciales bootstrap incorrectas.'], 403);
                }
                $this->json([
                    'ok' => true,
                    'message' => 'Supervisor temporal autenticado.',
                    'csrf' => $auth->csrfToken(),
                ]);
            }

            $csrf = (string)($_SERVER['HTTP_X_ARCADECLOUD_SETUP_CSRF'] ?? '');
            $auth->requireAuthenticated($csrf);

            if ($action === 'save_group') {
                $decoded = json_decode($this->post('values_json', '{}'), true);
                if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                    $this->json(['ok' => false, 'error' => 'Payload de configuración inválido.'], 400);
                }
                $this->json((new SetupConfigurationService())->saveGroup(
                    $this->post('group'),
                    $decoded
                ));
            }

            if ($action === 'create_superadmin') {
                $result = (new SuperAdminBootstrapService())->create([
                    'firstname' => $this->post('firstname'),
                    'lastname' => $this->post('lastname'),
                    'email' => $this->post('email'),
                    'password' => $this->post('password'),
                ]);
                $auth->logout();
                $this->json($result);
            }

            if ($action === 'logout') {
                $auth->logout();
                $this->json(['ok' => true, 'message' => 'Sesión de setup cerrada.']);
            }

            $this->json(['ok' => false, 'error' => 'Acción no permitida.'], 400);
        } catch (RuntimeException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            error_log('[ArcadeCloud setup] ' . $e->getMessage());
            $this->json(['ok' => false, 'error' => 'No se pudo completar la operación de instalación.'], 500);
        }
    }

    private function post(string $name, string $default = ''): string
    {
        $value = $_POST[$name] ?? $default;
        return is_string($value) ? $value : $default;
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
