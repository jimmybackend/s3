<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/mobile_capture.php');
$upload = (string)file_get_contents($root . '/mobile-capture-upload.php');
$script = (string)file_get_contents($root . '/js/mobile-capture.js');
$native = (string)file_get_contents($root . '/../android-app/legacy-webview/src/main/java/com/arcadecloud/drive/MainActivity.java');
$manifest = (string)file_get_contents($root . '/../android-app/legacy-webview/src/main/AndroidManifest.xml');

$checks = [
    'authenticated mobile page' => str_contains($page, "requireAuthenticated('index.php')"),
    'page lists real owned folders' => str_contains($page, 'listHierarchyRows($userId)'),
    'capture requires explicit save' => str_contains($script, "element('save-audio').addEventListener")
        && str_contains($script, "element('save-photo').addEventListener"),
    'camera user action' => str_contains($script, "element('open-camera').addEventListener"),
    'microphone user action' => str_contains($script, "element('record').addEventListener"),
    'upload authenticated' => str_contains($upload, 'isAuthenticated()'),
    'upload csrf protected' => str_contains($upload, "HTTP_X_DRIVE_CSRF"),
    'server validates destination ownership' => str_contains($upload, 'uploadDestinationService()->resolve'),
    'size bounded' => str_contains($upload, '25 * 1024 * 1024'),
    'no unauthenticated upload' => !str_contains($upload, 'PublicUploadController'),
    'native media permission requests' => str_contains($native, 'onPermissionRequest(PermissionRequest request)')
        && str_contains($native, 'requestPermissions('),
    'native allowlist before WebView grant' => str_contains($native, 'UrlPolicy.isFirstParty(request.getOrigin().toString())'),
    'manifest camera and microphone' => str_contains($manifest, 'android.permission.CAMERA')
        && str_contains($manifest, 'android.permission.RECORD_AUDIO'),
];
foreach ($checks as $name => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}
