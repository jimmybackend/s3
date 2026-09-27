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
$imageExtensions = ['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff'];
$audioExtensions = ['mp3','wav','ogg','opus','m4a','aac','flac'];
$videoExtensions = ['mp4','webm','mov','avi','mkv','m4v','mpeg','mpg'];
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
  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
</head>
<body class="arcade-os">
  <main class="os-desktop" id="osDesktop">
    <button class="os-desktop-icon" type="button" data-window-open="nodeWindow">
      <span class="os-icon-tile"><i class="fas fa-server"></i></span>
      <span>Mi nodo</span>
    </button>

    <button class="os-desktop-icon" type="button" data-window-open="explorerWindow">
      <span class="os-icon-tile"><i class="fas fa-folder-open"></i></span>
      <span>Mis documentos</span>
    </button>

    <button class="os-desktop-icon" type="button" data-window-open="appsWindow">
      <span class="os-icon-tile"><i class="fas fa-shapes"></i></span>
      <span>Aplicaciones</span>
    </button>

    <section class="os-window" id="explorerWindow" data-window-title="Explorador" style="left:7vw;top:8vh;width:min(1050px,86vw);height:min(680px,72vh);">
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

      <div class="os-folder-commandbar"
           data-current-folder-route="<?= $e($currentRoute) ?>"
           data-current-folder-name="<?= $e($currentFolderName) ?>"
           data-current-folder-root="<?= $currentIsRoot ? '1' : '0' ?>">
        <button type="button" data-current-folder-action="sync" title="Sincronizar esta carpeta desde S3">
          <i class="fas fa-rotate"></i><span>Sincronizar</span>
        </button>
        <button type="button" data-current-folder-action="create-document" title="Crear archivo de texto en esta carpeta">
          <i class="fas fa-file-circle-plus"></i><span>Crear archivo</span>
        </button>
        <button type="button" data-current-folder-action="move" title="<?= $currentIsRoot ? 'La raíz del usuario no se puede mover' : 'Mover esta carpeta' ?>" <?= $currentIsRoot ? 'disabled' : '' ?>>
          <i class="fas fa-arrows-alt"></i><span>Mover</span>
        </button>
        <button type="button" data-current-folder-action="rename" title="<?= $currentIsRoot ? 'La raíz del usuario no se puede renombrar' : 'Editar nombre de esta carpeta' ?>" <?= $currentIsRoot ? 'disabled' : '' ?>>
          <i class="fas fa-pen"></i><span>Editar</span>
        </button>
        <button type="button" class="is-danger" data-current-folder-action="delete" title="<?= $currentIsRoot ? 'La raíz del usuario no se puede eliminar' : 'Eliminar esta carpeta' ?>" <?= $currentIsRoot ? 'disabled' : '' ?>>
          <i class="fas fa-trash"></i><span>Eliminar</span>
        </button>
        <span id="syncStatus" class="os-folder-command-status" aria-live="polite"></span>
      </div>

      <div class="os-window-body os-explorer-body"
           data-current-folder-route="<?= $e($currentRoute) ?>"
           data-current-folder-name="<?= $e($currentFolderName) ?>"
           data-current-folder-root="<?= $currentIsRoot ? '1' : '0' ?>">
        <div class="os-entry-grid">
          <?php foreach ($folders as $folder): ?>
            <a class="os-entry os-folder-entry"
               href="so.php?ruta=<?= rawurlencode((string)$folder['prefix']) ?>"
               data-folder-route="<?= $e((string)$folder['prefix']) ?>"
               data-folder-name="<?= $e((string)$folder['name']) ?>"
               data-folder-root="0"
               title="<?= $e($folder['name']) ?>">
              <span class="os-entry-menu os-folder-entry-menu" aria-hidden="true"><i class="fas fa-ellipsis-vertical"></i></span>
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
              $isImage = in_array($ext, $imageExtensions, true);
              $isAudio = in_array($ext, $audioExtensions, true);
              $isVideo = in_array($ext, $videoExtensions, true);
              $canTextract = in_array($ext, $textractExtensions, true);
              $canTranslate = in_array($ext, $translateExtensions, true);
              $canRekognition = in_array($ext, $rekognitionExtensions, true);
              $canTranscribe = in_array($ext, $transcribeExtensions, true);
              $canComprehend = in_array($ext, $comprehendExtensions, true);
              $canPolly = in_array($ext, ['txt','md'], true);
            ?>
            <button type="button"
                    class="os-entry os-file-entry<?= $locked ? ' is-locked' : '' ?>"
                    data-name="<?= $e($name) ?>"
                    data-ext="<?= $e($ext) ?>"
                    data-key="<?= $e($key) ?>"
                    data-bytes="<?= (int)($row['Tamano'] ?? 0) ?>"
                    data-open-url="<?= $e($openUrl) ?>"
                    data-download-url="<?= $e($downloadUrl) ?>"
                    data-edit-url="<?= $e($editUrl) ?>"
                    data-classic-url="s3.php"
                    data-locked="<?= $locked ? '1' : '0' ?>"
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
          <article><span>Disco total</span><strong><?= $e($formatBytes($diskTotalBytes)) ?></strong></article>
          <article><span>Disco usado</span><strong><?= $e($formatBytes($diskUsedBytes)) ?> · <?= $e((string)$diskUsedPercent) ?>%</strong></article>
          <article><span>Disco libre</span><strong><?= $e($formatBytes($diskFreeBytes)) ?></strong></article>
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
          <div><span>Espacio del usuario en S3</span><strong><?= $e((string)($storageUsage['formatted'] ?? '0 B')) ?></strong></div>
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

  <div class="os-file-context os-folder-context" id="folderContextMenu" hidden>
    <div class="os-context-name" id="folderContextName">Carpeta</div>
    <button type="button" data-folder-action="open"><i class="fas fa-folder-open"></i>Abrir</button>
    <button type="button" data-folder-action="sync"><i class="fas fa-rotate"></i>Sincronizar desde S3</button>
    <button type="button" data-folder-action="create-document"><i class="fas fa-file-circle-plus"></i>Crear archivo</button>
    <button type="button" data-folder-action="create-folder"><i class="fas fa-folder-plus"></i>Nueva subcarpeta</button>
    <div class="os-context-divider" data-folder-mutating-divider></div>
    <button type="button" data-folder-action="move" data-folder-mutating><i class="fas fa-arrows-alt"></i>Mover</button>
    <button type="button" data-folder-action="rename" data-folder-mutating><i class="fas fa-pen"></i>Editar nombre</button>
    <button type="button" class="is-danger" data-folder-action="delete" data-folder-mutating><i class="fas fa-trash"></i>Eliminar</button>
  </div>

  <div class="os-file-context" id="fileContextMenu" hidden>
    <div class="os-context-name" id="fileContextName">Archivo</div>
    <button type="button" data-file-action="open"><i class="fas fa-eye"></i>Abrir en ventana</button>
    <button type="button" data-file-action="edit"><i class="fas fa-pen"></i>Editar texto</button>
    <button type="button" data-file-action="download"><i class="fas fa-download"></i>Descargar</button>
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
    <button type="button" data-file-action="classic"><i class="fas fa-hard-drive"></i>Abrir en Drive clásico</button>
  </div>

  <div class="os-folder-dialogs">
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
      </div>
    </div>
    <button type="button" data-window-open="nodeWindow"><i class="fas fa-server"></i> Mi nodo</button>
    <button type="button" data-window-open="explorerWindow"><i class="fas fa-folder-open"></i> Mis archivos</button>
    <button type="button" data-window-open="appsWindow"><i class="fas fa-shapes"></i> Aplicaciones</button>
    <a href="s3.php"><i class="fas fa-hard-drive"></i> Drive clásico</a>
    <a href="logout.php" class="is-danger"><i class="fas fa-right-from-bracket"></i> Cerrar sesión</a>
  </div>

  <div class="os-task-context" id="osTaskContext" hidden>
    <div class="os-context-name" id="osTaskContextName">Ventana</div>
    <button type="button" data-task-action="maximize"><i class="far fa-square"></i><span>Maximizar</span></button>
    <button type="button" data-task-action="close" class="is-danger"><i class="fas fa-xmark"></i><span>Cerrar</span></button>
  </div>

  <footer class="os-taskbar">
    <button type="button" class="os-start" id="osStart" aria-expanded="false" title="Herramientas" aria-label="Herramientas">
      <i class="fas fa-gear"></i>
    </button>
    <div class="os-task-buttons" id="osTaskButtons"></div>
    <div class="os-clock" id="osClock"></div>
  </footer>

  <script>
    window.DRIVE_UPLOAD_CSRF = <?= json_encode($uploadCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.DRIVE_INITIAL_ROUTE = <?= json_encode($currentRoute, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.rutaActual = <?= json_encode($currentRoute, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.AWS_BUCKET_NAME = <?= json_encode($app->bucket(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.ARCADECLOUD_OS_ROOT_ROUTE = <?= json_encode($userRoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.ARCADECLOUD_OS_CURRENT_FOLDER = <?= json_encode([
      'route' => $currentRoute,
      'name' => $currentFolderName,
      'is_root' => $currentIsRoot,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  </script>
  <script src="js/polly.js?v=<?= (int)filemtime(__DIR__ . '/js/polly.js') ?>"></script>
  <script src="js/aws-comprehend.js?v=<?= (int)filemtime(__DIR__ . '/js/aws-comprehend.js') ?>"></script>
  <script src="js/media-processing.js?v=<?= (int)filemtime(__DIR__ . '/js/media-processing.js') ?>"></script>
  <script data-background-task-feedback src="js/background-task-feedback.js?v=<?= (int)filemtime(__DIR__ . '/js/background-task-feedback.js') ?>"></script>
  <script data-polly-background src="js/polly-background.js?v=<?= (int)filemtime(__DIR__ . '/js/polly-background.js') ?>"></script>
  <script data-transcribe-background src="js/transcribe-background.js?v=<?= (int)filemtime(__DIR__ . '/js/transcribe-background.js') ?>"></script>
  <script data-background-tasks src="js/background-tasks.js?v=<?= (int)filemtime(__DIR__ . '/js/background-tasks.js') ?>"></script>
  <script src="js/move-tasks.js?v=<?= (int)filemtime(__DIR__ . '/js/move-tasks.js') ?>"></script>
  <script src="js/carpetas.js?v=<?= (int)filemtime(__DIR__ . '/js/carpetas.js') ?>"></script>
  <script src="js/folder-document.js?v=<?= (int)filemtime(__DIR__ . '/js/folder-document.js') ?>"></script>
  <script src="js/sincronizar.js?v=<?= (int)filemtime(__DIR__ . '/js/sincronizar.js') ?>"></script>
  <script src="js/so-folders.js?v=<?= (int)filemtime(__DIR__ . '/js/so-folders.js') ?>"></script>
  <script src="js/so.js?v=<?= (int)filemtime(__DIR__ . '/js/so.js') ?>"></script>
</body>
</html>
