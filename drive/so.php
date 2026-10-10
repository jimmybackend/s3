<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Application\FileListService;
use ArcadeCloud\Drive\Federation\FederationConfig;
use ArcadeCloud\Drive\Federation\NodeIdentityService;
use ArcadeCloud\Drive\System\NodeCapabilityService;
use ArcadeCloud\Drive\Security\OsPreferenceNodeResolver;
use ArcadeCloud\Drive\Security\UserOsPreferencesRepository;
use ArcadeCloud\Drive\View\FileIconResolver;
use ArcadeCloud\Drive\View\FileViewHelper;

header('Content-Type: text/html; charset=UTF-8');
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

$userIdentifier = $session->userName();
$userAlias = \ArcadeCloud\Drive\View\UserIdentityPresenter::alias($userIdentifier);
$userInitials = \ArcadeCloud\Drive\View\UserIdentityPresenter::initials($userIdentifier);
$userAvatarUrl = '';
$osPreferences = [];
$osPreferenceNodeKey = OsPreferenceNodeResolver::resolve();

try {
    $profile = $app->userProfileService()->profile($userId);
    $userAlias = (string)($profile['alias'] ?? $userAlias);
    $userInitials = (string)($profile['initials'] ?? $userInitials);
    $userAvatarUrl = (string)($profile['avatar_url'] ?? '');
} catch (Throwable) {
    // El shell puede funcionar con las iniciales aunque el perfil no cargue.
}

try {
    $osPreferences = (new UserOsPreferencesRepository($app->db()))->find($userId, $osPreferenceNodeKey);
} catch (Throwable $error) {
    error_log('[ArcadeCloud OS preferences] ' . $error->getMessage());
}

$uploadCsrf = (string)$session->get('upload_csrf', '');
if (!preg_match('/\A[a-f0-9]{64}\z/', $uploadCsrf)) {
    $uploadCsrf = bin2hex(random_bytes(32));
    $session->set('upload_csrf', $uploadCsrf);
}

$userRoot = $app->userStorageProvisioner()->ensureRoot($userId);
$currentRoute = $app->userStoragePath()->normalizeForUser(
    (string)($_GET['ruta'] ?? $userRoot),
    $userId
);
$session->set('ruta_actual', $currentRoute);
$visibleRoute = $app->folderQueryService()->displayPathForUser($userId, $currentRoute);
$visibleBreadcrumbs = $app->folderQueryService()->breadcrumbsForUser($userId, $currentRoute);

$page = max(1, (int)($_GET['pagina'] ?? 1));
$fileState = $app->fileListService()->load($userId, $currentRoute, [
    'pagina' => $page,
    'limite' => FileListService::WEB_OS_PAGE_SIZE,
    'buscar' => (string)($_GET['buscar'] ?? ''),
]);
$page = max(1, (int)($fileState['page'] ?? 1));
$pages = max(1, (int)($fileState['pages'] ?? 1));
$fileTotal = max(0, (int)($fileState['total'] ?? 0));
$folderFileTotal = max(0, (int)($fileState['folder_total'] ?? $fileTotal));
$folderBytes = max(0, (int)($fileState['folder_bytes'] ?? 0));
$pageVisibleFiles = 0;
$pageLockedFiles = 0;
$pageProtectedOpenFiles = 0;
foreach (($fileState['rows'] ?? []) as $folderInfoRow) {
    if (FileViewHelper::isLocked($folderInfoRow)) {
        $pageLockedFiles++;
        continue;
    }
    $pageVisibleFiles++;
    if (FileViewHelper::hasSecurity($folderInfoRow)) {
        $pageProtectedOpenFiles++;
    }
}
$pagerPageSet = [];
foreach ([
    [1, min(3, $pages)],
    [max(1, $page - 2), min($pages, $page + 2)],
    [max(1, $pages - 2), $pages],
] as [$rangeStart, $rangeEnd]) {
    for ($pagerPageNumber = $rangeStart; $pagerPageNumber <= $rangeEnd; $pagerPageNumber++) {
        $pagerPageSet[$pagerPageNumber] = true;
    }
}
ksort($pagerPageSet, SORT_NUMERIC);

$pagerItems = [];
$pagerPrevious = null;
foreach (array_keys($pagerPageSet) as $pagerPageNumber) {
    if ($pagerPrevious !== null && ($pagerPageNumber - $pagerPrevious) > 1) {
        $pagerItems[] = null;
    }
    $pagerItems[] = $pagerPageNumber;
    $pagerPrevious = $pagerPageNumber;
}
$files = $fileState['rows'];
$storageUsage = $app->storageUsageService()->getUsage($userId);

$normalizePrefix = static function (string $prefix): string {
    $prefix = str_replace('\\', '/', trim($prefix));
    $prefix = preg_replace('~/+~', '/', $prefix) ?? $prefix;
    $prefix = ltrim($prefix, '/');
    return $prefix === '' ? '' : rtrim($prefix, '/') . '/';
};

$rootPrefix = $normalizePrefix($userRoot);
$currentPrefix = $normalizePrefix($currentRoute);
$folderRows = $app->folderRepository()->listHierarchyRows($userId);
$folders = [];
$parentRoute = $rootPrefix;

foreach ($folderRows as $row) {
    $prefix = $normalizePrefix((string)($row['Prefix'] ?? ''));
    $parent = $normalizePrefix((string)($row['ParentPrefix'] ?? ''));

    if ($prefix === $currentPrefix && $currentPrefix !== $rootPrefix && $parent !== '') {
        $parentRoute = $app->userStoragePath()->normalizeForUser($parent, $userId);
    }

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

    $folders[] = [
        'prefix' => $prefix,
        'name' => $name,
    ];
}

usort($folders, static fn(array $a, array $b): int => strnatcasecmp((string)$a['name'], (string)$b['name']));

$nodeSnapshot = (new NodeCapabilityService())->snapshot(__DIR__);

$federationNodeName = '';
try {
    $federationConfig = FederationConfig::fromEnvironment();
    $federationIdentity = new NodeIdentityService($federationConfig->identityPath());
    $federationNodeName = trim($federationIdentity->nodeName());
} catch (Throwable) {
    // El icono puede degradar al host local si FederationCloud no está disponible.
}

$desktopNodeLabel = 'Nodo local';
if ($federationNodeName !== '') {
    $normalizedFederationNodeName = strtolower($federationNodeName);
    $friendlyFederationNodeName = match ($normalizedFederationNodeName) {
        'drive' => 'Drive',
        'fastdrive' => 'FastDrive',
        default => $federationNodeName,
    };
    $desktopNodeLabel = 'Nodo ' . $friendlyFederationNodeName;
}

$nodeName = trim((string)(getenv('ARCADECLOUD_PUBLIC_URL') ?: ''));
if ($nodeName !== '') {
    $host = parse_url($nodeName, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        $nodeName = $host;
    }
}
if ($nodeName === '') {
    $nodeName = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
}
if ($nodeName === '') {
    $nodeName = (string)($nodeSnapshot['hostname'] ?? 'ArcadeCloud');
}

$e = static fn(mixed $value): string => FileViewHelper::escape($value);
$formatBytes = static fn(int $bytes): string => FileViewHelper::formatBytes($bytes);
$load = $nodeSnapshot['load_average'] ?? [0, 0, 0];
$isSuperAdmin = $session->isSuperAdmin();
$canViewPersonalTools = $app->personalToolAccessService()->state() === 'owner';
$serverConsoleCsrf = (string)$session->get('csrf', '');
if ($isSuperAdmin && !preg_match('/\A[a-f0-9]{32,128}\z/', $serverConsoleCsrf)) {
    $serverConsoleCsrf = bin2hex(random_bytes(16));
    $session->set('csrf', $serverConsoleCsrf);
}

$serverAdminCsrf = '';
if ($isSuperAdmin) {
    $serverAdminCsrf = (string)$session->get('server_admin_csrf', '');
    if (!preg_match('/\A[a-f0-9]{64}\z/', $serverAdminCsrf)) {
        $serverAdminCsrf = bin2hex(random_bytes(32));
        $session->set('server_admin_csrf', $serverAdminCsrf);
    }
}

$fastDrivePowerCsrf = '';
if ($isSuperAdmin) {
    $fastDrivePowerCsrf = (string)$session->get('fastdrive_control_csrf', '');
    if (!preg_match('/\A[a-f0-9]{64}\z/', $fastDrivePowerCsrf)) {
        $fastDrivePowerCsrf = bin2hex(random_bytes(32));
        $session->set('fastdrive_control_csrf', $fastDrivePowerCsrf);
    }
}

$textExtensions = ['txt','md','markdown','html','htm','css','js','json','csv','sql','php','py','srt','vtt','log','xml','yaml','yml'];
$imageExtensions = ['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff'];
$audioExtensions = ['mp3','wav','ogg','opus','m4a','aac','flac'];
$videoExtensions = ['mp4','webm','mov','avi','mkv','m4v','mpeg','mpg'];
$officeExtensions = ['doc','docx','odt','rtf','xls','xlsx','ods','ppt','pptx','odp'];
$textractExtensions = ['jpg','jpeg','png','tif','tiff','pdf'];
$translateExtensions = ['txt','pdf','jpg','jpeg','png','tif','tiff'];
$rekognitionExtensions = ['jpg','jpeg','png'];
$transcribeExtensions = ['amr','flac','m4a','mp3','mp4','ogg','webm','wav'];
$comprehendExtensions = ['txt','jas','md','markdown','csv','json','html','htm','xml','sql','log','srt','vtt','php','phtml','js','mjs','css','py','ini','cfg','conf','yaml','yml'];

$diskTotalBytes = max(0, (int)($nodeSnapshot['disk_total_bytes'] ?? 0));
$diskFreeBytes = max(0, (int)($nodeSnapshot['disk_free_bytes'] ?? 0));
$diskUsedBytes = max(0, $diskTotalBytes - $diskFreeBytes);
$diskUsedPercent = $diskTotalBytes > 0 ? round(($diskUsedBytes / $diskTotalBytes) * 100, 1) : 0.0;
$currentIsRoot = $currentPrefix === $rootPrefix;
$currentFolderName = $currentIsRoot
    ? rtrim($rootPrefix, '/')
    : basename(rtrim($currentPrefix, '/'));
$isExplorerFragment = (string)($_GET['_os_fragment'] ?? '') === 'explorer';
// The authenticated 3D action window needs its target explorer immediately.
$isDrive3dActions = (string)($_GET['_drive3d_actions'] ?? '') === '1';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>ArcadeCloud OS</title>
  <link rel="icon" href="ellogo.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="css/so.css?v=<?= (int)filemtime(__DIR__ . '/css/so.css') ?>">
  <link rel="stylesheet" href="css/activity-costs.css?v=<?= (int)filemtime(__DIR__ . '/css/activity-costs.css') ?>">
  <link rel="stylesheet" href="css/personal-tools.css?v=<?= (int)filemtime(__DIR__ . '/css/personal-tools.css') ?>">
  <link rel="stylesheet" href="css/federation.css?v=<?= (int)filemtime(__DIR__ . '/css/federation.css') ?>">
  <link rel="stylesheet" href="css/federation-portal.css?v=<?= (int)filemtime(__DIR__ . '/css/federation-portal.css') ?>">
  <link rel="stylesheet" href="css/os-system-panel.css?v=<?= (int)filemtime(__DIR__ . '/css/os-system-panel.css') ?>">
  <link rel="stylesheet" href="css/federation-os-admin.css?v=<?= (int)filemtime(__DIR__ . '/css/federation-os-admin.css') ?>">
  <link rel="stylesheet" href="css/federation-moderation.css?v=<?= (int)filemtime(__DIR__ . '/css/federation-moderation.css') ?>">
  <link rel="stylesheet" href="css/upload-center.css?v=<?= (int)filemtime(__DIR__ . '/css/upload-center.css') ?>">
  <link rel="stylesheet" href="css/compute-node-idle.css?v=<?= (int)filemtime(__DIR__ . '/css/compute-node-idle.css') ?>">
  <link rel="stylesheet" href="css/os-media-cloud.css?v=<?= (int)filemtime(__DIR__ . '/css/os-media-cloud.css') ?>">
  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
