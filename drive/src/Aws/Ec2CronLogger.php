<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Aws;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Ec2CronLogger
{
    public function __construct(
        private string $path,
        private DateTimeZone $timezone
    ) {
    }

    public function log(string $message): void
    {
        $now = new DateTimeImmutable('now', $this->timezone);
        $line = '[' . $now->format('Y-m-d H:i:s T') . '] ' . $message . PHP_EOL;

        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir el log del cron EC2.');
        }
    }
}
