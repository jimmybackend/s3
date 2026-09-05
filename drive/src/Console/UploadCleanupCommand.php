<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Console;

use ArcadeCloud\Drive\Core\DriveApplication;
use ArcadeCloud\Drive\Upload\UploadCleanupService;

final class UploadCleanupCommand
{
    public function __construct(private DriveApplication $app)
    {
    }

    public function run(array $argv): int
    {
        $execute = in_array('--execute', $argv, true);
        $days = $this->daysFromArgs($argv);

        try {
            $report = $this->service()->run($days, $execute);
            echo json_encode(
                [
                    'ok' => true,
                    'mode' => $execute ? 'execute' : 'dry-run',
                    'report' => $report,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . PHP_EOL;
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            return 1;
        }
    }

    private function daysFromArgs(array $argv): int
    {
        foreach ($argv as $arg) {
            if (preg_match('/^--days=(\d+)$/', (string)$arg, $m) === 1) {
                return max(1, min(3650, (int)$m[1]));
            }
        }
        return 30;
    }

    private function service(): UploadCleanupService
    {
        return new UploadCleanupService(
            $this->app->db(),
            $this->app->s3(),
            $this->app->bucket(),
            $this->app->userStoragePath(),
            sys_get_temp_dir() . '/arcadecloud-public-upload-state'
        );
    }
}
