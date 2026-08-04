<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';

$client = Config::getS3();
$bucket = Config::BUCKET;

$prefix = 'Data/uploads/';
$olderThanMinutes = 60; // abortar subidas incompletas con más de 60 minutos

$now = time();
$abortados = 0;
$vistos = 0;

$params = [
    'Bucket' => $bucket,
    'Prefix' => $prefix,
];

do {
    $res = $client->listMultipartUploads($params);
    $uploads = $res->get('Uploads') ?: [];

    foreach ($uploads as $u) {
        $vistos++;

        $key = (string)($u['Key'] ?? '');
        $uploadId = (string)($u['UploadId'] ?? '');
        $initiated = $u['Initiated'] ?? null;

        $initiatedTs = $initiated ? strtotime((string)$initiated) : 0;
        $ageMinutes = $initiatedTs > 0 ? (($now - $initiatedTs) / 60) : 999999;

        echo "Encontrado: {$key}\n";
        echo "UploadId: {$uploadId}\n";
        echo "Edad aprox: " . round($ageMinutes, 1) . " minutos\n";

        if ($key !== '' && $uploadId !== '' && $ageMinutes >= $olderThanMinutes) {
            $client->abortMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);

            $abortados++;
            echo "ABORTADO\n\n";
        } else {
            echo "NO abortado todavía\n\n";
        }
    }

    $params['KeyMarker'] = $res->get('NextKeyMarker');
    $params['UploadIdMarker'] = $res->get('NextUploadIdMarker');

} while ($res->get('IsTruncated'));

echo "Multipart vistos: {$vistos}\n";
echo "Multipart abortados: {$abortados}\n";
