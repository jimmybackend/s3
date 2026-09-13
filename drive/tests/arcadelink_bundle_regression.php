<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Federation/FederationException.php';
require_once dirname(__DIR__) . '/src/Federation/ArcadeLinkService.php';
require_once dirname(__DIR__) . '/src/Federation/ArcadeLinkBundleService.php';

use ArcadeCloud\Drive\Federation\ArcadeLinkBundleService;
use ZipArchive;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function openBundle(string $path): ZipArchive
{
    $zip = new ZipArchive();
    expect($zip->open($path) === true, 'Portable ZIP cannot be opened.');
    return $zip;
}

function assertNoSecretMarkers(string $content, string $context): void
{
    foreach ([
        'AWS_SECRET_ACCESS_KEY',
        'AWS_ACCESS_KEY_ID=',
        'X-Amz-Credential=',
        'BEGIN PRIVATE KEY',
        'private_key',
        'PHPSESSID=',
        'Set-Cookie:',
    ] as $marker) {
        expect(!str_contains($content, $marker), $context . ' contains forbidden secret marker: ' . $marker);
    }
}

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('ZipArchive extension is required for this regression.');
}

$service = new ArcadeLinkBundleService();
$paths = [];

try {
    // 1 archivo -> 1 ZIP portable, 1 launcher y 1 .arcadelink.
    $single = $service->create([
        [
            'filename' => 'sentencia.pdf.arcadelink',
            'content' => "{\"format\":\"arcadelink\",\"resource_id\":\"arl_single\"}\n",
        ],
    ], 'https://drive.esforzados.com/federationcloud/');
    $singlePath = (string)$single['path'];
    $paths[] = $singlePath;

    expect(is_file($singlePath), 'Single portable ZIP was not created.');
    expect((string)$single['filename'] === 'ArcadeLink-portable.zip', 'Single bundle filename is incorrect.');
    expect((int)$single['count'] === 1, 'Single bundle count is incorrect.');
    expect((string)$single['launcher'] === 'abrir-federtioncloud.html', 'Portable launcher name is incorrect.');

    $zip = openBundle($singlePath);
    try {
        expect($zip->numFiles === 2, 'Single bundle must contain launcher plus one ArcadeLink.');
        $launcher = $zip->getFromName('abrir-federtioncloud.html');
        $link = $zip->getFromName('sentencia.pdf.arcadelink');
        expect(is_string($launcher), 'Single bundle launcher is missing.');
        expect(is_string($link) && str_contains($link, 'arl_single'), 'Single ArcadeLink was not preserved.');
        expect(str_contains($launcher, 'https://drive.esforzados.com/federationcloud/'), 'Launcher has the wrong FederationCloud URL.');
        assertNoSecretMarkers($launcher, 'Single launcher');
        assertNoSecretMarkers($link, 'Single ArcadeLink');
    } finally {
        $zip->close();
    }

    // N archivos -> 1 ZIP, un solo launcher y N ArcadeLinks sin colisiones.
    $multi = $service->create([
        [
            'filename' => 'juicio-video.mp4.arcadelink',
            'content' => "{\"format\":\"arcadelink\",\"resource_id\":\"arl_1\"}\n",
        ],
        [
            'filename' => 'juicio-video.mp4.arcadelink',
            'content' => "{\"format\":\"arcadelink\",\"resource_id\":\"arl_2\"}\n",
        ],
        [
            'filename' => 'pruebas.zip.arcadelink',
            'content' => "{\"format\":\"arcadelink\",\"resource_id\":\"arl_3\"}\n",
        ],
    ], 'https://drive.esforzados.com/federationcloud/');
    $multiPath = (string)$multi['path'];
    $paths[] = $multiPath;

    expect(is_file($multiPath), 'Multi portable ZIP was not created.');
    expect((string)$multi['filename'] === 'ArcadeLinks-portables.zip', 'Multi bundle filename is incorrect.');
    expect((int)$multi['count'] === 3, 'Multi bundle count is incorrect.');

    $zip = openBundle($multiPath);
    try {
        expect($zip->numFiles === 4, 'Expected one launcher plus three ArcadeLinks.');

        $names = [];
        $launcherCount = 0;
        $arcadeLinkCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $names[] = $name;
            if ($name === 'abrir-federtioncloud.html') {
                $launcherCount++;
            } elseif (preg_match('/\.arcadelink\z/i', $name)) {
                $arcadeLinkCount++;
            } else {
                throw new RuntimeException('Unexpected file inside portable ZIP: ' . $name);
            }

            $content = $zip->getFromIndex($i);
            expect(is_string($content), 'Could not read ZIP entry: ' . $name);
            assertNoSecretMarkers($content, 'ZIP entry ' . $name);
        }

        expect($launcherCount === 1, 'Portable ZIP must contain exactly one abrir-federtioncloud.html.');
        expect($arcadeLinkCount === 3, 'Portable ZIP must contain one ArcadeLink per selected file.');
        expect(in_array('juicio-video.mp4.arcadelink', $names, true), 'First duplicate ArcadeLink name is missing.');
        expect(in_array('juicio-video.mp4-2.arcadelink', $names, true), 'Duplicate ArcadeLink filename was not made collision-safe.');
        expect(in_array('pruebas.zip.arcadelink', $names, true), 'Third ArcadeLink is missing.');

        $first = $zip->getFromName('juicio-video.mp4.arcadelink');
        $second = $zip->getFromName('juicio-video.mp4-2.arcadelink');
        expect(is_string($first) && str_contains($first, 'arl_1'), 'First ArcadeLink content changed in ZIP.');
        expect(is_string($second) && str_contains($second, 'arl_2'), 'Second ArcadeLink content changed in ZIP.');
    } finally {
        $zip->close();
    }

    echo "OK portable ArcadeLink bundle regression\n";
} finally {
    foreach ($paths as $path) {
        if ($path !== '') @unlink($path);
    }
}
