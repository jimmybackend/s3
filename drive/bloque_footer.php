<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$rutaActualFooter = isset($footerRutaActual) ? (string)$footerRutaActual : (string)($_SESSION['ruta_actual'] ?? 'Data/');
$espacioUsadoFooter = isset($footerEspacioUsado) ? (string)$footerEspacioUsado : '0 B';
$isFederationAdmin = trim((string)($_SESSION['system_role'] ?? 'user')) === 'superadmin';
$federationProviderCsrf = '';
$serverAdminCsrf = '';
if ($isFederationAdmin) {
    $existingCsrf = $_SESSION['federation_provider_csrf'] ?? null;
    if (!is_string($existingCsrf) || !preg_match('/\A[a-f0-9]{64}\z/', $existingCsrf)) {
        $_SESSION['federation_provider_csrf'] = bin2hex(random_bytes(32));
    }
    $federationProviderCsrf = (string)$_SESSION['federation_provider_csrf'];

    $existingServerCsrf = $_SESSION['server_admin_csrf'] ?? null;
    if (!is_string($existingServerCsrf) || !preg_match('/\A[a-f0-9]{64}\z/', $existingServerCsrf)) {
        $_SESSION['server_admin_csrf'] = bin2hex(random_bytes(32));
    }
    $serverAdminCsrf = (string)$_SESSION['server_admin_csrf'];
}

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$federationFooterJs = __DIR__ . '/js/federation-footer.js';
$serverAdminJs = __DIR__ . '/js/server-admin.js';
$arcadeLinkShareJs = __DIR__ . '/js/arcadelink-share.js';
?>
<footer class="drive-footer">
  <div class="text-muted small drive-footer-route">
    <i class="fas fa-folder-open mr-1"></i>
    <strong id="footerRutaActual"><?= $e($rutaActualFooter) ?></strong>
  </div>

  <div class="text-muted small drive-footer-storage">
    <i class="fas fa-network-wired mr-1" aria-hidden="true"></i>
    Nodo: <strong id="footerFederationNode" class="text-info">consultando…</strong>
    <?php if ($isFederationAdmin): ?>
      <button type="button" id="btnFederationNodeIdentity" class="btn btn-link btn-sm p-0 ml-1 align-baseline text-info"
        data-toggle="modal" data-target="#modalFederationNodeIdentity" data-csrf="<?= $e($federationProviderCsrf) ?>" title="Crear o modificar identidad del nodo">
        <i class="fas fa-pen" aria-hidden="true"></i><span class="sr-only">Administrar identidad del nodo</span>
      </button>
    <?php endif; ?>
    <span aria-hidden="true"> · </span>
    Nodos conectados: <strong id="footerFederationPeers" class="text-info">—</strong>
    <?php if ($isFederationAdmin): ?>
      <span aria-hidden="true"> · </span>
      <button type="button" id="btnFederationProviderRequests" class="btn btn-link btn-sm p-0 align-baseline text-warning"
        data-toggle="modal" data-target="#modalFederationProviderRequests" data-csrf="<?= $e($federationProviderCsrf) ?>" title="Revisar solicitudes de nodos proveedores">
        Solicitudes: <strong id="footerFederationRequests">—</strong>
      </button>
      <span aria-hidden="true"> · </span>
      <button type="button" id="btnServerAdmin" class="btn btn-link btn-sm p-0 align-baseline text-info"
        data-toggle="modal" data-target="#modalServerAdmin" data-csrf="<?= $e($serverAdminCsrf) ?>" title="Configuración administrada del servidor">
        <i class="fas fa-tools mr-1" aria-hidden="true"></i>Servidor
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
<div class="modal fade" id="modalFederationNodeIdentity" tabindex="-1" role="dialog" aria-labelledby="modalFederationNodeIdentityLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content bg-dark text-light border-secondary">
      <div class="modal-header border-secondary">
        <div>
          <h5 class="modal-title" id="modalFederationNodeIdentityLabel"><i class="fas fa-fingerprint mr-1"></i> Identidad del nodo FederationCloud</h5>
          <div class="small text-muted">Un superusuario puede crear la identidad inicial o renombrar su etiqueta firmada.</div>
        </div>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="federationNodeIdentityAlert" class="alert d-none" role="alert"></div>
        <div class="alert alert-info small">
          El <strong>Node ID</strong> y las claves Ed25519 identifican al nodo. El nombre se comprueba contra el seed para evitar colisiones globales.
        </div>
        <div class="form-group">
          <label for="federationNodeNameInput">Nombre visible del nodo</label>
          <input id="federationNodeNameInput" class="form-control" maxlength="64" autocomplete="off" placeholder="drive.esforzados.com">
          <small class="form-text text-muted">Puede ser etiqueta, dominio o IP pública legible. La conexión real sigue usando las URLs firmadas.</small>
        </div>
        <div class="form-group"><label for="federationNodeIdReadonly">Node ID criptográfico</label><input id="federationNodeIdReadonly" class="form-control" readonly></div>
        <div class="form-group"><label for="federationNodePublicUrlReadonly">Public URL</label><input id="federationNodePublicUrlReadonly" class="form-control" readonly></div>
        <div class="form-group mb-0"><label for="federationNodeFederationUrlReadonly">Federation URL</label><input id="federationNodeFederationUrlReadonly" class="form-control" readonly></div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-outline-light" data-dismiss="modal">Cancelar</button>
        <button type="button" id="btnSaveFederationNodeIdentity" class="btn btn-info"><i class="fas fa-save mr-1"></i>Guardar nombre</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalServerAdmin" tabindex="-1" role="dialog" aria-labelledby="modalServerAdminLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
    <div class="modal-content bg-dark text-light border-secondary">
      <div class="modal-header border-secondary">
        <div>
          <h5 class="modal-title" id="modalServerAdminLabel"><i class="fas fa-tools mr-1"></i> Configuración del servidor</h5>
          <div class="small text-muted">FederationCloud, SMTP, base de datos y credenciales AWS de esta instalación.</div>
        </div>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="serverAdminAlert" class="alert d-none" role="alert"></div>
        <div class="alert alert-info small">
          Aquí puedes ver las variables que usa ArcadeCloud, su valor actual y de dónde se cargan. Base de datos y AWS se configuran como bloque para evitar cambios incompletos.
        </div>
        <div id="serverAdminHelperStatus" class="small text-muted mb-3">Consultando helper…</div>

        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0">Variables disponibles</h6>
          <span id="serverAdminVariableCount" class="badge badge-secondary">—</span>
        </div>
        <div class="table-responsive border border-secondary rounded mb-4" style="max-height: 390px; overflow-y: auto;">
          <table class="table table-dark table-sm table-hover mb-0">
            <thead>
              <tr>
                <th style="min-width: 290px;">Variable</th>
                <th style="min-width: 260px;">Valor actual</th>
                <th style="min-width: 130px;">Origen</th>
                <th class="text-right" style="width: 110px;">Acción</th>
              </tr>
            </thead>
            <tbody id="serverAdminSettingsTableBody">
              <tr><td colspan="4" class="text-muted">Cargando variables…</td></tr>
            </tbody>
          </table>
        </div>

        <div id="serverAdminSingleEditor">
          <h6>Modificar variable</h6>
          <div class="form-group">
            <label for="serverAdminVariable">Variable</label>
            <select id="serverAdminVariable" class="form-control"></select>
          </div>
          <div class="form-group">
            <label for="serverAdminValue">Nuevo valor</label>
            <input id="serverAdminValue" class="form-control" autocomplete="off">
            <small id="serverAdminValueHelp" class="form-text text-muted"></small>
          </div>
        </div>

        <div id="serverAdminGroupEditor" class="d-none">
          <h6 id="serverAdminGroupTitle">Configurar grupo</h6>
          <div id="serverAdminGroupFields"></div>
        </div>

        <div class="form-group mb-0">
          <label for="serverAdminPassword">Contraseña actual de superusuario</label>
          <input id="serverAdminPassword" type="password" class="form-control" autocomplete="current-password">
          <small class="form-text text-muted">Se usa para confirmar el cambio; los campos secretos existentes pueden dejarse vacíos para conservar su valor.</small>
        </div>
      </div>
      <div class="modal-footer border-secondary">
        <button type="button" class="btn btn-outline-light" data-dismiss="modal">Cerrar</button>
        <button type="button" id="btnSaveServerAdmin" class="btn btn-info"><i class="fas fa-save mr-1"></i>Guardar variable</button>
        <button type="button" id="btnSaveServerAdminGroup" class="btn btn-info d-none"><i class="fas fa-save mr-1"></i>Guardar grupo</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalFederationProviderRequests" tabindex="-1" role="dialog" aria-labelledby="modalFederationProviderRequestsLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content bg-dark text-light border-secondary">
      <div class="modal-header border-secondary">
        <div><h5 class="modal-title" id="modalFederationProviderRequestsLabel"><i class="fas fa-server mr-1"></i> Proveedores FederationCloud</h5><div class="small text-muted">Sólo superusuarios pueden aprobar, rechazar o revocar nodos.</div></div>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div id="federationProviderAdminAlert" class="alert d-none" role="alert"></div>
        <div class="d-flex align-items-center justify-content-between mb-2"><h6 class="mb-0">Solicitudes pendientes</h6><span id="federationProviderPendingBadge" class="badge badge-warning">0</span></div>
        <div id="federationProviderPendingList" class="mb-4"><div class="text-muted small">Cargando solicitudes…</div></div>
        <div class="d-flex align-items-center justify-content-between mb-2"><h6 class="mb-0">Proveedores autorizados</h6><span id="federationProviderActiveBadge" class="badge badge-success">0</span></div>
        <div id="federationProviderActiveList"><div class="text-muted small">Cargando proveedores…</div></div>
      </div>
      <div class="modal-footer border-secondary"><button type="button" class="btn btn-outline-light" data-dismiss="modal">Cerrar</button></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (is_file($federationFooterJs)): ?><script src="js/federation-footer.js?v=<?= (int)filemtime($federationFooterJs) ?>"></script><?php endif; ?>
<?php if (is_file($serverAdminJs)): ?><script src="js/server-admin.js?v=<?= (int)filemtime($serverAdminJs) ?>"></script><?php endif; ?>
<?php if (is_file($arcadeLinkShareJs)): ?><script src="js/arcadelink-share.js?v=<?= (int)filemtime($arcadeLinkShareJs) ?>"></script><?php endif; ?>
