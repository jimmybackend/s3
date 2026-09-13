<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/ArcadeLinkService.php';
require_once dirname(__DIR__) . '/src/Federation/ArcadeLinkBundleService.php';

use ArcadeCloud\Drive\Federation\ArcadeLinkBundleService;
use ZipArchive;

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('ZipArchive extension is required for this regression.');
}

$service = new ArcadeLinkBundleService();
$bundle = $service->create([
    [
        'filename' => 'juicio-video.arcadelink',
        'content' => "{\"format\":\"arcadelink\",\"id\":1}\n",
    ],
    [
        'filename' => 'juicio-video.arcadelink',
        'content' => "{\"format\":\"arcadelink\",\"id\":2}\n",
    ],
], 'https://drive.esforzados.com/federationcloud/');

$path = (string)$bundle['path'];
if (!is_file($path)) {
    throw new RuntimeException('Portable ZIP was not created.');
}
if ((int)$bundle['count'] !== 2) {
    throw new RuntimeException('Bundle count is incorrect.');
}
if ((string)$bundle['launcher'] !== 'abrir-federtioncloud.html') {
    throw new RuntimeException('Portable launcher name is incorrect.');
}

$zip = new ZipArchive();
if ($zip->open($path) !== true) {
    throw new RuntimeException('Portable ZIP cannot be opened.');
}

try {
    $launcherName = 'abrir-federtioncloud.html';
    $launcher = $zip->getFromName($launcherName);
    if (!is_string($launcher) || !str_contains($launcher, 'https://drive.esforzados.com/federationcloud/')) {
        throw new RuntimeException('Portable launcher is missing or has the wrong FederationCloud URL.');
    }

    $first = $zip->getFromName('juicio-video.arcadelink');
    $second = $zip->getFromName('juicio-video-2.arcadelink');
    if (!is_string($first) || !str_contains($first, '"id":1')) {
        throw new RuntimeException('First ArcadeLink was not preserved in ZIP.');
    }
    if (!is_string($second) || !str_contains($second, '"id":2')) {
        throw new RuntimeException('Duplicate ArcadeLink filename was not made collision-safe.');
    }

    if ($zip->numFiles !== 3) {
        throw new RuntimeException('Expected launcher plus two ArcadeLinks.');
    }
} finally {
    $zip->close();
    @unlink($path);
}

echo "OK portable ArcadeLink bundle regression\n";
