<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Office;

use RuntimeException;

final class OfficeWorkstationClient
{
    public function status(string $privateIp): array
    {
        return $this->request($privateIp, 'status');
    }

    public function start(string $privateIp): array
    {
        return $this->request($privateIp, 'start');
    }

    public function prepareDocument(
        string $privateIp,
        string $sessionId,
        string $controlToken
    ): array {
        return $this->documentRequest($privateIp, 'prepare', $sessionId, $controlToken);
    }

    public function syncDocument(
        string $privateIp,
        string $sessionId,
        string $controlToken
    ): array {
        return $this->documentRequest($privateIp, 'sync', $sessionId, $controlToken);
    }

    public function closeDocument(
        string $privateIp,
        string $sessionId,
        string $controlToken
    ): array {
        return $this->documentRequest($privateIp, 'close', $sessionId, $controlToken);
    }

    /** @return array<string,mixed> */
    private function documentRequest(
        string $privateIp,
        string $action,
        string $sessionId,
        string $controlToken
    ): array {
        if (filter_var($privateIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('El nodo de cómputo no publicó una IPv4 privada válida.');
        }
        if (
            !in_array($action, ['prepare', 'sync', 'close'], true)
            || !preg_match('/^[a-f0-9]{32}$/', $sessionId)
            || !preg_match('/^[a-f0-9]{64}$/', $controlToken)
        ) {
            throw new RuntimeException('Solicitud documental Workstation inválida.');
        }

        $payload = json_encode([
            'session_id' => $sessionId,
            'control_token' => $controlToken,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $privateIp . ':80',
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('El agente documental Office no responde.');
        }

        stream_set_timeout($socket, 120);
        $request = "POST /__arcadecloud_office_document HTTP/1.0\r\n"
            . "Host: office.esforzados.com\r\n"
            . "X-ArcadeCloud-Office-Document-Action: " . $action . "\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($payload) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $payload;

        fwrite($socket, $request);
        $response = stream_get_contents($socket, 262145);
        fclose($socket);

        if (!is_string($response) || $response === '' || strlen($response) > 262144) {
            throw new RuntimeException('Respuesta del agente documental inválida.');
        }

        [$headers, $body] = array_pad(
            preg_split("/\r?\n\r?\n/", $response, 2) ?: [],
            2,
            ''
        );
        if (!preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $headers, $match)) {
            throw new RuntimeException('El agente documental devolvió HTTP inválido.');
        }

        $status = (int)$match[1];
        $decoded = json_decode(trim($body), true);
        if ($status !== 200 || !is_array($decoded) || ($decoded['ok'] ?? null) !== true) {
            $message = is_array($decoded) ? trim((string)($decoded['error'] ?? '')) : '';
            throw new RuntimeException(
                $message !== '' ? $message : 'El agente documental rechazó la solicitud.'
            );
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function request(string $privateIp, string $action): array
    {
        if (filter_var($privateIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('El nodo de cómputo no publicó una IPv4 privada válida.');
        }
        if (!in_array($action, ['status', 'start'], true)) {
            throw new RuntimeException('Acción Workstation no permitida.');
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'tcp://' . $privateIp . ':80',
            $errno,
            $errstr,
            3,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('Workstation todavía no responde en la red privada.');
        }

        stream_set_timeout($socket, 5);
        $method = $action === 'status' ? 'GET' : 'POST';
        $request = $method . " /__arcadecloud_workstation HTTP/1.0\r\n"
            . "Host: office.esforzados.com\r\n"
            . "X-ArcadeCloud-Workstation-Action: " . $action . "\r\n"
            . "Connection: close\r\n"
            . "Content-Length: 0\r\n\r\n";

        fwrite($socket, $request);
        $response = stream_get_contents($socket, 131073);
        fclose($socket);

        if (!is_string($response) || $response === '' || strlen($response) > 131072) {
            throw new RuntimeException('Respuesta Workstation inválida.');
        }

        [$headers, $body] = array_pad(
            preg_split("/\r?\n\r?\n/", $response, 2) ?: [],
            2,
            ''
        );
        if (!preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $headers, $match)) {
            throw new RuntimeException('Workstation devolvió una respuesta HTTP inválida.');
        }

        $status = (int)$match[1];
        $decoded = json_decode(trim($body), true);
        if ($status !== 200 || !is_array($decoded) || ($decoded['ok'] ?? null) !== true) {
            $message = is_array($decoded) ? trim((string)($decoded['error'] ?? '')) : '';
            throw new RuntimeException(
                $message !== '' ? $message : 'Workstation rechazó la solicitud.'
            );
        }

        return $decoded;
    }
}
