<?php
// relock_file.php
// Vuelve a bloquear 1 o varios archivos cambiando su estado persistente a "secure".
// Este flujo ya no depende del desbloqueo por sesión; ahora trabaja directamente
// sobre la BD para que el archivo vuelva a quedar protegido.

declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

/**
 * Envía una respuesta JSON y termina la ejecución.
 */
function json_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Obtiene el id del usuario desde distintas variables de sesión compatibles.
 */
function resolve_relock_user_id(): int
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
 * Normaliza una key/ruta de archivo para evitar diferencias por slashes.
 */
function normalize_relock_key(string $key): string
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
function split_relock_key(string $key): array
{
    $key = normalize_relock_key($key);
    $pos = strrpos($key, '/');

    if ($pos === false) {
        return ['', $key];
    }

    return [
        substr($key, 0, $pos + 1),
        substr($key, $pos + 1),
    ];
}

/**
 * Limpia cualquier rastro de desbloqueo temporal en sesión para el archivo.
 * Aunque ahora el control principal es persistente en BD, esto evita estados mixtos.
 */
function clear_relock_session_keys(array $row, string $receivedKey): void
{
    if (!isset($_SESSION['secure_ok_files']) || !is_array($_SESSION['secure_ok_files'])) {
        return;
    }

    $keysToClear = [];

    $receivedKey = normalize_relock_key($receivedKey);
    if ($receivedKey !== '') {
        $keysToClear[] = $receivedKey;
    }

    $enc = normalize_relock_key((string)($row['Encriptado'] ?? ''));
    if ($enc !== '') {
        $keysToClear[] = $enc;
    }

    $ruta = normalize_relock_key((string)($row['Ruta'] ?? ''));
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

$userId = resolve_relock_user_id();
if ($userId <= 0) {
    json_out(['ok' => false, 'msg' => 'Sesión inválida']);
}

/**
 * Recibe una o varias keys:
 * - key
 * - keys
 * - keys[]
 * - ruta + encriptado (compatibilidad)
 */
$keys = [];

if (isset($_POST['key']) && $_POST['key'] !== '') {
    $keys[] = (string)$_POST['key'];
}

if (isset($_POST['keys'])) {
    $keys = array_merge($keys, is_array($_POST['keys']) ? $_POST['keys'] : [$_POST['keys']]);
}

if (isset($_POST['keys[]'])) {
    $keys = array_merge($keys, is_array($_POST['keys[]']) ? $_POST['keys[]'] : [$_POST['keys[]']]);
}

$ruta = trim((string)($_POST['ruta'] ?? ''));
$enc  = trim((string)($_POST['encriptado'] ?? $_POST['enc'] ?? ''));

if ($ruta !== '' && $enc !== '') {
    $keys[] = $ruta . $enc;
}

/**
 * Normaliza y elimina duplicados.
 */
$keys = array_values(array_unique(array_filter(array_map(
    'normalize_relock_key',
    $keys
))));

if (!$keys) {
    json_out(['ok' => false, 'msg' => 'key(s) requeridas']);
}

$ok = 0;
$fail = 0;
$errors = [];

try {
    /**
     * Búsqueda por key completa.
     */
    $findByFullKey = $db_connection->prepare(
        "SELECT id_, Encriptado, Ruta, AccessType, PasswordHash, SecureHint
         FROM FileS3
         WHERE user_id_=? AND Encriptado=? AND Found=1
         LIMIT 1"
    );

    if (!$findByFullKey) {
        throw new RuntimeException('No se pudo preparar SELECT por key completa');
    }

    /**
     * Búsqueda de respaldo por Ruta + Encriptado.
     */
    $findByRutaEnc = $db_connection->prepare(
        "SELECT id_, Encriptado, Ruta, AccessType, PasswordHash, SecureHint
         FROM FileS3
         WHERE user_id_=? AND Ruta=? AND Encriptado=? AND Found=1
         LIMIT 1"
    );

    if (!$findByRutaEnc) {
        throw new RuntimeException('No se pudo preparar SELECT por ruta/encriptado');
    }

    /**
     * UPDATE para volver a bloquear el archivo.
     * Solo cambia el estado a "secure" y conserva PasswordHash + SecureHint.
     */
    $updateStmt = $db_connection->prepare(
        "UPDATE FileS3
         SET AccessType='secure',
             SecureUpdatedAt=NOW()
         WHERE id_=? AND user_id_=? AND Found=1"
    );

    if (!$updateStmt) {
        throw new RuntimeException('No se pudo preparar UPDATE secure');
    }

    /**
     * Procesa cada archivo recibido.
     */
    foreach ($keys as $key) {
        $row = null;

        // Intento 1: búsqueda por key completa
        $findByFullKey->bind_param('is', $userId, $key);
        $findByFullKey->execute();
        $res = $findByFullKey->get_result();
        $row = $res ? $res->fetch_assoc() : null;

        // Intento 2: búsqueda por Ruta + Encriptado
        if (!$row) {
            [$rutaCalc, $encCalc] = split_relock_key($key);

            if ($rutaCalc !== '' && $encCalc !== '') {
                $findByRutaEnc->bind_param('iss', $userId, $rutaCalc, $encCalc);
                $findByRutaEnc->execute();
                $res = $findByRutaEnc->get_result();
                $row = $res ? $res->fetch_assoc() : null;
            }
        }

        if (!$row || empty($row['id_'])) {
            $fail++;
            $errors[] = "No existe el archivo: {$key}";
            continue;
        }

        /**
         * Si el archivo no tiene contraseña, no tiene sentido dejarlo en secure.
         * Esto evita archivos en estado inconsistente.
         */
        $passwordHash = (string)($row['PasswordHash'] ?? '');
        if ($passwordHash === '') {
            $fail++;
            $errors[] = "El archivo no tiene contraseña configurada: {$key}";
            continue;
        }

        $id = (int)$row['id_'];

        $updateStmt->bind_param('ii', $id, $userId);
        $updateStmt->execute();

        if ($updateStmt->errno) {
            $fail++;
            $errors[] = "Error al bloquear: {$key}";
            continue;
        }

        /**
         * Limpia cualquier desbloqueo temporal por sesión para evitar
         * que el archivo siga apareciendo como abierto en otra parte del sistema.
         */
        clear_relock_session_keys($row, $key);

        $ok++;
    }

    $updateStmt->close();
    $findByFullKey->close();
    $findByRutaEnc->close();

    json_out([
        'ok'         => $ok > 0,
        'ok_count'   => $ok,
        'fail_count' => $fail,
        'errors'     => $errors,
        'access_type'=> 'secure',
        'msg'        => $ok > 0 ? 'Archivo(s) bloqueado(s) correctamente' : 'No se bloqueó ningún archivo',
    ]);
} catch (Throwable $e) {
    error_log('[relock_file.php] ' . $e->getMessage());

    json_out([
        'ok'    => false,
        'msg'   => 'Error de servidor',
        'error' => $e->getMessage(),
    ]);
}