</head>
<body class="arcade-os">
  <main class="os-desktop" id="osDesktop">
    <button class="os-desktop-icon" type="button" data-window-open="nodeWindow">
      <span class="os-icon-tile"><i class="fas fa-server"></i></span>
      <span data-node-local-label><?= $e($desktopNodeLabel) ?></span>
    </button>

    <button class="os-desktop-icon" type="button" data-app-open="explorer">
      <span class="os-icon-tile"><i class="fas fa-folder-open"></i></span>
      <span>Mis datos</span>
    </button>

    <button class="os-desktop-icon" type="button" data-window-open="appsWindow">
      <span class="os-icon-tile"><i class="fas fa-shapes"></i></span>
      <span>Aplicaciones</span>
    </button>

    <a class="os-desktop-icon" href="notebook.php" target="_blank" rel="noopener">
      <span class="os-icon-tile"><i class="fas fa-book-open"></i></span>
      <span>Notebook</span>
    </a>

    <?php if ($isExplorerFragment || $isDrive3dActions): ?>
    <section class="os-window os-explorer-window<?= $isDrive3dActions ? ' is-open is-active' : '' ?>" data-window-title="Mis datos">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-folder-open"></i><span>Mis datos</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>

      <div class="os-explorer-live"
           data-explorer-route="<?= $e($currentRoute) ?>"
           data-explorer-visible-route="<?= $e($visibleRoute) ?>"
           data-explorer-breadcrumbs="<?= $e(json_encode($visibleBreadcrumbs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
           data-explorer-page="<?= $page ?>"
           data-explorer-pages="<?= $pages ?>">
      <div class="os-explorer-pathrow">
        <div class="os-address" title="<?= $e($visibleRoute) ?>">
          <i class="fas fa-folder"></i>
          <span><?= $e($visibleRoute) ?></span>
        </div>
      </div>

      <div class="os-explorer-toolbar">
        <div class="os-selection-actions" data-selection-actions hidden>
          <strong><span data-selection-count>0</span> seleccionados</strong>
          <button type="button" data-selection-action="copy"><i class="fas fa-copy"></i> Copiar</button>
          <button type="button" data-selection-action="cut"><i class="fas fa-scissors"></i> Cortar</button>
          <button type="button" data-selection-action="download"><i class="fas fa-download"></i> Descargar</button>
          <button type="button" data-selection-action="delete"><i class="fas fa-trash"></i> Eliminar</button>
          <button type="button" data-selection-action="more"><i class="fas fa-ellipsis"></i> Más…</button>
        </div>
        <?php if ($currentPrefix !== $rootPrefix): ?>
          <a class="os-tool-button"
             href="so.php?ruta=<?= rawurlencode($parentRoute) ?>"
             data-explorer-route="<?= $e($parentRoute) ?>"
             title="Subir una carpeta"
             aria-label="Subir una carpeta">
            <i class="fas fa-arrow-up"></i>
          </a>
        <?php else: ?>
          <span class="os-tool-button is-disabled" aria-hidden="true"><i class="fas fa-arrow-up"></i></span>
        <?php endif; ?>

        <button type="button"
                class="os-tool-button os-upload-launch"
                data-drive-upload-center
                title="Subir archivos a esta carpeta"
                aria-label="Subir archivos a esta carpeta">
          <i class="fas fa-cloud-arrow-up"></i>
          <span>Subir</span>
        </button>

        <button type="button"
                class="os-tool-button"
                data-folder-info
                aria-expanded="false"
                title="Información de la carpeta"
                aria-label="Información de la carpeta">
          <i class="fas fa-circle-info"></i>
        </button>

        <div class="os-explorer-view-switch" role="group" aria-label="Vista de archivos">
          <button type="button" class="os-tool-button is-active" data-explorer-view="grid" title="Vista en cuadrícula" aria-label="Vista en cuadrícula" aria-pressed="true"><i class="fas fa-grip"></i></button>
          <button type="button" class="os-tool-button" data-explorer-view="list" title="Vista en lista" aria-label="Vista en lista" aria-pressed="false"><i class="fas fa-list"></i></button>
        </div>

        <nav class="os-folder-pagination" aria-label="Páginas de archivos">
          <?php if ($page > 1): ?>
            <a href="so.php?ruta=<?= rawurlencode($currentRoute) ?>&pagina=1"
               data-explorer-route="<?= $e($currentRoute) ?>"
               data-explorer-page="1"
               class="os-page-button"
               title="Primera página"
               aria-label="Primera página">&lt;|</a>
            <a href="so.php?ruta=<?= rawurlencode($currentRoute) ?>&pagina=<?= $page - 1 ?>"
               data-explorer-route="<?= $e($currentRoute) ?>"
               data-explorer-page="<?= $page - 1 ?>"
               class="os-page-button"
               title="Página anterior"
               aria-label="Página anterior">&lt;</a>
          <?php else: ?>
            <span class="os-page-button is-disabled" aria-hidden="true">&lt;|</span>
            <span class="os-page-button is-disabled" aria-hidden="true">&lt;</span>
          <?php endif; ?>

          <?php foreach ($pagerItems as $pagerItem): ?>
            <?php if ($pagerItem === null): ?>
              <span class="os-page-ellipsis" aria-hidden="true">…</span>
            <?php elseif ($pagerItem === $page): ?>
              <span class="os-page-button is-active" aria-current="page"><?= $pagerItem ?></span>
            <?php else: ?>
              <a href="so.php?ruta=<?= rawurlencode($currentRoute) ?>&pagina=<?= $pagerItem ?>"
                 data-explorer-route="<?= $e($currentRoute) ?>"
                 data-explorer-page="<?= $pagerItem ?>"
                 class="os-page-button"
                 aria-label="Página <?= $pagerItem ?>"><?= $pagerItem ?></a>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if ($page < $pages): ?>
            <a href="so.php?ruta=<?= rawurlencode($currentRoute) ?>&pagina=<?= $page + 1 ?>"
               data-explorer-route="<?= $e($currentRoute) ?>"
               data-explorer-page="<?= $page + 1 ?>"
               class="os-page-button"
               title="Página siguiente"
               aria-label="Página siguiente">&gt;</a>
            <a href="so.php?ruta=<?= rawurlencode($currentRoute) ?>&pagina=<?= $pages ?>"
               data-explorer-route="<?= $e($currentRoute) ?>"
               data-explorer-page="<?= $pages ?>"
               class="os-page-button"
               title="Última página"
               aria-label="Última página">|&gt;</a>
          <?php else: ?>
            <span class="os-page-button is-disabled" aria-hidden="true">&gt;</span>
            <span class="os-page-button is-disabled" aria-hidden="true">|&gt;</span>
          <?php endif; ?>
        </nav>
      </div>

      <div class="os-folder-info-panel" data-folder-info-panel hidden>
        <div class="os-folder-info-head">
          <strong><i class="fas fa-circle-info"></i> Información de carpeta</strong>
          <button type="button" data-folder-info-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="os-folder-info-grid">
          <div><span>Ruta</span><strong><?= $e($visibleRoute) ?></strong></div>
          <div><span>Archivos</span><strong><?= $folderFileTotal ?></strong></div>
          <div><span>Peso</span><strong><?= $e($formatBytes($folderBytes)) ?></strong></div>
          <div><span>Subcarpetas</span><strong><?= count($folders) ?></strong></div>
          <div><span>Visibles (página)</span><strong><?= $pageVisibleFiles ?></strong></div>
          <div><span>Bloqueados (página)</span><strong><?= $pageLockedFiles ?></strong></div>
          <div><span>Protegidos abiertos</span><strong><?= $pageProtectedOpenFiles ?></strong></div>
          <div><span>Filtrados / paginados</span><strong><?= $fileTotal ?></strong></div>
        </div>
      </div>

      <div class="os-window-body os-explorer-body"
           data-current-folder-route="<?= $e($currentRoute) ?>"
           data-current-folder-name="<?= $e($currentFolderName) ?>"
           data-current-folder-root="<?= $currentIsRoot ? '1' : '0' ?>">
        <div class="os-entry-grid">
          <div class="os-entry-list-head" aria-hidden="true">
            <span></span><strong>Nombre</strong><strong>Tipo</strong><strong>Tamaño</strong><strong>Fecha</strong><span></span>
          </div>
          <?php foreach ($folders as $folder): ?>
            <a class="os-entry os-folder-entry"
               href="so.php?ruta=<?= rawurlencode((string)$folder['prefix']) ?>"
               data-folder-route="<?= $e((string)$folder['prefix']) ?>"
               data-folder-name="<?= $e((string)$folder['name']) ?>"
               data-folder-root="0"
               data-list-type="Carpeta"
               data-list-size=""
               data-list-date=""
               title="<?= $e($folder['name']) ?>">
              <span class="os-entry-menu os-folder-entry-menu" aria-hidden="true"><i class="fas fa-ellipsis-vertical"></i></span>
              <span class="os-entry-icon"><i class="fas fa-folder"></i></span>
              <span class="os-entry-name"><?= $e($folder['name']) ?></span>
              <span class="os-entry-meta">Carpeta</span>
              <span class="os-entry-list-type">Carpeta</span>
              <span class="os-entry-list-size">—</span>
              <span class="os-entry-list-date">—</span>
            </a>
          <?php endforeach; ?>

          <?php foreach ($files as $row): ?>
            <?php
              $fileId = (int)($row['id_'] ?? 0);
              $name = (string)($row['Nombre'] ?? '');
              $ext = FileViewHelper::extension($name);
              $storedMetadata = json_decode((string)($row['Metadatos'] ?? ''), true);
              $storedMetadata = is_array($storedMetadata) ? $storedMetadata : [];
              $mime = strtolower(trim((string)($storedMetadata['mime_type'] ?? $storedMetadata['content_type'] ?? $storedMetadata['mime'] ?? '')));
              $icon = FileIconResolver::resolve($ext);
              $locked = FileViewHelper::isLocked($row);
              $key = FileViewHelper::buildS3Key((string)($row['Ruta'] ?? ''), (string)($row['Encriptado'] ?? ''));
              $keyQ = rawurlencode($key);
              $thumbUrl = 'thumb.php?key=' . $keyQ . '&w=160&h=120&fit=cover';
              $openUrl = $locked ? '' : 'ver_archivo.php?archivo=' . $keyQ;
              $downloadUrl = $locked ? '' : 'descargar_archivo.php?archivo=' . $keyQ . '&nombre=' . rawurlencode($name);
              $editUrl = (!$locked && in_array($ext, $textExtensions, true))
                  ? 'editor.php?archivo=' . $keyQ
                  : '';
              $isImage = in_array($ext, $imageExtensions, true);
              $isAudio = in_array($ext, $audioExtensions, true);
              $isVideo = in_array($ext, $videoExtensions, true);
              $canTextract = in_array($ext, $textractExtensions, true);
              $canTranslate = in_array($ext, $translateExtensions, true);
              $canRekognition = in_array($ext, $rekognitionExtensions, true);
              $canTranscribe = in_array($ext, $transcribeExtensions, true);
              $canComprehend = in_array($ext, $comprehendExtensions, true);
              $canPolly = in_array($ext, ['txt','md'], true);
              $officeUrl = (!$locked && $fileId > 0 && in_array($ext, $officeExtensions, true))
                  ? 'office-launch.php?file_id=' . $fileId
                  : '';
              $listType = (string)($icon['label'] ?? ($ext !== '' ? strtoupper($ext) : 'Archivo'));
              $listSize = $formatBytes((int)($row['Tamano'] ?? 0));
              $listDate = trim((string)($row['Fecha'] ?? ''));
            ?>
            <button type="button"
                    class="os-entry os-file-entry<?= $locked ? ' is-locked' : '' ?>"
                    data-file-id="<?= $fileId ?>"
                    data-name="<?= $e($name) ?>"
                    data-ext="<?= $e($ext) ?>"
                    data-mime="<?= $e($mime) ?>"
                    data-key="<?= $e($key) ?>"
                    data-bytes="<?= (int)($row['Tamano'] ?? 0) ?>"
                    data-updated-at="<?= $e((string)($row['Fecha'] ?? '')) ?>"
                    data-created-at="<?= $e((string)($row['Fecha'] ?? '')) ?>"
                    data-list-type="<?= $e($listType) ?>"
                    data-list-size="<?= $e($listSize) ?>"
                    data-list-date="<?= $e($listDate) ?>"
                    data-open-url="<?= $e($openUrl) ?>"
                    data-wallpaper-url="<?= $isImage && !$locked ? $e('ver_archivo.php?archivo=' . $keyQ) : '' ?>"
                    data-download-url="<?= $e($downloadUrl) ?>"
                    data-edit-url="<?= $e($editUrl) ?>"
                    data-office-url="<?= $e($officeUrl) ?>"
                    data-classic-url="s3.php"
                    data-locked="<?= $locked ? '1' : '0' ?>"
                    data-has-security="<?= FileViewHelper::hasSecurity($row) ? '1' : '0' ?>"
                    data-unlocked="<?= (($row['AccessType'] ?? 'normal') === 'unlocked') ? '1' : '0' ?>"
                    data-hint="<?= $e((string)($row['SecureHint'] ?? '')) ?>"
                    data-image="<?= $isImage ? '1' : '0' ?>"
                    data-audio="<?= $isAudio ? '1' : '0' ?>"
                    data-video="<?= $isVideo ? '1' : '0' ?>"
                    data-textract="<?= $canTextract ? '1' : '0' ?>"
                    data-translate="<?= $canTranslate ? '1' : '0' ?>"
                    data-rekognition="<?= $canRekognition ? '1' : '0' ?>"
                    data-transcribe="<?= $canTranscribe ? '1' : '0' ?>"
                    data-comprehend="<?= $canComprehend ? '1' : '0' ?>"
                    data-polly="<?= $canPolly ? '1' : '0' ?>"
                    title="<?= $e($name) ?>">
              <span class="os-entry-menu" aria-hidden="true"><i class="fas fa-ellipsis-vertical"></i></span>
              <?php if ($isImage && !$locked): ?>
                <span class="os-entry-thumb-wrap">
                  <img class="os-entry-thumbnail"
                       src="<?= $e($thumbUrl) ?>"
                       width="96" height="72"
                       loading="lazy"
                       decoding="async"
                       alt="Miniatura de <?= $e($name) ?>">
                </span>
              <?php else: ?>
                <span class="os-entry-icon os-file-type-<?= $e($icon['category']) ?>">
                  <i class="fas <?= $e($locked ? 'fa-lock' : $icon['icon']) ?>"></i>
                </span>
              <?php endif; ?>
              <span class="os-entry-name"><?= $e($name) ?></span>
              <span class="os-entry-meta"><?= $e($locked ? 'Protegido' : $formatBytes((int)($row['Tamano'] ?? 0))) ?></span>
              <span class="os-entry-list-type"><?= $e($listType) ?></span>
              <span class="os-entry-list-size"><?= $e($listSize) ?></span>
              <span class="os-entry-list-date"><?= $e($listDate !== '' ? $listDate : '—') ?></span>
            </button>
          <?php endforeach; ?>

          <?php if ($folders === [] && $files === []): ?>
            <div class="os-empty">
              <i class="far fa-folder-open"></i>
              <strong>Esta carpeta está vacía.</strong>
              <span>Usa el Drive clásico para subir archivos mientras terminamos el escritorio.</span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="os-statusbar">
        <span><?= count($folders) ?> carpetas · <?= $fileTotal ?> archivos · Página <?= $page ?> de <?= $pages ?></span>
        <a href="s3.php"><i class="fas fa-arrow-up-right-from-square"></i> Abrir Drive clásico</a>
      </div>
      </div>
    </section>
    <?php endif; ?>

    <section class="os-window" id="nodeWindow" data-window-title="Mi nodo" data-node-access="<?= $isSuperAdmin ? 'superadmin' : 'standard' ?>">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-server"></i><span>Mi nodo</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>
      <div class="os-window-body">
        <div class="os-node-heading">
          <span class="os-node-large-icon"><i class="fas fa-server"></i></span>
          <div>
            <h2><?= $e($nodeName) ?></h2>
            <p><?= $isSuperAdmin ? 'Servidor local que está ejecutando esta instalación de ArcadeCloud. Recursos, servicios y programas pertenecen únicamente a este nodo.' : 'Estado general y uso seguro de recursos del servidor local.' ?></p>
          </div>
        </div>

        <?php if (!$isSuperAdmin): ?>
        <div class="os-stat-grid os-node-legacy-summary is-standard">
          <article><span>vCPU</span><strong data-node-field="vcpu"><?= (int)$nodeSnapshot['vcpu'] ?></strong></article>
          <article><span>RAM total</span><strong data-node-field="memory_total"><?= $e($formatBytes((int)$nodeSnapshot['memory_total_bytes'])) ?></strong></article>
          <article class="os-node-memory-card">
            <span>RAM disponible</span>
            <div class="os-node-memory-value">
              <strong data-node-field="memory_available"><?= $e($formatBytes((int)$nodeSnapshot['memory_available_bytes'])) ?></strong>
            </div>
          </article>
          <article><span>Disco total</span><strong data-node-field="disk_total"><?= $e($formatBytes($diskTotalBytes)) ?></strong></article>
          <article class="os-node-memory-card">
            <span>Disco usado</span>
            <div class="os-node-memory-value">
              <strong data-node-field="disk_used"><?= $e($formatBytes($diskUsedBytes)) ?> · <?= $e((string)$diskUsedPercent) ?>%</strong>
            </div>
          </article>
          <article><span>Disco libre</span><strong data-node-field="disk_free"><?= $e($formatBytes($diskFreeBytes)) ?></strong></article>
          <article><span>Carga</span><strong data-node-field="load"><?= $e(implode(' · ', array_map('strval', $load))) ?></strong></article>
        </div>
        <?php endif; ?>

        <div class="os-node-live-head">
          <small data-node-updated>Datos tomados al abrir ArcadeCloud OS</small>
          <button type="button" data-node-refresh title="Actualizar ahora"><i class="fas fa-rotate"></i> Actualizar</button>
        </div>
        <div class="os-node-dashboard" data-node-dashboard aria-live="polite">
          <p class="os-node-placeholder">Abre Mi nodo o pulsa Actualizar para consultar el diagnóstico.</p>
        </div>
        <div class="os-capability-list">
          <?php if ($isSuperAdmin): ?>
          <div><span>FFmpeg</span><strong data-node-capability="ffmpeg" class="<?= $nodeSnapshot['ffmpeg_available'] ? 'is-ready' : 'is-missing' ?>"><?= $nodeSnapshot['ffmpeg_available'] ? 'Disponible' : 'No disponible' ?></strong></div>
          <div><span>FFprobe</span><strong data-node-capability="ffprobe" class="<?= $nodeSnapshot['ffprobe_available'] ? 'is-ready' : 'is-missing' ?>"><?= $nodeSnapshot['ffprobe_available'] ? 'Disponible' : 'No disponible' ?></strong></div>
          <div><span>Docker</span><strong data-node-capability="docker" class="<?= $nodeSnapshot['docker_installed'] ? 'is-ready' : 'is-missing' ?>"><?= $nodeSnapshot['docker_installed'] ? 'Instalado' : 'No instalado' ?></strong></div>
          <div><span>GPU</span><strong data-node-capability="gpu" class="<?= $nodeSnapshot['gpu_present'] ? 'is-ready' : 'is-neutral' ?>"><?= $nodeSnapshot['gpu_present'] ? 'Detectada' : 'No detectada' ?></strong></div>
          <?php endif; ?>
          <div><span>Raíz de almacenamiento</span><strong><?= $e($visibleRoute) ?></strong></div>
          <div><span>Espacio del usuario en S3</span><strong><?= $e((string)($storageUsage['formatted'] ?? '0 B')) ?></strong></div>
          <?php if ($isSuperAdmin && (string)$nodeSnapshot['instance_id'] !== ''): ?>
            <div><span>EC2 Instance ID</span><strong data-node-field="instance_id"><?= $e((string)$nodeSnapshot['instance_id']) ?></strong></div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="os-window" id="appsWindow" data-window-title="Aplicaciones">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-shapes"></i><span>Aplicaciones</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>
      <div class="os-window-body">
        <div class="os-app-grid">
          <button type="button" class="os-app-card is-ready" data-app-open="explorer"><i class="fas fa-folder-open"></i><strong>Mis datos</strong><span>Disponible</span></button>
          <button type="button" class="os-app-card is-ready" data-window-open="searchWindow" data-open-search>
            <i class="fas fa-magnifying-glass"></i><strong>Buscar</strong><span>Normal + IA</span>
          </button>
          <button type="button" class="os-app-card is-ready" data-window-open="federationWindow" data-open-federation>
            <i class="fas fa-globe"></i><strong>FederationCloud</strong><span>Compartidos y red</span>
          </button>
          <?php if ($isSuperAdmin): ?>
          <button type="button" class="os-app-card is-ready" data-window-open="terminalWindow" data-open-terminal>
            <i class="fas fa-terminal"></i><strong>Consola servidor</strong><span>Mi nodo</span>
          </button>
          <?php endif; ?>
          <a class="os-app-card is-ready" href="notebook.php" target="_blank" rel="noopener"><i class="fas fa-book-open"></i><strong>Notebook</strong><span>Nueva pestaña</span></a>
          <a class="os-app-card is-ready" href="s3.php"><i class="fas fa-hard-drive"></i><strong>Drive clásico</strong><span>Disponible</span></a>
          <a class="os-app-card is-ready" href="dataword3d.php"><i class="fas fa-cubes"></i><strong>Drive 3D</strong><span>Biblioteca espacial</span></a>
          <a class="os-app-card is-ready" data-launcher-app="office" href="office-launch.php" target="_blank" rel="noopener"><i class="fas fa-file-word"></i><strong>Office</strong><span>Disponible</span></a>
          <a class="os-app-card is-ready" data-launcher-app="linux-xfce" href="office-launch.php" target="_blank" rel="noopener" title="Abrir escritorio Linux remoto por noVNC"><i class="fab fa-linux"></i><strong>Linux XFCE</strong><span>noVNC</span></a>
          <a class="os-app-card is-ready" data-launcher-app="guacamole" href="office-launch.php?target=guacamole" target="_blank" rel="noopener" title="Abrir escritorio Linux remoto por Guacamole RDP"><i class="fas fa-headset"></i><strong>Guacamole</strong><span>Audio + micrófono</span></a>
          <a class="os-app-card is-ready" data-launcher-app="office-web" href="https://office.esforzados.com/" target="_blank" rel="noopener" title="Suite ofimática en la nube (enlace directo)"><i class="fas fa-file-lines"></i><strong>Office Web</strong><span>Enlace directo</span></a>
          <a class="os-app-card is-ready" data-launcher-app="kde-plasma" href="office-launch.php?target=kde" target="_blank" rel="noopener" title="Abrir escritorio KDE Plasma remoto por Guacamole"><i class="fas fa-desktop"></i><strong>KDE Plasma</strong><span>Guacamole</span></a>
          <button type="button" class="os-app-card" disabled><i class="fas fa-pen-ruler"></i><strong>Diagramas</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-image"></i><strong>Imagen</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-wave-square"></i><strong>Audio</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-film"></i><strong>Video</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-file-pdf"></i><strong>PDF</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-earth-americas"></i><strong>Mapas</strong><span>Próximamente</span></button>
        </div>
      </div>
    </section>

    <section class="os-window os-notebook-window" id="notebookWindow" data-window-title="Notebook">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-book-open"></i><span>Notebook</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>
      <div class="os-window-body os-notebook-body">
        <iframe src="notebook.php" title="Notebook" loading="lazy" allow="clipboard-read; clipboard-write"></iframe>
      </div>
    </section>

    <section class="os-window os-search-window"
             id="searchWindow"
             data-window-title="Buscar">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-magnifying-glass"></i><span>Buscar</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>

      <div class="os-window-body os-search-body">
        <div class="os-search-mode" role="group" aria-label="Modo de búsqueda">
          <button type="button" data-os-search-mode="normal" aria-pressed="true">
            <i class="fas fa-font"></i><span>Normal</span>
          </button>
          <button type="button" data-os-search-mode="ai" aria-pressed="false">
            <i class="fas fa-wand-magic-sparkles"></i><span>Con IA</span>
          </button>
        </div>

        <form id="osSearchForm" class="os-search-form" autocomplete="off">
          <div class="os-search-input-row">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search"
                   id="osSearchInput"
                   maxlength="600"
                   placeholder="factura, fact*, *.pdf, *2026*"
                   aria-label="Buscar archivos"
                   required>
            <button type="submit"><i class="fas fa-search"></i><span>Buscar</span></button>
          </div>
          <small id="osSearchHelp">Busca por nombre: factura, fact*, *.pdf, *2026* o usa ? para un carácter.</small>
        </form>

        <div id="osSearchResults" class="os-search-results" aria-live="polite">
          <div class="os-search-empty">
            <i class="fas fa-magnifying-glass"></i>
            <strong>Busca en todo Mi Drive</strong>
            <span>Los resultados salen del catálogo MySQL del usuario; no se lista S3.</span>
          </div>
        </div>
      </div>
    </section>

    <section class="os-window os-federation-window"
             id="federationWindow"
             data-window-title="FederationCloud">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-globe"></i><span>FederationCloud</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>

      <div class="os-federation-toolbar" id="federationToolbar" role="toolbar" aria-label="Herramientas FederationCloud">
        <button type="button" class="is-active" data-federation-view="search" title="Buscar en el catálogo global">
          <i class="fas fa-search"></i><span>Buscar</span>
        </button>
        <button type="button" data-federation-view="requests" title="Solicitudes de acceso">
          <i class="fas fa-inbox"></i><span>Solicitudes</span>
        </button>
        <button type="button" data-federation-view="shares" title="Archivos compartidos">
          <i class="fas fa-folder-tree"></i><span>Compartidos</span>
        </button>
        <button type="button" data-federation-view="replicas" title="Trabajos de réplica">
          <i class="fas fa-copy"></i><span>Réplicas</span>
        </button>
        <span class="os-federation-toolbar-spacer"></span>
        <button type="button" data-federation-view="about" title="Nodo, red y administración">
          <i class="fas fa-circle-info"></i><span>Acerca de</span>
        </button>
        <?php if ($isSuperAdmin): ?>
        <button type="button" data-federation-view="moderation" title="Moderación FederationCloud">
          <i class="fas fa-shield-halved"></i><span>Moderación</span>
        </button>
        <?php endif; ?>
      </div>

      <div class="os-window-body os-federation-body">
        <div class="os-federation-loading" id="federationFrameLoading" hidden>
          <i class="fas fa-circle-notch fa-spin"></i><span>Cargando FederationCloud…</span>
        </div>
        <div id="federationApp" class="os-federation-app"
             data-source="federationcloud/portal.php?embed=1&amp;view=search"
             aria-live="polite"></div>
      </div>

      <div class="os-statusbar">
        <span><i class="fas fa-network-wired mr-1"></i>FederationCloud · sesión actual</span>
        <span id="federationWindowStatus">Catálogo global</span>
      </div>
    </section>

    <?php if ($isSuperAdmin): ?>
    <section class="os-window os-terminal-window"
             id="terminalWindow"
             data-window-title="Terminal">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-terminal"></i><span>Terminal · Mi nodo</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>
      <div class="os-window-body os-terminal-body">
        <div class="os-terminal-note">
          <i class="fas fa-shield-halved"></i>
          <span>Terminal restringida del servidor local. Sólo ejecuta los comandos exactos permitidos por ArcadeCloud; no abre una shell Linux arbitraria.</span>
        </div>

        <div id="osTerminalStatus" class="os-terminal-status" aria-live="polite">
          Abre la terminal para consultar las opciones disponibles en este nodo.
        </div>

        <div id="osTerminalCommands" class="os-terminal-commands" aria-label="Comandos permitidos">
          <div class="os-terminal-loading"><i class="fas fa-circle-notch fa-spin"></i> Cargando comandos…</div>
        </div>

        <pre id="osTerminalOutput" class="os-terminal-output" aria-live="polite">ArcadeCloud restricted server console
