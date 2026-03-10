<?php
/**
 * generar_galeria.php
 *
 * Devuelve un JSON con las imágenes visibles en la carpeta actual (o la ruta pasada por GET),
 * generando para cada item:
 *   - key      : clave S3 completa (p.ej. "Data/Carpeta/archivo.jpg")
 *   - nombre   : nombre de archivo
 *   - original : URL para ver el original (usa tu ver_archivo.php para streaming)
 *   - thumb    : URL de miniatura (usa thumb.php que genera/sube redirigiendo a S3)
 *
 * Si no hay imágenes en la ruta, devuelve un único item con img/file.png como placeholder.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
  // ==== Dependencias de tu proyecto ====
  require_once __DIR__ . '/vendor/autoload.php';
  require_once __DIR__ . '/app_bootstrap.php';
  require_once __DIR__ . '/S3Manager.php';

  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

  // ==== Parámetros opcionales ====
 /* 
  $ruta = isset($_GET['ruta']) ? (string)$_GET['ruta'] : '';
  if ($ruta === '' && !empty($_SESSION['ruta_actual'])) {
    $ruta = (string)$_SESSION['ruta_actual'];
  }
  */
  
  $ruta = (string)$_SESSION['ruta_actual'];
  
  // Normaliza prefijo
  $prefix = ltrim($ruta, '/');
  if ($prefix !== '' && substr($prefix, -1) !== '/') {
    $prefix .= '/';
  }

  // Tamaño de miniatura solicitado
  $W = isset($_GET['w']) ? max(1, (int)$_GET['w']) : 384;
  $H = isset($_GET['h']) ? max(1, (int)$_GET['h']) : 216;

  // Límite de objetos a explorar por seguridad (puedes subirlo si lo necesitas)
  $MAX_ITEMS = 1000;

  // ==== Cliente S3 y bucket ====
  $s3      = Config::getS3();
  $manager = new S3Manager();
  $bucket  = $manager->getBucket();

  // ==== Utilidades ====
  $isImage = static function(string $key): bool {
    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
  };

  $buildOriginal = static function(string $key): string {
    // Usa tu streaming actual (coherente con el resto del sistema)
    return 'ver_archivo.php?archivo=' . rawurlencode($key);
  };

  $buildThumb = static function(string $key, int $w, int $h): string {
    // Usa el redirector de miniaturas (thumb.php) que genera/sube y redirige a S3
    return 'thumb.php?key=' . rawurlencode($key) . "&w={$w}&h={$h}&fit=cover&fmt=jpg";
  };

  // ==== Recorre S3 (listObjectsV2 con paginación) ====
  $out = [];
  $params = [
    'Bucket' => $bucket,
    'Prefix' => $prefix,       // si $prefix == '' lista todo (mejor siempre pasar la ruta)
  ];
  $fetched = 0;

  do {
    $resp = $s3->listObjectsV2($params);

    if (!empty($resp['Contents'])) {
      foreach ($resp['Contents'] as $obj) {
        if (!isset($obj['Key'])) continue;
        $key = (string)$obj['Key'];

        // omite "directorios" (terminados en '/')
        if ($key === '' || substr($key, -1) === '/') continue;

        if (!$isImage($key)) continue;

        $nombre   = basename($key);
        $original = $buildOriginal($key);
        $thumb    = $buildThumb($key, $W, $H);

        $out[] = [
          'key'      => $key,
          'nombre'   => $nombre,
          'original' => $original,
          'thumb'    => $thumb,
        ];

        if (++$fetched >= $MAX_ITEMS) {
          // Cortamos para no traer demasiados en una sola llamada
          break 2;
        }
      }
    }

    if (!empty($resp['IsTruncated']) && !empty($resp['NextContinuationToken'])) {
      $params['ContinuationToken'] = $resp['NextContinuationToken'];
    } else {
      break;
    }
  } while (true);

  // ==== Si no hay imágenes, devuelve placeholder ====
  if (count($out) === 0) {
    // Muestra la miniatura de "img/file.png" (ruta relativa a tu app)
    // Puedes ajustar a ruta absoluta si lo prefieres.
    $placeholder = 'img/file.png';

    $out[] = [
      'key'      => '',
      'nombre'   => 'Sin imágenes',
      'original' => $placeholder, // también como "original" para que abra algo si hacen clic
      'thumb'    => $placeholder, // se mostrará el ícono/placeholder
    ];
  }

  echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  // En caso de error, devolvemos un placeholder para no romper el UI
  http_response_code(200);
  echo json_encode([[
    'key'      => '',
    'nombre'   => 'Sin imágenes',
    'original' => 'img/file.png',
    'thumb'    => 'img/file.png',
    'error'    => 'Galería no disponible: ' . $e->getMessage()
  ]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
