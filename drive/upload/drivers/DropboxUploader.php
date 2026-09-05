<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;

require_once __DIR__ . '/../core/UploaderInterface.php';
require_once __DIR__ . '/../repositories/FileS3Repository.php';

final class DropboxUploader implements UploaderInterface
{
    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private StorageObjectNameCodec $codec
    ) {
    }

    public function init(array $req): array
    {
        $files = isset($req['_files']) && is_array($req['_files'])
            ? $req['_files']
            : [];

        if (empty($files['file'])) {
            throw new RuntimeException('No se recibió archivo (field "file")');
        }

        $repo = new FileS3Repository($this->db);
        $rutaBase = rtrim((string)($req['ruta_objetivo'] ?? ''), '/') . '/';
        if ($rutaBase === '/') {
            throw new RuntimeException('Falta ruta_objetivo');
        }

        $file = $files['file'];
        if (!is_array($file['tmp_name'])) {
            $file = [
                'name' => [$file['name']],
                'type' => [$file['type']],
                'tmp_name' => [$file['tmp_name']],
                'error' => [$file['error']],
                'size' => [$file['size']],
            ];
        }

        $userId = (int)($req['_user_id'] ?? 0);
        $resultados = [];

        for ($i = 0; $i < count($file['name']); $i++) {
            if (($file['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $resultados[] = [
                    'estado' => 'error',
                    'mensaje' => 'UPLOAD_ERR=' . (int)$file['error'][$i],
                ];
                continue;
            }

            $tmpFile = (string)$file['tmp_name'][$i];
            $nombreOriginal = (string)$file['name'][$i];
            $nombreHash = $this->codec->createFileObjectName($nombreOriginal);
            $keyFinal = $rutaBase . $nombreHash;

            $metadatosArray = [
                'tipo' => @mime_content_type($tmpFile) ?: ($file['type'][$i] ?? 'application/octet-stream'),
                'tamano_kb' => round((int)@filesize($tmpFile) / 1024, 2),
                'hash_sha256' => @hash_file('sha256', $tmpFile) ?: '',
                'subido_por' => (string)($req['_usuario'] ?? 'usuario'),
                'ip_origen' => (string)($req['_remote_addr'] ?? '0.0.0.0'),
                'fecha' => date('Y-m-d'),
                'hora' => date('H:i:s'),
                'navegador' => (string)($req['_user_agent'] ?? 'desconocido'),
            ];
            $metadatosJSON = json_encode($metadatosArray, JSON_UNESCAPED_UNICODE);

            try {
                $this->s3->putObject([
                    'Bucket' => $this->bucket,
                    'Key' => $keyFinal,
                    'SourceFile' => $tmpFile,
                    'ACL' => 'private',
                    'Metadata' => $metadatosArray,
                ]);

                $fileId = $repo->insertFile([
                    'Nombre' => $nombreOriginal,
                    'Encriptado' => $nombreHash,
                    'Tamano' => (int)@filesize($tmpFile),
                    'Metadatos' => $metadatosJSON,
                    'Ruta' => $rutaBase,
                    'Found' => 1,
                    'AccessType' => 'normal',
                    'Fecha' => date('Y-m-d H:i:s'),
                    'user_id_' => $userId,
                ]);

                $resultados[] = [
                    'estado' => 'ok',
                    'key' => $keyFinal,
                    'file_id' => $fileId,
                ];
            } catch (AwsException $e) {
                $resultados[] = [
                    'estado' => 'error',
                    'mensaje' => $e->getAwsErrorMessage() ?: $e->getMessage(),
                ];
            } catch (Throwable $e) {
                $resultados[] = [
                    'estado' => 'error',
                    'mensaje' => $e->getMessage(),
                ];
            }
        }

        return ['estado' => 'ok', 'resultados' => $resultados];
    }

    public function part(array $req): array
    {
        return ['ok' => true];
    }

    public function complete(array $req): array
    {
        return ['ok' => true];
    }
}
