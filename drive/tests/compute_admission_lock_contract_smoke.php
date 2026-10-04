<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$lock = (string)file_get_contents($root . '/src/System/ComputeNodeAdmissionLock.php');
$mediaNode = (string)file_get_contents($root . '/src/Media/MediaWorkerNodeService.php');
$mediaService = (string)file_get_contents($root . '/src/Media/MediaProcessingService.php');
$office = (string)file_get_contents($root . '/src/Office/OfficeGatewayService.php');
$gateway = (string)file_get_contents($root . '/office-gateway.php');
$fastDrive = (string)file_get_contents($root . '/src/Admin/FastDriveControlService.php');

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
};

$check(str_contains($lock, 'SELECT GET_LOCK(?, ?) AS acquired'), 'mutex usa GET_LOCK de MariaDB/MySQL');
$check(str_contains($lock, 'SELECT RELEASE_LOCK(?)'), 'mutex libera el advisory lock');
$check(str_contains($mediaNode, 'public function admitWork('), 'nodo multimedia expone admisión atómica');
$check(str_contains($mediaService, '->admitWork('), 'job multimedia se persiste dentro del mutex');
$check(str_contains($mediaNode, 'requestIdleStopUnlocked') && str_contains($mediaNode, 'withAdmissionLock'), 'apagado interactivo conserva lógica actual dentro del mutex');
$check(str_contains($mediaNode, 'handleIdleUnlocked') && str_contains($mediaNode, 'withAdmissionLock'), 'autoapagado conserva lógica actual dentro del mutex');
$check(str_contains($mediaNode, 'claimIdleStop('), 'se conserva el claim atómico de idle posterior');
$check(str_contains($mediaNode, 'reconcileOfficeSessions()'), 'se conserva reconciliación moderna de sesiones Office');
$check(str_contains($office, 'prepareAndClaimWorkstation('), 'Office prepara y publica lease atómicamente');
$check(str_contains($gateway, 'prepareAndClaimWorkstation(') && !str_contains($gateway, '$office->claimOfficeSession($officeUserId, $instanceId, $officeSessionKey);'), 'gateway no deja ventana entre preparación y lease');
$check(str_contains($fastDrive, 'ComputeNodeAdmissionLock') && str_contains($fastDrive, '->synchronized($instanceId'), 'apagado manual comparte el mutex de admisión');

fwrite(STDOUT, "Compute admission lock contract passed.\n");
