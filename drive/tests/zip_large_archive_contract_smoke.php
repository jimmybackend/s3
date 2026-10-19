<?php
declare(strict_types=1);
$code = (string)file_get_contents(dirname(__DIR__) . '/src/Application/ZipExplorerService.php');
$checks = [
    'no 128 MB cap' => !str_contains($code, '128 * 1024 * 1024'),
    'no 10k entry cap' => !str_contains($code, '$maxItems'),
    'no 256 MB extraction cap' => !str_contains($code, '$maxExtract'),
    'page-sized listing' => str_contains($code, '$pageSize = 100'),
    'selective folder ZIP' => str_contains($code, 'downloadFolder($zip, $selectedFolder)'),
    'ZIP folder paths checked' => str_contains($code, 'ruta insegura'),
    'authorization preserved' => str_contains($code, 'requireReadableByKey($session->userId(), $key)'),
    'streaming output' => str_contains($code, 'stream_copy_to_stream'),
];
foreach ($checks as $name => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}
