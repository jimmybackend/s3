<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Mail;

use RuntimeException;
use Throwable;

final class SmtpEmailService
{
    public function __construct(private SmtpConfig $config)
    {
    }

    public static function fromEnvironment(): self
    {
        return new self(SmtpConfig::fromEnvironment());
    }

    public function sendPasswordVerification(string $recipient, string $code, string $host = ''): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El usuario no tiene un correo válido para recibir la verificación.');
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException('El código de verificación no es válido.');
        }

        $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = 'Código para cambiar tu contraseña de ArcadeCloud';
        $textBody = "Recibimos una solicitud para cambiar tu contraseña de ArcadeCloud.\n\n"
            . "Código de verificación: {$code}\n\n"
            . "Este código vence en 10 minutos. Si tú no solicitaste el cambio, ignora este mensaje.";
        $htmlBody = '<!doctype html><html lang="es"><head><meta charset="utf-8"></head>'
            . '<body style="font-family:Arial,sans-serif;color:#0f172a">'
            . '<p>Recibimos una solicitud para cambiar tu contraseña de ArcadeCloud.</p>'
            . '<p style="font-size:24px;font-weight:700;letter-spacing:4px">' . $safeCode . '</p>'
            . '<p>Este código vence en 10 minutos. Si tú no solicitaste el cambio, ignora este mensaje.</p>'
            . '</body></html>';

        $this->send($recipient, $subject, $htmlBody, $textBody);
    }

    public function sendFederationDropAccess(
        string $recipient,
        string $filename,
        string $shareUrl,
        string $manageUrl,
        string $arcadeLinkUrl,
        string $expiresAt,
        int $maxDownloads
    ): void {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('FederationDrop requiere un correo válido.');
        }

        $safeFilename = htmlspecialchars($filename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeShare = htmlspecialchars($shareUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeManage = htmlspecialchars($manageUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeArcade = htmlspecialchars($arcadeLinkUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeExpires = htmlspecialchars($expiresAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = 'Tu FederationDrop ya está disponible';
        $textBody = "Tu archivo {$filename} ya está disponible.\n\n"
            . "Descarga pública: {$shareUrl}\n"
            . "ArcadeLink: {$arcadeLinkUrl}\n"
            . "Administrar o eliminar: {$manageUrl}\n"
            . "Vence: {$expiresAt}\n"
            . "Máximo de descargas: {$maxDownloads}\n\n"
            . "No compartas el enlace de administración.";
        $htmlBody = '<!doctype html><html lang="es"><head><meta charset="utf-8"></head>'
            . '<body style="font-family:Arial,sans-serif;color:#0f172a">'
            . '<h2>FederationDrop activo</h2>'
            . '<p><strong>' . $safeFilename . '</strong> ya está disponible.</p>'
            . '<p><a href="' . $safeShare . '">Descargar archivo</a></p>'
            . '<p><a href="' . $safeArcade . '">Descargar ArcadeLink</a></p>'
            . '<p><a href="' . $safeManage . '">Administrar o eliminar</a></p>'
            . '<p>Vence: ' . $safeExpires . '<br>Máximo de descargas: ' . max(1, $maxDownloads) . '</p>'
            . '<p><strong>No compartas el enlace de administración.</strong></p>'
            . '</body></html>';

        $this->send($recipient, $subject, $htmlBody, $textBody);
    }

    private function send(string $recipient, string $subject, string $htmlBody, string $textBody): void
    {
        $socket = null;
        $primaryAccepted = false;
        $bccAccepted = false;
        $messageAccepted = false;

        try {
            $remote = ($this->config->secure === 'ssl' ? 'ssl://' : '')
                . $this->config->host
                . ':'
                . $this->config->port;

            $socket = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                $this->config->timeout,
                STREAM_CLIENT_CONNECT
            );
            if (!is_resource($socket)) {
                throw new RuntimeException("No se pudo conectar con el servidor SMTP ({$errno}).");
            }

            stream_set_timeout($socket, $this->config->timeout);
            [$code, $response] = $this->readResponse($socket);
            $this->debug('CONNECT: ' . $response);
            if ($code !== 220) {
                throw new RuntimeException('El servidor SMTP no aceptó la conexión.');
            }

            $ehloHost = $this->ehloHost();
            $this->command($socket, 'EHLO ' . $ehloHost, [250]);

            if ($this->config->secure === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                $cryptoEnabled = @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );
                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('No se pudo activar STARTTLS con el servidor SMTP.');
                }
                $this->command($socket, 'EHLO ' . $ehloHost, [250]);
            }

            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($this->config->username), [334], true);
            $this->command($socket, base64_encode($this->config->password), [235], true);

            $this->command($socket, 'MAIL FROM:' . $this->emailPath($this->config->fromEmail), [250]);

            $primaryAccepted = $this->acceptRecipient($socket, $recipient);
            $bcc = trim($this->config->bccEmail);
            if ($bcc !== '' && !hash_equals(strtolower($recipient), strtolower($bcc))) {
                $bccAccepted = $this->acceptRecipient($socket, $bcc);
            }

            if (!$primaryAccepted && !$bccAccepted) {
                throw new RuntimeException('El servidor SMTP rechazó todos los destinatarios.');
            }

            $this->command($socket, 'DATA', [354]);

            $message = $this->buildMessage($recipient, $subject, $htmlBody, $textBody);
            if (fwrite($socket, $message . "\r\n.\r\n") === false) {
                throw new RuntimeException('No se pudo escribir el mensaje SMTP.');
            }

            [$dataCode, $dataResponse] = $this->readResponse($socket);
            $this->debug('DATA RESP: ' . $dataResponse);
            if ($dataCode !== 250) {
                throw new RuntimeException('El servidor SMTP rechazó el mensaje.');
            }
            $messageAccepted = true;

            $this->command($socket, 'QUIT', [221, 250]);
            fclose($socket);
            $socket = null;

            if (!$primaryAccepted && $bccAccepted) {
                throw new RuntimeException('El destinatario principal fue rechazado, pero la copia de soporte sí fue enviada.');
            }
        } catch (Throwable $error) {
            $this->debug('ERROR: ' . $error->getMessage());
            if (is_resource($socket)) {
                fclose($socket);
            }

            if (!$primaryAccepted && $bccAccepted && $messageAccepted) {
                throw new RuntimeException(
                    'El destinatario principal fue rechazado, pero la copia de soporte sí fue enviada.'
                );
            }

            throw new RuntimeException(
                'No se pudo enviar el correo por SMTP. Revisa la configuración del servidor de correo.'
            );
        }
    }

    private function acceptRecipient($socket, string $email): bool
    {
        $command = 'RCPT TO:' . $this->emailPath($email);
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('No se pudo escribir el destinatario SMTP.');
        }

        [$code, $response] = $this->readResponse($socket);
        $this->debug('CMD: ' . $command . ' | RESP: ' . $response);

        return in_array($code, [250, 251], true);
    }

    private function command($socket, string $command, array $expectedCodes, bool $sensitive = false): string
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('No se pudo escribir un comando SMTP.');
        }

        [$code, $response] = $this->readResponse($socket);
        $loggedCommand = $sensitive ? '[credencial oculta]' : $command;
        $this->debug('CMD: ' . $loggedCommand . ' | RESP: ' . $response);

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('Respuesta SMTP inesperada.');
        }

        return $response;
    }

    private function readResponse($socket): array
    {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        return [(int)substr($response, 0, 3), trim($response)];
    }

    private function buildMessage(string $recipient, string $subject, string $htmlBody, string $textBody): string
    {
        $boundary = 'arcade_' . bin2hex(random_bytes(16));
        $fromName = $this->encodeHeader($this->config->fromName);
        $encodedSubject = $this->encodeHeader($subject);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $fromName . ' <' . $this->config->fromEmail . '>',
            'To: <' . $recipient . '>',
            'Reply-To: ' . $this->config->replyTo,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: ArcadeCloud Drive SMTP',
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $textBody . "\r\n\r\n";
        $message .= '--' . $boundary . "\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";
        $message .= '--' . $boundary . "--\r\n";

        return $this->normalizeEol($message);
    }

    private function normalizeEol(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\n", "\r\n", $text);
        return preg_replace('/^\./m', '..', $text) ?? $text;
    }

    private function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', trim($value));
        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function emailPath(string $email): string
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Dirección SMTP inválida.');
        }

        return '<' . $email . '>';
    }

    private function ehloHost(): string
    {
        $hostname = strtolower(trim((string)(gethostname() ?: 'localhost')));
        $hostname = preg_replace('/[^a-z0-9.-]/', '-', $hostname) ?? 'localhost';
        return trim($hostname, '.-') ?: 'localhost';
    }

    private function debug(string $message): void
    {
        if ($this->config->debug) {
            error_log('[ARCADECLOUD SMTP] ' . $message);
        }
    }
}
