<?php
// relock_file.php — cierra acceso (elimina desbloqueo en sesión) de 1..N archivos
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function normalize_relock_key(string $key): string
{
    $key = trim(str_replace('\\', '/', $key));
    $key = preg_replace('~/+~', '/', $key);
    return ltrim($key, '/');
}

$keys = [];
if (isset($_POST['key']) && $_POST['key'] !== '') $keys[] = (string)$_POST['key'];
if (isset($_POST['keys'])) $keys = array_merge($keys, is_array($_POST['keys']) ? $_POST['keys'] : [$_POST['keys']]);
if (isset($_POST['keys[]'])) $keys = array_merge($keys, is_array($_POST['keys[]']) ? $_POST['keys[]'] : [$_POST['keys[]']]);

$ruta = trim((string)($_POST['ruta'] ?? ''));
$enc  = trim((string)($_POST['encriptado'] ?? $_POST['enc'] ?? ''));
if ($ruta !== '' && $enc !== '') {
    $keys[] = $ruta . $enc;
}

$keys = array_values(array_unique(array_filter(array_map('normalize_relock_key', $keys))));
if (!$keys) {
    echo json_encode(['ok' => false, 'msg' => 'key(s) requeridas']);
    exit;
}

$ok = 0;
$fail = 0;
foreach ($keys as $k) {
    if (isset($_SESSION['secure_ok_files'][$k])) {
        unset($_SESSION['secure_ok_files'][$k]);
        $ok++;
    } else {
        $fail++;
    }
}

echo json_encode(['ok' => true, 'ok_count' => $ok, 'fail_count' => $fail]);
