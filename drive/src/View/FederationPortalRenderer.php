<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FederationPortalRenderer
{
    public function render(bool $authenticated, ?array $node = null): void
    {
        $h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $driveRoot = dirname(__DIR__, 2);
        $stylesVersion = is_file($driveRoot . '/css/styles.css') ? (int)filemtime($driveRoot . '/css/styles.css') : 1;
        $responsiveVersion = is_file($driveRoot . '/css/responsive.css') ? (int)filemtime($driveRoot . '/css/responsive.css') : 1;
        $federationVersion = is_file($driveRoot . '/css/federation.css') ? (int)filemtime($driveRoot . '/css/federation.css') : 1;
        $portalCssVersion = is_file($driveRoot . '/css/federation-portal.css') ? (int)filemtime($driveRoot . '/css/federation-portal.css') : 1;
        $themeBridgeVersion = is_file($driveRoot . '/js/theme-state-bridge.js') ? (int)filemtime($driveRoot . '/js/theme-state-bridge.js') : 1;
        $portalJsVersion = is_file($driveRoot . '/js/federation-portal.js') ? (int)filemtime($driveRoot . '/js/federation-portal.js') : 1;
        $shareDriveJsVersion = is_file($driveRoot . '/js/federation-share-drive.js') ? (int)filemtime($driveRoot . '/js/federation-share-drive.js') : 1;
        $nodeId = is_array($node) ? (string)($node['node_id'] ?? '') : '';
        ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>FederationCloud · ArcadeCloud Drive</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="icon" href="../ellogo.png" type="image/png">
  <link rel="stylesheet" href="../css/styles.css?v=<?= $stylesVersion ?>">
  <link rel="stylesheet" href="../css/responsive.css?v=<?= $responsiveVersion ?>">
  <link rel="stylesheet" href="../css/federation.css?v=<?= $federationVersion ?>">
  <link rel="stylesheet" href="../css/federation-portal.css?v=<?= $portalCssVersion ?>">
  <script defer src="../js/theme-state-bridge.js?v=<?= $themeBridgeVersion ?>"></script>
</head>
<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on federation-portal-body" data-federation-node-id="<?= $h($nodeId) ?>">
<nav class="navbar navbar-expand-lg navbar-dark px-3 drive-navbar">
  <a class="navbar-brand d-flex align-items-center" href="../s3.php" title="Volver al Drive">
    <img src="../ellogo.png" width="48" height="38" class="drive-brand-logo mr-2" alt="Logo">
    Cloud Drive
  </a>
  <div class="ml-auto d-flex align-items-center flex-wrap">
    <a class="btn btn-outline-info btn-sm mr-2" href="./"><i class="fas fa-link mr-1"></i>ArcadeLink</a>
    <a class="btn btn-outline-light btn-sm" href="../s3.php"><i class="fas fa-arrow-left mr-1"></i>Drive</a>
  </div>
</nav>

<main class="federation-portal-shell">
  <div class="federation-portal-header">
    <div>
      <h1><i class="fas fa-globe mr-2"></i>FederationCloud</h1>
      <p class="text-muted mb-0">Catálogo global local, solicitudes, Shares y réplicas físicas sin listar S3 ni depender de un nodo matriz.</p>
    </div>
    <?php if ($node): ?>
      <div class="federation-node-chip">
        <span class="small text-muted">Nodo</span>
        <strong><?= $h($node['node_name'] ?? $node['node_id'] ?? 'FederationCloud') ?></strong>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$authenticated): ?>
    <div class="alert alert-warning mt-4">
      Debes iniciar sesión en ArcadeCloud Drive para usar el portal FederationCloud.
      <a class="alert-link" href="../">Volver al inicio</a>.
    </div>
  <?php else: ?>
    <div id="federationPortalAlert" class="alert d-none" role="alert"></div>

    <ul class="nav nav-tabs federation-portal-tabs" id="federationPortalTabs" role="tablist">
      <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#federationSearchPane" role="tab"><i class="fas fa-search mr-1"></i>Buscar global</a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#federationRequestsPane" role="tab"><i class="fas fa-inbox mr-1"></i>Solicitudes <span id="federationIncomingBadge" class="badge badge-warning ml-1">0</span></a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#federationSharesPane" role="tab"><i class="fas fa-folder-tree mr-1"></i>Compartidos <span id="federationSharesBadge" class="badge badge-info ml-1">0</span></a></li>
      <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#federationReplicasPane" role="tab"><i class="fas fa-copy mr-1"></i>Réplicas <span id="federationReplicaBadge" class="badge badge-secondary ml-1">0</span></a></li>
    </ul>

    <div class="tab-content federation-portal-content">
      <section class="tab-pane fade show active" id="federationSearchPane" role="tabpanel">
        <div class="federation-panel">
          <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
            <div>
              <h2 class="h5 mb-1">Catálogo global</h2>
              <div class="small text-muted">La búsqueda consulta <strong>FederatedResources</strong> local; no pregunta a todos los nodos en tiempo real.</div>
            </div>
          </div>
          <form id="federationSearchForm" class="form-inline federation-search-form">
            <label class="sr-only" for="federationSearchInput">Buscar</label>
            <input id="federationSearchInput" class="form-control flex-grow-1 mr-sm-2" minlength="2" maxlength="160" autocomplete="off" placeholder="Buscar archivo, tipo o recurso..." required>
            <button class="btn btn-info mt-2 mt-sm-0" type="submit"><i class="fas fa-search mr-1"></i>Buscar</button>
          </form>
          <div id="federationSearchSummary" class="small text-muted mt-3"></div>
          <div id="federationSearchResults" class="federation-result-list mt-3">
            <div class="text-muted small">Escribe al menos 2 caracteres para buscar en la réplica local del catálogo global.</div>
          </div>
        </div>
      </section>

      <section class="tab-pane fade" id="federationRequestsPane" role="tabpanel">
        <div class="row">
          <div class="col-lg-6 mb-3"><div class="federation-panel h-100"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">Recibidas</h2><span id="federationIncomingCount" class="badge badge-secondary">0</span></div><div id="federationIncomingList"><div class="small text-muted">Cargando...</div></div></div></div>
          <div class="col-lg-6 mb-3"><div class="federation-panel h-100"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">Enviadas</h2><span id="federationOutgoingCount" class="badge badge-secondary">0</span></div><div id="federationOutgoingList"><div class="small text-muted">Cargando...</div></div></div></div>
        </div>
      </section>

      <section class="tab-pane fade" id="federationSharesPane" role="tabpanel">
        <div class="alert alert-info small">
          <strong>Compartidos</strong> es una carpeta lógica. Abrir un Share no lo duplica. Cuando elijas <strong>Agregar a Mi Drive</strong>, se descarga una copia privada a tu raíz <code>DataN/</code>, se registra en <code>FileS3</code> y el Share continúa visible aquí como referencia de origen.
        </div>
        <div class="row">
          <div class="col-lg-6 mb-3"><div class="federation-panel h-100"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">Compartidos/Recibidos</h2><span id="federationReceivedCount" class="badge badge-secondary">0</span></div><div id="federationReceivedShares"><div class="small text-muted">Cargando...</div></div></div></div>
          <div class="col-lg-6 mb-3"><div class="federation-panel h-100"><div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 mb-0">Compartidos/Enviados</h2><span id="federationSentCount" class="badge badge-secondary">0</span></div><div id="federationSentShares"><div class="small text-muted">Cargando...</div></div></div></div>
        </div>
      </section>

      <section class="tab-pane fade" id="federationReplicasPane" role="tabpanel">
        <div class="federation-panel">
          <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
            <div>
              <h2 class="h5 mb-1">Trabajos de réplica</h2>
              <div class="small text-muted">Sólo recursos <code>copy_allowed</code> con SHA-256 conocido. Los bytes viajan S3→proveedor mediante URL prefirmada temporal.</div>
            </div>
            <button id="btnFederationReplicaRefresh" class="btn btn-outline-info btn-sm" type="button"><i class="fas fa-rotate mr-1"></i>Actualizar</button>
          </div>
          <div id="federationReplicaJobs"><div class="small text-muted">Cargando...</div></div>
        </div>
      </section>
    </div>
  <?php endif; ?>
</main>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const requestedView = new URLSearchParams(window.location.search).get('view') || '';
  const panes = {
    search: '#federationSearchPane',
    requests: '#federationRequestsPane',
    shares: '#federationSharesPane',
    replicas: '#federationReplicasPane'
  };
  const pane = panes[requestedView];
  if (!pane) return;
  const link = document.querySelector(`#federationPortalTabs a[href="${pane}"]`);
  if (link && window.jQuery) {
    window.jQuery(link).tab('show');
  }
})();
</script>
<?php if ($authenticated): ?>
<script src="../js/federation-portal.js?v=<?= $portalJsVersion ?>"></script>
<script src="../js/federation-share-drive.js?v=<?= $shareDriveJsVersion ?>"></script>
<?php endif; ?>
</body>
</html>
<?php
    }
}
