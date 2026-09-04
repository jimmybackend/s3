<?php
// get_playlist.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/app_bootstrap.php';
require_once 'S3Manager.php';


// (Opcional) Si quieres obligar sesi¨®n, deja esto; si no, com¨¦ntalo.
// if (!isset($_SESSION['usuario'])) {
//     echo json_encode(['ok' => false, 'audios' => [], 'videos' => [], 'error' => 'Sesi¨®n inv¨¢lida']);
//     exit;
// }

// 1) Determinar la ruta a listar
$ruta = isset($_GET['ruta']) && $_GET['ruta'] !== ''
  ? $_GET['ruta']
  : ($_SESSION['ruta_actual'] ?? Config::RUTA_RAIZ);

// Normalizar
$ruta = trim($ruta);
if ($ruta === '' || $ruta === '/') {
  $ruta = Config::RUTA_RAIZ;
}
$ruta = rtrim($ruta, '/') . '/';

$manager = new S3Manager();

try {
    // 2) Listar objetos de S3 en la ruta dada
    $archivos = $manager->listArchivos($ruta, false);

    // 3) Preparar consulta para obtener nombre ¡°bonito¡± desde la BD
    $stmt = $db_connection->prepare("SELECT Nombre FROM FileS3 WHERE Encriptado = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Error preparando consulta: ' . $db_connection->error);
    }

    $audios = [];
    $videos = [];

    foreach ($archivos as $a) {
        if (empty($a['Key'])) continue;
        $key = $a['Key'];

        // Saltar pseudo-carpetas
        if (substr($key, -1) === '/') continue;

        $nombreEncriptado = basename($key);
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        // 4) Buscar nombre amigable (si existe) en la tabla FileS3
        $stmt->bind_param("s", $nombreEncriptado);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;

        $nombreAmigable = $row['Nombre'] ?? $nombreEncriptado; // fallback

        // 5) Clasificar por tipo
        if (in_array($ext, ['mp3','wav','ogg','opus','m4a'], true)) {
            $audios[] = ['Key' => $key, 'Nombre' => $nombreAmigable];
        } elseif (in_array($ext, ['mp4','webm','mov','avi','mkv'], true)) {
            $videos[] = ['Key' => $key, 'Nombre' => $nombreAmigable];
        }
    }

    $stmt->close();

    // 6) Devolver JSON
    echo json_encode([
        'ok'     => true,
        'ruta'   => $ruta,
        'audios' => array_values($audios),
        'videos' => array_values($videos),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log("Error en get_playlist.php: " . $e->getMessage());
    echo json_encode([
        'ok'     => false,
        'ruta'   => $ruta ?? '(desconocida)',
        'audios' => [],
        'videos' => [],
        'error'  => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
