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
$scene = (string)file_get_contents($root . '/js/drive3d-scene.js');
$production = (string)file_get_contents($root . '/js/drive3d-production.js');
$mediaCloud = (string)file_get_contents($root . '/js/os-media-cloud.js');
$mediaCloudCss = (string)file_get_contents($root . '/css/os-media-cloud.css');

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
drive3dContract(str_contains($view, 'data-dw-desk-carousel') && str_contains($view, 'data-desk-prev') && str_contains($view, 'data-desk-next') && str_contains($js, 'folderStateForDesk') && str_contains($js, 'scrollDeskCarousel'), 'Traer al escritorio abre un carrusel navegable de archivos sobre la mesa 3D');
drive3dContract(str_contains($production, 'onDeskProjection') && str_contains($scene, 'deskProjectionState') && str_contains($js, 'updateDeskProjection') && str_contains($css, '--dw-desk-screen-x'), 'carrusel se proyecta desde la mesa física Three.js y no desde una coordenada fija de pantalla');
drive3dContract(str_contains($view, "'media_key' => \$key") && str_contains($view, "'media_route' => \$route") && str_contains($js, 'itemKey') && str_contains($js, 'itemMime') && str_contains($js, 'itemRoute'), 'archivos del carrusel conservan clave, MIME y ruta necesarias para reproductor multimedia');
drive3dContract(str_contains($view, 'css/os-media-cloud.css') && str_contains($view, 'js/os-media-cloud.js') && str_contains($view, 'ARCADECLOUD_OS_APPEARANCE'), 'Drive 3D reutiliza el mismo reproductor nube y preferencias de so.php');
drive3dContract(str_contains($js, 'playCloudMedia') && str_contains($js, 'ArcadeCloudMediaCloud') && str_contains($mediaCloud, 'class ArcadeCloudMediaCloud') && str_contains($mediaCloudCss, '.ac-media-cloud'), 'audio y video del escritorio usan el reproductor nube ArcadeCloud existente');
drive3dContract(str_contains($view, 'Imagenes/fondos3D') && str_contains($backgroundUpload, "/Imagenes/") && str_contains($backgroundUpload, "fondos3D/"), 'fondos 3D se almacenan en Imagenes/fondos3D del usuario');
drive3dContract(str_contains($backgroundUpload, 'requireDriveCsrf') && str_contains($backgroundUpload, 'singleUploadService()->upload'), 'subida de fondos usa CSRF y servicio privado normal del Drive');
drive3dContract(str_contains($preferences, "drive3dPreference") && str_contains($preferences, 'Drive3dPreferenceSanitizer') && str_contains($view, "'preferences' => $drive3dPreferences"), 'configuración 3D persiste en Users.os_preferences por nodo');
drive3dContract(str_contains($view, 'data-dw-spatial-picture-layer') && str_contains($js, 'openSpatialImage') && str_contains($js, 'restoreSpatialImages'), 'Drive 3D permite múltiples imágenes persistentes como cuadros independientes');
drive3dContract(str_contains($js, 'Redimensionar cuadro') && str_contains($js, 'bindSpatialPictureResize') && str_contains($css, '.dw-spatial-picture-resize-handle'), 'cada cuadro incluye botón y esquina de arrastre para redimensionar');
drive3dContract(str_contains($js, 'size:Array.isArray(entry.size)') && str_contains($preferenceSanitizer, 'pictureSize') && str_contains($preferenceSanitizer, "'size' =>"), 'tamaño de cada cuadro se persiste y valida junto con su posición');
drive3dContract(str_contains($js, 'spatialPictureState') && str_contains($js, 'keepalive:true') && str_contains($preferenceSanitizer, 'array_fill(0, 12, null)'), 'posiciones de cuadros se guardan al salir y los doce slots reemplazan posiciones antiguas sin lógica extra en el endpoint');
drive3dContract(str_contains($preferenceSanitizer, "'spatialImages'") && str_contains($preferenceSanitizer, 'localViewerHref') && str_contains($preferenceSanitizer, 'worldPosition'), 'preferencias de cuadros 3D validan ruta local y coordenadas antes de persistir');
drive3dContract(str_contains($preferenceSanitizer, "'cameraYaw'") && str_contains($preferenceSanitizer, "'cameraLateral'") && str_contains($preferenceSanitizer, "'cameraForward'") && str_contains($preferenceSanitizer, "'cameraModel'"), 'preferencias de cámara 3D se validan fuera del endpoint');
drive3dContract(str_contains($view, 'data-background-upload') && str_contains($js, 'uploadBackground') && str_contains($js, 'useChosenBackground'), 'panel permite subir y aplicar fondos por clic');
drive3dContract(str_contains($view, 'dw-orchid') && str_contains($css, '@keyframes dwPlantSway'), 'sala incluye orquídeas con movimiento ambiental leve');
drive3dContract(str_contains($css, 'background:none;') && str_contains($css, 'border-color:#38cfff'), 'selección conserva la madera y limita el neón al contorno');
drive3dContract(str_contains($view, 'dw-dome') && str_contains($css, '.dw-dome{') && str_contains($css, '.dw-dome-ribs'), 'la sala incluye un domo superior de cristal transparente');
drive3dContract(str_contains($view, 'dw-file-window') && str_contains($js, 'showFileInDome') && str_contains($js, "this.camera.pitch = -18"), 'abrir un archivo mantiene visor rectangular y orienta ligeramente la mirada hacia arriba');
drive3dContract(str_contains($js, 'dw-dome-document-frame') && str_contains($js, "item.kind === 'video'") && str_contains($js, "item.kind === 'audio'"), 'visor superior soporta documentos, imágenes, video y audio');
drive3dContract(str_contains($js, "if (item?.kind === 'image')") && str_contains($js, 'this.openSpatialImage(item)') && str_contains($css, '.dw-spatial-picture-window'), 'imágenes usan ventanas 3D independientes sin cerrar las demás');
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
drive3dContract(str_contains($view, 'dwShelfTemplate') && str_contains($js, "title.textContent = shelf.dataset.itemName"), 'plantilla única con nombres de carpetas aplicados como texto');
drive3dContract(str_contains($view, 'data-preview-files-secondary') && !str_contains($view, 'Selecciona para explorar'), 'los tres compartimientos del librero se destinan a contenido y no a una tarjeta informativa');
drive3dContract(str_contains($js, "files.slice(10, 20)") && str_contains($js, 'dw-shelf-ornament'), 'tercera repisa muestra más archivos o un accesorio decorativo cuando está vacía');
drive3dContract(str_contains($css, 'Drive 3D architectural panorama') && str_contains($css, 'border-radius:1px !important') && str_contains($css, '.dw-shelf.is-edge-visible-left .dw-shelf-volume-left') && str_contains($css, '.dw-shelf.is-edge-visible-right .dw-shelf-volume-right'), 'frentes de librero permanecen cuadrados y el volumen lateral sólo aparece en los extremos');
drive3dContract(str_contains($js, '360 / Math.max(8, this.shelves.length)') && str_contains($js, 'const shelfYaw = -relative'), 'escena y radar comparten ángulos repartidos alrededor del domo');
drive3dContract(!str_contains($js, 'const yawPan = Math.sin(yawRad) * radius') && str_contains($js, 'this.positionShelvesForCamera(yaw, false)') && str_contains($js, 'translate3d(${-lateralPx}px,${pitchPan}px,0)'), 'cámara gira la mirada sobre la pared circular en lugar de arrastrarla lateralmente');
drive3dContract(str_contains($view, 'data-dw-dome-image') && str_contains($js, "--dw-panorama-shift") && str_contains($css, 'width:300% !important') && str_contains($css, 'translate3d(var(--dw-panorama-shift),0,0)'), 'panorama de cristales se desplaza como envoltura del domo al cambiar yaw de cámara');
drive3dContract(substr_count($view, 'data-dw-edge-lamp=') === 2 && str_contains($js, 'positionEndMarkersForCamera') && str_contains($css, '.dw-edge-lamp-shade'), 'lámpara de pie marca visualmente el extremo alcanzado de la biblioteca');
drive3dContract(str_contains($css, 'height:46% !important') && str_contains($css, '.dw-stage{') && str_contains($css, 'inset:5.5% 0 185px 0 !important'), 'domo se eleva y deja los libreros completos debajo de la cúpula visible');
drive3dContract(str_contains($css, 'width:min(650px,54vw)') && str_contains($css, 'width:min(790px,64vw)') && str_contains($css, '☁  ARCADECLOUD OS'), 'mesa central y acuario forman el núcleo visual del render objetivo');
drive3dContract(str_contains($css, 'width:132px !important') && str_contains($css, 'width:276px !important') && str_contains($css, 'aspect-ratio:1 / 1 !important'), 'paneles laterales y radar se ajustan a la composición de referencia');
drive3dContract(substr_count($so, 'href="dataword3d.php"') >= 2 && str_contains($so, '<strong>Drive 3D</strong>'), 'ArcadeCloud OS enlaza Drive 3D en aplicaciones y launcher');
drive3dContract(str_contains($scene, 'const spatialAnchors = new Map()') && str_contains($scene, 'this.moveSpatialMedia = (id, dx, dy)') && str_contains($scene, 'this.spatialMediaState = (id'), 'motor Three.js mantiene múltiples anclas espaciales independientes');
drive3dContract(str_contains($production, 'app.restoreSpatialImages?.()'), 'renderer de producción restaura cuadros persistidos al volver a Drive 3D');
drive3dContract(str_contains($production, 'app.world.append(app.deskCarousel)') && str_contains($production, 'app.hideDeskCarousel?.()'), 'renderer Three.js coloca el carrusel sobre el escritorio y oculta contenido obsoleto al cambiar de librero');
drive3dContract(str_contains($css, 'Drive 3D literal desk carousel + crisp HUD') && str_contains($css, 'body:not(.is-camera-near).dw-real .dw-hud') && str_contains($css, 'opacity:1!important'), 'panel derecho permanece nítido y opaco como la navegación izquierda');

fwrite(STDOUT, "Drive 3D contract smoke passed.\n");