Escribe help o usa uno de los botones disponibles.</pre>

        <div class="os-terminal-input-row">
          <span class="os-terminal-prompt">$</span>
          <input type="text"
                 id="osTerminalInput"
                 name="terminal_command"
                 value=""
                 autocomplete="off"
                 data-lpignore="true"
                 data-1p-ignore="true"
                 autocapitalize="none"
                 spellcheck="false"
                 placeholder="Escribe un comando permitido…">
          <button type="button" id="osTerminalRun"><i class="fas fa-play"></i><span>Ejecutar</span></button>
        </div>

        <div class="os-terminal-private-confirm" id="osTerminalPrivateConfirm" hidden>
          <div>
            <strong>Liberar caché Linux</strong>
            <p>Esta acción ejecuta <code>sync</code> y libera page cache, dentries e inodes. No termina procesos.</p>
          </div>
          <input type="password"
                 id="osTerminalPrivatePassword"
                 autocomplete="current-password"
                 placeholder="Contraseña privada">
          <div class="os-terminal-private-actions">
            <button type="button" id="osTerminalPrivateCancel">Cancelar</button>
            <button type="button" id="osTerminalPrivateAccept" class="is-danger">Confirmar</button>
          </div>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <section class="os-window os-page-monitor-window" id="pageMonitorWindow" data-window-title="Administrador de la página">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-chart-line"></i><span>Administrador de la página</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>
      <div class="os-window-body os-page-monitor-body" data-page-monitor>
        <header class="os-page-monitor-heading">
          <strong>Recursos de esta pestaña</strong>
          <p>Mide el navegador y ArcadeCloud OS en esta página; no son recursos del EC2.</p>
        </header>
        <div class="os-page-monitor-grid">
          <article><span>Memoria JS usada</span><strong data-page-memory-used>—</strong><small data-page-memory-detail>Compatibilidad del navegador pendiente</small></article>
          <article><span>DOM</span><strong data-page-dom-nodes>—</strong><small>Nodos actualmente cargados</small></article>
          <article><span>Ventanas</span><strong data-page-windows>—</strong><small data-page-window-detail>Abiertas en ArcadeCloud OS</small></article>
          <article><span>Tareas</span><strong data-page-tasks>—</strong><small>Activas en el centro de tareas</small></article>
          <article><span>Recursos cargados</span><strong data-page-resources>—</strong><small>JS, CSS, imágenes y solicitudes</small></article>
          <article><span>Tiempo abierta</span><strong data-page-uptime>—</strong><small>Desde la navegación actual</small></article>
        </div>
        <section class="os-page-window-monitor">
          <div class="os-page-window-monitor-head">
            <div><strong>Ventanas abiertas</strong><small>La barra representa una huella estimada de interfaz; la memoria JS real se muestra arriba.</small></div>
            <button type="button" data-page-window-refresh><i class="fas fa-rotate"></i> Actualizar</button>
          </div>
          <div class="os-page-memory-chart" data-page-memory-chart aria-label="Gráfica de huella estimada por ventana"></div>
          <div class="os-page-window-list" data-page-window-list></div>
        </section>
        <div class="os-page-monitor-note" data-page-memory-note>La memoria exacta depende de las APIs que permita el navegador.</div>
      </div>
    </section>

    <section class="os-window os-settings-window" id="settingsWindow" data-window-title="Configuración">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-gear"></i><span>Configuración</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>
      <div class="os-window-body os-settings-body">
        <header class="os-settings-heading"><span>Apariencia</span><p>Personaliza este escritorio. Los cambios se aplican y guardan al instante.</p></header>
        <div class="os-appearance-preview" id="osAppearancePreview" aria-label="Vista previa del escritorio"><span></span><i></i><b></b></div>
        <fieldset class="os-settings-group">
          <legend>Tema</legend>
          <div class="os-choice-row" role="radiogroup" aria-label="Tema">
            <button type="button" data-os-theme="light" role="radio"><i class="fas fa-sun"></i> Claro</button>
            <button type="button" data-os-theme="dark" role="radio"><i class="fas fa-moon"></i> Oscuro</button>
          </div>
        </fieldset>
        <fieldset class="os-settings-group">
          <legend>Fondo del escritorio</legend>
          <div class="os-wallpaper-grid">
            <button type="button" class="os-wallpaper-choice is-original" data-os-wallpaper-choice="original"><span></span><strong>Fondo original</strong></button>
            <button type="button" class="os-wallpaper-choice is-none" data-os-wallpaper-choice="none"><span><i class="fas fa-ban"></i></span><strong>Sin imagen</strong></button>
            <button type="button" class="os-wallpaper-choice is-current" data-os-wallpaper-choice="current" hidden><span></span><strong data-os-current-wallpaper>Imagen elegida</strong></button>
          </div>
          <small>También puedes elegir cualquier imagen desde su menú contextual en “Mis datos”.</small>
        </fieldset>
        <fieldset class="os-settings-group os-opacity-controls">
          <legend>Transparencia</legend>
          <label for="osWindowOpacity"><span>Opacidad del contenido de ventanas</span><output id="osWindowOpacityValue">94%</output></label>
          <input type="range" id="osWindowOpacity" min="0" max="100" step="1" value="94">
          <label for="osMenuOpacity"><span>Opacidad de menús</span><output id="osMenuOpacityValue">98%</output></label>
          <input type="range" id="osMenuOpacity" min="0" max="100" step="1" value="98">
          <label for="osChromeOpacity"><span>Opacidad de título y footer</span><output id="osChromeOpacityValue">96%</output></label>
          <input type="range" id="osChromeOpacity" min="0" max="100" step="1" value="96">
          <small>0% deja ver el fondo; contornos, nombres, iconos y controles permanecen visibles.</small>
        </fieldset>
        <button type="button" class="os-settings-reset" data-os-reset-appearance><i class="fas fa-arrow-rotate-left"></i> Restaurar configuración de apariencia</button>
      </div>
    </section>

    <section class="os-window os-links-window" id="linksWindow" data-window-title="Enlaces">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-link"></i><span>Enlaces</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>
      <div class="os-window-body">
        <div class="os-links-grid">
          <a href="activity_costs.php" data-os-tool="activity-costs" data-tool-title="Actividad y costos"><i class="fas fa-receipt"></i><span><strong>Actividad y costos</strong><small>Consumo de tu cuenta</small></span></a>
          <a href="up.php" target="_blank" rel="noopener noreferrer"><i class="fas fa-cloud-arrow-up"></i><span><strong>Subir archivos</strong><small>Subida pública a usuarios</small></span></a>
          <?php if ($canViewPersonalTools): ?>
          <a href="aws.php" data-os-tool="aws" data-tool-title="AWS y códigos TOTP"><i class="fab fa-aws"></i><span><strong>AWS y códigos TOTP</strong><small>Herramienta personal autorizada</small></span></a>
          <a href="ec2.php?surface=os" data-os-tool="ec2" data-tool-title="Gestión EC2"><i class="fas fa-server"></i><span><strong>Gestión EC2</strong><small>Instancias y bases AWS</small></span></a>
          <?php endif; ?>
          <a href="#federationWindow" data-window-open="federationWindow" data-open-federation><i class="fas fa-globe"></i><span><strong>FederationCloud</strong><small>Red y contenido compartido</small></span></a>
        </div>
      </div>
    </section>
  </main>

  <div class="os-file-context os-folder-context" id="folderContextMenu" hidden>
    <div class="os-context-name" id="folderContextName">Carpeta</div>
    <button type="button" data-folder-action="open"><i class="fas fa-folder-open"></i>Abrir</button>
    <button type="button" data-folder-action="open-new"><i class="fas fa-window-restore"></i>Abrir en nueva ventana</button>
    <button type="button" data-folder-action="sync"><i class="fas fa-rotate"></i>Sincronizar desde S3</button>
    <button type="button" data-folder-action="create-empty-file"><i class="fas fa-file-circle-plus"></i>Nuevo archivo de texto</button>
    <button type="button" data-folder-action="create-document"><i class="fas fa-paste"></i>Crear desde texto pegado</button>
    <button type="button" data-folder-action="create-folder"><i class="fas fa-folder-plus"></i>Nueva subcarpeta</button>
    <div class="os-context-divider" data-folder-mutating-divider></div>
    <button type="button" data-folder-action="move" data-folder-mutating><i class="fas fa-arrows-alt"></i>Mover</button>
    <button type="button" data-folder-action="rename" data-folder-mutating><i class="fas fa-pen"></i>Editar nombre</button>
    <button type="button" class="is-danger" data-folder-action="delete" data-folder-mutating><i class="fas fa-trash"></i>Eliminar</button>
  </div>

  <div class="os-file-context" id="fileContextMenu" hidden>
    <div class="os-context-name" id="fileContextName">Archivo</div>
    <button type="button" class="os-context-page-control is-up" data-context-page-up hidden aria-label="Ver acciones anteriores"><i class="fas fa-chevron-up"></i><span>10 anteriores</span></button>
    <button type="button" data-file-action="open"><i class="fas fa-eye"></i><span>Abrir en ventana</span></button>
    <button type="button" data-file-action="open-with"><i class="fas fa-table-list"></i><span>Abrir con…</span></button>
    <button type="button" data-file-action="office"><i class="fas fa-file-word"></i><span>Abrir con Office</span></button>
    <button type="button" data-file-action="edit"><i class="fas fa-pen"></i>Editar texto</button>
    <button type="button" data-file-action="download"><i class="fas fa-download"></i>Descargar</button>
    <button type="button" data-file-action="details"><i class="fas fa-circle-info"></i><span>Detalles</span></button>
    <button type="button" data-file-action="wallpaper"><i class="fas fa-panorama"></i><span>Usar como fondo de pantalla</span></button>
    <div class="os-context-divider" data-selection-context-divider hidden></div>
    <button type="button" data-selection-context="download" data-selection-action="download" hidden>
      <i class="fas fa-download"></i><span>Descargar seleccionados</span>
    </button>
    <button type="button" class="is-danger" data-selection-context="delete" data-selection-action="delete" hidden>
      <i class="fas fa-trash"></i><span>Eliminar seleccionados</span>
    </button>
    <div class="os-context-divider" data-service-divider></div>
    <button type="button" data-file-action="textract" data-service-action><i class="fas fa-file-lines"></i>Textract · Extraer texto</button>
    <button type="button" data-file-action="transcribe" data-service-action><i class="fas fa-wave-square"></i>Transcribe · Audio a texto</button>
    <button type="button" data-file-action="polly" data-service-action><i class="fas fa-headphones"></i>Polly · Crear audio</button>
    <button type="button" data-file-action="translate" data-service-action><i class="fas fa-language"></i>Translate · Traducir</button>
    <button type="button" data-file-action="rekognition" data-service-action><i class="fas fa-tags"></i>Rekognition · Analizar imagen</button>
    <button type="button" data-file-action="comprehend" data-service-action><i class="fas fa-brain"></i>Comprehend · Analizar texto</button>
    <button type="button" data-file-action="split-video" data-service-action><i class="fas fa-scissors"></i>Dividir video</button>
    <button type="button" data-file-action="extract-mp3" data-service-action><i class="fas fa-file-audio"></i>Extraer MP3</button>
    <button type="button" data-file-action="split-audio" data-service-action><i class="fas fa-scissors"></i>Dividir audio</button>
    <div class="os-context-divider"></div>
    <button type="button" data-file-action="security-lock"><i class="fas fa-lock"></i><span>Bloquear con contraseña</span></button>
    <button type="button" data-file-action="security-unlock"><i class="fas fa-lock-open"></i><span>Desbloquear</span></button>
    <button type="button" data-file-action="security-relock"><i class="fas fa-lock"></i><span>Bloquear de nuevo</span></button>
    <button type="button" class="is-danger" data-file-action="security-unsecure"><i class="fas fa-shield-virus"></i><span>Quitar protección</span></button>
    <div class="os-context-divider"></div>
    <button type="button" data-file-action="classic"><i class="fas fa-hard-drive"></i>Abrir en Drive clásico</button>
    <button type="button" class="os-context-page-control is-down" data-context-page-down hidden aria-label="Ver acciones siguientes"><i class="fas fa-chevron-down"></i><span>10 siguientes</span></button>
  </div>

  <!-- Modal compartido: Seguridad del archivo -->
  <div class="modal fade" id="securityFileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content security-modal-content">
        <div class="modal-header">
          <h5 id="secModalTitle" class="modal-title">Seguridad del archivo</h5>
          <button type="button" class="close" aria-label="Cerrar" data-sec-close>
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="security-help" id="secModalText"></div>
          <div class="security-help">
            Archivo: <strong class="security-file-name" id="secFileName">—</strong>
          </div>
          <div class="security-hint-box" id="secHintBox" style="display:none;"></div>

          <div class="mb-3" id="secPasswordField">
            <label for="secPasswordInput" class="form-label">Contraseña</label>
            <div class="input-group">
              <input type="password" id="secPasswordInput" class="form-control" autocomplete="new-password">
              <button type="button" class="btn btn-toggle-pass" data-sec-toggle="secPasswordInput">Ver</button>
            </div>
          </div>

          <div class="mb-3" id="secConfirmField" style="display:none;">
            <label for="secConfirmInput" class="form-label">Confirmar contraseña</label>
            <div class="input-group">
              <input type="password" id="secConfirmInput" class="form-control" autocomplete="new-password">
              <button type="button" class="btn btn-toggle-pass" data-sec-toggle="secConfirmInput">Ver</button>
            </div>
          </div>

          <div class="mb-3" id="secHintField" style="display:none;">
            <label for="secHintInput" class="form-label">Pista para recordar</label>
            <input type="text" id="secHintInput" class="form-control" maxlength="255" placeholder="Opcional">
          </div>

          <label class="sec-check-row" id="secShowAllRow" style="display:none;">
            <input type="checkbox" id="secShowAllCheckbox"> Mostrar contraseñas
          </label>

          <div class="security-error" id="secModalError"></div>
        </div>
        <div class="modal-footer">
          <div class="security-actions w-100">
            <button type="button" class="btn btn-secondary" data-sec-cancel>Cancelar</button>
            <button type="button" class="btn btn-primary" data-sec-accept>Aceptar</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="os-folder-dialogs">
    <!-- Nuevo archivo de texto sin contenido: reutiliza el endpoint autenticado de documentos. -->
    <div class="modal fade" id="modalCrearArchivoVacio" tabindex="-1" role="dialog" aria-labelledby="emptyTextFileTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <form id="formCrearArchivoVacio" class="modal-content" autocomplete="off">
          <div class="modal-header">
            <div>
              <h5 class="modal-title" id="emptyTextFileTitle"><i class="fas fa-file-circle-plus mr-2"></i>Nuevo archivo de texto</h5>
              <small class="text-muted">En la carpeta: <span id="emptyTextFileFolderName"></span></small>
            </div>
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="emptyTextFileRoute" name="route">
            <div id="emptyTextFileMessage" class="alert d-none" role="alert" aria-live="polite"></div>
            <div class="form-group">
              <label for="emptyTextFileName">Nombre del archivo</label>
              <input type="text" class="form-control" id="emptyTextFileName" name="name" maxlength="180"
                     placeholder="Mi documento" autocomplete="off" required>
            </div>
            <div class="form-group">
              <label for="emptyTextFileFormat">Tipo de archivo</label>
              <select class="form-control" id="emptyTextFileFormat" name="format" required>
                <option value="txt" selected>Texto plano (.txt)</option>
                <option value="md">Markdown (.md)</option>
                <option value="html">HTML (.html)</option>
              </select>
            </div>
            <p class="text-muted mb-0">Nombre final: <strong id="emptyTextFilePreview">Mi documento.txt</strong></p>
            <small class="text-muted">Se creará vacío, listo para editarlo después. Si ya existe el nombre, se solicitará otro.</small>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            <button type="submit" id="emptyTextFileSave" class="btn btn-primary"><i class="fas fa-check mr-1"></i>Crear archivo</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="modalCrearDocumentoCarpeta" tabindex="-1" role="dialog" aria-labelledby="folderDocumentTitle" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <form id="formCrearDocumentoCarpeta" class="modal-content" autocomplete="off">
          <div class="modal-header">
            <div>
              <h5 class="modal-title" id="folderDocumentTitle"><i class="fas fa-file-alt mr-2"></i>Crear archivo desde texto</h5>
              <small class="text-muted">Carpeta: <span id="folderDocumentFolderName"></span></small>
            </div>
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="folderDocumentRoute" name="route" value="">
            <div id="folderDocumentMessage" class="alert d-none" role="alert"></div>
            <div class="form-row">
              <div class="form-group col-md-8">
                <label for="folderDocumentName">Nombre</label>
                <input type="text" class="form-control" id="folderDocumentName" name="name" maxlength="180" placeholder="Mi documento" required>
              </div>
              <div class="form-group col-md-4">
                <label for="folderDocumentFormat">Guardar como</label>
                <select class="form-control" id="folderDocumentFormat" name="format">
                  <option value="html" selected>HTML (.html)</option>
                  <option value="md">Markdown (.md)</option>
                  <option value="txt">Texto (.txt)</option>
                </select>
              </div>
            </div>
            <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap">
              <label class="mb-1" for="folderDocumentEditor">Contenido</label>
              <button type="button" class="btn btn-sm btn-outline-primary mb-1" id="folderDocumentPaste">
                <i class="fas fa-paste mr-1"></i>Pegar desde portapapeles
              </button>
            </div>
            <div id="folderDocumentEditor"
                 class="folder-document-editor"
                 contenteditable="true"
                 role="textbox"
                 aria-multiline="true"
                 data-placeholder="Escribe o pega aquí el contenido..."></div>
            <small id="folderDocumentFormatHelp" class="form-text text-muted mt-2"></small>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary" id="folderDocumentSave">
              <i class="fas fa-save mr-1"></i>Guardar en esta carpeta
            </button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="modalCrearCarpeta" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <form id="formCrearCarpeta" class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Nueva subcarpeta</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="ruta" id="crearCarpetaRuta" value="<?= $e($currentRoute) ?>">
            <div class="form-group">
              <label for="crearCarpetaNombre">Nombre</label>
              <input type="text" class="form-control" name="nueva" id="crearCarpetaNombre" placeholder="Nueva carpeta" required>
            </div>
            <small class="text-muted">Ruta actual: <span id="crearCarpetaRutaTexto"><?= $e($currentRoute) ?></span></small>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary" id="btnCrearCarpeta">Crear</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="modalMoverCarpeta" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content" id="formMoverCarpeta" novalidate onsubmit="return false;">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-arrows-alt mr-2"></i>Mover carpeta</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <input type="hidden" id="moverOrigen" name="origen">
            <div class="form-group">
              <label>Carpeta a mover</label>
              <input type="text" class="form-control" id="moverNombre" readonly>
              <small class="form-text text-muted">Ruta: <span id="moverOrigenLabel" class="text-monospace"></span></small>
            </div>
            <div class="form-group">
              <label for="moverDestino">Destino</label>
              <select id="moverDestino" name="destino" class="form-control" required>
                <option value="">— Selecciona —</option>
              </select>
              <small class="form-text text-muted">La carpeta se moverá como <code id="moverPreview"></code>.</small>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" id="btnMoverCarpeta" class="btn btn-primary">Mover</button>
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="modalRenombrar" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <form class="modal-content">
          <input type="hidden" name="ruta" id="renombrarRuta">
          <div class="modal-header">
            <h5 class="modal-title">Editar nombre de carpeta</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="nombre_actual" id="nombreActual">
            <div class="form-group">
              <label for="nuevoNombre">Nuevo nombre</label>
              <input type="text" name="nuevo_nombre" id="nuevoNombre" class="form-control" required>
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" class="btn btn-primary">Guardar</button>
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal fade" id="modalEliminarCarpeta" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <form id="formEliminarCarpeta" class="modal-content">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title">Eliminar carpeta</h5>
            <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="ruta" id="eliminarRuta">
            <p>Vas a eliminar <strong id="eliminarNombre"></strong> y todo su contenido.</p>
            <p class="mb-2">Para confirmar, escribe <code>eliminar</code>:</p>
            <input type="text" id="eliminarConfirm" class="form-control" placeholder="eliminar" autocomplete="off">
          </div>
          <div class="modal-footer">
            <button type="submit" id="btnEliminarAceptar" class="btn btn-danger" disabled>Eliminar</button>
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="os-service-dialogs">
<!-- Modal Traducir -->
<div class="modal fade" id="modalTraducir" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">Traducir documento</h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Archivo</label>
            <input id="traducirArchivoKey" class="form-control" readonly>
          </div>
          <div class="form-group col-md-6">
            <label>Idioma destino</label>
            <select id="traducirTarget" class="form-control">
              <!-- default -->
              <option value="es" selected>Spanish (es)</option>

              <option value="af">Afrikaans (af)</option>
              <option value="sq">Albanian (sq)</option>
              <option value="am">Amharic (am)</option>
              <option value="ar">Arabic (ar)</option>
              <option value="hy">Armenian (hy)</option>
              <option value="az">Azerbaijani (az)</option>
              <option value="bn">Bengali (bn)</option>
              <option value="bs">Bosnian (bs)</option>
              <option value="bg">Bulgarian (bg)</option>
              <option value="ca">Catalan (ca)</option>
              <option value="zh">Chinese (Simplified) (zh)</option>
              <option value="zh-TW">Chinese (Traditional) (zh-TW)</option>
              <option value="hr">Croatian (hr)</option>
              <option value="cs">Czech (cs)</option>
              <option value="da">Danish (da)</option>
              <option value="fa-AF">Dari (fa-AF)</option>
              <option value="nl">Dutch (nl)</option>
              <option value="en">English (en)</option>
              <option value="et">Estonian (et)</option>
              <option value="fa">Farsi (Persian) (fa)</option>
              <option value="tl">Filipino / Tagalog (tl)</option>
              <option value="fi">Finnish (fi)</option>
              <option value="fr">French (fr)</option>
              <option value="fr-CA">French (Canada) (fr-CA)</option>
              <option value="ka">Georgian (ka)</option>
              <option value="de">German (de)</option>
              <option value="el">Greek (el)</option>
              <option value="gu">Gujarati (gu)</option>
              <option value="ht">Haitian Creole (ht)</option>
              <option value="ha">Hausa (ha)</option>
              <option value="he">Hebrew (he)</option>
              <option value="hi">Hindi (hi)</option>
              <option value="hu">Hungarian (hu)</option>
              <option value="is">Icelandic (is)</option>
              <option value="id">Indonesian (id)</option>
              <option value="ga">Irish (ga)</option>
              <option value="it">Italian (it)</option>
              <option value="ja">Japanese (ja)</option>
              <option value="kn">Kannada (kn)</option>
              <option value="kk">Kazakh (kk)</option>
              <option value="ko">Korean (ko)</option>
              <option value="lv">Latvian (lv)</option>
              <option value="lt">Lithuanian (lt)</option>
              <option value="mk">Macedonian (mk)</option>
              <option value="ms">Malay (ms)</option>
              <option value="ml">Malayalam (ml)</option>
              <option value="mt">Maltese (mt)</option>
              <option value="mr">Marathi (mr)</option>
              <option value="mn">Mongolian (mn)</option>
              <option value="no">Norwegian (Bokmål) (no)</option>
              <option value="ps">Pashto (ps)</option>
              <option value="pl">Polish (pl)</option>
              <option value="pt">Portuguese (Brazil) (pt)</option>
              <option value="pt-PT">Portuguese (Portugal) (pt-PT)</option>
              <option value="pa">Punjabi (pa)</option>
              <option value="ro">Romanian (ro)</option>
              <option value="ru">Russian (ru)</option>
              <option value="sr">Serbian (sr)</option>
              <option value="si">Sinhala (si)</option>
              <option value="sk">Slovak (sk)</option>
              <option value="sl">Slovenian (sl)</option>
              <option value="so">Somali (so)</option>
              <option value="es-MX">Spanish (Mexico) (es-MX)</option>
              <option value="sw">Swahili (sw)</option>
              <option value="sv">Swedish (sv)</option>
              <option value="ta">Tamil (ta)</option>
              <option value="te">Telugu (te)</option>
              <option value="th">Thai (th)</option>
              <option value="tr">Turkish (tr)</option>
              <option value="uk">Ukrainian (uk)</option>
              <option value="ur">Urdu (ur)</option>
              <option value="uz">Uzbek (uz)</option>
              <option value="vi">Vietnamese (vi)</option>
              <option value="cy">Welsh (cy)</option>
            </select>

            <small class="form-text text-muted">El idioma origen se detecta automáticamente.</small>
          </div>
        </div>

        <div id="traducirCargando" class="alert alert-info d-none">
          Procesando… por favor espera.
        </div>

        <div id="traducirResultadoBox" class="d-none">
          <label class="mt-2">Texto traducido</label>
          <div class="d-flex mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary ml-auto" onclick="copiarTraducido()">
              Copiar
            </button>
          </div>
          <textarea id="traducirResultado" class="form-control" rows="12" readonly></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button id="btnTraducirAhora" type="button" class="btn btn-primary">Traducir</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Dividir audio/video -->
