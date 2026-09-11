<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use Aws\Ses\SesClient;
use RuntimeException;
use Throwable;

final class SesEmailService
{
    private SesClient $ses;

    public function __construct(?SesClient $ses = null)
    {
        $this->ses = $ses ?? new SesClient(
            \Config::getAwsClientConfig(['version' => '2010-12-01'])
        );
    }

    public function sendPasswordVerification(string $recipient, string $code, string $host): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El usuario no tiene un correo válido para recibir la verificación.');
        }

        $source = $this->sourceAddress($host);
        $subject = 'Código para cambiar tu contraseña de ArcadeCloud';
        $text = "Recibimos una solicitud para cambiar tu contraseña de ArcadeCloud.\n\n"
            . "Código de verificación: {$code}\n\n"
            . "Este código vence en 10 minutos. Si tú no solicitaste el cambio, ignora este mensaje.";
        $html = '<p>Recibimos una solicitud para cambiar tu contraseña de ArcadeCloud.</p>'
            . '<p style="font-size:24px;font-weight:700;letter-spacing:4px">'
            . htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p>'
            . '<p>Este código vence en 10 minutos. Si tú no solicitaste el cambio, ignora este mensaje.</p>';

        try {
            $this->ses->sendEmail([
                'Source' => $source,
                'Destination' => [
                    'ToAddresses' => [$recipient],
                ],
                'Message' => [
                    'Subject' => [
                        'Data' => $subject,
                        'Charset' => 'UTF-8',
                    ],
                    'Body' => [
                        'Text' => [
                            'Data' => $text,
                            'Charset' => 'UTF-8',
                        ],
                        'Html' => [
                            'Data' => $html,
                            'Charset' => 'UTF-8',
                        ],
                    ],
                ],
            ]);
        } catch (Throwable) {
            throw new RuntimeException(
                'No se pudo enviar el código. Verifica que Amazon SES tenga permiso de envío y que el dominio remitente esté verificado.'
            );
        }
    }

    private function sourceAddress(string $host): string
    {
        $configured = trim((string)(getenv('ARCADECLOUD_MAIL_FROM') ?: ''));
        if ($configured !== '') {
            if (!filter_var($configured, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('ARCADECLOUD_MAIL_FROM no contiene un correo válido.');
            }
            return $configured;
        }

        $domain = strtolower(trim($host));
        $domain = preg_replace('/:\d+$/', '', $domain) ?? '';
        $domain = trim($domain, "[] \t\n\r\0\x0B");

        if ($domain === '' || strlen($domain) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new RuntimeException(
                'No se pudo determinar un dominio seguro para el remitente. Configura ARCADECLOUD_MAIL_FROM.'
            );
        }

        return 'no-reply@' . $domain;
    }
}
