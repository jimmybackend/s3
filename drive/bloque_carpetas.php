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
  #arbolCarpetas .folder-actions .dropdown-menu {
    min-width: 15rem;
    z-index: 1080;
  }
  #arbolCarpetas .folder-actions-toggle::after {
    display: none;
  }
  #modalCrearDocumentoCarpeta .folder-document-editor {
    min-height: 260px;
    max-height: 55vh;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: .6rem;
    background: var(--panel-bg2);
    color: var(--text);
    padding: .85rem;
    line-height: 1.5;
    outline: none;
  }
  #modalCrearDocumentoCarpeta .folder-document-editor:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 .18rem rgba(var(--accent-rgb), .16);
  }
  #modalCrearDocumentoCarpeta .folder-document-editor:empty::before {
    content: attr(data-placeholder);
    color: var(--text-soft);
    pointer-events: none;
  }
  #modalCrearDocumentoCarpeta .modal-content {
    background: var(--panel-solid);
    color: var(--text);
    border-color: var(--border);
  }
  #modalCrearDocumentoCarpeta .form-control {
    background: var(--panel-bg2);
    color: var(--text);
    border-color: var(--border);
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

          <?= \ArcadeCloud\Drive\View\FolderTreeRenderer::actionsMenu($root, rtrim($root, '/'), true) ?>
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

  <div class="modal fade" id="modalCrearDocumentoCarpeta" tabindex="-1" role="dialog" aria-labelledby="folderDocumentTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
      <form id="formCrearDocumentoCarpeta" class="modal-content" autocomplete="off">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="folderDocumentTitle"><i class="fas fa-file-alt mr-2"></i>Crear documento desde texto</h5>
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
              <input type="text" class="form-control" id="folderDocumentName" name="name" maxlength="180" placeholder="Mi prompt" required>
            </div>
            <div class="form-group col-md-4">
              <label for="folderDocumentFormat">Guardar como</label>
              <select class="form-control" id="folderDocumentFormat" name="format">
                <option value="html" selected>HTML (.html) · conserva formato</option>
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
               data-placeholder="Pega aquí una respuesta, prompt o texto de ChatGPT..."></div>
          <small id="folderDocumentFormatHelp" class="form-text text-muted mt-2">
            HTML conserva títulos, negritas, listas, tablas, enlaces y bloques de código. Los estilos propios de ChatGPT no se guardan.
          </small>
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

  <script src="js/folder-document.js?v=<?= (int) filemtime(__DIR__ . '/js/folder-document.js') ?>"></script>
</div>