<div class="modal fade" id="modalMediaSplit" tabindex="-1" role="dialog" aria-labelledby="mediaSplitTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="mediaSplitTitle">
          <i class="fas fa-scissors mr-2"></i>Dividir archivo
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <div id="mediaSplitStatus" class="alert d-none" role="alert"></div>

        <div class="border rounded p-3 mb-3">
          <div class="small text-muted">Archivo</div>
          <div id="mediaSplitFile" class="font-weight-bold text-break">—</div>
          <div class="small text-muted mt-2">
            Tamaño: <strong id="mediaSplitSize">—</strong>
            <span class="mx-1">·</span>
            Duración: <strong id="mediaSplitDuration">—</strong>
          </div>
        </div>

        <div id="mediaNodeStatus" class="alert alert-secondary mb-3">
          Comprobando el nodo de procesamiento…
        </div>

        <div id="mediaNodeAuthorizationWrap" class="alert alert-warning d-none">
          <div class="custom-control custom-checkbox">
            <input type="checkbox" class="custom-control-input" id="mediaNodeAuthorization">
            <label class="custom-control-label font-weight-bold" for="mediaNodeAuthorization">
              Autorizo encender la EC2 de alto rendimiento para esta tarea
            </label>
          </div>
          <div class="small mt-2">
            El tiempo de uso y su costo estimado quedarán registrados. El nodo se apagará automáticamente cuando quede ocioso.
          </div>
        </div>

        <div id="mediaNodeCost" class="small text-muted mb-3"></div>

        <div class="form-group" id="mediaSplitPartsGroup">
          <label for="mediaSplitParts" class="font-weight-bold">¿En cuántas partes lo deseas dividir?</label>
          <input id="mediaSplitParts"
                 type="number"
                 class="form-control form-control-lg"
                 min="2"
                 max="50"
                 step="1"
                 value="2"
                 inputmode="numeric">
          <small class="form-text text-muted">
            Puedes elegir entre 2 y 50 partes. No hay tamaño mínimo; el máximo por archivo es 8 GB.
          </small>
        </div>

        <div class="alert alert-info mb-2" id="mediaSplitOverlapInfo">
          <i class="fas fa-microphone-lines mr-1"></i>
          <strong>Protección para transcripción:</strong>
          cada corte conserva 10 segundos antes y 10 segundos después para reducir el riesgo de perder una palabra.
        </div>

        <div class="alert alert-light border mb-0" id="mediaSplitResultInfo">
          <i class="fas fa-shield-alt mr-1"></i>
          El archivo original permanece intacto. Las partes se guardarán en la misma carpeta como
          <code>-parte1</code>, <code>-parte2</code>, etc.
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btnMediaSplitSubmit">
          Enviar a procesamiento
        </button>
      </div>
    </div>
  </div>
