<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$rutaActualFooter = isset($footerRutaActual)
    ? (string) $footerRutaActual
    : (string) ($_SESSION['ruta_actual'] ?? 'Data/');

$espacioUsadoFooter = isset($footerEspacioUsado)
    ? (string) $footerEspacioUsado
    : '0 B';

$isFederationAdmin = trim((string)($_SESSION['system_role'] ?? 'user')) === 'superadmin';
$federationProviderCsrf = '';
if ($isFederationAdmin) {
    $existingCsrf = $_SESSION['federation_provider_csrf'] ?? null;
    if (!is_string($existingCsrf) || !preg_match('/\A[a-f0-9]{64}\z/', $existingCsrf)) {
        $_SESSION['federation_provider_csrf'] = bin2hex(random_bytes(32));
    }
    $federationProviderCsrf = (string)$_SESSION['federation_provider_csrf'];
}

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$federationFooterJs = __DIR__ . '/js/federation-footer.js';
?>
<footer class="drive-footer">
  <div class="text-muted small drive-footer-route">
    <i class="fas fa-folder-open mr-1"></i>
    <strong id="footerRutaActual"><?= $e($rutaActualFooter) ?></strong>
  </div>

  <div class="text-muted small drive-footer-storage">
    <i class="fas fa-network-wired mr-1" aria-hidden="true"></i>
    Nodo: <strong id="footerFederationNode" class="text-info">consultando…</strong>
    <span aria-hidden="true"> · </span>
    Nodos conectados: <strong id="footerFederationPeers" class="text-info">—</strong>
    <?php if ($isFederationAdmin): ?>
      <span aria-hidden="true"> · </span>
      <button
        type="button"
        id="btnFederationProviderRequests"
        class="btn btn-link btn-sm p-0 align-baseline text-warning"
        data-toggle="modal"
        data-target="#modalFederationProviderRequests"
        data-csrf="<?= $e($federationProviderCsrf) ?>"
        title="Revisar solicitudes de nodos proveedores">
        Solicitudes: <strong id="footerFederationRequests">—</strong>
      </button>
    <?php endif; ?>
    <span aria-hidden="true"> · </span>
    Espacio usado: <strong id="footerEspacioUsado" class="text-info"><?= $e($espacioUsadoFooter) ?></strong>
  </div>

  <div class="text-muted small drive-footer-clock">
    <span id="relojFooter"><strong><?= date('Y-m-d H:i:s') ?></strong></span>
  </div>
</footer>

<?php if ($isFederationAdmin): ?>
<div class="modal fade" id="modalFederationProviderRequests" tabindex="-1" role="dialog" aria-labelledby="modalFederationProviderRequestsLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content bg-dark text-light border-secondary">
      <div class="modal-header border-secondary">
        <div>
          <h5 class="modal-title" id="modalFederationProviderRequestsLabel">
            <i class="fas fa-server mr-1"></i> Proveedores FederationCloud
          </h5>
          <div class="small text-muted">Sólo superusuarios pueden aprobar, rechazar o revocar nodos.</div>
        </div>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div id="federationProviderAdminAlert" class="alert d-none" role="alert"></div>

        <div class="d-flex align-items-center justify-content-between mb-2">
          <h6 class="mb-0">Solicitudes pendientes</h6>
          <span id="federationProviderPendingBadge" class="badge badge-warning">0</span>
        </div>
        <div id="federationProviderPendingList" class="mb-4">
          <div class="text-muted small">Cargando solicitudes…</div>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-2">
          <h6 class="mb-0">Proveedores autorizados</h6>
          <span id="federationProviderActiveBadge" class="badge badge-success">0</span>
        </div>
        <div id="federationProviderActiveList">
          <div class="text-muted small">Cargando proveedores…</div>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-outline-light" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (is_file($federationFooterJs)): ?>
<script src="js/federation-footer.js?v=<?= (int) filemtime($federationFooterJs) ?>"></script>
<?php endif; ?>
