<?php
declare(strict_types=1);

use ArcadeCloud\Drive\View\FileViewHelper;

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/app_bootstrap.php';

$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();

$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');

$userId = $session->userId();

$ruta = trim((string)(
    $_GET['ruta']
    ?? $_SESSION['ruta_actual']
    ?? ''
));

$ruta = $app
    ->userStoragePath()
    ->normalizeForUser(
        $ruta,
        $userId
    );

$audioExt = [
    'mp3',
    'wav',
    'ogg',
    'opus',
    'm4a',
    'aac',
    'flac',
    'amr'
];

$videoExt = [
    'mp4',
    'webm',
    'mov',
    'avi',
    'mkv',
    'm4v',
    'ogv'
];

$db = $app->db();

$sql = "
    SELECT
        Nombre,
        Encriptado,
        Ruta,
        AccessType,
        Fecha
    FROM FileS3
    WHERE user_id_ = ?
      AND Ruta = ?
      AND Found = 1
      AND AccessType <> 'secure'
      AND LOWER(
            SUBSTRING_INDEX(Nombre, '.', -1)
          ) IN (
            'mp3','wav','ogg','opus','m4a','aac','flac','amr',
            'mp4','webm','mov','avi','mkv','m4v','ogv'
          )
    ORDER BY Fecha DESC, id_ DESC
";

$stmt = $db->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'No se pudo preparar la playlist.'
    ]);

    exit;
}

$stmt->bind_param(
    'is',
    $userId,
    $ruta
);

$stmt->execute();

$result = $stmt->get_result();

$audio = [];
$video = [];

while ($row = $result->fetch_assoc()) {

    $nombre = (string)($row['Nombre'] ?? '');

    $ext = strtolower(
        (string)pathinfo(
            $nombre,
            PATHINFO_EXTENSION
        )
    );

    $key = FileViewHelper::buildS3Key(
        (string)($row['Ruta'] ?? ''),
        (string)($row['Encriptado'] ?? '')
    );

    if ($key === '') {
        continue;
    }

    $item = [
        'key' => $key,
        'nombre' => $nombre,
        'src' => 'ver_archivo.php?archivo=' .
                 rawurlencode($key)
    ];

    if (in_array($ext, $audioExt, true)) {
        $audio[] = $item;

    } elseif (in_array($ext, $videoExt, true)) {
        $video[] = $item;
    }
}

$stmt->close();

echo json_encode(
    [
        'ok' => true,
        'ruta' => $ruta,
        'audio' => $audio,
        'video' => $video
    ],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);
