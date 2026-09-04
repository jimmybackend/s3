<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Core;

use ArcadeCloud\Drive\Application\DrivePageService;
use ArcadeCloud\Drive\Application\FileListService;
use ArcadeCloud\Drive\Application\UploadDestinationService;
use ArcadeCloud\Drive\Security\SessionManager;
use ArcadeCloud\Drive\Storage\StorageUsageService;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use ArcadeCloud\Drive\View\FolderTreeRenderer;
use Aws\S3\S3Client;
use mysqli;

final class DriveApplication
{
    private mysqli $db;
    private S3Client $s3;
    private string $bucket;
    private ?\S3Manager $s3Manager = null;
    private ?SessionManager $session = null;
    private ?FileListService $fileListService = null;
    private ?DrivePageService $drivePageService = null;
    private ?UploadDestinationService $uploadDestinationService = null;
    private ?StorageUsageService $storageUsageService = null;
    private ?UserStoragePath $userStoragePath = null;

    private function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->s3 = \Config::getS3();
        $this->bucket = \Config::BUCKET;
    }

    public static function boot(mysqli $db): self
    {
        return new self($db);
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    public function s3(): S3Client
    {
        return $this->s3;
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function session(): SessionManager
    {
        return $this->session ??= new SessionManager();
    }

    public function userStoragePath(): UserStoragePath
    {
        return $this->userStoragePath ??= new UserStoragePath();
    }

    public function s3Manager(): \S3Manager
    {
        if ($this->s3Manager === null) {
            require_once dirname(__DIR__, 2) . '/S3Manager.php';
            $this->s3Manager = new \S3Manager($this->s3, $this->db, $this->bucket);
        }
        return $this->s3Manager;
    }

    public function fileListService(): FileListService
    {
        return $this->fileListService ??= new FileListService($this->db);
    }

    public function drivePageService(): DrivePageService
    {
        return $this->drivePageService ??= new DrivePageService(
            $this->fileListService(),
            $this->userStoragePath()
        );
    }

    public function uploadDestinationService(): UploadDestinationService
    {
        return $this->uploadDestinationService ??= new UploadDestinationService(
            $this->db,
            $this->userStoragePath()
        );
    }

    public function storageUsageService(): StorageUsageService
    {
        return $this->storageUsageService ??= new StorageUsageService($this->db);
    }

    public function folderTreeRenderer(int $userId): FolderTreeRenderer
    {
        return new FolderTreeRenderer($this->db, $userId, $this->userStoragePath());
    }
}
