<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Application\FileListService;
use ArcadeCloud\Drive\View\FileViewHelper;

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

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$userRoot = $app->userStorageProvisioner()->ensureRoot($userId);
$currentRoute = $app->userStoragePath()->normalizeForUser(
    (string)($_GET['ruta'] ?? $userRoot),
    $userId
);

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

$iconForKind = static function (string $kind): string {
    return match ($kind) {
        'image' => 'fa-image',
        'audio' => 'fa-music',
        'video' => 'fa-film',
        'pdf' => 'fa-file-pdf',
        'document' => 'fa-file-lines',
        default => 'fa-file',
    };
};

$buildState = static function (string $route) use (
    $app,
    $userId,
    $userRoot,
    $rootPrefix,
    $normalizePrefix,
    $folderRows,
    $folderChildren,
    $classifyFile,
    $iconForKind
): array {
    $route = $app->userStoragePath()->normalizeForUser($route, $userId);
    $currentPrefix = $normalizePrefix($route);
    $visiblePath = $app->folderQueryService()->displayPathForUser($userId, $route);
    $folders = $folderChildren($route);

    $fileState = $app->fileListService()->load($userId, $route, [
        'pagina' => 1,
        'limite' => FileListService::WEB_OS_PAGE_SIZE,
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
        $key = FileViewHelper::buildS3Key((string)($row['Ruta'] ?? ''), (string)($row['Encriptado'] ?? ''));
        $openHref = $locked || $key === '' ? '' : 'ver_archivo.php?archivo=' . rawurlencode($key);
        $downloadHref = $locked || $key === '' ? '' : 'descargar_archivo.php?archivo=' . rawurlencode($key) . '&nombre=' . rawurlencode($name);

        $files[] = [
            'type' => 'file',
            'name' => $name,
            'kind' => $kind,
            'icon' => $iconForKind($kind),
            'extension' => $extension !== '' ? strtoupper($extension) : 'ARCHIVO',
            'mime' => $mime,
            'size' => FileViewHelper::formatBytes((int)($row['Tamano'] ?? 0)),
            'date' => trim((string)($row['Fecha'] ?? '')),
            'visible_path' => rtrim($visiblePath, '/') . '/' . $name,
            'locked' => $locked,
            'open_href' => $openHref,
            'download_href' => $downloadHref,
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
        'folder_bytes' => FileViewHelper::formatBytes((int)($fileState['folder_bytes'] ?? 0)),
        'latest_date' => $latestDate,
        'classic_href' => 'so.php?ruta=' . rawurlencode($route),
        'parent_href' => $parentPrefix !== '' ? 'dataword3d.php?ruta=' . rawurlencode($parentPrefix) : '',
    ];
};

$state = $buildState($currentRoute);

if ((string)($_GET['api'] ?? '') === 'preview') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'state' => $state,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

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
</head>
<body class="dw3d" data-environment="future">
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
      <button type="button" data-dw-fullscreen title="Pantalla completa"><i class="fas fa-expand"></i></button>
      <a href="<?= $e((string)$state['classic_href']) ?>" title="Vista clásica"><i class="fas fa-desktop"></i></a>
      <a href="so.php" title="Salir de Drive 3D"><i class="fas fa-right-from-bracket"></i></a>
    </div>
  </header>

  <aside class="dw-sidebar" aria-label="Navegación Drive 3D">
    <a class="is-active" href="dataword3d.php"><i class="fas fa-cubes"></i><span>Drive 3D</span></a>
    <a href="<?= $e((string)$state['classic_href']) ?>"><i class="fas fa-table-cells-large"></i><span>Vista clásica</span></a>
    <?php if ((string)$state['parent_href'] !== ''): ?>
      <a href="<?= $e((string)$state['parent_href']) ?>"><i class="fas fa-arrow-turn-up"></i><span>Subir nivel</span></a>
    <?php endif; ?>
    <button type="button" data-dw-environment><i class="fas fa-panorama"></i><span>Entorno</span></button>
    <div class="dw-sidebar-spacer"></div>
    <div class="dw-user-chip"><i class="fas fa-user-astronaut"></i><span><?= $e($userAlias) ?></span></div>
  </aside>

  <main class="dw-world" id="dwWorld">
    <div class="dw-sky" aria-hidden="true"></div>
    <div class="dw-roof" aria-hidden="true"></div>
    <div class="dw-floor" aria-hidden="true"></div>
    <div class="dw-aquarium" aria-hidden="true">
      <span class="dw-fish fish-a">◁</span><span class="dw-fish fish-b">◁</span><span class="dw-fish fish-c">◁</span>
    </div>

    <section class="dw-stage" aria-label="Biblioteca tridimensional">
      <div class="dw-ring" id="dwShelfRing">
        <?php if ($state['folders'] === []): ?>
          <article class="dw-shelf dw-shelf-empty is-active" data-dw-item data-item-type="folder" data-item-name="<?= $e(rtrim((string)$state['visible_path'], '/')) ?>" data-item-path="<?= $e((string)$state['visible_path']) ?>">
            <div class="dw-shelf-crown"><i class="fas fa-folder-open"></i><strong>Esta sala</strong></div>
            <div class="dw-compartment"><div class="dw-empty-message">No hay subcarpetas en este nivel.</div></div>
            <div class="dw-compartment"><div class="dw-empty-message">Tus archivos están sobre el escritorio central.</div></div>
            <div class="dw-compartment"><div class="dw-empty-message">Puedes volver o cambiar a vista clásica.</div></div>
          </article>
        <?php else: ?>
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
              <div class="dw-shelf-crown"><i class="fas fa-folder-open"></i><strong><?= $e($folder['name']) ?></strong></div>
              <div class="dw-compartment dw-compartment-folders" data-preview-folders>
                <span class="dw-book dw-book-large"><b></b></span><span class="dw-book dw-book-large"><b></b></span><span class="dw-book dw-book-large"><b></b></span>
              </div>
              <div class="dw-compartment dw-compartment-files" data-preview-files>
                <?php for ($i = 0; $i < 8; $i++): ?><span class="dw-book dw-book-small"><b></b></span><?php endfor; ?>
              </div>
              <div class="dw-compartment dw-compartment-info" data-preview-info>
                <span><i class="fas fa-sparkles"></i> Selecciona para explorar</span>
              </div>
              <div class="dw-shelf-base"><span data-preview-counts>Carpeta</span></div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <button class="dw-rotate dw-rotate-left" type="button" data-dw-prev aria-label="Girar a la izquierda"><i class="fas fa-chevron-left"></i></button>
    <button class="dw-rotate dw-rotate-right" type="button" data-dw-next aria-label="Girar a la derecha"><i class="fas fa-chevron-right"></i></button>

    <section class="dw-desk" aria-label="Escritorio central">
      <div class="dw-desk-globe" aria-hidden="true"><i class="fas fa-earth-americas"></i></div>
      <div class="dw-desk-focus" data-dw-desk-focus>
        <i class="fas fa-cube"></i>
        <span>Toca un estante o un libro</span>
      </div>
      <div class="dw-desk-files" data-dw-current-files aria-label="Archivos de esta carpeta">
        <?php foreach (array_slice($state['files'], 0, 16) as $file): ?>
          <button type="button"
                  class="dw-desk-book"
                  data-dw-item
                  data-item-type="file"
                  data-item-kind="<?= $e($file['kind']) ?>"
                  data-item-name="<?= $e($file['name']) ?>"
                  data-item-path="<?= $e($file['visible_path']) ?>"
                  data-item-size="<?= $e($file['size']) ?>"
                  data-item-date="<?= $e($file['date']) ?>"
                  data-item-format="<?= $e($file['extension']) ?>"
                  data-open-href="<?= $e($file['open_href']) ?>"
                  data-download-href="<?= $e($file['download_href']) ?>"
                  data-item-locked="<?= !empty($file['locked']) ? '1' : '0' ?>"
                  title="<?= $e($file['name']) ?>">
            <i class="fas <?= $e($file['icon']) ?>"></i><span><?= $e($file['name']) ?></span>
          </button>
        <?php endforeach; ?>
        <?php if ($state['files'] === []): ?><span class="dw-desk-empty">Sin archivos directos en esta sala.</span><?php endif; ?>
      </div>
    </section>

    <section class="dw-media-stage" data-dw-media-stage hidden>
      <button type="button" class="dw-media-close" data-dw-media-close aria-label="Cerrar visor"><i class="fas fa-xmark"></i></button>
      <div data-dw-media-content></div>
    </section>

    <section class="dw-hud" aria-live="polite">
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
      <span><kbd>←</kbd><kbd>→</kbd> girar</span>
      <span><i class="fas fa-computer-mouse"></i> seleccionar</span>
      <span><kbd>Enter</kbd> abrir</span>
      <span><kbd>Esc</kbd> volver</span>
    </div>

    <section class="dw-environment-panel" data-dw-environment-panel hidden>
      <div><strong>Entorno de la sala</strong><button type="button" data-dw-environment-close><i class="fas fa-xmark"></i></button></div>
      <button type="button" data-environment-choice="future">Ciudad futura</button>
      <button type="button" data-environment-choice="mountain">Montaña</button>
      <button type="button" data-environment-choice="prehistoric">Prehistórico</button>
      <button type="button" data-environment-choice="ocean">Océano</button>
    </section>
  </main>

  <script>
    window.ARCADECLOUD_DRIVE3D = <?= json_encode([
      'visiblePath' => $state['visible_path'],
      'classicHref' => $state['classic_href'],
      'parentHref' => $state['parent_href'],
      'folderCount' => $state['folder_count'],
      'fileCount' => $state['file_count'],
      'folderBytes' => $state['folder_bytes'],
      'latestDate' => $state['latest_date'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="js/dataword3d.js?v=<?= (int)filemtime(__DIR__ . '/js/dataword3d.js') ?>"></script>
</body>
</html>