</div>


<!-- Modal Transcribir audio/video -->
<div class="modal fade" id="modalTranscribir" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">Transcribir audio/video</h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group col-md-12">
            <label>Archivo (S3 Key)</label>
            <input id="txArchivoKey" class="form-control" readonly>
            <input type="hidden" id="txFolderKey">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Nombre del trabajo</label>
            <input id="txJobName" class="form-control">
            <small class="form-text text-muted">Se llena automáticamente con el nombre del archivo sin extensión.</small>
          </div>
          <div class="form-group col-md-6">
            <label>Carpeta de salida</label>
            <input id="txFolderView" class="form-control" readonly>
            <small class="form-text text-muted">La transcripción se guardará en esta misma carpeta.</small>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Modo de idioma</label>
            <select id="txLanguageMode" class="form-control">
              <option value="specific" selected>Idioma específico</option>
              <option value="auto">Identificación automática de idiomas</option>
              <option value="auto_multi">Identificación automática de varios idiomas</option>
            </select>
          </div>
          <div id="txLanguageSpecificBox" class="form-group col-md-6">
            <label>Idioma del audio</label>
            <select id="txLanguage" class="form-control">
              <option value="es-ES" selected>Español (es-ES)</option>
              <option value="es-US">Español US (es-US)</option>
              <option value="en-US">English US (en-US)</option>
              <option value="en-GB">English UK (en-GB)</option>
              <option value="pt-BR">Português BR (pt-BR)</option>
              <option value="pt-PT">Português PT (pt-PT)</option>
              <option value="fr-FR">Français (fr-FR)</option>
              <option value="fr-CA">Français CA (fr-CA)</option>
              <option value="de-DE">Deutsch (de-DE)</option>
              <option value="it-IT">Italiano (it-IT)</option>
              <option value="ja-JP">日本語 (ja-JP)</option>
              <option value="ko-KR">한국어 (ko-KR)</option>
              <option value="zh-CN">中文(简体) (zh-CN)</option>
              <option value="zh-TW">中文(繁體) (zh-TW)</option>
              <option value="ar-SA">العربية SA (ar-SA)</option>
              <option value="ar-AE">العربية AE (ar-AE)</option>
              <option value="nl-NL">Nederlands (nl-NL)</option>
              <option value="sv-SE">Svenska (sv-SE)</option>
              <option value="fi-FI">Suomi (fi-FI)</option>
              <option value="da-DK">Dansk (da-DK)</option>
              <option value="no-NO">Norsk Bokmål (no-NO)</option>
              <option value="pl-PL">Polski (pl-PL)</option>
              <option value="tr-TR">Türkçe (tr-TR)</option>
              <option value="ru-RU">Русский (ru-RU)</option>
              <option value="uk-UA">Українська (uk-UA)</option>
              <option value="hi-IN">हिन्दी (hi-IN)</option>
              <option value="id-ID">Bahasa Indonesia (id-ID)</option>
              <option value="cs-CZ">Čeština (cs-CZ)</option>
              <option value="el-GR">Ελληνικά (el-GR)</option>
              <option value="ro-RO">Română (ro-RO)</option>
              <option value="hu-HU">Magyar (hu-HU)</option>
              <option value="sk-SK">Slovenčina (sk-SK)</option>
              <option value="sl-SI">Slovenščina (sl-SI)</option>
              <option value="bg-BG">Български (bg-BG)</option>
              <option value="et-EE">Eesti (et-EE)</option>
              <option value="lv-LV">Latviešu (lv-LV)</option>
              <option value="lt-LT">Lietuvių (lt-LT)</option>
              <option value="vi-VN">Tiếng Việt (vi-VN)</option>
              <option value="th-TH">ไทย (th-TH)</option>
            </select>
          </div>
        </div>

        <div id="txLanguageOptionsBox" class="border rounded p-3 mb-3 d-none">
          <label class="d-block mb-2">Idiomas a considerar en la detección automática (opcional)</label>
          <div class="form-row">
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="txLanguageOptions[]" value="es-US" id="txLangOptEsUs"><label class="form-check-label" for="txLangOptEsUs">es-US</label></div></div>
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="txLanguageOptions[]" value="es-ES" id="txLangOptEsEs"><label class="form-check-label" for="txLangOptEsEs">es-ES</label></div></div>
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="txLanguageOptions[]" value="en-US" id="txLangOptEnUs"><label class="form-check-label" for="txLangOptEnUs">en-US</label></div></div>
            <div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="txLanguageOptions[]" value="en-GB" id="txLangOptEnGb"><label class="form-check-label" for="txLangOptEnGb">en-GB</label></div></div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Tipo de modelo</label>
            <select id="txModelType" class="form-control">
              <option value="general" selected>Modelo general</option>
              <option value="custom">Modelo de idioma personalizado</option>
            </select>
          </div>
          <div id="txCustomModelBox" class="form-group col-md-6 d-none">
            <label>Nombre del modelo personalizado</label>
            <input id="txCustomLanguageModelName" class="form-control" placeholder="Nombre del modelo">
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-12">
            <label>Formato de archivo de subtítulos</label>
            <div class="border rounded p-2">
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="txSubtitleFormats[]" id="txSubtitleSrt" value="srt">
                <label class="form-check-label" for="txSubtitleSrt">SRT</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="txSubtitleFormats[]" id="txSubtitleVtt" value="vtt">
                <label class="form-check-label" for="txSubtitleVtt">VTT</label>
              </div>
              <small class="form-text text-muted">Si no eliges ninguno, Amazon generará el resultado JSON con el nombre del trabajo en la misma carpeta.</small>
            </div>
          </div>
        </div>

        <hr>
        <h6>Configuración avanzada</h6>

        <div class="border rounded p-3 mb-3">
          <label class="d-block mb-2">Configuración de audio</label>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="txAudioIdType" id="txAudioNone" value="none" checked>
            <label class="form-check-label" for="txAudioNone">Ninguna</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="txAudioIdType" id="txAudioChannel" value="channel">
            <label class="form-check-label" for="txAudioChannel">Identificación de canales</label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="txAudioIdType" id="txAudioSpeaker" value="speaker">
            <label class="form-check-label" for="txAudioSpeaker">Partición de voces</label>
          </div>
          <div id="txSpeakerBox" class="form-group mb-0 d-none">
            <label>Cantidad máxima de voces</label>
            <input id="txMaxSpeakerLabels" type="number" min="2" max="30" value="10" class="form-control">
          </div>
        </div>

        <div class="border rounded p-3 mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="txShowAlternatives">
            <label class="form-check-label" for="txShowAlternatives">Habilitar resultados alternativos</label>
          </div>
          <div id="txAlternativesBox" class="form-group mb-0 d-none">
            <label>Cantidad máxima de resultados alternativos</label>
            <input id="txMaxAlternatives" type="number" min="2" max="10" value="2" class="form-control">
          </div>
        </div>

        <div class="border rounded p-3 mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="txEnableContentRedaction">
            <label class="form-check-label" for="txEnableContentRedaction">Redacción de información de identificación personal (PII)</label>
          </div>
          <div id="txPiiBox" class="form-group mb-0 d-none">
            <label>Tipos de entidad PII (separados por coma, opcional)</label>
            <input id="txPiiEntityTypes" class="form-control" placeholder="BANK_ACCOUNT_NUMBER,CREDIT_DEBIT_NUMBER,PHONE">
          </div>
        </div>

        <div class="border rounded p-3 mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="txToxicityDetection">
            <label class="form-check-label" for="txToxicityDetection">Detección de toxicidad</label>
          </div>
          <div id="txToxicityBox" class="d-none">
            <label class="d-block mb-2">Categorías de toxicidad (opcional)</label>
            <div class="form-row">
              <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="txToxicityCategories[]" value="ALL" id="txToxAll"><label class="form-check-label" for="txToxAll">ALL</label></div></div>
              <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="txToxicityCategories[]" value="HATE_SPEECH" id="txToxHate"><label class="form-check-label" for="txToxHate">HATE_SPEECH</label></div></div>
              <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="txToxicityCategories[]" value="HARASSMENT_OR_ABUSE" id="txToxHarassment"><label class="form-check-label" for="txToxHarassment">HARASSMENT_OR_ABUSE</label></div></div>
            </div>
          </div>
        </div>

        <div class="border rounded p-3 mb-3">
          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Vocabulario personalizado</label>
              <input id="txVocabularyName" class="form-control" placeholder="Nombre del vocabulary">
            </div>
            <div class="form-group col-md-6">
              <label>Filtrado de vocabulario</label>
              <input id="txVocabularyFilterName" class="form-control" placeholder="Nombre del vocabulary filter">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-6 mb-0">
              <label>Método del filtro</label>
              <select id="txVocabularyFilterMethod" class="form-control">
                <option value="remove" selected>remove</option>
                <option value="mask">mask</option>
                <option value="tag">tag</option>
              </select>
            </div>
            <div class="form-group col-md-6 mb-0">
              <div class="form-check mt-4 pt-2">
                <input class="form-check-input" type="checkbox" id="txMedicalPhi">
                <label class="form-check-label" for="txMedicalPhi">Identificación PHI (requiere Transcribe Medical)</label>
              </div>
            </div>
          </div>
        </div>

        <div id="txCargando" class="alert alert-info d-none">Creando trabajo de transcripción…</div>
        <div id="txEstadoInfo" class="alert alert-secondary d-none"></div>

        <div id="txResultadoBox" class="d-none">
          <label class="mt-2">Transcripción</label>
          <div class="d-flex mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary ml-auto" onclick="copiarTx()">Copiar</button>
          </div>
          <textarea id="txResultado" class="form-control" rows="12" readonly></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button id="btnTxIniciar" type="button" class="btn btn-primary">Crear trabajo</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Textract -->
