<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

$userId = 0;
if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
} elseif (isset($_SESSION['user_id_'])) {
    $userId = (int)$_SESSION['user_id_'];
}

if ($userId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Sesión inválida']);
    exit;
}

$mode = isset($_POST['mode']) ? trim((string)$_POST['mode']) : '';
if ($mode !== 'secure' && $mode !== 'normal') {
    echo json_encode(['ok' => false, 'msg' => 'Modo inválido']);
    exit;
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

$keys = array_values(array_unique(array_filter(array_map(static function ($v) {
    $v = trim(str_replace('\\', '/', (string)$v));
    $v = preg_replace('#/+#', '/', $v);
    return ltrim((string)$v, '/');
}, $keys))));

if (!$keys) {
    echo json_encode(['ok' => false, 'msg' => 'Sin archivos seleccionados']);
    exit;
}

$password = isset($_POST['password']) ? trim((string)$_POST['password']) : '';
$secureHint = isset($_POST['secure_hint']) ? trim((string)$_POST['secure_hint']) : '';

if ($mode === 'secure') {
    $len = mb_strlen($password, 'UTF-8');
    if ($len < 4 || $len > 100) {
        echo json_encode(['ok' => false, 'msg' => 'La contraseña debe tener entre 4 y 100 caracteres']);
        exit;
    }
}

$ok = 0;
$fail = 0;
$errors = [];

try {
    $checkStmt = $db_connection->prepare(
        "SELECT id_, AccessType, PasswordHash, SecureHint FROM FileS3 WHERE user_id_=? AND Encriptado=? AND Found=1 LIMIT 1"
    );

    if (!$checkStmt) {
        throw new RuntimeException('No se pudo preparar SELECT de validación');
    }

    if ($mode === 'secure') {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db_connection->prepare(
            "UPDATE FileS3
                SET AccessType='secure',
                    PasswordHash=?,
                    SecureHint=?,
                    SecureUpdatedAt=NOW()
              WHERE user_id_=? AND Encriptado=? AND Found=1"
        );

        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar UPDATE secure');
        }

        foreach ($keys as $key) {
            $checkStmt->bind_param('is', $userId, $key);
            $checkStmt->execute();
            $row = $checkStmt->get_result()->fetch_assoc();

            if (!$row) {
                $fail++;
                $errors[] = "No existe el archivo: {$key}";
                continue;
            }

            $stmt->bind_param('ssis', $hash, $secureHint, $userId, $key);
            $stmt->execute();

            if ($stmt->errno) {
                $fail++;
                $errors[] = "Error al actualizar: {$key}";
                continue;
            }

            $ok++;
            unset($_SESSION['secure_ok_files'][$key]);
        }

        $stmt->close();
    } else {
        $stmt = $db_connection->prepare(
            "UPDATE FileS3
                SET AccessType='normal',
                    PasswordHash=NULL,
                    SecureHint=NULL,
                    SecureUpdatedAt=NOW()
              WHERE user_id_=? AND Encriptado=? AND Found=1"
        );

        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar UPDATE normal');
        }

        foreach ($keys as $key) {
            $checkStmt->bind_param('is', $userId, $key);
            $checkStmt->execute();
            $row = $checkStmt->get_result()->fetch_assoc();

            if (!$row) {
                $fail++;
                $errors[] = "No existe el archivo: {$key}";
                continue;
            }

            $stmt->bind_param('is', $userId, $key);
            $stmt->execute();

            if ($stmt->errno) {
                $fail++;
                $errors[] = "Error al actualizar: {$key}";
                continue;
            }

            $ok++;
            unset($_SESSION['secure_ok_files'][$key]);
        }

        $stmt->close();
    }

    $checkStmt->close();

    echo json_encode([
        'ok' => $ok > 0,
        'ok_count' => $ok,
        'fail_count' => $fail,
        'errors' => $errors,
        'msg' => $ok > 0 ? 'Seguridad actualizada' : 'No se actualizó ningún archivo'
    ]);
} catch (Throwable $e) {
    error_log('[set_file_security.php] ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'msg' => 'Error de servidor',
        'error' => $e->getMessage()
    ]);
}
