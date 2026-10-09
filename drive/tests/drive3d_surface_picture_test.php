<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Security/Drive3dPreferenceSanitizer.php';

$sanitize = new \ArcadeCloud\Drive\Security\Drive3dPreferenceSanitizer();
$base = ['id'=>'img_fixture','name'=>'Prueba','path'=>'Data/Imagenes/Prueba.jpg',
    'openHref'=>'ver_archivo.php?archivo=Data%2Ff_123.jpg',
    'world'=>[1.5,0.065,1.8], 'size'=>[340,190]];

$save = static function (array $settings) use ($sanitize): array {
    $result=$sanitize->sanitize(['spatialImages'=>[$settings]]);
    return (array)($result['spatialImages'][0]??[]);
};
$require = static function (bool $condition,string $message): void {
    if (!$condition)throw new \RuntimeException($message);
};

$floor=$save($base+['mode'=>'floor','surfaceScale'=>2.1]);
$require($floor['mode']==='floor' && $floor['panelId']==='' && $floor['surfaceScale']===2.1,
    'Floor wallpaper must keep the image placement/size.');

$window=$save(array_merge($base,['mode'=>'window','panelId'=>'w-13-2','surfaceScale'=>.81]));
$require($window['mode']==='window' && $window['panelId']==='w-13-2', 'Exact dome pane must persist.');

$ceiling=$save(array_merge($base,['mode'=>'ceiling','panelId'=>'c-3-0','surfaceScale'=>.6]));
$require($ceiling['mode']==='ceiling' && $ceiling['panelId']==='c-3-0', 'Ceiling section must persist.');

$illegal=$save(array_merge($base,['mode'=>'window','panelId'=>'w-99-9','surfaceScale'=>100]));
$require($illegal['mode']==='free' && $illegal['panelId']==='' && $illegal['surfaceScale']<=.94,
    'Forged pane identifier must never be trusted.');

$badHref=$save(array_merge($base,['openHref'=>'https://malicious.example/file']));
$require($badHref===[], 'Remote image source must be rejected.');

$legacy=$save($base);
$require($legacy['mode']==='free' && $legacy['world']===$base['world'],
    'Previously saved free pictures must stay valid.');

echo "Drive 3D surface picture preferences passed.\n";
