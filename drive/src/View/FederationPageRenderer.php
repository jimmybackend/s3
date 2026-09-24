<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

use ArcadeCloud\Drive\Federation\FederationDropConfig;

final class FederationPageRenderer
{
    public function render(
        int $userId,
        ?array $inspected = null,
        ?string $notice = null,
        ?string $error = null,
        ?array $node = null
    ): void {
        $h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $dropConfig = FederationDropConfig::fromEnvironment();
        $sourceHost = (string)(parse_url((string)getenv('ARCADECLOUD_PUBLIC_URL'), PHP_URL_HOST) ?: '');
        $dropUrl = rtrim($dropConfig->commerceUrl, '/') . '/?source=' . rawurlencode($sourceHost);

        $statusLabels = [
            'available' => 'Disponible',
            'not_available' => 'No disponible',
            'private_auth_required' => 'Requiere autenticación',
            'secure_resource_requires_drive' => 'Protegido en el Drive de origen',
            'content_changed' => 'Contenido modificado',
            'origin_identity_mismatch' => 'Identidad de origen no coincide',
            'origin_unavailable' => 'Origen sin confirmar',
            'origin_unreachable' => 'Nodo origen no disponible',
        ];

        $items = [];
        if (is_array($inspected['items'] ?? null)) {
            foreach ($inspected['items'] as $item) {
                if (!is_array($item) || !is_array($item['resource'] ?? null)) continue;
                $items[] = [
                    'resource' => $item['resource'],
                    'raw' => is_string($item['raw'] ?? null) ? $item['raw'] : '',
                ];
            }
        } elseif (is_array($inspected['resource'] ?? null)) {
            $items[] = [
                'resource' => $inspected['resource'],
                'raw' => is_string($inspected['raw'] ?? null) ? $inspected['raw'] : '',
            ];
        }

        $isCollection = !empty($inspected['collection']);
        $collectionTitle = trim((string)($inspected['title'] ?? ''));
        $iconFor = static function (array $resource): string {
            $title = trim((string)($resource['title'] ?? ''));
            $mediaType = strtolower(trim((string)($resource['media_type'] ?? $resource['resource_type'] ?? '')));
            $extension = strtolower(pathinfo($title, PATHINFO_EXTENSION));
            if (str_starts_with($mediaType, 'image/') || in_array($extension, ['jpg','jpeg','png','gif','webp','bmp','avif','tif','tiff'], true)) {
                return 'fa-file-image';
            }
            if ($mediaType === 'application/pdf' || $extension === 'pdf') return 'fa-file-pdf';
            if (str_starts_with($mediaType, 'audio/') || in_array($extension, ['mp3','wav','ogg','opus','m4a','aac','flac'], true)) {
                return 'fa-file-audio';
            }
            if (str_starts_with($mediaType, 'video/') || in_array($extension, ['mp4','webm','mov','avi','mkv'], true)) {
                return 'fa-file-video';
            }
            if (str_starts_with($mediaType, 'text/') || in_array($extension, ['txt','md','html','css','js','php','py','json','csv','sql'], true)) {
                return 'fa-file-lines';
            }
            if (in_array($extension, ['zip','rar','7z','tar','gz'], true)) return 'fa-file-zipper';
            return 'fa-file';
        };

        $driveRoot = dirname(__DIR__, 2);
        $federationCssVersion = is_file($driveRoot . '/css/federation.css') ? (int)filemtime($driveRoot . '/css/federation.css') : 1;
        $stylesVersion = is_file($driveRoot . '/css/styles.css') ? (int)filemtime($driveRoot . '/css/styles.css') : 1;
        $responsiveVersion = is_file($driveRoot . '/css/responsive.css') ? (int)filemtime($driveRoot . '/css/responsive.css') : 1;
        $pageJsVersion = is_file($driveRoot . '/js/federation-page.js') ? (int)filemtime($driveRoot . '/js/federation-page.js') : 1;
        ?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>ArcadeLink · Cloud Drive</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="icon" href="../ellogo.png" type="image/png">
  <link rel="stylesheet" href="../css/styles.css?v=<?= $stylesVersion ?>">
  <link rel="stylesheet" href="../css/responsive.css?v=<?= $responsiveVersion ?>">
  <link rel="stylesheet" href="../css/federation.css?v=<?= $federationCssVersion ?>">
</head>
<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on">
<nav class="navbar navbar-expand-lg navbar-dark px-3 drive-navbar">
  <a class="navbar-brand d-flex align-items-center" href="../s3.php" title="Volver al Drive">
    <img src="../ellogo.png" width="48" height="38" class="drive-brand-logo mr-2" alt="Logo">
    Cloud Drive
  </a>
  <div class="ml-auto d-flex align-items-center">
    <span class="small text-muted mr-3 d-none d-md-inline">FederationCloud</span>
    <a class="btn btn-info btn-sm mr-2" rel="noopener noreferrer" href="<?= $h($dropUrl) ?>">
      <i class="fas fa-cloud-arrow-up mr-1"></i> Subir / pagar
    </a>
    <a class="btn btn-outline-light btn-sm" href="../s3.php">
      <i class="fas fa-arrow-left mr-1"></i> Drive
    </a>
  </div>
</nav>

<main class="federation-shell">
  <div class="federation-shell-inner">
    <div class="federation-title-row">
      <div>
        <h1><i class="fas fa-link mr-2"></i>Abrir ArcadeLink</h1>
        <p class="federation-subtitle">Un solo <code>.arcadelink</code> puede representar un archivo o una colección completa.</p>
      </div>
      <?php if ($items !== []): ?>
        <a href="./" class="btn btn-outline-primary btn-sm">
          <i class="fas fa-rotate mr-1"></i> Validar otro ArcadeLink
        </a>
      <?php endif; ?>
    </div>

    <div id="federationClientError" class="alert alert-danger federation-alert d-none" role="alert"></div>
    <?php if ($error): ?>
      <div class="alert alert-danger federation-alert" role="alert">
        <i class="fas fa-triangle-exclamation mr-1"></i><?= $h($error) ?>
      </div>
    <?php elseif ($notice && $items === []): ?>
      <div class="alert alert-success federation-alert" role="status">
        <i class="fas fa-circle-check mr-1"></i><?= $h($notice) ?>
      </div>
    <?php endif; ?>

    <?php if ($items === []): ?>
      <section class="federation-card p-3 p-md-4">
        <form method="post" enctype="multipart/form-data" id="inspectForm">
          <input type="hidden" name="action" value="inspect">
          <input id="arcadeFile" class="federation-file-input" type="file" name="arcadelink_file" accept=".arcadelink,application/json,text/plain" required>
          <div id="dropZone" class="federation-dropzone" role="button" tabindex="0" aria-controls="arcadeFile">
            <div>
              <span id="dropZoneIcon" class="federation-dropzone-icon"><i class="fas fa-cloud-arrow-up"></i></span>
              <div id="dropZoneTitle" class="federation-dropzone-title">Suelta aquí tu ArcadeLink</div>
              <div id="dropZoneText" class="small text-muted">o haz clic para seleccionar el archivo</div>
            </div>
          </div>
        </form>
      </section>
    <?php else: ?>
      <div class="alert alert-success federation-alert" role="status">
        <i class="fas fa-circle-check mr-1"></i>
        <strong>ArcadeLink validado.</strong>
        <?php if ($isCollection): ?>
          <?= $h($collectionTitle !== '' ? $collectionTitle : 'Colección') ?> contiene <?= count($items) ?> recurso<?= count($items) === 1 ? '' : 's' ?>.
        <?php else: ?>
          Firma y origen comprobados.
        <?php endif; ?>
      </div>

      <?php foreach ($items as $position => $item):
          $resource = $item['resource'];
          $raw = $item['raw'];
          $title = trim((string)($resource['title'] ?? 'Recurso ArcadeLink'));
          $mediaType = strtolower(trim((string)($resource['media_type'] ?? $resource['resource_type'] ?? 'application/octet-stream')));
          $status = (string)($resource['status'] ?? '');
          $fileIcon = $iconFor($resource);
          $resourceId = trim((string)($resource['resource_id'] ?? ''));
          $isPublicCopy = strtoupper((string)($resource['visibility'] ?? '')) === 'PUBLIC'
              && (string)($resource['rights'] ?? '') === 'copy_allowed'
              && $resourceId !== '';
          $dropResourceUrl = $dropConfig->commerceUrl !== '' && $isPublicCopy
              ? rtrim($dropConfig->commerceUrl, '/') . '/?' . http_build_query([
                  'source' => $sourceHost,
                  'resource_id' => $resourceId,
              ], '', '&', PHP_QUERY_RFC3986)
              : '';
          $copyResourceUrl = $isPublicCopy
              ? './portal.php?view=search&q=' . rawurlencode($resourceId)
              : '';
      ?>
        <section class="federation-card mb-3">
          <div class="federation-resource">
            <div class="federation-resource-icon" aria-hidden="true">
              <i class="fas <?= $h($fileIcon) ?>"></i>
            </div>
            <div class="federation-resource-main">
              <?php if ($isCollection): ?>
                <div class="small text-muted mb-1">Recurso <?= $position + 1 ?> de <?= count($items) ?></div>
              <?php endif; ?>
              <h2 class="federation-resource-name"><?= $h($title !== '' ? $title : 'Recurso ArcadeLink') ?></h2>
              <div class="federation-resource-meta">
                <span><?= $h(FileViewHelper::formatBytes((int)($resource['size_bytes'] ?? 0))) ?></span>
                <span>·</span>
                <span><?= $h($statusLabels[$status] ?? $status) ?></span>
                <span class="badge badge-info"><?= $h($resource['visibility'] ?? '') ?></span>
                <span class="badge badge-secondary"><?= $h($resource['rights'] ?? '') ?></span>
              </div>
            </div>
            <div class="federation-resource-actions">
              <?php if (!empty($resource['external_url']) && !empty($resource['can_open'])): ?>
                <a class="btn btn-primary" rel="noopener noreferrer" href="<?= $h($resource['external_url']) ?>">
                  <i class="fas fa-download mr-1"></i> Descargar
                </a>
              <?php elseif (!empty($resource['can_open']) && !empty($resource['local']) && $raw !== ''): ?>
                <form method="post" class="m-0">
                  <input type="hidden" name="action" value="open">
                  <input type="hidden" name="arcadelink_text" value="<?= $h($raw) ?>">
                  <button type="submit" class="btn btn-primary">
                    <i class="fas fa-arrow-up-right-from-square mr-1"></i> Abrir
                  </button>
                </form>
              <?php elseif (!empty($resource['request_access_url'])): ?>
                <a class="btn btn-warning" rel="noopener noreferrer" href="<?= $h($resource['request_access_url']) ?>">
                  <i class="fas fa-key mr-1"></i> Solicitar clave / acceso
                </a>
              <?php elseif (empty($resource['local']) && !empty($resource['origin_reachable'])): ?>
                <a class="btn btn-primary" rel="noopener noreferrer" href="<?= $h($resource['federation_url'] ?? '#') ?>">
                  <i class="fas fa-network-wired mr-1"></i> Ir al nodo
                </a>
              <?php endif; ?>
              <?php if ($isPublicCopy && $copyResourceUrl !== ''): ?>
                <a class="btn btn-outline-info" href="<?= $h($copyResourceUrl) ?>">
                  <i class="fas fa-folder-plus mr-1"></i> Copiar a Mi Drive
                </a>
              <?php endif; ?>
              <?php if ($isPublicCopy && $dropResourceUrl !== ''): ?>
                <a class="btn btn-outline-warning" rel="noopener noreferrer" href="<?= $h($dropResourceUrl) ?>">
                  <i class="fas fa-clock mr-1"></i> FederationDrop temporal
                </a>
              <?php endif; ?>
            </div>
          </div>

          <div class="federation-verified-strip text-success">
            <i class="fas fa-circle-check"></i>
            <strong>Firma verificada</strong>
            <span class="text-muted">· nodo <?= $h($resource['origin_node_id'] ?? '') ?></span>
          </div>

          <details class="federation-details mt-3">
            <summary>Detalles del recurso</summary>
            <dl class="federation-details-grid">
              <dt>Resource ID</dt><dd><?= $h($resource['resource_id'] ?? '') ?></dd>
              <dt>Nodo origen</dt><dd><?= $h($resource['origin_node_id'] ?? '') ?></dd>
              <dt>Tipo</dt><dd><?= $h($mediaType !== '' ? $mediaType : 'application/octet-stream') ?></dd>
              <dt>Content ID</dt><dd><?= $h($resource['content_id'] ?? 'No publicado') ?></dd>
              <dt>Emitido</dt><dd><?= $h($resource['issued_at'] ?? '') ?></dd>
            </dl>
          </details>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($node): ?>
      <div class="federation-node-hint">
        Nodo <?= $h($node['node_name'] ?? $node['node_id'] ?? '') ?> · FederationCloud
      </div>
    <?php endif; ?>
  </div>
</main>

<script src="../js/federation-page.js?v=<?= $pageJsVersion ?>"></script>
</body>
</html><?php
    }
}
