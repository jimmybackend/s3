<?php
declare(strict_types=1);

/**
 * set_file_security.php
 *
 * Este archivo administra 3 estados de seguridad persistentes en la BD:
 *
 * - normal   : archivo sin seguridad
 * - secure   : archivo protegido / bloqueado
 * - unlocked : archivo con contraseña, pero desbloqueado de forma persistente
 *
 * Reglas:
 * - secure   -> guarda contraseña e indicio
 * - unlocked -> conserva contraseña e indicio, pero permite trabajar con el archivo
 * - normal   -> quita toda la seguridad y limpia contraseña/indicio
 */

require_once __DIR__ . '/app_bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

/**
 * Respuesta JSON estándar y cierre inmediato.
 */
function json_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Normaliza una key/ruta de archivo para evitar diferencias por slash.
 */
function normalize_key(string $key): string
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
function split_key_parts(string $key): array
{
    $key = normalize_key($key);
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
 * Limpia cualquier desbloqueo temporal en sesión para una key.
 * Esto evita inconsistencias entre sesión y estado persistente en BD.
 */
function clear_secure_session_keys(array $row, string $receivedKey): void
{
    $keysToClear = [];

    $receivedKey = normalize_key($receivedKey);
    if ($receivedKey !== '') {
        $keysToClear[] = $receivedKey;
    }

    $enc = normalize_key((string)($row['Encriptado'] ?? ''));
    if ($enc !== '') {
        $keysToClear[] = $enc;
    }

    $ruta = normalize_key((string)($row['Ruta'] ?? ''));
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

/**
 * Obtiene el id del usuario desde la sesión.
 */
$userId = 0;
if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
} elseif (isset($_SESSION['user_id_'])) {
    $userId = (int)$_SESSION['user_id_'];
}

if ($userId <= 0) {
    json_out(['ok' => false, 'msg' => 'Sesión inválida']);
}

/**
 * Modos soportados:
 * - secure   : bloquear / proteger
 * - unlocked : desbloquear persistente conservando contraseña
 * - normal   : quitar seguridad
 */
$mode = isset($_POST['mode']) ? trim((string)$_POST['mode']) : '';
if (!in_array($mode, ['secure', 'unlocked', 'normal'], true)) {
    json_out(['ok' => false, 'msg' => 'Modo inválido']);
}

/**
 * Recibe una o varias keys desde POST.
 */
$keys = [];

if (isset($_POST['key']) && $_POST['key'] !== '') {
    $keys[] = (string)$_POST['key'];
}

if (isset($_POST['keys'])) {
    $tmp = is_array($_POST['keys']) ? $_POST['keys'] : [$_POST['keys']];
    $keys = array_merge($keys, $tmp);
}

if (isset($_POST['keys[]'])) {
    $tmp = is_array($_POST['keys[]']) ? $_POST['keys[]'] : [$_POST['keys[]']];
    $keys = array_merge($keys, $tmp);
}

/**
 * Normaliza y elimina duplicados.
 */
$keys = array_values(array_unique(array_filter(array_map(
    static function ($v) {
        return normalize_key((string)$v);
    },
    $keys
))));

if (!$keys) {
    json_out(['ok' => false, 'msg' => 'Sin archivos seleccionados']);
}

/**
 * Datos usados solo cuando se aplica seguridad.
 */
$password   = isset($_POST['password']) ? trim((string)$_POST['password']) : '';
$secureHint = isset($_POST['secure_hint']) ? trim((string)$_POST['secure_hint']) : '';

if ($mode === 'secure') {
    $len = mb_strlen($password, 'UTF-8');

    if ($len < 4 || $len > 100) {
        json_out([
            'ok'  => false,
            'msg' => 'La contraseña debe tener entre 4 y 100 caracteres'
        ]);
    }
}

$ok = 0;
$fail = 0;
$errors = [];

try {
    /**
     * Búsqueda robusta del archivo:
     * 1) por key completa en Encriptado
     * 2) por Ruta + Encriptado
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
     * Define el UPDATE según el modo solicitado.
     */
    $updateStmt = null;
    $hash = null;

    if ($mode === 'secure') {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $updateStmt = $db_connection->prepare(
            "UPDATE FileS3
             SET AccessType='secure',
                 PasswordHash=?,
                 SecureHint=?,
                 SecureUpdatedAt=NOW()
             WHERE id_=? AND user_id_=? AND Found=1"
        );

        if (!$updateStmt) {
            throw new RuntimeException('No se pudo preparar UPDATE secure');
        }
    } elseif ($mode === 'unlocked') {
        /**
         * unlocked:
         * - conserva PasswordHash
         * - conserva SecureHint
         * - cambia solo el estado persistente
         */
        $updateStmt = $db_connection->prepare(
            "UPDATE FileS3
             SET AccessType='unlocked',
                 SecureUpdatedAt=NOW()
             WHERE id_=? AND user_id_=? AND Found=1"
        );

        if (!$updateStmt) {
            throw new RuntimeException('No se pudo preparar UPDATE unlocked');
        }
    } else {
        /**
         * normal:
         * - quita seguridad completamente
         * - limpia contraseña e indicio
         */
        $updateStmt = $db_connection->prepare(
            "UPDATE FileS3
             SET AccessType='normal',
                 PasswordHash=NULL,
                 SecureHint=NULL,
                 SecureUpdatedAt=NOW()
             WHERE id_=? AND user_id_=? AND Found=1"
        );

        if (!$updateStmt) {
            throw new RuntimeException('No se pudo preparar UPDATE normal');
        }
    }

    /**
     * Procesa cada archivo recibido.
     */
    foreach ($keys as $key) {
        $row = null;

        // Intento 1: key completa
        $findByFullKey->bind_param('is', $userId, $key);
        $findByFullKey->execute();
        $res = $findByFullKey->get_result();
        $row = $res ? $res->fetch_assoc() : null;

        // Intento 2: Ruta + Encriptado separado
        if (!$row) {
            [$ruta, $enc] = split_key_parts($key);

            if ($ruta !== '' && $enc !== '') {
                $findByRutaEnc->bind_param('iss', $userId, $ruta, $enc);
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

        $id = (int)$row['id_'];

        /**
         * Ejecuta el UPDATE según el modo.
         */
        if ($mode === 'secure') {
            $updateStmt->bind_param('ssii', $hash, $secureHint, $id, $userId);
        } else {
            $updateStmt->bind_param('ii', $id, $userId);
        }

        $updateStmt->execute();

        if ($updateStmt->errno) {
            $fail++;
            $errors[] = "Error al actualizar: {$key}";
            continue;
        }

        if ($updateStmt->affected_rows < 0) {
            $fail++;
            $errors[] = "No se pudo actualizar: {$key}";
            continue;
        }

        /**
         * Limpia sesión temporal para evitar conflicto con el nuevo estado persistente.
         */
        clear_secure_session_keys($row, $key);

        $ok++;
    }

    $updateStmt->close();
    $findByFullKey->close();
    $findByRutaEnc->close();

    /**
     * Mensaje final según el modo aplicado.
     */
    $msg = 'Seguridad actualizada';

    if ($mode === 'secure') {
        $msg = 'Archivo(s) bloqueado(s) correctamente';
    } elseif ($mode === 'unlocked') {
        $msg = 'Archivo(s) desbloqueado(s) correctamente';
    } elseif ($mode === 'normal') {
        $msg = 'Seguridad eliminada correctamente';
    }

    json_out([
        'ok'         => $ok > 0,
        'ok_count'   => $ok,
        'fail_count' => $fail,
        'errors'     => $errors,
        'mode'       => $mode,
        'msg'        => $ok > 0 ? $msg : 'No se actualizó ningún archivo',
    ]);
} catch (Throwable $e) {
    error_log('[set_file_security.php] ' . $e->getMessage());

    json_out([
        'ok'    => false,
        'msg'   => 'Error de servidor',
        'error' => $e->getMessage(),
    ]);
}