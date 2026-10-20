<?php
declare(strict_types=1);

$router = (string)file_get_contents(dirname(__DIR__) . '/src/Application/ZipExplorerService.php');
$lambda = (string)file_get_contents(dirname(__DIR__) . '/src/Application/ZipLambdaExplorerService.php');
$checks = [
    'Lambda is sole ZIP explorer backend' => str_contains($router, 'ZipLambdaExplorerService::handle()'),
    'Lambda must be configured before dispatch' => str_contains($router, 'ZipLambdaExplorerService::enabled()'),
    'Lambda unavailability fails closed' => str_contains($router, 'http_response_code(503)'),
    'EC2 does not instantiate ZipArchive' => !str_contains($router, 'ZipArchive'),
    'EC2 does not download ZIP to local file' => !str_contains($router, 'SaveAs') && !str_contains($router, 'tempnam('),
    'EC2 does not write extracted ZIP files' => !str_contains($router, 'fopen(') && !str_contains($router, 'sys_get_temp_dir'),
    'Lambda enforces authenticated ownership' => str_contains($lambda, 'requireReadableByKey($userId, $key)'),
    'Lambda executes remote operation' => str_contains($lambda, "'InvocationType' => 'RequestResponse'"),
    'Lambda controls paginated listing' => str_contains($lambda, "'page' => " . '$page'),
    'Lambda file download uses remote redirect' => str_contains($lambda, "header('Location: ' . " . '$url' . ", true, 303)"),
    'Lambda validates signed download destination' => str_contains($lambda, '$expectedBucket' . " . '.s3.amazonaws.com'"),
];
foreach ($checks as $name => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}
