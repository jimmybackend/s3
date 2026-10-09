<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Application/Drive3dBackgroundFolderService.php';

use ArcadeCloud\Drive\Application\Drive3dBackgroundFolderService as Backgrounds;

$root = 'Data42/';
$images = 'Data42/d_583d8a/';
$background = 'Data42/d_583d8a/d_fd30a2/';
$rows = [
    ['Prefix'=>$images,'ParentPrefix'=>$root,'Nombre'=>'Imagenes'],
    ['Prefix'=>$background,'ParentPrefix'=>$images,'Nombre'=>'fondos3D'],
    // An unrelated folder of the same visible name cannot steal this route.
    ['Prefix'=>'Data42/d_other/','ParentPrefix'=>$root,'Nombre'=>'fondos3D'],
    ['Prefix'=>'Data42/d_wrong/','ParentPrefix'=>'Data42/d_other/','Nombre'=>'Imagenes'],
    ['Prefix'=>'Data42/d_583d8a/d_moved/','ParentPrefix'=>$images,'Nombre'=>'Fotos personales'],
];

$assert = static function (bool $test, string $reason): void {
    if (!$test) throw new RuntimeException($reason);
};
$foundImages = Backgrounds::findDirectChild($rows, $root, 'Imagenes');
$foundBackground = Backgrounds::findDirectChild($rows, (string)$foundImages, 'fondos3D');
$assert($foundImages === $images, 'Imagenes se identifica por etiqueta de DB, no prefijo S3 literal.');
$assert($foundBackground === $background, 'fondos3D debe ser hija directa de Imagenes.');
$assert(Backgrounds::findDirectChild($rows, $root, 'desconocida') === null, 'Carpeta inexistente no debe inventarse.');
$assert(Backgrounds::findDirectChild($rows, $root, 'FONDOS3D') === 'Data42/d_other/', 'Nombres visibles son insensibles a mayúsculas.');
$duplicate = [...$rows, ['Prefix'=>'Data42/d_new/', 'ParentPrefix'=>$root, 'Nombre'=>'Imagenes']];
try {
    Backgrounds::findDirectChild($duplicate, $root, 'Imagenes');
    throw new RuntimeException('No se rechazó la colisión de dos carpetas visibles en el mismo nivel.');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'mismo nombre'), 'Ambigüedad debe rechazarse explícitamente.');
}
echo "Drive 3D visible-background folder lookup passed.\n";
