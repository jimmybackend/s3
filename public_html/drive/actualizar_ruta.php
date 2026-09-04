<?php
// actualizar_ruta.php — Guarda la ruta actual en sesión y responde JSON
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

$ruta = isset($_POST['ruta']) ? (string)$_POST['ruta'] : '';

// Normaliza a "Data/.../"
$ruta = trim($ruta);
$ruta = preg_replace('~[\\/]+~', '/', $ruta);

if ($ruta === '') {
  echo json_encode(['ok' => false, 'mensaje' => 'Ruta vacía']);
  exit;
}

// Asegura prefijo "Data/"
if (strpos($ruta, 'Data/') !== 0) {
  $ruta = 'Data/' . ltrim($ruta, '/');
}

// Sin slash inicial; con slash final
$ruta = ltrim($ruta, '/');
if (substr($ruta, -1) !== '/') $ruta .= '/';

// Guarda en sesión exactamente como lo requiere bloque_archivos.php
$_SESSION['ruta_actual'] = $ruta;

echo json_encode(['ok' => true, 'ruta' => $ruta]);
