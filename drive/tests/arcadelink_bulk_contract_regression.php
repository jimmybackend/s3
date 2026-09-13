<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function source(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Cannot read source file: ' . $relative);
    }
    return $content;
}

function expectContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$js = source($root, 'js/arcadelink-share.js');
expectContract(str_contains($js, "prepareBulkShare()"), 'Bulk ArcadeLink action must open policy selection flow.');
expectContract(str_contains($js, "getElementById('arcadeLinkVisibility')"), 'Bulk flow must reuse visibility selector.');
expectContract(str_contains($js, "getElementById('arcadeLinkRights')"), 'Bulk flow must reuse rights selector.');
expectContract(str_contains($js, "getElementById('arcadeLinkDiscoveryPolicy')"), 'Bulk flow must reuse discovery policy selector.');
expectContract(str_contains($js, "form.action = 'federationcloud/bundle.php'"), 'Bulk flow must submit to FederationCloud bundle endpoint.');
expectContract(str_contains($js, "visibility,"), 'Bulk form must submit selected visibility.');
expectContract(str_contains($js, "rights,"), 'Bulk form must submit selected rights.');
expectContract(str_contains($js, "discovery_policy: discoveryPolicy"), 'Bulk form must submit selected discovery policy.');

$bundleController = source($root, 'src/Http/Controller/FederationBundleController.php');
expectContract(str_contains($bundleController, 'createLinkByStorageRef('), 'Bundle controller must create links through FederationService.');
expectContract(str_contains($bundleController, '$userId,'), 'Bundle controller must pass authenticated user_id when creating links.');
expectContract(str_contains($bundleController, "'drive.no_direct_aws_charge'"), 'ArcadeLink creation must remain classified as no direct AWS charge.');
expectContract(str_contains($bundleController, "'aws_direct' => false"), 'ArcadeLink activity must state that it has no direct AWS transfer.');

$federationService = source($root, 'src/Federation/FederationService.php');
expectContract(str_contains($federationService, 'findOwnedFileByStorageRef($userId, $storageRef)'), 'FederationService must resolve storage refs for the authenticated user only.');

$repository = source($root, 'src/Federation/FederatedResourceRepository.php');
expectContract(str_contains($repository, 'WHERE user_id_ = ? AND Found = 1'), 'Federated resource lookup must filter by user_id.');
expectContract(str_contains($repository, '$realKey !== $key'), 'Federated resource lookup must verify the reconstructed storage key exactly.');

$downloadEndpoint = source($root, 'descargar_archivo.php');
expectContract(str_contains($downloadEndpoint, '->signedDownload();'), 'Canonical download endpoint must use signedDownload.');

$fileAccessService = source($root, 'src/Application/FileAccessService.php');
$signedStart = strpos($fileAccessService, 'public function signedDownload');
$streamStart = strpos($fileAccessService, 'public function downloadStream');
expectContract($signedStart !== false && $streamStart !== false && $streamStart > $signedStart, 'Could not isolate signedDownload implementation.');
$signedDownload = substr($fileAccessService, $signedStart, $streamStart - $signedStart);
expectContract(str_contains($signedDownload, 'createPresignedRequest'), 'signedDownload must create a presigned S3 request.');
expectContract(!str_contains($signedDownload, 'getObject('), 'signedDownload must not fetch object bytes through PHP.');
expectContract(!str_contains($signedDownload, 'readfile('), 'signedDownload must not stream object bytes through PHP.');

$fileAccessController = source($root, 'src/Http/Controller/FileAccessController.php');
$controllerStart = strpos($fileAccessController, 'public function signedDownload');
$downloadStart = strpos($fileAccessController, 'public function download()', $controllerStart ?: 0);
expectContract($controllerStart !== false && $downloadStart !== false && $downloadStart > $controllerStart, 'Could not isolate FileAccessController::signedDownload.');
$controllerSigned = substr($fileAccessController, $controllerStart, $downloadStart - $controllerStart);
expectContract(str_contains($controllerSigned, '->redirect('), 'Download controller must redirect the browser to S3.');
expectContract(!str_contains($controllerSigned, '->attachment('), 'Signed download must not send the file body as an attachment from PHP.');
expectContract(!str_contains($controllerSigned, 'readfile('), 'Signed download must not use readfile for the S3 object.');

echo "OK ArcadeLink bulk and direct-download contracts\n";
