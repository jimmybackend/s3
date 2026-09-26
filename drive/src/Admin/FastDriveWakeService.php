<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Core\DriveApplication;
use RuntimeException;
use Throwable;

final class FastDriveWakeService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;
    private const FAILURE_DETAILS = 'FastDrive wake authorization failed.';

    public function __construct(private DriveApplication $app)
    {
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        [$instanceId, $region] = $this->target();
        $instance = $this->app->ec2Gateway($region)->getInstance($instanceId);
        if (!is_array($instance)) {
            throw new RuntimeException('La instancia FastDrive configurada no fue encontrada en AWS.');
        }

        return [
            'instance_id' => $instanceId,
            'region' => $region,
            'state' => (string)($instance['State']['Name'] ?? 'unknown'),
            'private_ip' => (string)($instance['PrivateIpAddress'] ?? ''),
        ];
    }

    /** @return array{state:string,changed:bool} */
    public function authorizeAndStart(string $password, string $ipAddress): array
    {
        $ipAddress = $this->normalizeIp($ipAddress);
        $this->assertAttemptBudget($ipAddress);

        $userId = $this->verifyActiveSuperAdminPassword($password);
        if ($userId <= 0) {
            $this->recordFailure($ipAddress);
            throw new RuntimeException('Contraseña de superadministrador inválida.');
        }

        [$instanceId, $region] = $this->target();
        $gateway = $this->app->ec2Gateway($region);
        $instance = $gateway->getInstance($instanceId);
        if (!is_array($instance)) {
            throw new RuntimeException('La instancia FastDrive configurada no fue encontrada en AWS.');
        }

        $state = (string)($instance['State']['Name'] ?? 'unknown');
        if ($state === 'stopped') {
            $gateway->start($instanceId);
            $this->audit($userId, $ipAddress, $instanceId, true);
            return ['state' => 'pending', 'changed' => true];
        }

        if (in_array($state, ['pending', 'running'], true)) {
            $this->audit($userId, $ipAddress, $instanceId, false);
            return ['state' => $state, 'changed' => false];
        }

        throw new RuntimeException('FastDrive no está en un estado que permita iniciar.');
    }

    private function verifyActiveSuperAdminPassword(string $password): int
    {
        if ($password === '' || strlen($password) > 4096) {
            return 0;
        }

        foreach ($this->app->authenticationRepository()->findActiveSuperAdmins() as $row) {
            $hash = (string)($row['password'] ?? '');
            if ($hash !== '' && password_verify($password, $hash)) {
                return (int)($row['id'] ?? 0);
            }
        }

        return 0;
    }

    private function assertAttemptBudget(string $ipAddress): void
    {
        if ($ipAddress === '') {
            throw new RuntimeException('No se pudo validar el origen de la solicitud.');
        }

        $details = self::FAILURE_DETAILS;
        $stmt = $this->app->db()->prepare(
            "SELECT COUNT(*) AS attempts
             FROM AccessControl
             WHERE action = 'Otro'
               AND ip_address = ?
               AND action_details = ?
               AND date_time >= (NOW() - INTERVAL " . self::WINDOW_MINUTES . " MINUTE)"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo validar el límite de intentos.');
        }

        $stmt->bind_param('ss', $ipAddress, $details);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int)($row['attempts'] ?? 0) >= self::MAX_FAILED_ATTEMPTS) {
            throw new RuntimeException('Demasiados intentos fallidos. Espera 15 minutos.');
        }
    }

    private function recordFailure(string $ipAddress): void
    {
        if ($ipAddress === '') {
            return;
        }

        try {
            $action = 'Otro';
            $details = self::FAILURE_DETAILS;
            $stmt = $this->app->db()->prepare(
                'INSERT INTO AccessControl (user_id, date_time, action, ip_address, action_details) '
                . 'VALUES (NULL, NOW(), ?, ?, ?)'
            );
            if (!$stmt) return;
            $stmt->bind_param('sss', $action, $ipAddress, $details);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {
        }
    }

    private function audit(int $userId, string $ipAddress, string $instanceId, bool $started): void
    {
        try {
            $action = 'Otro';
            $details = $started
                ? 'Superadmin autorizó encendido de FastDrive: ' . $instanceId . '.'
                : 'Superadmin validó acceso a FastDrive ya iniciado: ' . $instanceId . '.';
            $stmt = $this->app->db()->prepare(
                'INSERT INTO AccessControl (user_id, date_time, action, ip_address, action_details) '
                . 'VALUES (?, NOW(), ?, ?, ?)'
            );
            if (!$stmt) return;
            $stmt->bind_param('isss', $userId, $action, $ipAddress, $details);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {
        }
    }

    /** @return array{0:string,1:string} */
    private function target(): array
    {
        $instanceId = trim((string)(getenv('ARCADECLOUD_FASTDRIVE_INSTANCE_ID') ?: ''));
        $region = trim((string)(getenv('ARCADECLOUD_FASTDRIVE_REGION') ?: ''));

        if (!preg_match('/^i-[0-9a-f]{8,17}$/i', $instanceId)) {
            throw new RuntimeException('FastDrive no está configurado correctamente.');
        }
        if ($region === '') {
            $region = \Config::getRegion();
        }
        if (!preg_match('/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $region)) {
            throw new RuntimeException('La región de FastDrive no es válida.');
        }

        return [$instanceId, $region];
    }

    private function normalizeIp(string $ipAddress): string
    {
        $ipAddress = trim($ipAddress);
        return filter_var($ipAddress, FILTER_VALIDATE_IP) !== false
            ? substr($ipAddress, 0, 45)
            : '';
    }
}
