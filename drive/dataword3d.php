<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Application\FileListService;
use ArcadeCloud\Drive\Application\Drive3dBackgroundFolderService;
use ArcadeCloud\Drive\Security\OsPreferenceNodeResolver;
use ArcadeCloud\Drive\Security\UserOsPreferencesRepository;
use ArcadeCloud\Drive\View\FileViewHelper;
use ArcadeCloud\Drive\View\FileIconResolver;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/app_bootstrap.php';

$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
$session = $app->session();
$session->start();
$session->requireAuthenticated('index.php');

$userId = $session->userId();
if ($userId <= 0) {
    header('Location: logout.php');
    exit;
}

$osPreferenceNodeKey = OsPreferenceNodeResolver::resolve();
$osPreferences = [];
try {
    $osPreferences = (new UserOsPreferencesRepository($app->db()))->find($userId, $osPreferenceNodeKey);
} catch (Throwable $error) {
    error_log('[ArcadeCloud Drive3D preferences] ' . $error->getMessage());
}
$drive3dPreferences = is_array($osPreferences['drive3d'] ?? null) ? $osPreferences['drive3d'] : [];

$uploadCsrf = (string)$session->get('upload_csrf', '');
if (!preg_match('/\A[a-f0-9]{64}\z/', $uploadCsrf)) {
    $uploadCsrf = bin2hex(random_bytes(32));
    $session->set('upload_csrf', $uploadCsrf);
}

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$userRoot = $app->userStorageProvisioner()->ensureRoot($userId);
$currentRoute = $app->userStoragePath()->normalizeForUser(
    (string)($_GET['ruta'] ?? $userRoot),
    $userId
);
$session->set('ruta_actual', $currentRoute);

$normalizePrefix = static function (string $prefix): string {
    $prefix = str_replace('\\', '/', trim($prefix));
    $prefix = preg_replace('~/+~', '/', $prefix) ?? $prefix;
    $prefix = ltrim($prefix, '/');
    return $prefix === '' ? '' : rtrim($prefix, '/') . '/';
};

$rootPrefix = $normalizePrefix($userRoot);
$folderRows = $app->folderRepository()->listHierarchyRows($userId);

$folderChildren = static function (string $route) use ($folderRows, $normalizePrefix, $rootPrefix, $app, $userId): array {
    $currentPrefix = $normalizePrefix($route);
    $children = [];

    foreach ($folderRows as $row) {
        $prefix = $normalizePrefix((string)($row['Prefix'] ?? ''));
        $parent = $normalizePrefix((string)($row['ParentPrefix'] ?? ''));
        if ($prefix === '' || $prefix === $rootPrefix || strpos($prefix, $rootPrefix) !== 0) {
            continue;
        }
        if ($parent !== $currentPrefix) {
            continue;
        }
        $name = trim((string)($row['Nombre'] ?? ''));
        if ($name === '') {
            $name = basename(rtrim($prefix, '/'));
        }
        $children[] = [
            'prefix' => $prefix,
            'name' => $name,
        ];
    }

    usort($children, static fn(array $a, array $b): int => strnatcasecmp((string)$a['name'], (string)$b['name']));

    if ($children !== []) {
        $paths = $app->folderQueryService()->displayPathsForUser(
            $userId,
            array_values(array_map(static fn(array $row): string => (string)$row['prefix'], $children))
        );
        foreach ($children as &$child) {
            $prefix = (string)$child['prefix'];
            $child['visible_path'] = (string)($paths[$prefix] ?? 'Mi Drive/');
        }
        unset($child);
    }

    return $children;
};

$imageExtensions = ['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff'];
$audioExtensions = ['mp3','wav','ogg','opus','m4a','aac','flac'];
$videoExtensions = ['mp4','webm','mov','avi','mkv','m4v','mpeg','mpg'];
$documentExtensions = ['pdf','doc','docx','odt','rtf','xls','xlsx','ods','ppt','pptx','odp','txt','md','markdown','csv','json','xml','html','htm','php','js','css','py','sql'];

