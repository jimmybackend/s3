<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use ArcadeCloud\Drive\Mail\SmtpEmailService;

/**
 * Adaptador temporal de compatibilidad.
 *
 * El perfil dejó de usar Amazon SES para los códigos de contraseña.
 * Se conserva este nombre para no romper el ensamblado actual de DriveApplication;
 * el envío real se delega al servicio SMTP OOP configurado por entorno.
 */
final class SesEmailService
{
    private SmtpEmailService $smtp;

    public function __construct(?SmtpEmailService $smtp = null)
    {
        $this->smtp = $smtp ?? SmtpEmailService::fromEnvironment();
    }

    public function sendPasswordVerification(string $recipient, string $code, string $host): void
    {
        $this->smtp->sendPasswordVerification($recipient, $code, $host);
    }
}
