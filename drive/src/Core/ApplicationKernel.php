<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Core;

use mysqli;
use RuntimeException;

final class ApplicationKernel
{
    private static ?DriveApplication $application = null;

    private function __construct()
    {
    }

    public static function boot(mysqli $db): DriveApplication
    {
        if (self::$application === null) {
            self::$application = DriveApplication::boot($db);
        }
        return self::$application;
    }

    public static function app(): DriveApplication
    {
        if (self::$application === null) {
            throw new RuntimeException('DriveApplication no fue inicializada.');
        }
        return self::$application;
    }

    public static function resetForTests(): void
    {
        self::$application = null;
    }
}
