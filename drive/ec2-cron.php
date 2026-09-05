<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Aws\Ec2CostGuardService;
use ArcadeCloud\Drive\Aws\Ec2CronLogger;
use Aws\Exception\AwsException;

$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
$timezone = new DateTimeZone('America/Mexico_City');
$logger = new Ec2CronLogger(__DIR__ . '/ec2-cron.log', $timezone);

$service = new Ec2CostGuardService(
    $app->ec2Gateway(),
    $logger,
    $timezone,
    [
        'i-097146ee51c7f7026',
    ],
    null,
    8,
    16,
    17
);

try {
    $service->run();
} catch (AwsException $error) {
    $logger->log(
        'AWS ERROR: ' .
        ($error->getAwsErrorCode() ?: 'AWS') .
        ' - ' .
        ($error->getAwsErrorMessage() ?: $error->getMessage())
    );
} catch (Throwable $error) {
    $logger->log('FATAL ERROR: ' . $error->getMessage());
}