$classifyFile = static function (string $extension) use ($imageExtensions, $audioExtensions, $videoExtensions, $documentExtensions): string {
    if (in_array($extension, $imageExtensions, true)) return 'image';
    if (in_array($extension, $audioExtensions, true)) return 'audio';
    if (in_array($extension, $videoExtensions, true)) return 'video';
    if ($extension === 'pdf') return 'pdf';
    if (in_array($extension, $documentExtensions, true)) return 'document';
    return 'file';
};

$buildState = static function (string $route, int $page = 1, int $limit = FileListService::WEB_OS_PAGE_SIZE) use (
    $app,
    $userId,
    $userRoot,
    $rootPrefix,
    $normalizePrefix,
    $folderRows,
    $folderChildren,
    $classifyFile
): array {
    $route = $app->userStoragePath()->normalizeForUser($route, $userId);
    $currentPrefix = $normalizePrefix($route);
    $visiblePath = $app->folderQueryService()->displayPathForUser($userId, $route);
    $folders = $folderChildren($route);

    $fileState = $app->fileListService()->load($userId, $route, [
        'pagina' => max(1, $page),
        'limite' => max(5, min(100, $limit)),
    ]);

    $files = [];
    foreach (($fileState['rows'] ?? []) as $row) {
        $name = trim((string)($row['Nombre'] ?? ''));
        if ($name === '') continue;

        $extension = FileViewHelper::extension($name);
        $metadata = FileViewHelper::metadataArray($row['Metadatos'] ?? '');
        $mime = strtolower(trim((string)($metadata['mime_type'] ?? $metadata['content_type'] ?? $metadata['mime'] ?? '')));
        $kind = $classifyFile($extension);
        $locked = FileViewHelper::isLocked($row);
        $iconInfo = $locked
            ? ['icon' => 'fa-lock', 'category' => 'locked', 'label' => 'Archivo protegido']
            : FileIconResolver::resolve($extension);
        $key = FileViewHelper::buildS3Key((string)($row['Ruta'] ?? ''), (string)($row['Encriptado'] ?? ''));
        $openHref = $locked || $key === '' ? '' : 'ver_archivo.php?archivo=' . rawurlencode($key);
        $downloadHref = $locked || $key === '' ? '' : 'descargar_archivo.php?archivo=' . rawurlencode($key) . '&nombre=' . rawurlencode($name);
        $thumbnailHref = (!$locked && $key !== '' && $kind === 'image')
            ? 'thumb.php?key=' . rawurlencode($key) . '&w=420&h=280&fit=cover'
            : '';
        $environmentHref = (!$locked && $key !== '' && $kind === 'image')
            ? 'ver_archivo.php?archivo=' . rawurlencode($key)
            : '';

        $files[] = [
            'type' => 'file',
            'name' => $name,
            'kind' => $kind,
            'icon' => (string)$iconInfo['icon'],
            'icon_category' => (string)$iconInfo['category'],
            'icon_label' => (string)$iconInfo['label'],
            'extension' => $extension !== '' ? strtoupper($extension) : 'ARCHIVO',
            'mime' => $mime,
            'media_key' => $key,
            'media_route' => $route,
            'size' => FileViewHelper::formatBytes((int)($row['Tamano'] ?? 0)),
            'date' => trim((string)($row['Fecha'] ?? '')),
            'visible_path' => rtrim($visiblePath, '/') . '/' . $name,
            'locked' => $locked,
            'open_href' => $openHref,
            'download_href' => $downloadHref,
            'thumbnail_href' => $thumbnailHref,
            'environment_href' => $environmentHref,
        ];
    }

    $folderItems = [];
    foreach ($folders as $folder) {
        $prefix = (string)$folder['prefix'];
        $folderItems[] = [
            'type' => 'folder',
            'name' => (string)$folder['name'],
            'kind' => 'folder',
            'icon' => 'fa-folder-open',
            'visible_path' => (string)($folder['visible_path'] ?? 'Mi Drive/'),
            'open_href' => 'dataword3d.php?ruta=' . rawurlencode($prefix),
            'preview_href' => 'dataword3d.php?api=preview&ruta=' . rawurlencode($prefix),
        ];
    }

    $parentPrefix = '';
    if ($currentPrefix !== $rootPrefix) {
        foreach ($folderRows as $row) {
            if ($normalizePrefix((string)($row['Prefix'] ?? '')) !== $currentPrefix) continue;
            $candidate = $normalizePrefix((string)($row['ParentPrefix'] ?? ''));
            if ($candidate !== '' && strpos($candidate, $rootPrefix) === 0) {
                $parentPrefix = $candidate;
            }
            break;
        }
        if ($parentPrefix === '') {
            $parentPrefix = $rootPrefix;
        }
    }

    $latestDate = '';
    foreach ($files as $file) {
        $date = trim((string)($file['date'] ?? ''));
        if ($date !== '' && ($latestDate === '' || strcmp($date, $latestDate) > 0)) {
            $latestDate = $date;
        }
    }

    return [
        'visible_path' => $visiblePath,
        'is_root' => $currentPrefix === $rootPrefix,
        'folders' => $folderItems,
        'files' => $files,
        'folder_count' => count($folderItems),
        'file_count' => max(0, (int)($fileState['folder_total'] ?? count($files))),
        'file_page' => max(1, (int)($fileState['page'] ?? 1)),
        'file_pages' => max(1, (int)($fileState['pages'] ?? 1)),
        'file_limit' => max(1, (int)($fileState['limit'] ?? $limit)),
        'folder_bytes' => FileViewHelper::formatBytes((int)($fileState['folder_bytes'] ?? 0)),
        'latest_date' => $latestDate,
        'classic_href' => 'so.php?ruta=' . rawurlencode($route),
        'parent_href' => $parentPrefix !== '' ? 'dataword3d.php?ruta=' . rawurlencode($parentPrefix) : '',
    ];
};

