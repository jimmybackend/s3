<?php
declare(strict_types=1);

use ArcadeCloud\Drive\System\NodeCapabilityService;
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

try {
    $profile = $app->userProfileService()->profile($userId);
    $userAlias = (string)($profile['alias'] ?? $userAlias);
    $userInitials = (string)($profile['initials'] ?? $userInitials);
    $userAvatarUrl = (string)($profile['avatar_url'] ?? '');
} catch (Throwable) {
    // El shell puede funcionar con las iniciales aunque el perfil no cargue.
}

$userRoot = $app->userStorageProvisioner()->ensureRoot($userId);
$currentRoute = $app->userStoragePath()->normalizeForUser(
    (string)($_GET['ruta'] ?? $userRoot),
    $userId
);
$visibleRoute = $app->folderQueryService()->displayPathForUser($userId, $currentRoute);

$page = max(1, (int)($_GET['pagina'] ?? 1));
$fileState = $app->fileListService()->load($userId, $currentRoute, [
    'pagina' => $page,
    'limite' => 60,
]);
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

$textExtensions = ['txt','md','markdown','html','htm','css','js','json','csv','sql','php','py','srt','vtt','log','xml','yaml','yml'];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>ArcadeCloud OS</title>
  <link rel="icon" href="ellogo.png" type="image/png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/so.css?v=<?= (int)filemtime(__DIR__ . '/css/so.css') ?>">