<div class="modal fade" id="modalTextract" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title">Texto extraído (Textract)</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <div class="form-row">
          <div class="form-group col-md-12">
            <label>Archivo</label>
            <input id="textractArchivoKey" class="form-control" readonly>
          </div>
        </div>

        <div id="textractCargando" class="alert alert-info d-none">
          Procesando documento… por favor espera.
        </div>

        <div id="textractResultadoBox" class="d-none">
          <label class="mt-2">Texto detectado</label>
          <div class="d-flex mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary ml-auto" onclick="copiarTextract()">
              Copiar
            </button>
          </div>
          <textarea id="textractResultado" class="form-control" rows="12" readonly></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Polly TTS -->
<div class="modal fade" id="modalPollyTTS" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-secondary text-white">
        <h5 class="modal-title">Leer texto (Polly TTS)</h5>
        <input type="hidden" id="pollyArchivoKey" value="">
        <button type="button" class="close text-white" data-dismiss="modal">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body">

        <!-- NUEVO: archivo visible -->
        <div class="form-row">
          <div class="form-group col-md-12">
            <label>Archivo origen</label>
            <input id="pollyArchivoKeyView" class="form-control" readonly>
            <small class="form-text text-muted">
              El audio se guardará en la misma carpeta del archivo origen.
            </small>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-4">
            <label>Idioma</label>
            <select id="pollyLanguage" class="form-control">
              <option value="es-ES" selected>Español (España) - es-ES</option>
              <option value="es-MX">Español (México) - es-MX</option>
              <option value="es-US">Español (EE.UU.) - es-US</option>
              <option value="en-US">Inglés (EE.UU.) - en-US</option>
              <option value="en-GB">Inglés (Reino Unido) - en-GB</option>
              <option value="pt-BR">Portugués (Brasil) - pt-BR</option>
              <option value="fr-FR">Francés (Francia) - fr-FR</option>
              <option value="de-DE">Alemán - de-DE</option>
              <option value="it-IT">Italiano - it-IT</option>
              <option value="ja-JP">Japonés - ja-JP</option>
              <option value="ko-KR">Coreano - ko-KR</option>
            </select>
            <small class="form-text text-muted">Esto filtra las voces disponibles.</small>
          </div>

          <div class="form-group col-md-4">
            <label>Voz</label>
            <select id="pollyVoice" class="form-control">
              <option value="" selected>— Cargando voces… —</option>
            </select>
            <small id="pollyEngineHelp" class="form-text text-muted"></small>
          </div>

          <div class="form-group col-md-4">
            <label>Motor</label>
            <select id="pollyEngine" class="form-control">
              <option value="neural" selected>Neural</option>
              <option value="standard">Standard</option>
            </select>
            <small class="form-text text-muted">Si la voz no soporta Neural, cambia a Standard.</small>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group col-md-8">
            <label>Texto a leer</label>
            <textarea id="pollyTexto" class="form-control" rows="6"
                      placeholder="Escribe o pega texto a leer (máx ~3000 caracteres)"></textarea>
          </div>

          <div class="form-group col-md-4">
            <label>Formato</label>
            <select id="pollyFormat" class="form-control">
              <option value="mp3" selected>MP3</option>
              <option value="ogg_vorbis">OGG Vorbis</option>
              <option value="pcm">PCM (raw)</option>
            </select>

            <label class="mt-2">Frecuencia</label>
            <select id="pollySample" class="form-control">
              <option value="22050" selected>22050 Hz</option>
              <option value="24000">24000 Hz</option>
              <option value="16000">16000 Hz</option>
              <option value="8000">8000 Hz</option>
            </select>

            <div class="custom-control custom-switch mt-3">
              <input type="checkbox" class="custom-control-input" id="pollyToS3" checked>
              <label class="custom-control-label" for="pollyToS3">
                Guardar en S3 (sobrescribe si existe)
              </label>
            </div>
            <small class="form-text text-muted">
              Se guardará en la misma carpeta que el TXT (mismo nombre encriptado, distinta extensión).
            </small>
          </div>
        </div>

        <div id="pollyCargando" class="alert alert-info d-none">Generando audio…</div>

        <div id="pollyPlayerBox" class="d-none">
          <hr>
          <audio id="pollyAudio" controls style="width:100%"></audio>
          <div class="mt-2 d-flex">
            <a id="pollyDownload" class="btn btn-outline-primary btn-sm" download="polly.mp3">Descargar</a>
            <div id="pollyS3Note" class="small text-muted ml-3"></div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button id="btnPollyGenerar" type="button" class="btn btn-primary">Generar audio</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Análisis de imagen (Rekognition) -->
