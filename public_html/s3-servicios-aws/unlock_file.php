<?php
// unlock_file.php
// Valida la contraseña del archivo seguro y lo cambia a estado persistente "unlocked".
// Con este flujo el archivo sigue teniendo contraseña en BD, pero queda habilitado
// para mostrar todas las acciones hasta que el usuario vuelva a bloquearlo.

declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

/**
 * Devuelve respuesta JSON y finaliza la ejecución.
 */
function json_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Resuelve el id de usuario desde distintas claves de sesión compatibles.
 */
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

/**
 * Normaliza una key/ruta de archivo para evitar errores por slashes.
 */
function normalize_unlock_key(string $key): string
{
    $key = trim(str_replace('\\', '/', $key));
    $key = preg_replace('~/+~', '/', $key);
    return ltrim((string)$key, '/');
}

/**
 * Separa una key completa en:
 * - ruta con slash final
 * - nombre final del archivo
 */
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

/**
 * Limpia posibles claves de desbloqueo temporal en sesión para evitar
 * inconsistencias con el nuevo estado persistente en BD.
 */
function clear_unlock_session_keys(array $row, string $receivedKey): void
{
    if (!isset($_SESSION['secure_ok_files']) || !is_array($_SESSION['secure_ok_files'])) {
        return;
    }

    $keysToClear = [];

    $receivedKey = normalize_unlock_key($receivedKey);
    if ($receivedKey !== '') {
        $keysToClear[] = $receivedKey;
    }

    $enc = normalize_unlock_key((string)($row['Encriptado'] ?? ''));
    if ($enc !== '') {
        $keysToClear[] = $enc;
    }

    $ruta = normalize_unlock_key((string)($row['Ruta'] ?? ''));
    if ($ruta !== '' && substr($ruta, -1) !== '/') {
        $ruta .= '/';
    }

    if ($ruta !== '' && $enc !== '') {
        $keysToClear[] = $ruta . $enc;
    }

    foreach (array_unique($keysToClear) as $k) {
        unset($_SESSION['secure_ok_files'][$k]);
    }
}

$userId = resolve_unlock_user_id();
if ($userId <= 0) {
    json_out(['ok' => false, 'msg' => 'Sesión inválida']);
}

/**
 * Parámetros de entrada compatibles:
 * - key          : key completa del archivo
 * - ruta + enc   : respaldo para registros antiguos
 * - password/pass: contraseña ingresada por el usuario
 */
$key  = trim((string)($_POST['key'] ?? ''));
$ruta = trim((string)($_POST['ruta'] ?? ''));
$enc  = trim((string)($_POST['encriptado'] ?? $_POST['enc'] ?? ''));
$pass = (string)($_POST['password'] ?? $_POST['pass'] ?? '');

/**
 * Reconstruye la key completa priorizando "key".
 */
$fullKey = '';

if ($key !== '') {
    $fullKey = normalize_unlock_key($key);
} elseif ($enc !== '') {
    // Compatibilidad:
    // - si encriptado ya viene como key completa, se usa tal cual
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

/**
 * Si no llega ruta o enc por separado, se calculan desde la key completa.
 */
[$rutaCalc, $encCalc] = split_unlock_key($fullKey);

if ($ruta === '') {
    $ruta = $rutaCalc;
}

if ($enc === '') {
    $enc = $encCalc;
}

$row = null;

/**
 * PRIMERA BÚSQUEDA:
 * Busca el archivo seguro por key completa.
 * Solo se permite desbloquear si actualmente está en AccessType='secure'.
 */
$stmt = $db_connection->prepare(
    "SELECT id_, PasswordHash, SecureHint, Encriptado, Ruta, AccessType
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

/**
 * SEGUNDA BÚSQUEDA DE RESPALDO:
 * Por si alguna fila antigua estuviera guardada como Ruta + Encriptado separados.
 */
if (!$row && $ruta !== '' && $enc !== '') {
    $stmt = $db_connection->prepare(
        "SELECT id_, PasswordHash, SecureHint, Encriptado, Ruta, AccessType
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

/**
 * Valida la contraseña real almacenada en PasswordHash.
 */
$hash = (string)($row['PasswordHash'] ?? '');

if ($hash === '' || !password_verify($pass, $hash)) {
    json_out([
        'ok' => false,
        'msg' => 'Contraseña inválida',
        'secure_hint' => (string)($row['SecureHint'] ?? ''),
    ]);
}

/**
 * Al validar correctamente:
 * - NO se elimina la contraseña
 * - NO se elimina el hint
 * - SOLO se cambia el estado persistente a "unlocked"
 *
 * Así el archivo seguirá siendo seguro, pero ya no estará bloqueado.
 */
$upd = $db_connection->prepare(
    "UPDATE FileS3
     SET AccessType='unlocked',
         SecureUpdatedAt=NOW()
     WHERE id_=? AND user_id_=? AND Found=1
     LIMIT 1"
);

if (!$upd) {
    json_out(['ok' => false, 'msg' => 'No se pudo preparar la actualización']);
}

$fileId = (int)($row['id_'] ?? 0);
$upd->bind_param('ii', $fileId, $userId);
$upd->execute();

if ($upd->errno) {
    $error = $upd->error;
    $upd->close();

    json_out([
        'ok' => false,
        'msg' => 'No se pudo actualizar el estado del archivo',
        'error' => $error,
    ]);
}

$upd->close();

/**
 * Limpia cualquier rastro de desbloqueo temporal por sesión,
 * porque ahora el archivo queda desbloqueado de forma persistente en BD.
 */
clear_unlock_session_keys($row, $fullKey);

json_out([
    'ok' => true,
    'msg' => 'Archivo desbloqueado correctamente',
    'key' => $fullKey,
    'access_type' => 'unlocked',
    'secure_hint' => (string)($row['SecureHint'] ?? ''),
]);