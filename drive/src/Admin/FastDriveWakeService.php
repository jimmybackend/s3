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
    private const FAILURE_AUDIT = 'FastDrive wake authorization failed.';

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

        return $this->normalize($instanceId, $region, $instance);
    }

    /** @return array{ok:bool,changed:bool,state:string,message:string} */
    public function authorizeAndStart(string $password, string $ipAddress): array
    {
        if ($password === '' || strlen($password) > 4096) {
            $this->recordDeniedAttempt($ipAddress);
            throw new RuntimeException('Credenciales inválidas.');
        }

        $ipAddress = $this->normalizeIp($ipAddress);
        $this->assertAttemptBudget($ipAddress);

        $userId = $this->verifyActiveSuperAdminPassword($password);
        if ($userId <= 0) {
            $this->recordDeniedAttempt($ipAddress);
            throw new RuntimeException('Credenciales inválidas.');
        }

        [$instanceId, $region] = $this->target();
        $gateway = $this->app->ec2Gateway($region);
        $instance = $gateway->getInstance($instanceId);
        if (!is_array($instance)) {
            throw new RuntimeException('La instancia FastDrive configurada no fue encontrada en AWS.');
        }

        $state = (string)($instance['State']['Name'] ?? 'unknown');
        if (in_array($state, ['running', 'pending'], true)) {
            $this->recordSuccessfulAuthorization($userId, $ipAddress, $instanceId, false);

            return [
                'ok' => true,
                'changed' => false,
                'state' => $state,
                'message' => 'FastDrive ya está encendido o iniciándose.',
            ];
        }

        if ($state !== 'stopped') {
            throw new RuntimeException('FastDrive todavía no está disponible para iniciar.');
        }

        $gateway->start($instanceId);
        $this->recordSuccessfulAuthorization($userId, $ipAddress, $instanceId, true);

        return [
            'ok' => true,
            'changed' => true,
            'state' => 'pending',
            'message' => 'Autorización correcta. AWS aceptó el encendido de FastDrive.',
        ];
    }

    /** @return array{0:string,1:string} */
    private function target(): array
    {
        $instanceId = trim((string)(getenv('ARCADECLOUD_FASTDRIVE_INSTANCE_ID') ?: ''));
        $region = trim((string)(getenv('ARCADECLOUD_FASTDRIVE_REGION') ?: ''));

        if ($instanceId === '') {
            throw new RuntimeException('Control FastDrive no configurado.');
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

    private function verifyActiveSuperAdminPassword(string $password): int
    {
        $result = $this->app->db()->query(
            "SELECT id, password
             FROM Users
             WHERE system_role = 'superadmin' AND userstatus = 'Activo'
             ORDER BY id ASC"
        );
        if (!$result) {
            throw new RuntimeException('No se pudo verificar la autorización del superadmin.');
        }

        $matchedId = 0;
        while ($row = $result->fetch_assoc()) {
            $hash = (string)($row['password'] ?? '');
            if ($hash !== '' && password_verify($password, $hash)) {
                $matchedId = (int)($row['id'] ?? 0);
                break;
            }
        }
        $result->free();

        return $matchedId;
    }

    private function assertAttemptBudget(string $ipAddress): void
    {
        if ($ipAddress === '') {
            throw new RuntimeException('No se pudo validar el origen de la solicitud.');
        }

        $details = self::FAILURE_AUDIT;
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
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo validar el límite de intentos.');
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $attempts = (int)($row['attempts'] ?? 0);

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            throw new RuntimeException(
                'Demasiados intentos fallidos. Espera 15 minutos antes de volver a intentarlo.'
            );
        }
    }

    private function recordDeniedAttempt(string $ipAddress): void
    {
        try {
            $ipAddress = $this->normalizeIp($ipAddress);
            if ($ipAddress === '') {
                return;
            }

            $action = 'Otro';
            $details = self::FAILURE_AUDIT;
            $stmt = $this->app->db()->prepare(
                'INSERT INTO AccessControl (user_id, date_time, action, ip_address, action_details) '
                . 'VALUES (NULL, NOW(), ?, ?, ?)'
            );
            if (!$stmt) {
                return;
            }

            $stmt->bind_param('sss', $action, $ipAddress, $details);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {
            // Nunca registrar contraseñas ni convertir un rechazo normal en otro error.
        }
    }

    private function recordSuccessfulAuthorization(
        int $userId,
        string $ipAddress,
        string $instanceId,
        bool $started
    ): void {
        try {
            $action = 'Otro';
            $details = $started
                ? 'Superadmin autorizó encendido de FastDrive mediante fastdrive.esforzados.com: ' . $instanceId . '.'
                : 'Superadmin revalidó acceso al gateway de FastDrive ya iniciado: ' . $instanceId . '.';

            $stmt = $this->app->db()->prepare(
                'INSERT INTO AccessControl (user_id, date_time, action, ip_address, action_details) '
                . 'VALUES (?, NOW(), ?, ?, ?)'
            );
            if (!$stmt) {
                return;
            }

            $stmt->bind_param('isss', $userId, $action, $ipAddress, $details);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable) {
            // La auditoría no debe transformar un StartInstances exitoso en error.
        }
    }

    private function normalizeIp(string $ipAddress): string
    {
        $ipAddress = trim($ipAddress);
        if ($ipAddress === '' || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        return substr($ipAddress, 0, 45);
    }

    /** @param array<string,mixed> $instance
     *  @return array<string,mixed>
     */
    private function normalize(string $instanceId, string $region, array $instance): array
    {
        return [
            'ok' => true,
            'instance_id' => $instanceId,
            'region' => $region,
            'state' => (string)($instance['State']['Name'] ?? 'unknown'),
            'instance_type' => (string)($instance['InstanceType'] ?? ''),
            'private_ip' => (string)($instance['PrivateIpAddress'] ?? ''),
            'availability_zone' => (string)($instance['Placement']['AvailabilityZone'] ?? ''),
        ];
    }
}
