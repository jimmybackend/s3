<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Core;

use ArcadeCloud\Drive\Application\DrivePageService;
use ArcadeCloud\Drive\Application\FileListService;
use ArcadeCloud\Drive\Application\UploadDestinationService;
use ArcadeCloud\Drive\Aws\AwsCostService;
use ArcadeCloud\Drive\Aws\CostExplorerGateway;
use ArcadeCloud\Drive\Media\MediaPlaylistRepository;
use ArcadeCloud\Drive\Media\MediaPlaylistService;
use ArcadeCloud\Drive\Security\AuthenticationRepository;
use ArcadeCloud\Drive\Security\AuthenticationService;
use ArcadeCloud\Drive\Security\SessionManager;
use ArcadeCloud\Drive\Sharing\ShareAccessService;
use ArcadeCloud\Drive\Sharing\ShareFileRepository;
use ArcadeCloud\Drive\Sharing\ShareLinkService;
use ArcadeCloud\Drive\Sharing\ShareObjectStorage;
use ArcadeCloud\Drive\Sharing\ShareTokenStore;
use ArcadeCloud\Drive\Storage\StorageObjectNameCodec;
use ArcadeCloud\Drive\Storage\StorageUsageService;
use ArcadeCloud\Drive\Storage\UserStoragePath;
use ArcadeCloud\Drive\Storage\UserStorageProvisioner;
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
    private ?AuthenticationRepository $authenticationRepository = null;
    private ?AuthenticationService $authenticationService = null;
    private ?CostExplorerGateway $costExplorerGateway = null;
    private ?AwsCostService $awsCostService = null;
    private ?MediaPlaylistRepository $mediaPlaylistRepository = null;
    private ?MediaPlaylistService $mediaPlaylistService = null;
    private ?FileListService $fileListService = null;
    private ?DrivePageService $drivePageService = null;
    private ?UploadDestinationService $uploadDestinationService = null;
    private ?StorageUsageService $storageUsageService = null;
    private ?UserStoragePath $userStoragePath = null;
    private ?UserStorageProvisioner $userStorageProvisioner = null;
    private ?StorageObjectNameCodec $storageObjectNameCodec = null;
    private ?ShareFileRepository $shareFileRepository = null;
    private ?ShareTokenStore $shareTokenStore = null;
    private ?ShareObjectStorage $shareObjectStorage = null;
    private ?ShareLinkService $shareLinkService = null;
    private ?ShareAccessService $shareAccessService = null;

    private function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->s3 = \Config::getS3();
        $this->bucket = \Config::getBucket();
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

    public function authenticationRepository(): AuthenticationRepository
    {
        return $this->authenticationRepository ??= new AuthenticationRepository($this->db);
    }

    public function authenticationService(): AuthenticationService
    {
        return $this->authenticationService ??= new AuthenticationService(
            $this->authenticationRepository(),
            $this->session()
        );
    }

    public function costExplorerGateway(): CostExplorerGateway
    {
        return $this->costExplorerGateway ??= new CostExplorerGateway(
            \Config::getAwsCredentials(),
            'us-east-1'
        );
    }

    public function awsCostService(): AwsCostService
    {
        return $this->awsCostService ??= new AwsCostService(
            $this->costExplorerGateway()
        );
    }

    public function mediaPlaylistRepository(): MediaPlaylistRepository
    {
        return $this->mediaPlaylistRepository ??= new MediaPlaylistRepository($this->db);
    }

    public function mediaPlaylistService(): MediaPlaylistService
    {
        return $this->mediaPlaylistService ??= new MediaPlaylistService(
            $this->mediaPlaylistRepository(),
            $this->userStoragePath()
        );
    }

    public function userStoragePath(): UserStoragePath
    {
        return $this->userStoragePath ??= new UserStoragePath();
    }

    public function storageObjectNameCodec(): StorageObjectNameCodec
    {
        return $this->storageObjectNameCodec ??= new StorageObjectNameCodec();
    }

    public function s3Manager(): \S3Manager
    {
        if ($this->s3Manager === null) {
            require_once dirname(__DIR__, 2) . '/S3Manager.php';
            $this->s3Manager = new \S3Manager(
                $this->s3,
                $this->db,
                $this->bucket,
                $this->storageObjectNameCodec()
            );
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

    public function userStorageProvisioner(): UserStorageProvisioner
    {
        return $this->userStorageProvisioner ??= new UserStorageProvisioner(
            $this->db,
            $this->s3,
            $this->bucket,
            $this->userStoragePath()
        );
    }

    public function folderTreeRenderer(int $userId): FolderTreeRenderer
    {
        return new FolderTreeRenderer($this->db, $userId, $this->userStoragePath());
    }

    public function shareFileRepository(): ShareFileRepository
    {
        return $this->shareFileRepository ??= new ShareFileRepository($this->db);
    }

    public function shareTokenStore(): ShareTokenStore
    {
        return $this->shareTokenStore ??= new ShareTokenStore(
            dirname(__DIR__, 2) . '/tokens.json'
        );
    }

    public function shareObjectStorage(): ShareObjectStorage
    {
        return $this->shareObjectStorage ??= new ShareObjectStorage(
            $this->s3,
            $this->bucket
        );
    }

    public function shareLinkService(): ShareLinkService
    {
        return $this->shareLinkService ??= new ShareLinkService(
            $this->shareFileRepository(),
            $this->shareTokenStore()
        );
    }

    public function shareAccessService(): ShareAccessService
    {
        return $this->shareAccessService ??= new ShareAccessService(
            $this->shareFileRepository(),
            $this->shareTokenStore(),
            $this->shareObjectStorage()
        );
    }
}