<div class="modal fade" id="modalRekognition" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">Análisis de imagen (Rekognition)</h5>
        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="rekogLoading" class="alert alert-info d-none">Analizando…</div>

        <div id="rekogMeta" class="small text-muted mb-2"></div>

        <div id="rekogModerationBox" class="alert alert-warning d-none">
          <strong>Contenido moderación:</strong>
          <ul id="rekogModerationList" class="mb-0"></ul>
        </div>

        <div class="table-responsive">
          <table class="table table-sm table-bordered">
            <thead class="thead-light">
              <tr>
                <th>Etiqueta</th>
                <th>Conf.</th>
                <th>Padres</th>
                <th>#Instancias</th>
              </tr>
            </thead>
            <tbody id="rekogLabelsBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">

        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Amazon Comprehend -->
<div class="modal fade" id="modalComprehend" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="comprehendTitle">Amazon Comprehend</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body" id="comprehendBody">
        <div class="text-muted">Selecciona un archivo de texto para analizarlo.</div>
      </div>
      <div class="modal-footer">
        <small class="text-muted mr-auto">Entidades · frases clave · sentimiento · PII</small>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

  </div>

  <div class="modal fade" id="modalAcercaArcadeCloud" tabindex="-1" role="dialog" aria-labelledby="modalAcercaArcadeCloudLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="modalAcercaArcadeCloudLabel"><i class="fas fa-cloud mr-2"></i>ArcadeCloud Drive</h5>
            <small class="text-muted">Drive web para Amazon S3</small>
          </div>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p><strong>ArcadeCloud Drive</strong> mantiene la navegación y organización lógica en MySQL mientras Amazon S3 conserva el almacenamiento físico de los archivos.</p>
          <div class="alert alert-info">
            <strong>Software libre.</strong> ArcadeCloud Drive se distribuye bajo GNU General Public License v3.0 (GPLv3).
          </div>
          <div class="mb-3">
            <div><strong>Proyecto / autor:</strong> jimmybackend</div>
            <div><strong>Repositorio:</strong> github.com/jimmybackend/s3</div>
            <div><strong>Licencia:</strong> GNU GPL v3.0</div>
          </div>
          <small class="d-block text-muted mt-3">La sección de actualización sólo se muestra a superusuarios y reutiliza el actualizador seguro del Drive clásico.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade os-share-modal" id="modalCompartir" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-share-nodes mr-2"></i>Compartir archivo</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="form-group mb-2">
            <label for="diasCompartir" class="mb-1">Días de vigencia</label>
            <div class="input-group">
              <input type="number" id="diasCompartir" class="form-control" min="1" max="3650" step="1" value="1" inputmode="numeric">
              <div class="input-group-append"><span class="input-group-text">día(s)</span></div>
            </div>
            <small class="form-text text-muted">Expira el: <strong id="fechaExpiraLabel">—</strong></small>
          </div>
          <div class="form-group mb-1">
            <label for="enlaceCompartido" class="mb-1">Enlace directo</label>
            <div class="input-group">
              <input type="text" class="form-control" id="enlaceCompartido" readonly>
              <div class="input-group-append">
                <button class="btn btn-outline-info" type="button" id="btnCopyLink"><i class="fas fa-copy mr-1"></i>Copiar</button>
              </div>
            </div>
            <small id="copyStatus" class="form-text" style="opacity:0;transition:opacity .2s">&nbsp;</small>
            <small id="sharePanelStatus" class="small text-muted">El enlace se generará con la expiración indicada.</small>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($isSuperAdmin): ?>
  <div class="modal fade os-share-modal" id="nodeMemoryClearModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-broom mr-2"></i>Liberar memoria del nodo</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">Ejecuta <code>sync</code> y libera page cache, dentries e inodes. No termina procesos.</p>
          <p class="small text-info">Si existen tareas de procesamiento, quedará en la cola y se ejecutará después de ellas.</p>
          <label for="nodeMemoryPassword">Contraseña privada</label>
          <input type="password" class="form-control" id="nodeMemoryPassword" data-node-memory-password autocomplete="current-password">
          <div class="mt-2 small text-muted" data-node-memory-status>Lista para programarse.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-info" data-node-memory-submit><i class="fas fa-broom mr-1"></i>Programar limpieza</button>
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade os-share-modal" id="nodeDiskCleanModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="fas fa-hard-drive mr-2"></i>Liberar espacio de disco</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">Limpia únicamente temporales conocidos de ArcadeCloud, cachés regenerables, logs rotados antiguos y journal archivado.</p>
          <ul class="small text-muted pl-3 mb-2">
            <li>No borra archivos de usuarios ni objetos S3.</li>
            <li>No toca base de datos, sesiones, uploads activos ni colas.</li>
            <li>No elimina logs actuales.</li>
          </ul>
          <p class="small text-info">Si existen tareas de procesamiento, quedará en la cola y se ejecutará después de ellas.</p>
          <label for="nodeDiskPassword">Contraseña privada</label>
          <input type="password" class="form-control" id="nodeDiskPassword" data-node-disk-password autocomplete="current-password">
          <div class="mt-2 small text-muted" data-node-disk-status>Lista para programarse.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-info" data-node-disk-submit><i class="fas fa-broom mr-1"></i>Programar limpieza</button>
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade os-share-modal" id="nodeDatabaseBackupConfirmModal" tabindex="-1" role="dialog" aria-labelledby="nodeDatabaseBackupConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="nodeDatabaseBackupConfirmTitle"><i class="fas fa-database mr-2"></i>Crear respaldo de base de datos</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted mb-2">Se exportará la base de datos activa completa y se guardará como archivo privado en la carpeta <strong>Backup</strong> de tu Drive.</p>
          <p class="small text-info mb-0">ArcadeCloud verificará tablas, filas, vistas, procedimientos, funciones, triggers y eventos antes de guardar el respaldo.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-info" data-node-db-backup-continue><i class="fas fa-arrow-right mr-1"></i>Continuar</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade os-share-modal" id="nodeDatabaseBackupPasswordModal" tabindex="-1" role="dialog" aria-labelledby="nodeDatabaseBackupPasswordTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form class="modal-content" data-node-db-backup-form autocomplete="on">
        <div class="modal-header">
          <h5 class="modal-title" id="nodeDatabaseBackupPasswordTitle"><i class="fas fa-shield-halved mr-2"></i>Confirmar superadministrador</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">Confirma tu contraseña actual para autorizar el respaldo.</p>
          <div class="form-group mb-2">
            <label for="nodeDatabaseBackupUsername">Cuenta</label>
            <input type="text"
                   class="form-control"
                   id="nodeDatabaseBackupUsername"
                   name="username"
                   value="<?= $e($userIdentifier) ?>"
                   autocomplete="username"
                   readonly>
          </div>
          <div class="form-group mb-2">
            <label for="nodeDatabaseBackupPassword">Contraseña actual</label>
            <input type="password"
                   class="form-control"
                   id="nodeDatabaseBackupPassword"
                   data-node-db-backup-password
                   autocomplete="current-password"
                   required>
          </div>
          <div class="small text-muted" data-node-db-backup-status>El navegador puede ofrecer aquí la contraseña guardada.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-info" data-node-db-backup-submit><i class="fas fa-database mr-1"></i>Crear respaldo</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="os-launcher" id="osLauncher" hidden>
    <div class="os-launcher-header">
      <div class="os-launcher-profile">
        <?php if ($userAvatarUrl !== ''): ?>
          <img src="<?= $e($userAvatarUrl) ?>" alt="Perfil">
        <?php else: ?>
          <span class="os-avatar"><?= $e($userInitials) ?></span>
        <?php endif; ?>
        <div>
          <strong><?= $e($userAlias) ?></strong>
          <span>ArcadeCloud OS</span>
        </div>
        <?php if ($isSuperAdmin): ?>
        <button type="button"
                class="os-launcher-power"
                data-fastdrive-force-stop
                title="Apagar FastDrive"
                aria-label="Apagar FastDrive">
          <i class="fas fa-power-off"></i>
        </button>
        <?php endif; ?>
      </div>
    </div>
    <button type="button" data-window-open="nodeWindow"><i class="fas fa-server"></i> Mi nodo</button>
    <button type="button" data-app-open="explorer"><i class="fas fa-folder-open"></i> Mis datos</button>
    <button type="button" data-window-open="appsWindow"><i class="fas fa-shapes"></i> Aplicaciones</button>
    <a href="notebook.php"><i class="fas fa-book-open"></i> Notebook</a>
    <a href="s3.php"><i class="fas fa-hard-drive"></i> Drive clásico</a>
    <a href="dataword3d.php"><i class="fas fa-cubes"></i> Drive 3D</a>
    <button type="button" data-window-open="settingsWindow"><i class="fas fa-gear"></i> Configuración</button>
    <button type="button" data-window-open="linksWindow"><i class="fas fa-link"></i> Enlaces</button>
    <button type="button" data-os-reload><i class="fas fa-rotate-right"></i> Actualizar</button>
    <button type="button" data-os-about data-toggle="modal" data-target="#modalAcercaArcadeCloud"><i class="fas fa-circle-info"></i> Acerca de</button>
    <a href="logout.php" class="is-danger"><i class="fas fa-right-from-bracket"></i> Cerrar sesión</a>
  </div>

  <?php if ($isSuperAdmin): ?>
  <div class="modal fade" id="fastDrivePowerModal" tabindex="-1" role="dialog" aria-labelledby="fastDrivePowerTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content os-power-modal">
        <div class="modal-header">
          <h5 class="modal-title" id="fastDrivePowerTitle"><i class="fas fa-power-off mr-2"></i>Apagar FastDrive</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        </div>
        <div class="modal-body">
          <p>Esta orden apaga la EC2 grande aunque ArcadeCloud detecte tareas o una sesión gráfica activa.</p>
          <p class="os-power-warning"><strong>Los registros y colas no se eliminan.</strong> Una tarea que esté ejecutándose se interrumpe y puede necesitar reintento después del siguiente arranque.</p>
          <label for="fastDrivePowerPassword">Contraseña actual del superadmin</label>
          <input type="password"
                 class="form-control"
                 id="fastDrivePowerPassword"
                 autocomplete="current-password"
                 placeholder="Contraseña">
          <div id="fastDrivePowerMessage" class="os-power-message" aria-live="polite"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-danger" data-fastdrive-power-accept>
            <i class="fas fa-power-off mr-1"></i>Apagar ahora
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="os-task-context" id="osTaskContext" hidden>
    <div class="os-context-name" id="osTaskContextName">Ventana</div>
    <button type="button" data-task-action="minimize"><i class="fas fa-minus"></i><span>Minimizar</span></button>
    <button type="button" data-task-action="maximize"><i class="far fa-square"></i><span>Maximizar</span></button>
    <button type="button" data-task-action="close" class="is-danger"><i class="fas fa-xmark"></i><span>Cerrar</span></button>
  </div>

  <footer class="os-taskbar">
    <button type="button" class="os-start" id="osStart" aria-expanded="false" title="Inicio" aria-label="Inicio">
      <i class="fas fa-cloud"></i>
    </button>
    <div class="os-taskbar-right">
    <div class="os-task-buttons" id="osTaskButtons"></div>
    <button type="button" class="os-node-health-button" id="osNodeHealthButton" aria-expanded="false" aria-controls="osNodeHealthPopover" title="Estado de Mi nodo">
      <span class="os-status-light is-neutral" data-node-health-light></span>
      <span class="sr-only">Estado de Mi nodo</span>
    </button>
    <button type="button" class="os-resource-history-button" id="osResourceHistoryButton" data-window-open="nodeWindow" title="Recursos del nodo: CPU, RAM, red y disco" aria-label="Abrir recursos de Mi nodo">
      <span class="os-resource-spark-row"><small>C</small><canvas width="42" height="8" data-node-spark="cpu"></canvas></span>
      <span class="os-resource-spark-row"><small>R</small><canvas width="42" height="8" data-node-spark="memory"></canvas></span>
      <span class="os-resource-spark-row"><small>N</small><canvas width="42" height="8" data-node-spark="network"></canvas></span>
      <span class="os-resource-spark-row"><small>D</small><canvas width="42" height="8" data-node-spark="disk"></canvas></span>
    </button>
    <?php if ($isSuperAdmin): ?>
    <button type="button" class="os-node-health-button os-replica-health-button" id="osReplicaHealthButton" data-window-open="federationWindow" title="Nodo federado autorizado en línea" hidden>
      <span class="os-status-light is-ok"></span>
      <i class="fas fa-copy" aria-hidden="true"></i>
      <span class="os-replica-count" data-replica-online-count>0</span>
    </button>
    <?php endif; ?>
    <button type="button" class="os-task-center-button" id="osTaskCenterButton" aria-label="Tareas en segundo plano" title="Tareas en segundo plano">
      <i class="fas fa-list-check"></i><span class="os-task-center-count">0</span>
    </button>
    <div class="os-clock" id="osClock"></div>
    </div>
  </footer>

  <section class="os-node-health-popover" id="osNodeHealthPopover" hidden aria-live="polite">
    <div class="os-node-health-popover-head">
      <strong data-node-health-name>NODO LOCAL</strong>
      <button type="button" data-node-health-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="os-node-health-state"><span class="os-status-light is-neutral" data-node-health-popover-light></span><strong data-node-health-label>Consultando…</strong></div>
    <div class="os-node-health-server"><span>Servidor</span><strong data-node-health-server>—</strong></div>
    <button type="button" class="os-node-health-open" data-window-open="nodeWindow"><i class="fas fa-server"></i> Abrir Mi nodo</button>
  </section>

  <script>
    window.UPLOAD_API = 'api/upload.php';
    window.DRIVE_UPLOAD_CSRF = <?= json_encode($uploadCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.ARCADECLOUD_OS_APPEARANCE = {
      endpoint: 'os-preferences.php',
      csrf: window.DRIVE_UPLOAD_CSRF,
      nodeKey: <?= json_encode($osPreferenceNodeKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      preferences: <?= json_encode($osPreferences, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
    window.DRIVE_INITIAL_ROUTE = <?= json_encode($currentRoute, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.rutaActual = <?= json_encode($currentRoute, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.AWS_BUCKET_NAME = <?= json_encode($app->bucket(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.ARCADECLOUD_OS_ROOT_ROUTE = <?= json_encode($userRoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.ARCADECLOUD_OS_CURRENT_FOLDER = <?= json_encode([
      'route' => $currentRoute,
      'name' => $currentFolderName,
      'is_root' => $currentIsRoot,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    <?php if ($isSuperAdmin): ?>
    window.ARCADECLOUD_OS_SERVER_CONSOLE = {
      endpoint: 'server-console.php',
      csrf: <?= json_encode($serverConsoleCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
    window.ARCADECLOUD_UPDATER = {
      csrf: <?= json_encode($serverAdminCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
    window.ARCADECLOUD_FASTDRIVE_POWER = {
      endpoint: 'fastdrive-power.php',
      csrf: <?= json_encode($fastDrivePowerCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
    window.ARCADECLOUD_OS_NODE = {
      endpoint: 'node-status.php',
      csrf: <?= json_encode($serverAdminCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      superadmin: true
    };
    <?php else: ?>
    window.ARCADECLOUD_OS_NODE = { endpoint: 'node-status.php', csrf: '', superadmin: false };
    <?php endif; ?>
  </script>
  <script src="js/polly.js?v=<?= (int)filemtime(__DIR__ . '/js/polly.js') ?>"></script>
  <script src="js/aws-comprehend.js?v=<?= (int)filemtime(__DIR__ . '/js/aws-comprehend.js') ?>"></script>
  <script src="js/media-processing.js?v=<?= (int)filemtime(__DIR__ . '/js/media-processing.js') ?>"></script>
  <script data-background-task-feedback src="js/background-task-feedback.js?v=<?= (int)filemtime(__DIR__ . '/js/background-task-feedback.js') ?>"></script>
  <script data-polly-background src="js/polly-background.js?v=<?= (int)filemtime(__DIR__ . '/js/polly-background.js') ?>"></script>
  <script data-transcribe-background src="js/transcribe-background.js?v=<?= (int)filemtime(__DIR__ . '/js/transcribe-background.js') ?>"></script>
  <script data-background-tasks src="js/background-tasks.js?v=<?= (int)filemtime(__DIR__ . '/js/background-tasks.js') ?>"></script>
  <script src="js/filesystem-operations.js?v=<?= (int)filemtime(__DIR__ . '/js/filesystem-operations.js') ?>"></script>
  <script src="js/move-tasks.js?v=<?= (int)filemtime(__DIR__ . '/js/move-tasks.js') ?>"></script>
  <script src="js/carpetas.js?v=<?= (int)filemtime(__DIR__ . '/js/carpetas.js') ?>"></script>
  <script src="js/folder-document.js?v=<?= (int)filemtime(__DIR__ . '/js/folder-document.js') ?>"></script>
  <script src="js/so-new-text-file.js?v=<?= (int)filemtime(__DIR__ . '/js/so-new-text-file.js') ?>"></script>
  <script src="js/sincronizar.js?v=<?= (int)filemtime(__DIR__ . '/js/sincronizar.js') ?>"></script>
  <script src="js/so-folders.js?v=<?= (int)filemtime(__DIR__ . '/js/so-folders.js') ?>"></script>
  <script src="js/so-federation.js?v=<?= (int)filemtime(__DIR__ . '/js/so-federation.js') ?>"></script>
  <script src="js/federation-portal.js?v=<?= (int)filemtime(__DIR__ . '/js/federation-portal.js') ?>"></script>
  <script src="js/federation-share-drive.js?v=<?= (int)filemtime(__DIR__ . '/js/federation-share-drive.js') ?>"></script>
  <?php if ($isSuperAdmin): ?>
  <script src="js/so-terminal.js?v=<?= (int)filemtime(__DIR__ . '/js/so-terminal.js') ?>"></script>
  <?php endif; ?>
  <script src="js/upload-destination.js?v=<?= (int)filemtime(__DIR__ . '/js/upload-destination.js') ?>"></script>
  <script src="js/upload-center.js?v=<?= (int)filemtime(__DIR__ . '/js/upload-center.js') ?>"></script>
  <script data-drive-updater src="js/arcadecloud-updater.js?v=<?= (int)filemtime(__DIR__ . '/js/arcadecloud-updater.js') ?>"></script>
  <script src="js/compute-node-idle.js?v=<?= (int)filemtime(__DIR__ . '/js/compute-node-idle.js') ?>"></script>
  <?php if ($isSuperAdmin): ?>
  <script src="js/so-power.js?v=<?= (int)filemtime(__DIR__ . '/js/so-power.js') ?>"></script>
  <?php endif; ?>
  <script src="js/file-security.js?v=<?= (int)filemtime(__DIR__ . '/js/file-security.js') ?>"></script>
  <script src="js/so-search.js?v=<?= (int)filemtime(__DIR__ . '/js/so-search.js') ?>"></script>
  <script src="js/arcadelink-share.js?v=<?= (int)filemtime(__DIR__ . '/js/arcadelink-share.js') ?>"></script>
  <script src="js/so-share.js?v=<?= (int)filemtime(__DIR__ . '/js/so-share.js') ?>"></script>
  <script src="js/so-node.js?v=<?= (int)filemtime(__DIR__ . '/js/so-node.js') ?>"></script>
  <script src="js/so-appearance.js?v=<?= (int)filemtime(__DIR__ . '/js/so-appearance.js') ?>"></script>
  <script src="js/os-window-manager.js?v=<?= (int)filemtime(__DIR__ . '/js/os-window-manager.js') ?>"></script>
  <script src="js/os-media-cloud.js?v=<?= (int)filemtime(__DIR__ . '/js/os-media-cloud.js') ?>"></script>
  <script src="js/file-applications.js?v=<?= (int)filemtime(__DIR__ . '/js/file-applications.js') ?>"></script>
  <script src="js/so.js?v=<?= (int)filemtime(__DIR__ . '/js/so.js') ?>"></script>
  <script src="js/so-clipboard.js?v=<?= (int)filemtime(__DIR__ . '/js/so-clipboard.js') ?>"></script>
  <script src="js/page-task-manager.js?v=<?= (int)filemtime(__DIR__ . '/js/page-task-manager.js') ?>"></script>
  <script src="js/desktop-shell.js?v=<?= (int)filemtime(__DIR__ . '/js/desktop-shell.js') ?>"></script>
</body>
</html>
