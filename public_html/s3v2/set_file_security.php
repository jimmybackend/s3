<?php
// set_file_security.php — asegurar / quitar seguridad a 1..N archivos
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
  echo json_encode(['ok' => false, 'msg' => 'Sesión inválida']); exit;
}

$mode = isset($_POST['mode']) ? (string)$_POST['mode'] : '';
if ($mode !== 'secure' && $mode !== 'normal') {
  echo json_encode(['ok' => false, 'msg' => 'Modo inválido']); exit;
}

// Acepta keys en "keys" o "keys[]"
$keys = [];
if (isset($_POST['keys']))   $keys = is_array($_POST['keys']) ? $_POST['keys'] : [$_POST['keys']];
if (isset($_POST['keys[]'])) $keys = array_merge($keys, is_array($_POST['keys[]']) ? $_POST['keys[]'] : [$_POST['keys[]']]);
$keys = array_values(array_filter(array_map('strval', $keys)));

if (empty($keys)) {
  echo json_encode(['ok' => false, 'msg' => 'Sin archivos seleccionados']); exit;
}

$password = isset($_POST['password']) ? (string)$_POST['password'] : '';
if ($mode === 'secure') {
  $len = strlen($password);
  if ($len < 4 || $len > 20) {
    echo json_encode(['ok' => false, 'msg' => 'La contraseña debe tener entre 4 y 20 caracteres']); exit;
  }
}

$ok = 0; $fail = 0;

try {
  if ($mode === 'secure') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db_connection->prepare(
      "UPDATE FileS3
       SET AccessType='secure', PasswordHash=?, SecureUpdatedAt=NOW()
       WHERE user_id_=? AND Ruta=? AND Encriptado=? AND Found=1"
    );

    foreach ($keys as $key) {
      $pos = strrpos($key, '/');
      if ($pos === false) { $fail++; continue; }
      $ruta = substr($key, 0, $pos + 1);
      $enc  = substr($key, $pos + 1);

      $stmt->bind_param('siss', $hash, $userId, $ruta, $enc);
      if ($stmt->execute()) {
        $ok++;
        // No abrir en sesión: queda BLOQUEADO por defecto.
        if (isset($_SESSION['secure_ok_files'][$key])) unset($_SESSION['secure_ok_files'][$key]);
      } else {
        $fail++;
      }
    }
  } else { // normal
    $stmt = $db_connection->prepare(
      "UPDATE FileS3
       SET AccessType='normal', PasswordHash=NULL, SecureUpdatedAt=NOW()
       WHERE user_id_=? AND Ruta=? AND Encriptado=? AND Found=1"
    );

    foreach ($keys as $key) {
      $pos = strrpos($key, '/');
      if ($pos === false) { $fail++; continue; }
      $ruta = substr($key, 0, $pos + 1);
      $enc  = substr($key, $pos + 1);

      $stmt->bind_param('iss', $userId, $ruta, $enc);
      if ($stmt->execute()) {
        $ok++;
        // Limpiar cualquier apertura previa en sesión
        if (isset($_SESSION['secure_ok_files'][$key])) unset($_SESSION['secure_ok_files'][$key]);
      } else {
        $fail++;
      }
    }
  }

  echo json_encode(['ok' => true, 'ok_count' => $ok, 'fail_count' => $fail]);
} catch (\Throwable $e) {
  error_log('[set_file_security] ' . $e->getMessage());
  echo json_encode(['ok' => false, 'msg' => 'Error de servidor']);
}
