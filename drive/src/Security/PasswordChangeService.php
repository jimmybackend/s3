<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Security;

use ArcadeCloud\Drive\Aws\SesEmailService;
use InvalidArgumentException;
use RuntimeException;

final class PasswordChangeService
{
    private const CODE_TTL_SECONDS = 600;
    private const RESEND_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;
    private const SESSION_KEY = 'profile_password_verification';

    public function __construct(
        private UserProfileRepository $repository,
        private SessionManager $session,
        private SesEmailService $mailer
    ) {
    }

    public function requestCode(int $userId, string $host): array
    {
        $profile = $this->repository->findById($userId);
        if ($profile === null) {
            throw new RuntimeException('No se encontró el perfil del usuario.');
        }

        $email = trim((string)($profile['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El perfil no tiene un correo válido para la verificación.');
        }

        $now = time();
        $state = $this->session->get(self::SESSION_KEY, []);
        if (is_array($state)
            && (int)($state['user_id'] ?? 0) === $userId
            && $now - (int)($state['sent_at'] ?? 0) < self::RESEND_SECONDS) {
            throw new InvalidArgumentException('Espera un minuto antes de solicitar otro código.');
        }

        $code = (string)random_int(100000, 999999);
        $this->mailer->sendPasswordVerification($email, $code, $host);

        $hash = password_hash($code, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('No se pudo proteger el código de verificación.');
        }

        $this->session->set(self::SESSION_KEY, [
            'user_id' => $userId,
            'hash' => $hash,
            'sent_at' => $now,
            'expires_at' => $now + self::CODE_TTL_SECONDS,
            'attempts' => 0,
        ]);

        return [
            'ok' => true,
            'message' => 'Código enviado al correo registrado.',
            'email' => $this->maskEmail($email),
            'expires_in' => self::CODE_TTL_SECONDS,
        ];
    }

    public function changePassword(
        int $userId,
        string $code,
        string $newPassword,
        string $confirmation
    ): array {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new InvalidArgumentException('El código debe contener 6 dígitos.');
        }

        $password = UserProfileValidator::password($newPassword, $confirmation);
        $state = $this->session->get(self::SESSION_KEY, []);
        if (!is_array($state) || (int)($state['user_id'] ?? 0) !== $userId) {
            throw new InvalidArgumentException('Solicita primero un código de verificación.');
        }

        if (time() > (int)($state['expires_at'] ?? 0)) {
            $this->session->remove(self::SESSION_KEY);
            throw new InvalidArgumentException('El código de verificación venció. Solicita uno nuevo.');
        }

        $attempts = (int)($state['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->session->remove(self::SESSION_KEY);
            throw new InvalidArgumentException('Se agotaron los intentos de verificación. Solicita un código nuevo.');
        }

        $hash = (string)($state['hash'] ?? '');
        if ($hash === '' || !password_verify($code, $hash)) {
            $state['attempts'] = $attempts + 1;
            $this->session->set(self::SESSION_KEY, $state);
            throw new InvalidArgumentException('El código de verificación no es correcto.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new RuntimeException('No se pudo proteger la nueva contraseña.');
        }

        $this->repository->updatePassword($userId, $passwordHash);
        $this->session->remove(self::SESSION_KEY);

        return [
            'ok' => true,
            'message' => 'Contraseña actualizada correctamente.',
        ];
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($local === '' || $domain === '') {
            return 'correo registrado';
        }

        $localFirst = function_exists('mb_substr')
            ? (string)mb_substr($local, 0, 1, 'UTF-8')
            : substr($local, 0, 1);

        $domainParts = explode('.', $domain);
        $domainName = (string)($domainParts[0] ?? '');
        $suffix = count($domainParts) > 1 ? '.' . end($domainParts) : '';
        $domainFirst = $domainName !== '' ? substr($domainName, 0, 1) : '*';

        return $localFirst
            . str_repeat('*', max(3, min(8, strlen($local) - 1)))
            . '@'
            . $domainFirst
            . '***'
            . $suffix;
    }
}
