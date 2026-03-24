<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';

function security_resolve_user_id(): int
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

function security_normalize_key(string $key): string
{
    $key = trim(str_replace('\\', '/', $key));
    $key = preg_replace('#/+#', '/', $key) ?? $key;
    return ltrim($key, '/');
}

function security_lookup_file(mysqli $db, int $userId, string $key): ?array
{
    $stmt = $db->prepare("
        SELECT id_, Nombre, Ruta, Encriptado, AccessType, PasswordHash, SecureHint
        FROM FileS3
        WHERE user_id_ = ?
          AND Encriptado = ?
          AND Found = 1
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la validación de seguridad.');
    }

    $stmt->bind_param('is', $userId, $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function security_session_is_unlocked(string $key): bool
{
    $expires = $_SESSION['secure_ok_files'][$key] ?? null;
    if ($expires === null) {
        return false;
    }

    if ((int)$expires < time()) {
        unset($_SESSION['secure_ok_files'][$key]);
        return false;
    }

    return true;
}

function render_blocked_page(string $message): void
{
    http_response_code(423);
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

    echo '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Archivo protegido</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;padding:24px}
.wrap{max-width:560px;margin:10vh auto;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:24px}
h1{font-size:22px;margin:0 0 12px}
p{margin:0 0 18px;line-height:1.5;color:#333}
.row{display:flex;gap:12px;flex-wrap:wrap}
.btn{display:inline-block;padding:10px 14px;border-radius:8px;text-decoration:none;border:1px solid #cfd6e4;background:#fff;color:#1d2a3a;cursor:pointer}
.btn-primary{background:#0d6efd;color:#fff;border-color:#0d6efd}
</style>
</head>
<body>
<div class="wrap">
<h1>Archivo protegido</h1>
<p>'.$safe.'</p>
<div class="row">
<button class="btn btn-primary" type="button" onclick="history.back()">Volver</button>
<a class="btn" href="s3.php">Ir al explorador</a>
</div>
</div>
</body>
</html>';
    exit;
}

try {
    if (!isset($_GET['archivo']) || trim((string)$_GET['archivo']) === '') {
        throw new RuntimeException('Falta la clave del archivo.');
    }

    $userId = security_resolve_user_id();
    if ($userId <= 0) {
        http_response_code(401);
        echo 'Sesión inválida.';
        exit;
    }

    $archivo = security_normalize_key((string)$_GET['archivo']);

    global $db_connection;
    if (!$db_connection instanceof mysqli) {
        throw new RuntimeException('No existe una conexión mysqli válida.');
    }

    $fileRow = security_lookup_file($db_connection, $userId, $archivo);
    if (!$fileRow) {
        http_response_code(404);
        echo 'Archivo no encontrado.';
        exit;
    }

    $isSecure = (($fileRow['AccessType'] ?? 'normal') === 'secure')
        || !empty($fileRow['PasswordHash']);

    if ($isSecure && !security_session_is_unlocked($archivo)) {
        render_blocked_page('Debes desbloquear este archivo antes de descargarlo.');
    }

    $s3Manager = new S3Manager();
    $resultado = $s3Manager->downloadFile($archivo);

    header('Location: ' . $resultado['url_descarga']);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $e->getMessage();
}
