<?php
// unlock_file.php — valida contraseña y abre acceso temporal por sesión
declare(strict_types=1);
require_once __DIR__ . '/app_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) { echo json_encode(['ok'=>false,'msg'=>'Sesión inválida']); exit; }

$ruta = $_POST['ruta'] ?? '';
$enc  = $_POST['encriptado'] ?? '';
$pass = $_POST['password'] ?? '';
if ($ruta === '' || $enc === '' || $pass === '') {
  echo json_encode(['ok'=>false,'msg'=>'Parámetros incompletos']); exit;
}

$stmt = $db_connection->prepare("
  SELECT PasswordHash
  FROM FileS3
  WHERE user_id_=? AND Ruta=? AND Encriptado=? AND AccessType='secure' AND Found=1
  LIMIT 1
");
$stmt->bind_param('iss', $userId, $ruta, $enc);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row || empty($row['PasswordHash']) || !password_verify($pass, $row['PasswordHash'])) {
  echo json_encode(['ok'=>false,'msg'=>'Contraseña inválida']); exit;
}

// Acceso temporal por 1 hora
$key = $ruta . $enc;
$_SESSION['secure_ok_files'][$key] = time() + 3600;

echo json_encode(['ok'=>true,'msg'=>'Desbloqueado']);
