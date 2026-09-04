<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Core;

use ArcadeCloud\Drive\Application\DrivePageService;
use ArcadeCloud\Drive\Security\SessionManager;
use Aws\S3\S3Client;
use mysqli;

final class DriveApplication
{
    private mysqli $db;
    private S3Client $s3;
    private string $bucket;
    private ?\S3Manager $s3Manager = null;
    private ?SessionManager $session = null;
    private ?DrivePageService $drivePageService = null;

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
        if ($this->session === null) {
            $this->session = new SessionManager();
        }
        return $this->session;
    }

    public function s3Manager(): \S3Manager
    {
        if ($this->s3Manager === null) {
            require_once dirname(__DIR__, 2) . '/S3Manager.php';
            $this->s3Manager = new \S3Manager($this->s3, $this->db, $this->bucket);
        }
        return $this->s3Manager;
    }

    public function drivePageService(): DrivePageService
    {
        if ($this->drivePageService === null) {
            $this->drivePageService = new DrivePageService($this->s3Manager());
        }
        return $this->drivePageService;
    }
}
