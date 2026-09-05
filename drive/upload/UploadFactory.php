<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Security\SessionManager;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use Aws\S3\S3Client;

require_once __DIR__ . '/core/UploaderInterface.php';
require_once __DIR__ . '/drivers/LocalPresignedPutUploader.php';
require_once __DIR__ . '/drivers/RemoteUrlUploader.php';
require_once __DIR__ . '/drivers/DropboxUploader.php';
require_once __DIR__ . '/drivers/Chunked15MBUploader.php';
require_once __DIR__ . '/storage/UploadStateStore.php';

final class UploadFactory
{
    public function __construct(
        private mysqli $db,
        private S3Client $s3,
        private string $bucket,
        private StorageObjectNameCodec $codec,
        private SessionManager $session,
        private string $stateDirectory
    ) {
    }

    public function make(string $mode): UploaderInterface
    {
        return match ($mode) {
            'local_put' => new LocalPresignedPutUploader(
                $this->db,
                $this->s3,
                $this->bucket,
                $this->codec,
                $this->session
            ),
            'remote_url' => new RemoteUrlUploader(
                $this->db,
                $this->s3,
                $this->bucket,
                $this->codec
            ),
            'dropbox' => new DropboxUploader(
                $this->db,
                $this->s3,
                $this->bucket,
                $this->codec
            ),
            'chunked' => new Chunked15MBUploader(
                $this->db,
                $this->s3,
                $this->bucket,
                $this->codec,
                new UploadStateStore($this->stateDirectory)
            ),
            default => throw new RuntimeException('Modo inválido: ' . $mode),
        };
    }
}
