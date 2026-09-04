<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/app_bootstrap.php';

use ArcadeCloud\Drive\Aws\FileRecordLocator;

try {
    $app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
    $session = $app->session();
    $session->start();
    $session->requireAuthenticated('index.php');
    $userId = $session->userId();

    $key = trim((string)($_POST['archivo'] ?? $_POST['key'] ?? ''));
    $minConf = max(0.0, min(100.0, (float)($_POST['min_conf'] ?? 70.0)));
    $maxLabels = max(1, min(100, (int)($_POST['max_labels'] ?? 50)));
    if ($key === '') {
        throw new RuntimeException('Falta el archivo a analizar.');
    }

    $locator = new FileRecordLocator($app->db());
    $row = $locator->requireReadableByKey($userId, $key);
    $realKey = (string)$row['_key'];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $rek = Config::getRekognition();
    $image = [
        'S3Object' => [
            'Bucket' => $app->bucket(),
            'Name' => $realKey,
        ],
    ];

    $labelsResp = $rek->detectLabels([
        'Image' => $image,
        'MaxLabels' => $maxLabels,
        'MinConfidence' => $minConf,
        'Features' => ['GENERAL_LABELS', 'IMAGE_PROPERTIES'],
    ]);

    $labels = [];
    foreach ((array)$labelsResp->get('Labels') as $lab) {
        $parents = [];
        foreach ((array)($lab['Parents'] ?? []) as $parent) {
            if (!empty($parent['Name'])) {
                $parents[] = (string)$parent['Name'];
            }
        }
        $labels[] = [
            'Name' => (string)($lab['Name'] ?? ''),
            'Confidence' => isset($lab['Confidence']) ? (float)$lab['Confidence'] : null,
            'Parents' => $parents,
            'Instances' => count((array)($lab['Instances'] ?? [])),
        ];
    }

    $modResp = $rek->detectModerationLabels([
        'Image' => $image,
        'MinConfidence' => $minConf,
    ]);
    $moderation = [];
    foreach ((array)$modResp->get('ModerationLabels') as $item) {
        $moderation[] = [
            'Name' => (string)($item['Name'] ?? ''),
            'ParentName' => (string)($item['ParentName'] ?? ''),
            'Confidence' => isset($item['Confidence']) ? (float)$item['Confidence'] : null,
        ];
    }

    $meta = [];
    $rawMeta = trim((string)($row['Metadatos'] ?? ''));
    if ($rawMeta !== '') {
        $decoded = json_decode($rawMeta, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    $meta['Rekognition'] = [
        'ts' => date('c'),
        'Bucket' => $app->bucket(),
        'S3Key' => $realKey,
        'Ruta' => (string)($row['Ruta'] ?? ''),
        'Nombre' => (string)($row['Nombre'] ?? ''),
        'MinConf' => $minConf,
        'MaxLabels' => $maxLabels,
        'Labels' => $labels,
        'Moderation' => $moderation,
        'ImageProperties' => (array)$labelsResp->get('ImageProperties'),
    ];

    $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('No se pudieron serializar los metadatos.');
    }

    $recordId = (int)$row['id_'];
    $stmt = $app->db()->prepare('UPDATE FileS3 SET Metadatos = ? WHERE id_ = ? AND user_id_ = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la actualización de metadatos.');
    }
    $stmt->bind_param('sii', $json, $recordId, $userId);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        'ok' => true,
        'bucket' => $app->bucket(),
        's3_key' => $realKey,
        'ruta' => (string)($row['Ruta'] ?? ''),
        'nombre' => (string)($row['Nombre'] ?? ''),
        'min_conf' => $minConf,
        'max_labels' => $maxLabels,
        'labels' => $labels,
        'moderation' => $moderation,
        'saved' => true,
        'message' => 'Análisis realizado y metadatos guardados correctamente.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
