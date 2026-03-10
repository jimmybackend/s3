<?php
// ver_archivo.php — entrega archivos (imágenes, videos, etc.) desde S3 con cabeceras correctas
require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once 'S3Manager.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$key = $_GET['archivo'] ?? '';
$key = trim((string)$key);

if ($key === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Falta parámetro archivo';
  exit;
}

/**
 * Normaliza:
 * - Backslashes -> slashes
 * - múltiples slashes
 * - quita duplicado DataX/DataX/....
 */
$key = str_replace('\\', '/', $key);
$key = preg_replace('#/+#', '/', $key);
$key = ltrim($key, '/');

$parts = array_values(array_filter(explode('/', $key), function($p) {
  return $p !== '';
}));

// Detecta Data, Data2, Data3... sin límite
$isDataRoot = function (string $s): bool {
  return preg_match('/^Data\d*$/i', $s) === 1; // Data o Data + número
};

// Caso típico del bug: Data/Data/xxx o Data2/Data2/xxx
if (count($parts) >= 2 && strcasecmp($parts[0], $parts[1]) === 0 && $isDataRoot($parts[0])) {
  array_shift($parts); // quita el primer "DataX" duplicado
}

// Caso: prefijo repetido completo (ej. Data/Img/A/Data/Img/A/archivo.jpg)
// Detecta repetición inmediata de los primeros N segmentos y elimina la primera ocurrencia.
$len = count($parts);
for ($n = 1; $n * 2 <= $len - 1; $n++) { // -1 para no incluir el nombre de archivo en comparación
  $a = array_slice($parts, 0, $n);
  $b = array_slice($parts, $n, $n);
  if ($a === $b) {
    $parts = array_slice($parts, $n);
    break;
  }
}

$key = implode('/', $parts);

try {
  $s3      = Config::getS3();
  $manager = new S3Manager();
  $bucket  = $manager->getBucket();

  // Descarga desde S3
  $obj = $s3->getObject([
    'Bucket' => $bucket,
    'Key'    => $key,
  ]);

  // MIME y cache
  $mime = $obj['ContentType'] ?? 'application/octet-stream';
  header('Content-Type: ' . $mime);
  header('Cache-Control: public, max-age=31536000, immutable');

  if (isset($obj['ContentLength'])) {
    header('Content-Length: ' . $obj['ContentLength']);
  }

  // Entrega el cuerpo
  echo (string)$obj['Body'];
} catch (Throwable $e) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'No se pudo cargar el archivo: ' . $e->getMessage();
}