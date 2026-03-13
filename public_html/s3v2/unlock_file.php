<?php
// unlock_file.php — valida contraseña y abre acceso temporal por sesión
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

function resolve_unlock_user_id(): int
{
    $candidates = [
        $_SESSION['user_id_'] ?? null,
        $_SESSION['user_id'] ?? null,
        $_SESSION['id_usuario'] ?? null,
        $_SESSION['id_user'] ?? null,
        $_SESSION['id'] ?? null,
    ];

    foreach ($candidates as $value) {
        if ($value !== null && $value !== '' && ctype_digit((string)$value)) {
            return (int)$value;
        }
    }

    return 0;
}

function normalize_unlock_key(string $key): string
{
    $key = trim(str_replace('\\', '/', $key));
    $key = preg_replace('~/+~', '/', $key);
    return ltrim((string)$key, '/');
}

function split_unlock_key(string $key): array
{
    $key = normalize_unlock_key($key);
    $pos = strrpos($key, '/');
    if ($pos === false) {
        return ['', ''];
    }

    return [
        substr($key, 0, $pos + 1),
        substr($key, $pos + 1),
    ];
}

function json_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = resolve_unlock_user_id();
if ($userId <= 0) {
    json_out(['ok' => false, 'msg' => 'Sesión inválida']);
}

$key  = trim((string)($_POST['key'] ?? ''));
$ruta = trim((string)($_POST['ruta'] ?? ''));
$enc  = trim((string)($_POST['encriptado'] ?? $_POST['enc'] ?? ''));
$pass = (string)($_POST['password'] ?? $_POST['pass'] ?? '');

$fullKey = '';
if ($key !== '') {
    $fullKey = normalize_unlock_key($key);
} elseif ($enc !== '') {
    // Compatibilidad:
    // - si encriptado ya viene como key completa, úsala tal cual
    // - si viene solo el nombre final, concatena ruta + encriptado
    if ($ruta !== '' && strpos(str_replace('\\', '/', $enc), '/') === false) {
        $fullKey = normalize_unlock_key($ruta . $enc);
    } else {
        $fullKey = normalize_unlock_key($enc);
    }
}

if ($fullKey === '' || $pass === '') {
    json_out(['ok' => false, 'msg' => 'Parámetros incompletos']);
}

[$rutaCalc, $encCalc] = split_unlock_key($fullKey);
if ($ruta === '') {
    $ruta = $rutaCalc;
}
if ($enc === '') {
    $enc = $encCalc;
}

$row = null;

/*
 * PRIMERA BÚSQUEDA (la correcta para tu BD real):
 * FileS3.Encriptado guarda la key completa, por ejemplo:
 * Data/Otros/f_68b1e7d30e0e79.25206771_3e23fdf8.txt
 */
$stmt = $db_connection->prepare(
    "SELECT PasswordHash, SecureHint, Encriptado, Ruta
     FROM FileS3
     WHERE user_id_=? AND Encriptado=? AND AccessType='secure' AND Found=1
     LIMIT 1"
);

if (!$stmt) {
    json_out(['ok' => false, 'msg' => 'Error preparando consulta']);
}

$stmt->bind_param('is', $userId, $fullKey);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;
$stmt->close();

/*
 * SEGUNDA BÚSQUEDA DE RESPALDO:
 * Por si alguna fila antigua estuviera guardada con Ruta + nombre separado.
 */
if (!$row && $ruta !== '' && $enc !== '') {
    $stmt = $db_connection->prepare(
        "SELECT PasswordHash, SecureHint, Encriptado, Ruta
         FROM FileS3
         WHERE user_id_=? AND Ruta=? AND Encriptado=? AND AccessType='secure' AND Found=1
         LIMIT 1"
    );

    if (!$stmt) {
        json_out(['ok' => false, 'msg' => 'Error preparando consulta']);
    }

    $stmt->bind_param('iss', $userId, $ruta, $enc);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($row && !empty($row['Encriptado'])) {
        $fullKey = normalize_unlock_key((string)$row['Encriptado']);
    }
}

if (!$row) {
    json_out(['ok' => false, 'msg' => 'Archivo no encontrado']);
}

$hash = (string)($row['PasswordHash'] ?? '');
if ($hash === '' || !password_verify($pass, $hash)) {
    json_out([
        'ok' => false,
        'msg' => 'Contraseña inválida',
        'secure_hint' => (string)($row['SecureHint'] ?? ''),
    ]);
}

if (!isset($_SESSION['secure_ok_files']) || !is_array($_SESSION['secure_ok_files'])) {
    $_SESSION['secure_ok_files'] = [];
}

$_SESSION['secure_ok_files'][$fullKey] = time() + 3600;

json_out([
    'ok' => true,
    'msg' => 'Desbloqueado',
    'key' => $fullKey,
    'expires_in' => 3600,
    'secure_hint' => (string)($row['SecureHint'] ?? ''),
]);
