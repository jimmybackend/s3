<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Security\SessionManager;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\S3\S3Client;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';

final class LocalPresignedPutUploader implements UploaderInterface
{
    private const PENDING_KEY = 'drive_pending_local_uploads';
    private const TTL_SECONDS = 7200;

    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private StorageObjectNameCodec $codec,
        private SessionManager $session
    ) {
    }

    private function userId(array $req): int
    {
        $userId = (int)($req['_user_id'] ?? 0);
        return $userId > 0 ? $userId : $this->session->userId();
    }

    private function pendingUploads(): array
    {
        $pending = $this->session->get(self::PENDING_KEY, []);
        return is_array($pending) ? $pending : [];
    }

    private function savePendingUploads(array $pending): void
    {
        $this->session->set(self::PENDING_KEY, $pending);
    }

    private function cleanupPending(): void
    {
        $now = time();
        $pending = $this->pendingUploads();

        foreach ($pending as $token => $row) {
            $created = (int)($row['created_at'] ?? 0);
            if ($created <= 0 || ($now - $created) > self::TTL_SECONDS) {
                unset($pending[$token]);
            }
        }

        $this->savePendingUploads($pending);
    }

    private function existingFileId(int $userId, string $key): int
    {
        $stmt = $this->db->prepare(
            'SELECT id_ FROM FileS3 WHERE user_id_ = ? AND Encriptado = ? LIMIT 1'
        );

        if (!$stmt) {
            throw new RuntimeException('No se pudo comprobar FileS3: ' . $this->db->error);
        }

        $stmt->bind_param('is', $userId, $key);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('No se pudo comprobar FileS3: ' . $error);
        }

        $stmt->bind_result($id);
        $found = $stmt->fetch();
        $stmt->close();

        return $found ? (int)$id : 0;
    }

    public function init(array $req): array
    {
        $this->cleanupPending();

        $nombreOriginal = trim((string)($req['nombre'] ?? ''));
        $rutaObjetivo = rtrim((string)($req['ruta_objetivo'] ?? ''), '/') . '/';
        $userId = $this->userId($req);

        if ($nombreOriginal === '' || $rutaObjetivo === '/' || $userId <= 0) {
            throw new RuntimeException('Datos incompletos para iniciar la subida.');
        }

        $nombreEncriptado = $this->codec->createFileObjectName($nombreOriginal);
        $key = $rutaObjetivo . $nombreEncriptado;

        $cmd = $this->s3->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);

        $request = $this->s3->createPresignedRequest($cmd, '+1 hour');

        $metadatos = json_encode([
            'ip_origen' => (string)($req['_remote_addr'] ?? '127.0.0.1'),
            'user_agent' => (string)($req['_user_agent'] ?? 'desconocido'),
            'referer' => (string)($req['_referer'] ?? 'ninguno'),
            'fecha_servidor' => date('Y-m-d'),
            'hora_servidor' => date('H:i:s'),
            'usuario_envio' => (string)($req['_usuario'] ?? 'usuario'),
        ], JSON_UNESCAPED_UNICODE);

        $token = bin2hex(random_bytes(18));
        $pending = $this->pendingUploads();
        $pending[$token] = [
            'created_at' => time(),
            'user_id' => $userId,
            'Nombre' => $nombreOriginal,
            'Encriptado' => $nombreEncriptado,
            'Metadatos' => $metadatos,
            'Ruta' => $rutaObjetivo,
            'key' => $key,
            'completed_file_id' => 0,
        ];
        $this->savePendingUploads($pending);

        return [
            'url' => (string)$request->getUri(),
            'key' => $key,
            'ruta_objetivo' => $rutaObjetivo,
            'nombreOriginal' => $nombreOriginal,
            'nombreEncriptado' => $nombreEncriptado,
            'upload_token' => $token,
        ];
    }

    public function part(array $req): array
    {
        $this->cleanupPending();

        $cancel = filter_var($req['cancel'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$cancel) {
            return ['ok' => true];
        }

        $token = trim((string)($req['upload_token'] ?? ''));
        $userId = $this->userId($req);

        if ($token === '') {
            return ['ok' => true, 'cancelled' => true, 'already_gone' => true];
        }

        $pendingUploads = $this->pendingUploads();
        $pending = $pendingUploads[$token] ?? null;

        if (!is_array($pending)) {
            return ['ok' => true, 'cancelled' => true, 'already_gone' => true];
        }

        if ((int)($pending['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('La subida no pertenece al usuario actual.');
        }

        $completedId = (int)($pending['completed_file_id'] ?? 0);
        if ($completedId > 0) {
            return [
                'ok' => true,
                'cancelled' => false,
                'already_completed' => true,
                'file_id' => $completedId,
            ];
        }

        $key = (string)($pending['key'] ?? '');
        $existingId = $key !== '' ? $this->existingFileId($userId, $key) : 0;

        if ($existingId > 0) {
            $pendingUploads[$token]['completed_file_id'] = $existingId;
            $this->savePendingUploads($pendingUploads);

            return [
                'ok' => true,
                'cancelled' => false,
                'already_completed' => true,
                'file_id' => $existingId,
            ];
        }

        if ($key !== '') {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        }

        unset($pendingUploads[$token]);
        $this->savePendingUploads($pendingUploads);

        return ['ok' => true, 'cancelled' => true, 'key' => $key];
    }

    public function complete(array $req): array
    {
        $this->cleanupPending();

        $token = trim((string)($req['upload_token'] ?? ''));
        $requestedSize = max(0, (int)($req['tamano'] ?? 0));
        $userId = $this->userId($req);

        $pendingUploads = $this->pendingUploads();
        $pending = $token !== '' ? ($pendingUploads[$token] ?? null) : null;

        if (!is_array($pending)) {
            throw new RuntimeException('La sesión de subida expiró o no existe.');
        }

        if ((int)($pending['user_id'] ?? 0) !== $userId) {
            throw new RuntimeException('La subida no pertenece al usuario actual.');
        }

        $completedId = (int)($pending['completed_file_id'] ?? 0);
        if ($completedId > 0) {
            return [
                'ok' => true,
                'file_id' => $completedId,
                'key' => (string)$pending['key'],
                'ruta_objetivo' => (string)$pending['Ruta'],
                'tamano' => (int)($pending['completed_size'] ?? $requestedSize),
                'idempotent' => true,
            ];
        }

        $key = (string)$pending['key'];
        $head = $this->s3->headObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        $realSize = (int)($head['ContentLength'] ?? 0);

        if ($requestedSize > 0 && $realSize !== $requestedSize) {
            throw new RuntimeException('El tamaño recibido en S3 no coincide con el archivo original.');
        }

        $existingId = $this->existingFileId($userId, $key);
        if ($existingId > 0) {
            $pendingUploads[$token]['completed_file_id'] = $existingId;
            $pendingUploads[$token]['completed_size'] = $realSize;
            $this->savePendingUploads($pendingUploads);

            return [
                'ok' => true,
                'file_id' => $existingId,
                'key' => $key,
                'ruta_objetivo' => (string)$pending['Ruta'],
                'tamano' => $realSize,
                'idempotent' => true,
            ];
        }

        $repo = new FileS3Repository($this->db);
        $fileId = $repo->insertFile([
            'Nombre' => (string)$pending['Nombre'],
            'Encriptado' => (string)$pending['Encriptado'],
            'Tamano' => $realSize,
            'Metadatos' => $pending['Metadatos'] ?? null,
            'Ruta' => (string)$pending['Ruta'],
            'Found' => 1,
            'AccessType' => 'normal',
            'Fecha' => date('Y-m-d H:i:s'),
            'user_id_' => $userId,
        ]);

        $pendingUploads[$token]['completed_file_id'] = $fileId;
        $pendingUploads[$token]['completed_size'] = $realSize;
        $pendingUploads[$token]['completed_at'] = time();
        $this->savePendingUploads($pendingUploads);

        return [
            'ok' => true,
            'file_id' => $fileId,
            'key' => $key,
            'ruta_objetivo' => (string)$pending['Ruta'],
            'tamano' => $realSize,
            'idempotent' => false,
        ];
    }
}
