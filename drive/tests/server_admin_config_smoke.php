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
$envNames = [
    'ARCADECLOUD_PUBLIC_URL', 'ARCADECLOUD_FEDERATION_ENABLED',
    'ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL', 'ARCADECLOUD_FEDERATION_REPLICA_ROLE',
    'ARCADECLOUD_FEDERATION_REPLICA_SCOPE', 'ARCADECLOUD_DROP_ENABLED',
    'ARCADECLOUD_DROP_PUBLIC_URL', 'ARCADECLOUD_DROP_CHECKOUT_URL',
    'ARCADECLOUD_DROP_WEBHOOK_SECRET', 'ARCADECLOUD_DROP_CURRENCY',
    'ARCADECLOUD_DROP_MAX_DAYS', 'ARCADECLOUD_SMTP_HOST',
    'ARCADECLOUD_SMTP_PASSWORD', 'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME',
    'AWS_REGION', 'AWS_S3_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY',
];
try {
    file_put_contents($path, "{}\n");
    serverAdminOk(ManagedRuntimeEnvironment::read($path) === [], 'acepta objeto JSON vacío creado por el instalador');

    file_put_contents($path, json_encode([
        'ARCADECLOUD_PUBLIC_URL' => 'https://drive.example.test',
        'ARCADECLOUD_FEDERATION_ENABLED' => 'true',
        'ARCADECLOUD_DROP_ENABLED' => 'true',
        'ARCADECLOUD_DROP_PUBLIC_URL' => 'https://drive.example.test/federationdrop',
        'ARCADECLOUD_DROP_CHECKOUT_URL' => 'https://pay.example.test/drop',
        'ARCADECLOUD_DROP_WEBHOOK_SECRET' => 'synthetic-drop-webhook-secret-1234567890',
        'ARCADECLOUD_DROP_CURRENCY' => 'MXN',
        'ARCADECLOUD_DROP_MAX_DAYS' => '30',
        'ARCADECLOUD_SMTP_HOST' => 'smtp.example.test',
        'ARCADECLOUD_SMTP_PASSWORD' => 'synthetic-secret-only',
        'DB_HOST' => 'db.example.test',
        'DB_PORT' => '3306',
        'DB_USER' => 'arcadecloud_test',
        'DB_PASSWORD' => 'synthetic-db-secret',
        'DB_NAME' => 'arcadecloud_test',
        'AWS_REGION' => 'us-east-1',
        'AWS_S3_BUCKET' => 'arcadecloud-test-bucket',
        'AWS_ACCESS_KEY_ID' => 'TESTACCESSKEY1234',
        'AWS_SECRET_ACCESS_KEY' => 'syntheticAwsSecret1234',
        'PATH' => 'must-be-ignored',
    ], JSON_PRETTY_PRINT));

    ManagedRuntimeEnvironment::loadIntoProcess($path);
    serverAdminOk(getenv('ARCADECLOUD_PUBLIC_URL') === 'https://drive.example.test', 'carga variable ArcadeCloud');
    serverAdminOk(getenv('ARCADECLOUD_DROP_ENABLED') === 'true', 'carga FederationDrop administrado');
    serverAdminOk(getenv('ARCADECLOUD_DROP_WEBHOOK_SECRET') === 'synthetic-drop-webhook-secret-1234567890', 'carga secreto FederationDrop');
    serverAdminOk(getenv('ARCADECLOUD_SMTP_PASSWORD') === 'synthetic-secret-only', 'carga secreto SMTP');
    serverAdminOk(getenv('DB_HOST') === 'db.example.test', 'carga DB_HOST administrado');
    serverAdminOk(getenv('DB_PASSWORD') === 'synthetic-db-secret', 'carga DB_PASSWORD administrado');
    serverAdminOk(getenv('AWS_REGION') === 'us-east-1', 'carga AWS_REGION administrado');
    serverAdminOk(getenv('AWS_SECRET_ACCESS_KEY') === 'syntheticAwsSecret1234', 'carga secreto AWS administrado');
    serverAdminOk(getenv('PATH') !== 'must-be-ignored', 'ignora variable arbitraria fuera de allowlist');

    $state = ManagedRuntimeEnvironment::publicState($path);
    $byName = [];
    foreach ($state as $row) $byName[(string)$row['name']] = $row;

    serverAdminOk(($byName['ARCADECLOUD_DROP_WEBHOOK_SECRET']['value'] ?? 'x') === '', 'no devuelve secreto FederationDrop al navegador');
    serverAdminOk(($byName['ARCADECLOUD_SMTP_PASSWORD']['value'] ?? 'x') === '', 'no devuelve SMTP password al navegador');
    serverAdminOk(($byName['DB_PASSWORD']['value'] ?? 'x') === '', 'no devuelve DB_PASSWORD al navegador');
    serverAdminOk(($byName['AWS_ACCESS_KEY_ID']['value'] ?? 'x') === '', 'no devuelve AWS access key al navegador');
    serverAdminOk(($byName['DB_HOST']['atomic_group'] ?? '') === 'database', 'marca DB como grupo atómico');
    serverAdminOk(($byName['AWS_REGION']['atomic_group'] ?? '') === 'aws', 'marca AWS como grupo atómico');

    $databaseNames = ManagedRuntimeEnvironment::namesForAtomicGroup('database');
    serverAdminOk(in_array('DB_HOST', $databaseNames, true) && in_array('DB_PASSWORD', $databaseNames, true), 'expone grupo completo de base de datos');
    $awsNames = ManagedRuntimeEnvironment::namesForAtomicGroup('aws');
    serverAdminOk(in_array('AWS_ACCESS_KEY_ID', $awsNames, true) && in_array('AWS_S3_BUCKET', $awsNames, true), 'expone grupo de credenciales AWS');

    $secretWithSpaces = '  synthetic secret with spaces  ';
    serverAdminOk(
        ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_SMTP_PASSWORD', $secretWithSpaces) === $secretWithSpaces,
        'preserva espacios significativos de SMTP password'
    );
    serverAdminOk(ManagedRuntimeEnvironment::validateValue('DB_PORT', '3306') === '3306', 'acepta puerto MySQL válido');
    serverAdminOk(ManagedRuntimeEnvironment::validateValue('AWS_S3_BUCKET', 'arcadecloud-test-bucket') === 'arcadecloud-test-bucket', 'acepta nombre S3 válido');
    serverAdminOk(
        ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_FEDERATION_REPLICA_ROLE', 'mirror') === 'mirror',
        'acepta rol mirror administrado'
    );
    serverAdminOk(
        ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_FEDERATION_REPLICA_SCOPE', 'all_allowed_resources') === 'all_allowed_resources',
        'acepta scope de réplica administrado'
    );
    serverAdminOk(
        ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_FEDERATION_REPLICA_ORIGIN_URL', 'https://origin.example.test/federationcloud/') === 'https://origin.example.test/federationcloud/',
        'acepta origin HTTPS de mirror'
    );
    serverAdminOk(
        ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_DROP_PUBLIC_URL', 'https://drive.example.test/federationdrop') === 'https://drive.example.test/federationdrop',
        'acepta FederationDrop HTTPS'
    );
    serverAdminOk(
        ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_DROP_CURRENCY', 'mxn') === 'MXN',
        'normaliza moneda FederationDrop'
    );
    serverAdminOk(
        ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_DROP_MAX_DAYS', '365') === '365',
        'acepta duración FederationDrop administrada'
    );
    serverAdminOk(
        ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_DROP_WEBHOOK_SECRET', 'synthetic-drop-webhook-secret-1234567890') === 'synthetic-drop-webhook-secret-1234567890',
        'acepta secreto FederationDrop suficiente'
    );

    $rejected = false;
    try { ManagedRuntimeEnvironment::validateValue('PATH', '/tmp'); } catch (RuntimeException) { $rejected = true; }
    serverAdminOk($rejected, 'rechaza variable arbitraria');

    $rejected = false;
    try { ManagedRuntimeEnvironment::validateValue('DB_PORT', '70000'); } catch (RuntimeException) { $rejected = true; }
    serverAdminOk($rejected, 'rechaza puerto DB inválido');

    $rejected = false;
    try { ManagedRuntimeEnvironment::validateValue('AWS_S3_BUCKET', 's3://invalid/bucket'); } catch (RuntimeException) { $rejected = true; }
    serverAdminOk($rejected, 'rechaza bucket S3 inválido');

    $rejected = false;
    try { ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_FEDERATION_URL', 'http://node.example.test/federationcloud/'); } catch (RuntimeException) { $rejected = true; }
    serverAdminOk($rejected, 'Federation URL administrada exige HTTPS');

    $rejected = false;
    try { ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_DROP_CHECKOUT_URL', 'http://pay.example.test/drop'); } catch (RuntimeException) { $rejected = true; }
    serverAdminOk($rejected, 'checkout FederationDrop exige HTTPS');

    $rejected = false;
    try { ManagedRuntimeEnvironment::validateValue('ARCADECLOUD_DROP_WEBHOOK_SECRET', 'short'); } catch (RuntimeException) { $rejected = true; }
    serverAdminOk($rejected, 'rechaza secreto FederationDrop demasiado corto');

    fwrite(STDOUT, "server admin config smoke: OK\n");
} finally {
    @unlink($path);
    foreach ($envNames as $name) putenv($name);
}