$apiMode = (string)($_GET['api'] ?? '');
if ($apiMode === 'preview') {
    $state = $buildState($currentRoute, 1, 20);
    $state['folders'] = array_slice($state['folders'], 0, 6);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'state' => $state,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

if ($apiMode === 'files' || $apiMode === 'desk') {
    $page = max(1, (int)($_GET['pagina'] ?? 1));
    // A page fits four 360° rows in the 3D dome. Regular Drive pagination
    // still returns 100 entries when not requested by the immersive gallery.
    $limit = (string)($_GET['galeria'] ?? '') === '1' ? 72 : 100;
    $state = $buildState($currentRoute, $page, $limit);
    $state['folders'] = [];
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'state' => $state,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

$state = $buildState($currentRoute);

$backgroundFolders = new Drive3dBackgroundFolderService(
    $app->folderRepository(),
    $app->folderMutationService(),
    $app->userStoragePath()
);
$backgroundRoute = $backgroundFolders->existing($userId);
$backgroundState = $backgroundRoute !== null ? $buildState($backgroundRoute) : ['files' => []];
$backgrounds = array_values(array_filter(
    (array)($backgroundState['files'] ?? []),
    static fn(array $file): bool => ($file['kind'] ?? '') === 'image'
));

$breadcrumbs = $app->folderQueryService()->breadcrumbsForUser($userId, $currentRoute);
$e = static fn(mixed $value): string => FileViewHelper::escape($value);
$userAlias = \ArcadeCloud\Drive\View\UserIdentityPresenter::alias($session->userName());

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>ArcadeCloud Drive 3D</title>
  <link rel="icon" href="ellogo.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/dataword3d.css?v=<?= (int)filemtime(__DIR__ . '/css/dataword3d.css') ?>">
  <link rel="stylesheet" href="css/os-media-cloud.css?v=<?= (int)filemtime(__DIR__ . '/css/os-media-cloud.css') ?>">
</head>
<body class="dw3d dw-real" data-environment="future">
  <header class="dw-topbar">
    <a class="dw-brand" href="so.php" aria-label="Volver a ArcadeCloud OS">
      <span class="dw-brand-cloud"><i class="fas fa-cloud"></i></span>
      <span><strong>ArcadeCloud OS</strong><small>Drive 3D · dataword3d.php</small></span>
    </a>

    <nav class="dw-breadcrumb" aria-label="Ruta visible">
      <?php foreach ($breadcrumbs as $index => $crumb): ?>
        <?php if ($index > 0): ?><i class="fas fa-chevron-right" aria-hidden="true"></i><?php endif; ?>
        <a href="dataword3d.php?ruta=<?= rawurlencode((string)$crumb['route']) ?>"><?= $e($crumb['label']) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="dw-top-actions">
      <button type="button" class="dw-top-environment" data-dw-environment title="Entorno" aria-label="Configurar entorno"><i class="fas fa-panorama"></i><span>Entorno</span></button>
      <div class="dw-top-user dw-user-chip" title="<?= $e($userAlias) ?>" aria-label="Usuario: <?= $e($userAlias) ?>"><i class="fas fa-user-astronaut"></i><span><?= $e($userAlias) ?></span></div>
      <button type="button" data-dw-fullscreen title="Pantalla completa"><i class="fas fa-expand"></i></button>
      <a href="<?= $e((string)$state['classic_href']) ?>" title="Vista clásica"><i class="fas fa-desktop"></i></a>
      <a href="so.php" title="Salir de Drive 3D"><i class="fas fa-right-from-bracket"></i></a>
    </div>
  </header>

  <main class="dw-world" id="dwWorld">
    <div id="dwThreeViewport" tabindex="0" aria-label="Biblioteca 3D. Arrastra para mirar y usa WASD para caminar."></div>
    <p data-three-status role="status">Preparando biblioteca 3D…</p>
    <div class="dw-sky" aria-hidden="true"></div>
    <div class="dw-camera-scene" data-dw-camera-scene>
    <div class="dw-dome" aria-label="Domo de cristal superior">
      <div class="dw-dome-custom-image" data-dw-dome-image aria-hidden="true"></div>
      <div class="dw-dome-sky"></div>
      <div class="dw-dome-glass"></div>
      <div class="dw-dome-ribs"></div>
    </div>
    <div class="dw-room-panorama" aria-hidden="true">
      <div class="dw-room-custom-image" data-dw-glass-image></div>
      <div class="dw-room-scenery"></div>
      <div class="dw-room-mullions"></div>
    </div>
    <div class="dw-floor" data-dw-floor-nav aria-label="Piso navegable">
      <div class="dw-floor-custom-image" data-dw-floor-image></div>
    </div>
    <div class="dw-aquarium" aria-hidden="true">
      <span class="dw-fish fish-a">◁</span><span class="dw-fish fish-b">◁</span><span class="dw-fish fish-c">◁</span>
    </div>

    <section class="dw-stage" aria-label="Biblioteca tridimensional">
      <div class="dw-ring" id="dwShelfRing">
        <template id="dwShelfTemplate">
          <span class="dw-shelf-volume dw-shelf-volume-left" aria-hidden="true"></span>
          <span class="dw-shelf-volume dw-shelf-volume-right" aria-hidden="true"></span>
          <span class="dw-shelf-volume dw-shelf-volume-top" aria-hidden="true"></span>
          <div class="dw-shelf-crown"><i class="fas fa-folder-open"></i><strong>Carpeta</strong></div>
          <div class="dw-compartment dw-compartment-folders" data-preview-folders aria-label="Subcarpetas">
            <span class="dw-book dw-book-large"><b></b></span><span class="dw-book dw-book-large"><b></b></span><span class="dw-book dw-book-large"><b></b></span>
          </div>
          <div class="dw-compartment dw-compartment-files" data-preview-files aria-label="Archivos"><span class="dw-shelf-ornament"><i class="fas fa-folder-tree"></i></span></div>
          <div class="dw-compartment dw-compartment-files-secondary" data-preview-files-secondary aria-label="Carpeta"><span class="dw-shelf-ornament"><i class="fas fa-box-archive"></i></span></div>
          <div class="dw-shelf-base"><span data-preview-counts>Carpeta</span></div>
        </template>
        <?php foreach ($state['folders'] as $folder): ?>
          <article class="dw-shelf"
                   tabindex="0"
                   role="button"
                   data-dw-shelf
                   data-dw-item
                   data-item-type="folder"
                   data-item-name="<?= $e($folder['name']) ?>"
                   data-item-path="<?= $e($folder['visible_path']) ?>"
                   data-open-href="<?= $e($folder['open_href']) ?>"
                   data-preview-href="<?= $e($folder['preview_href']) ?>">
          </article>
        <?php endforeach; ?>
        <div class="dw-edge-lamp dw-edge-lamp-left" data-dw-edge-lamp="left" aria-hidden="true">
          <span class="dw-edge-lamp-shade"></span>
          <span class="dw-edge-lamp-pole"></span>
          <span class="dw-edge-lamp-base"></span>
        </div>
        <div class="dw-edge-lamp dw-edge-lamp-right" data-dw-edge-lamp="right" aria-hidden="true">
          <span class="dw-edge-lamp-shade"></span>
          <span class="dw-edge-lamp-pole"></span>
          <span class="dw-edge-lamp-base"></span>
        </div>
      </div>
    </section>
    </div><!-- /dw-camera-scene -->

    <section class="dw-radar" data-dw-radar aria-label="Mapa de orientación de la sala">
      <div class="dw-radar-room">
        <span class="dw-radar-view" data-radar-view></span>
        <span class="dw-radar-center"></span>
        <div class="dw-radar-points" data-radar-points></div>
      </div>
      <div class="dw-radar-caption">
        <strong>Orientación</strong>
        <span>toca el mapa para mirar</span>
      </div>
      <div class="dw-radar-look-controls">
        <button type="button" data-camera-pitch="-6" aria-label="Mirar arriba"><i class="fas fa-chevron-up"></i></button>
        <label class="dw-radar-pitch-control">
          <span>Ángulo vertical</span>
          <input type="range" min="-85" max="85" step="1" value="0" data-camera-pitch-range aria-label="Ángulo vertical de la mirada">
        </label>
        <button type="button" data-camera-pitch="6" aria-label="Mirar abajo"><i class="fas fa-chevron-down"></i></button>
      </div>
      <div class="dw-radar-move-controls">
        <button type="button" data-camera-strafe="-1" aria-label="Moverse a la izquierda"><i class="fas fa-arrow-left"></i></button>
        <button type="button" data-camera-forward="1" aria-label="Acercarse"><i class="fas fa-arrow-up"></i></button>
        <button type="button" data-camera-forward="-1" aria-label="Alejarse"><i class="fas fa-arrow-down"></i></button>
        <button type="button" data-camera-strafe="1" aria-label="Moverse a la derecha"><i class="fas fa-arrow-right"></i></button>
      </div>
      <div class="dw-radar-actions">
        <button type="button" data-camera-home><i class="fas fa-crosshairs"></i> Centro del domo</button>
        <button type="button" data-camera-exit-focus><i class="fas fa-eye"></i> Vista inicial</button>
      </div>
    </section>

    <div class="dw-spatial-picture-layer" data-dw-spatial-picture-layer aria-live="polite"></div>

    <section class="dw-media-stage dw-file-window" data-dw-media-stage hidden aria-live="polite">
      <header class="dw-file-window-titlebar" data-spatial-drag-handle>
        <span class="dw-file-window-icon"><i class="fas fa-file"></i></span>
        <strong data-dw-media-title>Archivo</strong>
        <div class="dw-spatial-actions" hidden data-spatial-actions>
          <button type="button" data-spatial-front title="Colocar frente a mí"><i class="fas fa-crosshairs"></i> Frente</button>
        </div>
        <button type="button" class="dw-media-close" data-dw-media-close aria-label="Cerrar archivo"><i class="fas fa-xmark"></i></button>
      </header>
      <div class="dw-file-window-body" data-dw-media-content></div>
    </section>

    <section class="dw-hud" aria-live="polite">
      <div class="dw-hud-preview" aria-hidden="true">
        <span class="dw-hud-preview-object">
          <i class="fas fa-folder-open" data-hud-preview-icon></i>
          <img data-hud-preview-image alt="" hidden>
        </span>
        <span class="dw-hud-preview-label" data-hud-preview-label>Carpeta</span>
      </div>
      <div class="dw-hud-eyebrow"><span class="dw-hud-dot"></span> ARCADE HUD · OBJETO DETECTADO</div>
      <div class="dw-hud-title"><i class="fas fa-crosshairs"></i><strong data-hud-name><?= $e(rtrim((string)$state['visible_path'], '/')) ?></strong></div>
      <dl>
        <div><dt>TIPO</dt><dd data-hud-type>Carpeta actual</dd></div>
        <div><dt>FORMATO</dt><dd data-hud-format>—</dd></div>
        <div><dt>TAMAÑO</dt><dd data-hud-size><?= $e((string)$state['folder_bytes']) ?></dd></div>
        <div><dt>FECHA</dt><dd data-hud-date><?= $e((string)($state['latest_date'] !== '' ? $state['latest_date'] : '—')) ?></dd></div>
        <div class="dw-hud-route"><dt>RUTA VISIBLE</dt><dd data-hud-path><?= $e((string)$state['visible_path']) ?></dd></div>
        <div><dt>CONTENIDO</dt><dd data-hud-counts><?= (int)$state['folder_count'] ?> carpetas · <?= (int)$state['file_count'] ?> archivos</dd></div>
      </dl>
      <div class="dw-hud-actions">
        <button type="button" data-hud-open disabled><i class="fas fa-folder-open"></i> Abrir</button>
        <button type="button" data-hud-desk><i class="fas fa-hand-sparkles"></i> Traer al escritorio</button>
        <button type="button" data-hud-play hidden><i class="fas fa-play"></i> Reproducir</button>
        <a data-hud-download hidden><i class="fas fa-download"></i> Descargar</a>
      </div>
    </section>

    <div class="dw-controls-hint" aria-hidden="true">
      <span><kbd>←</kbd><kbd>→</kbd> mirar</span>
      <span><kbd>↑</kbd><kbd>↓</kbd> arriba/abajo</span>
      <span><i class="fas fa-hand-pointer"></i> arrastrar para mirar</span>
      <span><i class="fas fa-computer-mouse"></i> librero = seleccionar · WASD / piso = caminar</span>
      <span><kbd>Home</kbd> centrar</span>
      <span><kbd>Enter</kbd> abrir archivo</span>
      <span><kbd>Esc</kbd> volver/cerrar visor</span>
    </div>

    <section class="dw-environment-panel" data-dw-environment-panel hidden>
      <div><strong>Personalizar sala 3D</strong><button type="button" data-dw-environment-close><i class="fas fa-xmark"></i></button></div>
      <div class="dw-environment-library" aria-label="Biblioteca de entornos">
        <label>Destino, época y estación
          <select data-environment-scene>
            <optgroup label="Paisajes y estaciones">
              <option value="original">Castillo alpino · Original</option>
              <option value="alpine-spring">Castillo alpino · Primavera</option>
              <option value="alpine-summer">Castillo alpino · Verano</option>
              <option value="alpine-autumn">Castillo alpino · Otoño</option>
              <option value="alpine-winter">Castillo alpino · Invierno</option>
            </optgroup>
            <optgroup label="Lugares y épocas">
              <option value="sunset">Montañas · Atardecer</option>
              <option value="night">Montañas · Noche estrellada</option>
              <option value="prehistoric">Valle prehistórico</option>
              <option value="future">Horizonte futurista</option>
            </optgroup>
          </select>
        </label>
        <label>Superficie bajo el domo
          <select data-environment-ground>
            <option value="original">Mármol espejo · Original</option>
            <option value="water">Agua</option>
            <option value="grass">Pasto</option>
            <option value="clouds">Nubes</option>
            <option value="sand">Arena</option>
            <option value="snow">Nieve</option>
          </select>
        </label>
        <small>Elige un panorama 360° o una textura de piso. Tus fondos personalizados siguen disponibles debajo.</small>
      </div>
      <p class="dw-environment-help">Los fondos se guardan en <b>Imagenes/fondos3D</b> y la configuración queda en tu perfil de ArcadeCloud.</p>
      <p class="dw-environment-route" aria-label="Ruta de fondos"><i class="fas fa-folder-open"></i> Imagenes/fondos3D/</p>
      <label class="dw-upload-background">
        <i class="fas fa-cloud-arrow-up"></i>
        <span>Subir nuevo fondo</span>
        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/avif" data-background-upload hidden>
      </label>
      <div class="dw-background-gallery" data-background-gallery>
        <?php foreach (array_slice($backgrounds, 0, 24) as $background): ?>
          <button type="button"
                  class="dw-background-option"
                  data-background-option
                  data-background-path="<?= $e((string)$background['visible_path']) ?>"
                  data-background-environment="<?= $e((string)($background['environment_href'] ?? '')) ?>"
                  title="<?= $e((string)$background['name']) ?>">
            <img src="<?= $e((string)($background['thumbnail_href'] ?? '')) ?>" loading="lazy" alt="<?= $e((string)$background['name']) ?>">
            <span><?= $e((string)$background['name']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <div class="dw-environment-selection">
        <span>Fondo seleccionado</span>
        <strong data-environment-selected>Selecciona una miniatura</strong>
      </div>
      <button type="button" data-environment-use="glass" disabled><i class="fas fa-window-maximize"></i> Aplicar a cristales</button>
      <button type="button" data-environment-reset-glass title="Recuperar el paisaje original 360° sin borrar tus imágenes ni cambiar el piso"><i class="fas fa-rotate-left" aria-hidden="true"></i> Volver al fondo original de los cristales</button>
      <button type="button" data-environment-use="floor" disabled><i class="fas fa-layer-group"></i> Aplicar al piso</button>
      <div class="dw-environment-preset-row">
        <label>Muebles <select data-furniture-preset><option value="default">ArcadeCloud Default</option></select></label>
        <label>Ventanas <select data-window-preset><option value="panoramic">Panorámicas</option></select></label>
      </div>
    </section>
  </main>

  <script>
    window.ARCADECLOUD_OS_APPEARANCE = {
      endpoint: 'os-preferences.php',
      csrf: <?= json_encode($uploadCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      nodeKey: <?= json_encode($osPreferenceNodeKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      preferences: <?= json_encode($osPreferences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
    window.DRIVE_INITIAL_ROUTE = <?= json_encode($currentRoute, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.ARCADECLOUD_DRIVE3D = <?= json_encode([
      'renderer' => 'three',
      'visiblePath' => $state['visible_path'],
      'classicHref' => $state['classic_href'],
      'parentHref' => $state['parent_href'],
      'folderCount' => $state['folder_count'],
      'fileCount' => $state['file_count'],
      'folderBytes' => $state['folder_bytes'],
      'latestDate' => $state['latest_date'],
      // First page is embedded so the room can paint files immediately; the API
      // then fills the complete collection without leaving the user with only shelves.
      'initialFiles' => $state['files'],
      'filesApiHref' => 'dataword3d.php?api=files&ruta=' . rawurlencode($currentRoute),
      'csrf' => $uploadCsrf,
      'preferencesEndpoint' => 'os-preferences.php',
      'uploadEndpoint' => 'drive3d-background-upload.php',
      'preferences' => $drive3dPreferences,
      'preferenceNodeKey' => $osPreferenceNodeKey,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="js/os-media-cloud.js?v=<?= (int)filemtime(__DIR__ . '/js/os-media-cloud.js') ?>"></script>
  <script src="js/dataword3d.js?v=<?= (int)filemtime(__DIR__ . '/js/dataword3d.js') ?>"></script>
</body>
</html>
