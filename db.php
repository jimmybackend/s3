<?php
declare(strict_types=1);

$required = ['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'];
$missing = [];

foreach ($required as $name) {
    $value = getenv($name);
    if ($value === false || trim((string) $value) === '') {
        $missing[] = $name;
    }
}

if ($missing !== []) {
    throw new RuntimeException(
        'Falta configuración esencial de base de datos: ' . implode(', ', $missing)
    );
}

$port = filter_var(
    (string) (getenv('DB_PORT') ?: '3306'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]
);

if ($port === false) {
    throw new RuntimeException('DB_PORT no es válido.');
}

$db_connection = mysqli_init();
if (!$db_connection) {
    throw new RuntimeException('No se pudo inicializar mysqli.');
}

mysqli_options($db_connection, MYSQLI_OPT_CONNECT_TIMEOUT, 10);

if (!@mysqli_real_connect(
    $db_connection,
    (string) getenv('DB_HOST'),
    (string) getenv('DB_USER'),
    (string) getenv('DB_PASSWORD'),
    (string) getenv('DB_NAME'),
    (int) $port
)) {
    throw new RuntimeException('No se pudo conectar a la base de datos remota.');
}

if (!mysqli_set_charset($db_connection, 'utf8mb4')) {
    throw new RuntimeException('No se pudo configurar utf8mb4.');
}
