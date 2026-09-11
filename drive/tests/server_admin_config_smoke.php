<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Admin/ManagedRuntimeEnvironment.php';

use ArcadeCloud\Drive\Admin\ManagedRuntimeEnvironment;

function serverAdminOk(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

$path = sys_get_temp_dir() . '/arcadecloud-runtime-env-' . bin2hex(random_bytes(6)) . '.json';
try {
    file_put_contents($path, json_encode([
        'ARCADECLOUD_PUBLIC_URL' => 'https://drive.example.test',
        'ARCADECLOUD_FEDERATION_ENABLED' => 'true',
        'ARCADECLOUD_SMTP_HOST' => 'smtp.example.test',
        'ARCADECLOUD_SMTP_PASSWORD' => 'synthetic-secret-only',
        'DB_PASSWORD' => 'must-be-ignored',
    ], JSON_PRETTY_PRINT));

    ManagedRuntimeEnvironment::loadIntoProcess($path);
    serverAdminOk(getenv('ARCADECLOUD_PUBLIC_URL') === 'https://drive.example.test', 'carga variable allowlisted');
    serverAdminOk(getenv('ARCADECLOUD_SMTP_PASSWORD') === 'synthetic-secret-only', 'carga secreto allowlisted al proceso');
    serverAdminOk(getenv('DB_PASSWORD') !== 'must-be-ignored', 'ignora variable fuera de allowlist');

    $state = ManagedRuntimeEnvironment::publicState($path);
    $smtpPassword = null;
    foreach ($state as $row) {
        if (($row['name'] ?? null) === 'ARCADECLOUD_SMTP_PASSWORD') $smtpPassword = $row;
    }
    serverAdminOk(is_array($smtpPassword), 'expone metadata de SMTP password');
    serverAdminOk(($smtpPassword['configured'] ?? false) === true, 'indica que secreto está configurado');
    serverAdminOk(($smtpPassword['value'] ?? 'x') === '', 'nunca devuelve secreto al navegador');

    $secretWithSpaces = '  synthetic secret with spaces  ';
    serverAdminOk(
        ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_SMTP_PASSWORD', $secretWithSpaces) === $secretWithSpaces,
        'preserva exactamente espacios significativos de secretos'
    );

    $rejected = false;
    try { ManagedRuntimeEnvironment::validateValue('DB_PASSWORD', 'x'); } catch (RuntimeException) { $rejected = true; }
    serverAdminOk($rejected, 'rechaza variable arbitraria');

    $rejected = false;
    try { ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_SMTP_PORT', '70000'); } catch (RuntimeException) { $rejected = true; }
    serverAdminOk($rejected, 'rechaza puerto SMTP inválido');

    $rejected = false;
    try { ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_FEDERATION_URL', 'http://node.example.test/federationcloud/'); } catch (RuntimeException) { $rejected = true; }
    serverAdminOk($rejected, 'Federation URL administrada exige HTTPS');

    fwrite(STDOUT, "server admin config smoke: OK\n");
} finally {
    @unlink($path);
    foreach (['ARCADECLOUD_PUBLIC_URL', 'ARCADECLOUD_FEDERATION_ENABLED', 'ARCADECLOUD_SMTP_HOST', 'ARCADECLOUD_SMTP_PASSWORD'] as $name) putenv($name);
}
