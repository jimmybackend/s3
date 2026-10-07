<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/dataword3d.php');
$css = (string)file_get_contents($root . '/css/dataword3d.css');
$js = (string)file_get_contents($root . '/js/dataword3d.js');
$so = (string)file_get_contents($root . '/so.php');
$preferences = (string)file_get_contents($root . '/os-preferences.php');
$preferenceSanitizer = (string)file_get_contents($root . '/src/Security/Drive3dPreferenceSanitizer.php');
$backgroundUpload = (string)file_get_contents($root . '/src/Http/Controller/Drive3dBackgroundUploadController.php');

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
drive3dContract(str_contains($view, 'dw-room-panorama') && str_contains($view, 'dw-desk') && !str_contains($view, 'dw-chair'), 'la sala 3D incluye arquitectura panorámica y mesa central sin silla');
drive3dContract(str_contains($view, 'data-hud-preview-icon') && str_contains($js, 'previewIcon'), 'HUD incluye representación visual del objeto seleccionado');
drive3dContract(str_contains($js, 'assignWorldAngles') && str_contains($js, 'renderCamera') && str_contains($js, 'worldAngle - this.camera.yaw'), 'los libreros permanecen anclados al mundo y la vista cambia mediante cámara');
drive3dContract(str_contains($view, 'data-dw-radar') && str_contains($js, 'renderRadar') && str_contains($js, 'focusShelf'), 'minimapa/isometría permite orientar la cámara hacia estantes fijos');
drive3dContract(str_contains($view, 'data-dw-camera-scene') && str_contains($js, 'this.cameraScene.style.transform') && str_contains($js, 'cameraLateral') && str_contains($js, 'cameraForward'), 'cámara mueve la vista completa del mundo fijo y conserva posición del jugador sobre el piso');
drive3dContract(str_contains($view, "'thumbnail_href'") && str_contains($view, 'thumb.php?key=') && str_contains($js, 'renderDeskPreview'), 'escritorio usa miniatura autenticada para imágenes y fallback de icono');
drive3dContract(str_contains($view, 'data-dw-desk-image') && str_contains($css, '.dw-desk-preview img'), 'vista previa visual del escritorio está integrada en la escena');
drive3dContract(str_contains($view, 'Imagenes/fondos3D') && str_contains($backgroundUpload, "/Imagenes/") && str_contains($backgroundUpload, "fondos3D/"), 'fondos 3D se almacenan en Imagenes/fondos3D del usuario');
drive3dContract(str_contains($backgroundUpload, 'requireDriveCsrf') && str_contains($backgroundUpload, 'singleUploadService()->upload'), 'subida de fondos usa CSRF y servicio privado normal del Drive');
drive3dContract(str_contains($preferences, "drive3dPreference") && str_contains($preferences, 'Drive3dPreferenceSanitizer') && str_contains($view, "'preferences' => $drive3dPreferences"), 'configuración 3D persiste en Users.os_preferences por nodo');
drive3dContract(str_contains($preferenceSanitizer, "'cameraYaw'") && str_contains($preferenceSanitizer, "'cameraLateral'") && str_contains($preferenceSanitizer, "'cameraForward'") && str_contains($preferenceSanitizer, "'cameraModel'"), 'preferencias de cámara 3D se validan fuera del endpoint');
drive3dContract(str_contains($view, 'data-background-upload') && str_contains($js, 'uploadBackground') && str_contains($js, 'useChosenBackground'), 'panel permite subir y aplicar fondos por clic');
drive3dContract(str_contains($view, 'dw-orchid') && str_contains($css, '@keyframes dwPlantSway'), 'sala incluye orquídeas con movimiento ambiental leve');
drive3dContract(str_contains($css, 'background:none;') && str_contains($css, 'border-color:#38cfff'), 'selección conserva la madera y limita el neón al contorno');
drive3dContract(str_contains($view, 'dw-dome') && str_contains($css, '.dw-dome{') && str_contains($css, '.dw-dome-ribs'), 'la sala incluye un domo superior de cristal transparente');
drive3dContract(str_contains($view, 'dw-file-window') && str_contains($js, 'showFileInDome') && str_contains($js, "this.camera.pitch = -18"), 'abrir un archivo mantiene visor rectangular y orienta ligeramente la mirada hacia arriba');
drive3dContract(str_contains($js, 'dw-dome-document-frame') && str_contains($js, "item.kind === 'video'") && str_contains($js, "item.kind === 'audio'"), 'visor superior soporta documentos, imágenes, video y audio');
drive3dContract(str_contains($js, 'preDomeCamera') && str_contains($js, 'closeMedia(restoreCamera = true)'), 'cerrar el visor restaura la perspectiva anterior del usuario');
drive3dContract(str_contains($js, 'pointermove') && str_contains($js, 'this.camera.yaw') && str_contains($js, 'this.camera.pitch') && str_contains($css, 'touch-action:none'), 'arrastrar directamente con el dedo mueve yaw/pitch de cámara sin un modo intermedio');
drive3dContract(str_contains($view, 'data-camera-pitch="-6"') && str_contains($view, 'data-camera-pitch="6"') && str_contains($js, 'vertical * 34'), 'radar permite subir y bajar la mirada además de orientar izquierda/derecha');
drive3dContract(str_contains($view, 'data-camera-pitch-range') && str_contains($js, 'this.pitchRange') && str_contains($css, '.dw-radar-pitch-control'), 'mapa isométrico incluye control continuo del ángulo vertical');
drive3dContract(str_contains($view, 'data-camera-strafe') && str_contains($view, 'data-camera-forward') && str_contains($view, 'data-dw-floor-nav'), 'radar y piso permiten caminar izquierda/derecha y acercarse/alejarse sin cambiar altura');
drive3dContract(str_contains($css, '.dw-sidebar') && str_contains($css, 'width:132px !important') && str_contains($css, '.dw-world') && str_contains($css, 'left:132px !important'), 'panel izquierdo compacto conserva más campo visual sin perder navegación');
drive3dContract(!str_contains($view, 'data-camera-turn=') && !str_contains($view, 'dw-room-light-ring') && !str_contains($view, 'dw-dome-ring'), 'se eliminan controles de mover libreros y aros de madera que obstruían las ventanas');
drive3dContract(str_contains($js, 'layoutFixedShelves') && str_contains($js, 'focusShelf(shelf)') && str_contains($js, 'navigateByFloorTap') && !str_contains($js, 'alreadyFocused'), 'libreros se posicionan una vez; tocar uno orienta/selecciona y tocar piso mueve al usuario');
drive3dContract(str_contains($view, 'dw-file-window') && str_contains($css, '.dw-media-stage.dw-file-window') && str_contains($css, 'border-radius:14px'), 'archivos se muestran en ventana rectangular normal dentro del entorno 3D');
drive3dContract(str_contains($css, '.dw-dome-glass') && str_contains($css, 'opacity:.10 !important') && str_contains($css, '.dw-room-scenery{opacity:.10'), 'render de referencia usa cristal casi invisible y paisaje nítido');
drive3dContract(str_contains($view, 'dw-shelf-volume-left') && str_contains($css, 'Drive 3D rigid shelf wall') && str_contains($css, 'display:none !important'), 'libreros conservan estructura rectangular pero ocultan caras falsas que producían efecto ladeado');
drive3dContract(str_contains($view, 'data-item-name="<?= $e($folder[\'name\']) ?>"') && str_contains($view, '<strong><?= $e($folder[\'name\']) ?></strong>'), 'cada librero usa exclusivamente el nombre real de una carpeta del nivel actual de Data');
drive3dContract(str_contains($view, 'data-preview-files-secondary') && !str_contains($view, 'Selecciona para explorar'), 'los tres compartimientos del librero se destinan a contenido y no a una tarjeta informativa');
drive3dContract(str_contains($js, "files.slice(10, 20)") && str_contains($js, 'dw-shelf-ornament'), 'tercera repisa muestra más archivos o un accesorio decorativo cuando está vacía');
drive3dContract(str_contains($css, 'transform-style:flat !important') && str_contains($css, '.dw-shelf-volume-left,') && str_contains($css, 'animation:none !important'), 'librero permanece rígido, sin alas laterales visibles ni animación del mueble seleccionado');
drive3dContract(str_contains($js, 'const tangentWidth = shelfWidth + gap') && str_contains($js, '2 * Math.atan') && str_contains($js, 'const shelfYaw = relative') && str_contains($js, 'positionShelvesForCamera'), 'libreros rígidos se unen por tangentes sobre una circunferencia amplia sin abanico exagerado');
drive3dContract(!str_contains($js, 'const yawPan = Math.sin(yawRad) * radius') && str_contains($js, 'this.positionShelvesForCamera(yaw, false)') && str_contains($js, 'translate3d(${-lateralPx}px,${pitchPan}px,0)'), 'cámara gira la mirada sobre la pared circular en lugar de arrastrarla lateralmente');
drive3dContract(str_contains($css, 'width:min(650px,54vw)') && str_contains($css, 'width:min(790px,64vw)') && str_contains($css, '☁  ARCADECLOUD OS'), 'mesa central y acuario forman el núcleo visual del render objetivo');
drive3dContract(str_contains($css, 'width:132px !important') && str_contains($css, 'width:276px !important') && str_contains($css, 'aspect-ratio:1 / 1 !important'), 'paneles laterales y radar se ajustan a la composición de referencia');
drive3dContract(substr_count($so, 'href="dataword3d.php"') >= 2 && str_contains($so, '<strong>Drive 3D</strong>'), 'ArcadeCloud OS enlaza Drive 3D en aplicaciones y launcher');

fwrite(STDOUT, "Drive 3D contract smoke passed.\n");