</head>
<body class="arcade-os">
  <header class="os-topbar">
    <div class="os-brand">
      <img src="ellogo.png" alt="" width="34" height="28">
      <div>
        <strong>ArcadeCloud OS</strong>
        <small>Estación de trabajo en la nube</small>
      </div>
    </div>
    <div class="os-node-chip" title="Nodo actual">
      <i class="fas fa-server"></i>
      <span><?= $e($nodeName) ?></span>
    </div>
    <div class="os-user">
      <?php if ($userAvatarUrl !== ''): ?>
        <img src="<?= $e($userAvatarUrl) ?>" alt="Perfil">
      <?php else: ?>
        <span class="os-avatar"><?= $e($userInitials) ?></span>
      <?php endif; ?>
      <span><?= $e($userAlias) ?></span>
    </div>
  </header>

  <main class="os-desktop" id="osDesktop">
    <button class="os-desktop-icon" type="button" data-window-open="explorerWindow">
      <span class="os-icon-tile"><i class="fas fa-folder-open"></i></span>
      <span>Mis archivos</span>
    </button>

    <button class="os-desktop-icon" type="button" data-window-open="nodeWindow">
      <span class="os-icon-tile"><i class="fas fa-server"></i></span>
      <span>Mi nodo</span>
    </button>

    <a class="os-desktop-icon" href="s3.php">
      <span class="os-icon-tile"><i class="fas fa-hard-drive"></i></span>
      <span>Drive clásico</span>
    </a>

    <button class="os-desktop-icon" type="button" data-window-open="appsWindow">
      <span class="os-icon-tile"><i class="fas fa-shapes"></i></span>
      <span>Aplicaciones</span>
    </button>

    <section class="os-window is-open is-active" id="explorerWindow" data-window-title="Explorador" style="left:7vw;top:8vh;width:min(1050px,86vw);height:min(680px,72vh);">
      <div class="os-window-titlebar" data-window-drag-handle>
        <div class="os-window-title"><i class="fas fa-folder-open"></i><span>Explorador</span></div>
        <div class="os-window-controls">
          <button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button>
          <button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button>
          <button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button>
        </div>
      </div>

      <div class="os-explorer-toolbar">
        <?php if ($currentPrefix !== $rootPrefix): ?>
          <a class="os-tool-button" href="so.php?ruta=<?= rawurlencode($parentRoute) ?>" title="Subir una carpeta">
            <i class="fas fa-arrow-up"></i>
          </a>
        <?php else: ?>
          <span class="os-tool-button is-disabled" aria-hidden="true"><i class="fas fa-arrow-up"></i></span>
        <?php endif; ?>
        <a class="os-tool-button" href="so.php?ruta=<?= rawurlencode($currentRoute) ?>" title="Actualizar">
          <i class="fas fa-rotate"></i>
        </a>
        <div class="os-address">
          <i class="fas fa-folder"></i>
          <span><?= $e($visibleRoute) ?></span>
        </div>
        <span class="os-storage"><?= $e((string)($storageUsage['formatted'] ?? '0 B')) ?> usados</span>
      </div>

      <div class="os-window-body os-explorer-body">
        <div class="os-entry-grid">
          <?php foreach ($folders as $folder): ?>
            <a class="os-entry os-folder-entry"
               href="so.php?ruta=<?= rawurlencode((string)$folder['prefix']) ?>"
               title="<?= $e($folder['name']) ?>">
              <span class="os-entry-icon"><i class="fas fa-folder"></i></span>
              <span class="os-entry-name"><?= $e($folder['name']) ?></span>
              <span class="os-entry-meta">Carpeta</span>
            </a>
          <?php endforeach; ?>

          <?php foreach ($files as $row): ?>
            <?php
              $name = (string)($row['Nombre'] ?? '');
              $ext = FileViewHelper::extension($name);
              $icon = FileIconResolver::resolve($ext);
              $locked = FileViewHelper::isLocked($row);
              $key = FileViewHelper::buildS3Key((string)($row['Ruta'] ?? ''), (string)($row['Encriptado'] ?? ''));
              $keyQ = rawurlencode($key);
              $openUrl = $locked ? '' : 'ver_archivo.php?archivo=' . $keyQ;
              $downloadUrl = $locked ? '' : 'descargar_archivo.php?archivo=' . $keyQ . '&nombre=' . rawurlencode($name);
              $editUrl = (!$locked && in_array($ext, $textExtensions, true))
                  ? 'editor.php?archivo=' . $keyQ
                  : '';
            ?>
            <button type="button"
                    class="os-entry os-file-entry<?= $locked ? ' is-locked' : '' ?>"
                    data-name="<?= $e($name) ?>"
                    data-ext="<?= $e($ext) ?>"
                    data-open-url="<?= $e($openUrl) ?>"
                    data-download-url="<?= $e($downloadUrl) ?>"
                    data-edit-url="<?= $e($editUrl) ?>"
                    data-classic-url="s3.php"
                    data-locked="<?= $locked ? '1' : '0' ?>"
                    title="<?= $e($name) ?>">
              <span class="os-entry-menu" aria-hidden="true"><i class="fas fa-ellipsis-vertical"></i></span>
              <span class="os-entry-icon os-file-type-<?= $e($icon['category']) ?>">
                <i class="fas <?= $e($locked ? 'fa-lock' : $icon['icon']) ?>"></i>
              </span>
              <span class="os-entry-name"><?= $e($name) ?></span>
              <span class="os-entry-meta"><?= $e($locked ? 'Protegido' : $formatBytes((int)($row['Tamano'] ?? 0))) ?></span>
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
        <span><?= count($folders) ?> carpetas · <?= (int)$fileState['total'] ?> archivos</span>
        <a href="s3.php"><i class="fas fa-arrow-up-right-from-square"></i> Abrir Drive clásico</a>
      </div>
    </section>

    <section class="os-window" id="nodeWindow" data-window-title="Mi nodo" style="left:18vw;top:14vh;width:min(720px,78vw);height:min(560px,68vh);">
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
            <p>Este es el nodo que está sirviendo tu sesión. ArcadeCloud comprobará sus recursos antes de habilitar aplicaciones pesadas.</p>
          </div>
        </div>

        <div class="os-stat-grid">
          <article><span>Instancia</span><strong><?= $e((string)($nodeSnapshot['instance_type'] ?: 'no identificada')) ?></strong></article>
          <article><span>vCPU</span><strong><?= (int)$nodeSnapshot['vcpu'] ?></strong></article>
          <article><span>RAM total</span><strong><?= $e($formatBytes((int)$nodeSnapshot['memory_total_bytes'])) ?></strong></article>
          <article><span>RAM disponible</span><strong><?= $e($formatBytes((int)$nodeSnapshot['memory_available_bytes'])) ?></strong></article>
          <article><span>Disco libre</span><strong><?= $e($formatBytes((int)$nodeSnapshot['disk_free_bytes'])) ?></strong></article>
          <article><span>Swap</span><strong><?= $e($formatBytes((int)$nodeSnapshot['swap_total_bytes'])) ?></strong></article>
          <article><span>Carga</span><strong><?= $e(implode(' · ', array_map('strval', $load))) ?></strong></article>
          <article><span>Rol</span><strong><?= $e((string)$nodeSnapshot['role']) ?></strong></article>
        </div>

        <div class="os-capability-list">
          <div><span>FFmpeg</span><strong class="<?= $nodeSnapshot['ffmpeg_available'] ? 'is-ready' : 'is-missing' ?>"><?= $nodeSnapshot['ffmpeg_available'] ? 'Disponible' : 'No disponible' ?></strong></div>
          <div><span>FFprobe</span><strong class="<?= $nodeSnapshot['ffprobe_available'] ? 'is-ready' : 'is-missing' ?>"><?= $nodeSnapshot['ffprobe_available'] ? 'Disponible' : 'No disponible' ?></strong></div>
          <div><span>Docker</span><strong class="<?= $nodeSnapshot['docker_installed'] ? 'is-ready' : 'is-missing' ?>"><?= $nodeSnapshot['docker_installed'] ? 'Instalado' : 'No instalado' ?></strong></div>
          <div><span>GPU</span><strong class="<?= $nodeSnapshot['gpu_present'] ? 'is-ready' : 'is-neutral' ?>"><?= $nodeSnapshot['gpu_present'] ? 'Detectada' : 'No detectada' ?></strong></div>
          <div><span>Raíz de almacenamiento</span><strong><?= $e($visibleRoute) ?></strong></div>
          <?php if ($isSuperAdmin && (string)$nodeSnapshot['instance_id'] !== ''): ?>
            <div><span>EC2 Instance ID</span><strong><?= $e((string)$nodeSnapshot['instance_id']) ?></strong></div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="os-window" id="appsWindow" data-window-title="Aplicaciones" style="left:24vw;top:11vh;width:min(760px,76vw);height:min(570px,68vh);">
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
          <button type="button" class="os-app-card is-ready" data-window-open="explorerWindow"><i class="fas fa-folder-open"></i><strong>Explorador</strong><span>Disponible</span></button>
          <a class="os-app-card is-ready" href="s3.php"><i class="fas fa-hard-drive"></i><strong>Drive clásico</strong><span>Disponible</span></a>
          <button type="button" class="os-app-card" disabled><i class="fas fa-file-word"></i><strong>Office</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-pen-ruler"></i><strong>Diagramas</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-image"></i><strong>Imagen</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-wave-square"></i><strong>Audio</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-film"></i><strong>Video</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-file-pdf"></i><strong>PDF</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-cubes"></i><strong>3D</strong><span>Próximamente</span></button>
          <button type="button" class="os-app-card" disabled><i class="fas fa-earth-americas"></i><strong>Mapas</strong><span>Próximamente</span></button>
        </div>
      </div>
    </section>
  </main>

  <div class="os-file-context" id="fileContextMenu" hidden>
    <div class="os-context-name" id="fileContextName">Archivo</div>
    <button type="button" data-file-action="open"><i class="fas fa-eye"></i>Abrir</button>
    <button type="button" data-file-action="edit"><i class="fas fa-pen"></i>Editar texto</button>
    <button type="button" data-file-action="download"><i class="fas fa-download"></i>Descargar</button>
    <button type="button" data-file-action="classic"><i class="fas fa-hard-drive"></i>Abrir en Drive clásico</button>
  </div>

  <div class="os-launcher" id="osLauncher" hidden>
    <div class="os-launcher-header">
      <strong>ArcadeCloud OS</strong>
      <span><?= $e($userAlias) ?></span>
    </div>
    <button type="button" data-window-open="explorerWindow"><i class="fas fa-folder-open"></i> Explorador</button>
    <button type="button" data-window-open="nodeWindow"><i class="fas fa-server"></i> Mi nodo</button>
    <button type="button" data-window-open="appsWindow"><i class="fas fa-shapes"></i> Aplicaciones</button>
    <a href="s3.php"><i class="fas fa-hard-drive"></i> Drive clásico</a>
    <a href="logout.php" class="is-danger"><i class="fas fa-right-from-bracket"></i> Cerrar sesión</a>
  </div>

  <footer class="os-taskbar">
    <button type="button" class="os-start" id="osStart" aria-expanded="false">
      <img src="ellogo.png" alt="" width="25" height="21">
      <span>ArcadeCloud</span>
    </button>
    <div class="os-task-buttons" id="osTaskButtons"></div>
    <div class="os-clock" id="osClock"></div>
  </footer>

  <script src="js/so.js?v=<?= (int)filemtime(__DIR__ . '/js/so.js') ?>"></script>
</body>
</html>
