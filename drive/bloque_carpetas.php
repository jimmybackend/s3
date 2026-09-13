<?php
declare(strict_types=1);

require_once __DIR__ . '/app_bootstrap.php';

$app = \ArcadeCloud\Drive\Core\ApplicationKernel::app();
$sessionManager = $app->session();
$sessionManager->start();
$sessionManager->requireAuthenticated('index.php');
$userId = $sessionManager->userId();

$tree = $app->folderTreeRenderer($userId);
$root = $tree->root();
$currentInput = (string) ($_GET['ruta_actual'] ?? $_SESSION['ruta_actual'] ?? $root);
$current = $tree->normalize($currentInput);
$_SESSION['ruta_actual'] = $current;

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<style>
  #arbolCarpetas .federation-shares-row {
    position: relative;
    cursor: pointer;
    border: 1px solid rgba(var(--accent-rgb), .22);
    background: rgba(var(--accent-rgb), .06) !important;
    margin-top: .35rem;
    opacity: 1 !important;
    filter: none !important;
  }
  #arbolCarpetas .federation-shares-row:hover {
    background: rgba(var(--accent-rgb), .14) !important;
  }
  #arbolCarpetas .federation-shares-row .shared-folder-link,
  #arbolCarpetas .federation-shares-row .shared-folder-link .name {
    color: var(--text-strong) !important;
    -webkit-text-fill-color: var(--text-strong) !important;
    font-weight: 700 !important;
    text-decoration: none !important;
    opacity: 1 !important;
    visibility: visible !important;
    filter: none !important;
    text-shadow: none !important;
  }
  #arbolCarpetas .federation-shares-row .shared-folder-link i {
    color: var(--accent) !important;
    -webkit-text-fill-color: currentColor !important;
    opacity: 1 !important;
  }
  #arbolCarpetas .federation-shares-row .badge {
    position: relative;
    z-index: 2;
    pointer-events: none;
    opacity: 1 !important;
  }
  body.ui-theme.theme-light #arbolCarpetas .federation-shares-row {
    background: rgba(var(--accent-rgb), .10) !important;
    border-color: rgba(var(--accent-rgb), .38) !important;
  }
  body.ui-theme.theme-light #arbolCarpetas .federation-shares-row .shared-folder-link,
  body.ui-theme.theme-light #arbolCarpetas .federation-shares-row .shared-folder-link .name {
    color: #17324d !important;
    -webkit-text-fill-color: #17324d !important;
  }
  body.ui-theme.theme-dark #arbolCarpetas .federation-shares-row .shared-folder-link,
  body.ui-theme.theme-dark #arbolCarpetas .federation-shares-row .shared-folder-link .name {
    color: #f2f2f2 !important;
    -webkit-text-fill-color: #f2f2f2 !important;
  }
</style>
<div id="bloque-carpetas" class="card">
  <div class="card-header py-2 d-flex align-items-center">
    <strong><i class="fas fa-folder-open"></i> Carpetas</strong>
    <div class="ml-auto d-flex align-items-center">
      <a href="activity_costs.php"
         class="btn btn-sm btn-outline-primary mr-1"
         title="Actividad y costos"
         aria-label="Actividad y costos">
        <i class="fas fa-receipt"></i>
      </a>
      <button type="button"
              class="btn btn-sm btn-outline-primary"
              data-toggle="modal"
              data-target="#modalCrearCarpeta"
              data-ruta="<?= $e($root) ?>">
        <i class="fas fa-folder-plus"></i> Nueva
      </button>
    </div>
  </div>

  <div class="card-body p-2">
    <ul id="arbolCarpetas" class="folder-tree list-unstyled mb-0" data-root="<?= $e($root) ?>">
      <li class="folder-item" data-prefix="<?= $e($root) ?>">
        <div class="folder-row d-flex align-items-center">
          <span class="toggle <?= $tree->hasChildren($root) ? '' : 'empty' ?>" title="Expandir/contraer">
            <?= $tree->hasChildren($root) ? '−' : '·' ?>
          </span>

          <a href="#"
             class="folder<?= $current === $root ? ' active' : '' ?>"
             data-route="<?= $e($root) ?>"
             data-ruta="<?= $e($root) ?>">
            <i class="fas fa-hdd mr-1"></i>
            <span class="name"><?= $e(rtrim($root, '/')) ?></span>
          </a>

          <div class="ml-auto btn-group btn-group-sm">
            <button type="button"
                    class="btn btn-light btn-xs js-sync-folder"
                    title="Sincronizar todo este usuario desde S3"
                    aria-label="Sincronizar raíz del usuario"
                    data-sync-prefix="<?= $e($root) ?>"
                    data-sync-name="<?= $e(rtrim($root, '/')) ?>">
              <i class="fas fa-rotate"></i>
            </button>
            <button type="button"
                    class="btn btn-light btn-xs"
                    title="Nueva subcarpeta"
                    data-toggle="modal"
                    data-target="#modalCrearCarpeta"
                    data-ruta="<?= $e($root) ?>">
              <i class="fas fa-folder-plus"></i>
            </button>
          </div>
        </div>

        <div class="children" style="display:block">
          <?= $tree->renderChildren($root, $current) ?>
        </div>
      </li>

      <li class="folder-item folder-item-virtual mt-1" data-prefix="virtual:shares">
        <div class="folder-row d-flex align-items-center federation-shares-row">
          <span class="toggle empty" aria-hidden="true">·</span>
          <a href="federationcloud/portal.php?view=shares"
             class="shared-folder-link stretched-link"
             title="Abrir FederationCloud · Compartidos"
             aria-label="Abrir FederationCloud Compartidos">
            <i class="fas fa-users mr-1"></i>
            <span class="name">Compartidos</span>
          </a>
          <span class="ml-auto badge badge-info">FederationCloud</span>
        </div>
      </li>
    </ul>
  </div>
</div>
