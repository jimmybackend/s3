<?php
declare(strict_types=1);

use Aws\S3\S3Client;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';

final class LocalPresignedPutUploader implements UploaderInterface
{
    private const PENDING_KEY = 'drive_pending_local_uploads';
    private const TTL_SECONDS = 7200;

    private function db(): mysqli
    {
        global $db_connection;
        if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
            throw new RuntimeException('DB no disponible ($db_connection).');
        }
        return $db_connection;
    }

    private function s3(): S3Client
    {
        return Config::getS3();
    }

    private function bucket(): string
    {
        return (string) Config::BUCKET;
    }

    private function userId(array $req): int
    {
        return (int) ($req['_user_id'] ?? $_SESSION['user_id'] ?? 0);
    }

    private function cleanupPending(): void
    {
        $now = time();
        $pending = $_SESSION[self::PENDING_KEY] ?? [];
        if (!is_array($pending)) {
            $_SESSION[self::PENDING_KEY] = [];
            return;
        }
        foreach ($pending as $token => $row) {
            $created = (int) ($row['created_at'] ?? 0);
            if ($created <= 0 || ($now - $created) > self::TTL_SECONDS) {
                unset($pending[$token]);
            }
        }
        $_SESSION[self::PENDING_KEY] = $pending;
    }

    public function init(array $req): array
    {
        $this->cleanupPending();

        $nombreOriginal = trim((string) ($req['nombre'] ?? ''));
        $rutaObjetivo = rtrim((string) ($req['ruta_objetivo'] ?? ''), '/') . '/';
        $userId = $this->userId($req);
        if ($nombreOriginal === '' || $rutaObjetivo === '/' || $userId <= 0) {
            throw new RuntimeException('Datos incompletos para iniciar la subida.');
        }

        $nombreEncriptado = (new \ArcadeCloud\Drive\Storage\StorageObjectNameCodec())->createFileObjectName($nombreOriginal);
        $key = $rutaObjetivo . $nombreEncriptado;

        $cmd = $this->s3()->getCommand('PutObject', [
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ACL' => 'private',
        ]);
        $request = $this->s3()->createPresignedRequest($cmd, '+1 hour');

        $metadatos = json_encode([
            'ip_origen' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'desconocido',
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'ninguno',
            'fecha_servidor' => date('Y-m-d'),
            'hora_servidor' => date('H:i:s'),
            'usuario_envio' => (string) ($req['_usuario'] ?? 'usuario'),
        ], JSON_UNESCAPED_UNICODE);

        $token = bin2hex(random_bytes(18));
        $_SESSION[self::PENDING_KEY][$token] = [
            'created_at' => time(),
            'user_id' => $userId,
            'Nombre' => $nombreOriginal,
            'Encriptado' => $nombreEncriptado,
            'Metadatos' => $metadatos,
            'Ruta' => $rutaObjetivo,
            'key' => $key,
        ];

        return [
            'url' => (string) $request->getUri(),
            'key' => $key,
            'ruta_objetivo' => $rutaObjetivo,
            'nombreOriginal' => $nombreOriginal,
            'nombreEncriptado' => $nombreEncriptado,
            'upload_token' => $token,
        ];
    }

    public function part(array $req): array
    {
        return ['ok' => true];
    }

    public function complete(array $req): array
    {
        $this->cleanupPending();

        $token = trim((string) ($req['upload_token'] ?? ''));
        $tamano = max(0, (int) ($req['tamano'] ?? 0));
        $userId = $this->userId($req);
        $pending = $token !== '' ? ($_SESSION[self::PENDING_KEY][$token] ?? null) : null;

        if (!is_array($pending)) {
            throw new RuntimeException('La sesión de subida expiró o no existe.');
        }
        if ((int) ($pending['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('La subida no pertenece al usuario actual.');
        }

        $repo = new FileS3Repository($this->db());
        $fileId = $repo->insertFile([
            'Nombre' => (string) $pending['Nombre'],
            'Encriptado' => (string) $pending['Encriptado'],
            'Tamano' => $tamano,
            'Metadatos' => $pending['Metadatos'] ?? null,
            'Ruta' => (string) $pending['Ruta'],
            'Found' => 1,
            'AccessType' => 'normal',
            'Fecha' => date('Y-m-d H:i:s'),
            'user_id_' => $userId,
        ]);

        unset($_SESSION[self::PENDING_KEY][$token]);

        return [
            'ok' => true,
            'file_id' => $fileId,
            'key' => (string) $pending['key'],
            'ruta_objetivo' => (string) $pending['Ruta'],
            'tamano' => $tamano,
        ];
    }
}
