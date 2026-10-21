<?php
declare(strict_types=1);
final class Config { public const RUTA_RAIZ = 'Data/'; }
require_once __DIR__ . '/../src/Storage/UserStoragePath.php';
require_once __DIR__ . '/../src/View/FileViewHelper.php';
require_once __DIR__ . '/../src/Application/NativeDrivePolicy.php';
use ArcadeCloud\Drive\Application\NativeDrivePolicy as Policy;
use ArcadeCloud\Drive\Storage\UserStoragePath;
$paths = new UserStoragePath(); $checks = 0;
function check(bool $value): void { global $checks; if (!$value) throw new RuntimeException('Assertion failed'); $checks++; }
foreach ([1 => 'Data/', 2 => 'Data2/', 35 => 'Data35/'] as $uid => $root) {
    check(Policy::route($paths, $uid, '') === $root);
    check(Policy::route($paths, $uid, $root . 'child/') === $root . 'child/');
    foreach (['Data999/', '../', $root . '../other/', $root . './x/', $root . 'x\\y/', $root . "x\x00/"] as $bad) {
        try { Policy::route($paths,$uid,$bad); throw new LogicException('Unauthorized route accepted'); }
        catch (RuntimeException $e) { check($e->getCode() === 403); }
    }
}
try { Policy::route($paths,0,''); throw new LogicException('Unauthenticated user accepted'); }
catch(RuntimeException $e) { check($e->getCode() === 401); }
$row = ['id_' => 7, 'Nombre' => 'Visible.jpg', 'Encriptado' => 'physical.jpg', 'Ruta' => 'Data2/x/', 'Tamano' => 123, 'Fecha' => '2026-10-21', 'AccessType' => 'secure', 'PasswordHash' => 'must-never-leak', 'Metadatos' => '{"private":"data"}'];
$file = Policy::file($row);
check(!isset($file['PasswordHash'])); check(!str_contains(json_encode($file), 'must-never-leak'));
check($file['metadata'] === null); check($file['locked'] === true);
check($file['key'] === 'Data2/x/physical.jpg'); check($file['name'] === 'Visible.jpg');
$row['Encriptado'] = 'Data2/x/physical.jpg'; check(Policy::file($row)['key'] === 'Data2/x/physical.jpg');
$row['AccessType'] = 'normal'; check(Policy::file($row)['metadata'] === ['private'=>'data']);
echo "native_drive_policy_regression: $checks assertions passed\n";
