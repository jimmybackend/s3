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
    </ul>
  </div>
</div>
