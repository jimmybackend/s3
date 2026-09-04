<?php
// polly_list_voices.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app_bootstrap.php';

use Aws\Polly\PollyClient;

try {
    $language = isset($_GET['language']) ? trim((string)$_GET['language']) : ''; // ej: 'es-ES', 'es-MX', 'en-US'

    $polly = new PollyClient([
        'version'     => 'latest',
        'region'      => Config::REGION,
        'credentials' => [
            'key'    => Config::ACCESS_KEY,
            'secret' => Config::SECRET_KEY,
        ],
    ]);

    $args = [];
    if ($language !== '') {
        $args['LanguageCode'] = $language; // filtra por idioma
    }

    $resp   = $polly->describeVoices($args);
    $voices = [];

    foreach ($resp['Voices'] as $v) {
        $voices[] = [
            'Id'               => $v['Id'],
            'Name'             => $v['Name'] ?? $v['Id'],
            'LanguageCode'     => $v['LanguageCode'],
            'Gender'           => $v['Gender'] ?? null,
            'SupportedEngines' => $v['SupportedEngines'] ?? [], // ['standard','neural']
        ];
    }

    // Orden alfabético por nombre
    usort($voices, function($a,$b){ return strcmp($a['Name'], $b['Name']); });

    echo json_encode(['ok' => true, 'voices' => $voices]);
} catch (\Throwable $e) {
    echo json_encode(['error' => 'List voices error: ' . $e->getMessage()]);
}
