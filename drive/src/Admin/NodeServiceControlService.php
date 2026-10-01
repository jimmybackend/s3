<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Security\SuperAdminReauthenticationService;
use ArcadeCloud\Drive\System\NodeServiceCatalog;
use RuntimeException;
use Throwable;

/** Executes only catalogued component/action pairs through the root allowlist helper. */
final class NodeServiceControlService
{
    public function __construct(private DriveApplication $app, private ?PrivilegedServerHelper $helper = null) {}

    public function execute(string $componentId, string $action, string $password): array
    {
        $definition = NodeServiceCatalog::component($componentId);
        if ($definition === null || !in_array($action, (array)$definition['allowed_actions'], true)) {
            throw new RuntimeException('Acción de componente no permitida.');
        }
        if (($definition['critical'] ?? true) === true) throw new RuntimeException('Los servicios críticos son de solo lectura.');

        (new SuperAdminReauthenticationService($this->app))->requireRecent($password);
        $helper = $this->helper ?? new PrivilegedServerHelper();
        $success = false;
        try {
            $result = $helper->controlNodeComponent($componentId, $action);
            $success = true;
            return ['component' => $componentId, 'action' => $action, 'state' => $result,
                'message' => $action === 'run-now' ? 'La tarea se inició; el estado se actualizará en breve.' : 'Acción completada correctamente.'];
        } finally {
            $this->audit($componentId, $action, $success);
        }
    }

    private function audit(string $componentId, string $action, bool $success): void
    {
        try {
            $userId = $this->app->session()->userId();
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $details = sprintf('Mi nodo: %s sobre %s en nodo local: %s.', $action, $componentId, $success ? 'correcto' : 'error');
            $kind = 'Otro';
            $stmt = $this->app->db()->prepare('INSERT INTO AccessControl (user_id, date_time, action, ip_address, action_details) VALUES (?, NOW(), ?, ?, ?)');
            if (!$stmt) return;
            $stmt->bind_param('isss', $userId, $kind, $ip, $details); $stmt->execute(); $stmt->close();
        } catch (Throwable) {}
    }
}
