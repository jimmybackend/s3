<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_key(string $key): string
{
    $key = trim(str_replace('\\', '/', $key));
    $key = preg_replace('~/+~', '/', $key);
    return ltrim((string)$key, '/');
}

function split_key_parts(string $key): array
{
    $key = normalize_key($key);
    $pos = strrpos($key, '/');

    if ($pos === false) {
        return ['', $key];
    }

    return [
        substr($key, 0, $pos + 1), // Ruta con slash final
        substr($key, $pos + 1),    // Nombre / Encriptado final
    ];
}

$userId = 0;
if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
} elseif (isset($_SESSION['user_id_'])) {
    $userId = (int)$_SESSION['user_id_'];
}

if ($userId <= 0) {
    json_out(['ok' => false, 'msg' => 'Sesión inválida']);
}

$mode = isset($_POST['mode']) ? trim((string)$_POST['mode']) : '';
if ($mode !== 'secure' && $mode !== 'normal') {
    json_out(['ok' => false, 'msg' => 'Modo inválido']);
}

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

$keys = array_values(array_unique(array_filter(array_map(
    function ($v) {
        return normalize_key((string)$v);
    },
    $keys
))));

if (!$keys) {
    json_out(['ok' => false, 'msg' => 'Sin archivos seleccionados']);
}

$password   = isset($_POST['password']) ? trim((string)$_POST['password']) : '';
$secureHint = isset($_POST['secure_hint']) ? trim((string)$_POST['secure_hint']) : '';

if ($mode === 'secure') {
    $len = mb_strlen($password, 'UTF-8');
    if ($len < 4 || $len > 100) {
        json_out(['ok' => false, 'msg' => 'La contraseña debe tener entre 4 y 100 caracteres']);
    }
}

$ok = 0;
$fail = 0;
$errors = [];

try {
    /**
     * 1) Buscar por key completa en Encriptado
     * 2) Si no existe, buscar por Ruta + Encriptado separado
     * 3) Actualizar por id_ para no depender del formato guardado
     */

    $findByFullKey = $db_connection->prepare(
        "SELECT id_, Encriptado, Ruta
         FROM FileS3
         WHERE user_id_=? AND Encriptado=? AND Found=1
         LIMIT 1"
    );

    if (!$findByFullKey) {
        throw new RuntimeException('No se pudo preparar SELECT por key completa');
    }

    $findByRutaEnc = $db_connection->prepare(
        "SELECT id_, Encriptado, Ruta
         FROM FileS3
         WHERE user_id_=? AND Ruta=? AND Encriptado=? AND Found=1
         LIMIT 1"
    );

    if (!$findByRutaEnc) {
        throw new RuntimeException('No se pudo preparar SELECT por ruta/encriptado');
    }

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

            $updateStmt->bind_param('ssii', $hash, $secureHint, $id, $userId);
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

            $ok++;
            unset($_SESSION['secure_ok_files'][$key]);

            // Limpia también por el valor real almacenado, por si difiere del key recibido
            if (!empty($row['Encriptado'])) {
                unset($_SESSION['secure_ok_files'][normalize_key((string)$row['Encriptado'])]);
            }
        }

        $updateStmt->close();
    } else {
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

            $updateStmt->bind_param('ii', $id, $userId);
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

            $ok++;
            unset($_SESSION['secure_ok_files'][$key]);

            if (!empty($row['Encriptado'])) {
                unset($_SESSION['secure_ok_files'][normalize_key((string)$row['Encriptado'])]);
            }
        }

        $updateStmt->close();
    }

    $findByFullKey->close();
    $findByRutaEnc->close();

    json_out([
        'ok' => $ok > 0,
        'ok_count' => $ok,
        'fail_count' => $fail,
        'errors' => $errors,
        'msg' => $ok > 0 ? 'Seguridad actualizada' : 'No se actualizó ningún archivo'
    ]);
} catch (Throwable $e) {
    error_log('[set_file_security.php] ' . $e->getMessage());

    json_out([
        'ok' => false,
        'msg' => 'Error de servidor',
        'error' => $e->getMessage()
    ]);
}