<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Security\SuperAdminReauthenticationService;
use RuntimeException;
use Throwable;

final class FastDriveControlService
{
    public function __construct(private DriveApplication $app) {}

    public function status(): array
    {
        $this->requireSuperAdmin();
        [$instanceId, $region] = $this->target();

        $instance = $this->app->ec2Gateway($region)->getInstance($instanceId);
        if (!is_array($instance)) {
            throw new RuntimeException('La instancia FastDrive configurada no fue encontrada en AWS.');
        }

        return $this->normalize($instanceId, $region, $instance);
    }

    public function start(string $currentPassword): array
    {
        $this->requireSuperAdmin();

        // A diferencia del updater, aquí se exige la contraseña en cada intento
        // de encendido: no se reutiliza la elevación temporal de 10 minutos.
        (new SuperAdminReauthenticationService($this->app))->verify($currentPassword);

        [$instanceId, $region] = $this->target();
        $gateway = $this->app->ec2Gateway($region);
        $instance = $gateway->getInstance($instanceId);
        if (!is_array($instance)) {
            throw new RuntimeException('La instancia FastDrive configurada no fue encontrada en AWS.');
        }

        $state = (string)($instance['State']['Name'] ?? 'unknown');
        if (in_array($state, ['running', 'pending'], true)) {
            return [
                'ok' => true,
                'changed' => false,
                'message' => 'FastDrive ya está encendido o iniciándose.',
                'state' => $state,
            ];
        }

        if ($state !== 'stopped') {
            throw new RuntimeException(
                "FastDrive está en estado '{$state}'. AWS sólo permite iniciar esta operación desde 'stopped'."
            );
        }

        $gateway->start($instanceId);
        $this->auditStart($instanceId);

        return [
            'ok' => true,
            'changed' => true,
            'message' => 'AWS aceptó la orden de encender FastDrive.',
            'state' => 'pending',
        ];
    }

    /** @return array{0:string,1:string} */
    private function target(): array
    {
        $instanceId = trim((string)(getenv('ARCADECLOUD_FASTDRIVE_INSTANCE_ID') ?: ''));
        $region = trim((string)(getenv('ARCADECLOUD_FASTDRIVE_REGION') ?: ''));

        if ($instanceId === '') {
            throw new RuntimeException(
                'Control FastDrive no configurado: falta ARCADECLOUD_FASTDRIVE_INSTANCE_ID en este nodo.'
            );
        }
        if (!preg_match('/^i-[0-9a-f]{8,17}$/i', $instanceId)) {
            throw new RuntimeException('ARCADECLOUD_FASTDRIVE_INSTANCE_ID no contiene un ID EC2 válido.');
        }
        if ($region === '') {
            $region = \Config::getRegion();
        }
        if (!preg_match('/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $region)) {
            throw new RuntimeException('ARCADECLOUD_FASTDRIVE_REGION no contiene una región AWS válida.');
        }

        return [$instanceId, $region];
    }

    private function requireSuperAdmin(): void
    {
        $session = $this->app->session();
        $session->start();
        if (!$session->isAuthenticated() || !$session->isSuperAdmin()) {
            throw new RuntimeException('Se requiere una sesión superadmin válida.');
        }
    }

    /** @param array<string,mixed> $instance */
    private function normalize(string $instanceId, string $region, array $instance): array
    {
        return [
            'ok' => true,
            'instance_id' => $instanceId,
            'region' => $region,
            'state' => (string)($instance['State']['Name'] ?? 'unknown'),
            'instance_type' => (string)($instance['InstanceType'] ?? ''),
            'private_ip' => (string)($instance['PrivateIpAddress'] ?? ''),
            'public_ip' => (string)($instance['PublicIpAddress'] ?? ''),
            'availability_zone' => (string)($instance['Placement']['AvailabilityZone'] ?? ''),
        ];
    }

    private function auditStart(string $instanceId): void
    {
        try {
            $userId = $this->app->session()->userId();
            if ($userId <= 0) return;

            $ip = isset($_SERVER['REMOTE_ADDR'])
                ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45)
                : '';
            $action = 'Otro';
            $details = 'Superadmin autorizó encendido manual de FastDrive EC2 ' . $instanceId . '.';

            $stmt = $this->app->db()->prepare(
                'INSERT INTO AccessControl (user_id, date_time, action, ip_address, action_details) '
                . 'VALUES (?, NOW(), ?, ?, ?)'
            );
            if (!$stmt) return;
            $stmt->bind_param('isss', $userId, $action, $ip, $details);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {
            // La auditoría no debe convertir un StartInstances exitoso en error.
        }
    }
}
