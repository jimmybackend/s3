<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/dataword3d.php');
$css = (string)file_get_contents($root . '/css/dataword3d.css');
$js = (string)file_get_contents($root . '/js/dataword3d.js');
$so = (string)file_get_contents($root . '/so.php');

function drive3dContract(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    fwrite(STDOUT, "OK: {$message}\n");
}

drive3dContract(str_contains($view, "requireAuthenticated('index.php')"), 'Drive 3D exige la misma sesión autenticada');
drive3dContract(str_contains($view, 'normalizeForUser') && str_contains($view, '$userId'), 'cada ruta se normaliza contra el usuario autenticado');
drive3dContract(str_contains($view, 'listHierarchyRows($userId)') && !str_contains($view, 'isSuperAdmin'), 'Drive 3D no abre una vista global ni siquiera para superadmin');
drive3dContract(str_contains($view, 'displayPathForUser') && str_contains($view, "'visible_path'"), 'HUD usa ruta lógica visible obtenida del catálogo');
drive3dContract(str_contains($view, "'open_href'") && !str_contains($view, '<dt>RUTA FÍSICA</dt>'), 'la interfaz no presenta la ruta física de almacenamiento');
drive3dContract(str_contains($view, 'data-preview-href') && str_contains($js, 'loadShelfPreview'), 'los estantes cargan su contenido bajo demanda');
drive3dContract(str_contains($css, '.dw-shelf.is-active') && str_contains($css, '@keyframes dwScan'), 'el contenedor seleccionado tiene iluminación y escaneo visibles');
drive3dContract(str_contains($view, 'ARCADE HUD · OBJETO DETECTADO') && str_contains($css, '.dw-hud'), 'metadatos se presentan como HUD de realidad aumentada');
drive3dContract(str_contains($view, 'dw-room-panorama') && str_contains($view, 'dw-chair'), 'la sala 3D incluye arquitectura panorámica y escritorio con silla');
drive3dContract(str_contains($view, 'data-hud-preview-icon') && str_contains($js, 'previewIcon'), 'HUD incluye representación visual del objeto seleccionado');
drive3dContract(str_contains($js, 'arcStepDegrees') && str_contains($js, 'Math.sin(angle)') && str_contains($js, 'Math.cos(angle)'), 'carrusel distribuye libreros sobre un arco circular con giro progresivo');
drive3dContract(str_contains($view, "'thumbnail_href'") && str_contains($view, 'thumb.php?key=') && str_contains($js, 'renderDeskPreview'), 'escritorio usa miniatura autenticada para imágenes y fallback de icono');
drive3dContract(str_contains($view, 'data-dw-desk-image') && str_contains($css, '.dw-desk-preview img'), 'vista previa visual del escritorio está integrada en la escena');
drive3dContract(str_contains($css, 'background:none;') && str_contains($css, 'border-color:#38cfff'), 'selección conserva la madera y limita el neón al contorno');
drive3dContract(str_contains($js, "['audio','video']") && str_contains($js, 'showMedia'), 'audio y video pueden reproducirse desde Drive 3D');
drive3dContract(substr_count($so, 'href="dataword3d.php"') >= 2 && str_contains($so, '<strong>Drive 3D</strong>'), 'ArcadeCloud OS enlaza Drive 3D en aplicaciones y launcher');

fwrite(STDOUT, "Drive 3D contract smoke passed.\n");
