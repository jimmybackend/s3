<?php
// bloque_carpetas.php — Árbol de carpetas con acciones (crear / renombrar / mover / eliminar)
// Requiere: tabla S3Folders con al menos: Prefix (PK), ParentPrefix, Nombre
// Usa $_SESSION['ruta_actual'] como ruta activa y la actualiza vía AJAX (actualizar_ruta.php).

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Conexión a BD (ajusta la ruta si es necesario)
require_once __DIR__ . '/app_bootstrap.php';

$current = isset($_SESSION['ruta_actual']) ? $_SESSION['ruta_actual'] : 'Data/';
if ($current === '' || $current === '/') $current = 'Data/';

function norm_prefix(string $p): string {
  $p = trim($p);
  if ($p === '' || $p === '/') return 'Data/';
  $p = str_replace('\\', '/', $p);
  $p = preg_replace('~/+~', '/', $p);
  if (substr($p, -1) !== '/') $p .= '/';
  if (stripos($p, 'Data/') !== 0) $p = 'Data/' . ltrim($p, '/');
  return $p;
}
$current = norm_prefix($current);

// Obtiene hijos directos de un prefix
function fetchChildren(mysqli $db, string $parent): array {
  $sql = "SELECT Prefix, ParentPrefix, Nombre
          FROM S3Folders
          WHERE ParentPrefix = ?
          ORDER BY Nombre ASC";
  if (!$stmt = $db->prepare($sql)) return [];
  $stmt->bind_param('s', $parent);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) { $rows[] = $r; }
  $stmt->close();
  return $rows;
}

// Checa si un prefix tiene hijos
function hasChildren(mysqli $db, string $parent): bool {
  $sql = "SELECT 1 FROM S3Folders WHERE ParentPrefix = ? LIMIT 1";
  if (!$stmt = $db->prepare($sql)) return false;
  $stmt->bind_param('s', $parent);
  $stmt->execute();
  $stmt->store_result();
  $has = $stmt->num_rows > 0;
  $stmt->close();
  return $has;
}

// Render recursivo
function renderTree(mysqli $db, string $parent, string $active, bool $expandAncestors = true) {
  $children = fetchChildren($db, $parent);
  if (!$children) return;

  echo '<ul class="subfolders list-unstyled mb-0" data-parent="' . htmlspecialchars($parent) . '">';
  foreach ($children as $c) {
    $pref = norm_prefix($c['Prefix'] ?? '');
    $name = $c['Nombre'] ?? basename(rtrim($pref, '/'));
    $isActive = ($pref === $active);
    $isAncestor = ($expandAncestors && strpos($active, $pref) === 0 && $pref !== $active);
    $hasKids = hasChildren($db, $pref);

    echo '<li class="folder-item" data-prefix="' . htmlspecialchars($pref) . '">';
    echo '  <div class="folder-row d-flex align-items-center">';

    $toggleClass = $hasKids ? 'toggle' : 'toggle empty';
    $toggleText  = $hasKids ? '−' : '·';
    echo '    <span class="' . $toggleClass . '" title="Expandir/contraer">' . $toggleText . '</span>';

    // Link carpeta (doble clic -> cargarCarpetas)
    echo '    <a href="#" class="folder' . ($isActive ? ' active' : '') . '"'
       . '       data-route="' . htmlspecialchars($pref) . '" data-ruta="' . htmlspecialchars($pref) . '"'
       . '       ondblclick="cargarCarpetas(\'' . htmlspecialchars($pref) . '\', true); return false;">'
       . '      <i class="fas fa-folder mr-1"></i> <span class="name">' . htmlspecialchars($name) . '</span>'
       . '    </a>';

    // Acciones: abrir modales con data-attrs que esperan tus JS
    echo '    <div class="ml-auto btn-group btn-group-sm">';
    // MOVER -> js/mover-carpeta.js lee data-route y data-name
    echo '      <button type="button" class="btn btn-light btn-xs" title="Mover" '
       . '              data-toggle="modal" data-target="#modalMoverCarpeta" '
       . '              data-route="' . htmlspecialchars($pref) . '" data-name="' . htmlspecialchars($name) . '">'
       . '        <i class="fas fa-arrows-alt"></i>'
       . '      </button>';
    // RENOMBRAR -> js/modalRenombrar.js lee data-actual y data-nombre
    echo '      <button type="button" class="btn btn-light btn-xs" title="Renombrar" '
       . '              data-toggle="modal" data-target="#modalRenombrar" '
       . '              data-actual="' . htmlspecialchars($pref) . '" data-nombre="' . htmlspecialchars($name) . '">'
       . '        <i class="fas fa-i-cursor"></i>'
       . '      </button>';
    // ELIMINAR -> tu JS de eliminar lee data-route y data-name
    echo '      <button type="button" class="btn btn-light btn-xs text-danger" title="Eliminar" '
       . '              data-toggle="modal" data-target="#modalEliminarCarpeta" '
       . '              data-route="' . htmlspecialchars($pref) . '" data-name="' . htmlspecialchars($name) . '">'
       . '        <i class="fas fa-trash"></i>'
       . '      </button>';
    echo '    </div>';

    echo '  </div>';

    $display = ($isAncestor || $isActive) ? 'block' : 'none';
    echo '  <div class="children" style="display:' . $display . '">';
    if ($hasKids) { renderTree($db, $pref, $active, $expandAncestors); }
    echo '  </div>';

    echo '</li>';
  } 
  echo '</ul>';
}

$root = 'Data/';
?>
<div id="bloque-carpetas" class="card">
  <div class="card-header py-2 d-flex align-items-center">
    <strong><i class="fas fa-folder-open"></i> Carpetas</strong>
    <div class="ml-auto">
      <!-- Crear en raíz: usa tu formCrearCarpeta en la página principal -->
      <button type="button"
            class="btn btn-sm btn-outline-primary"
            data-toggle="modal"
            data-target="#modalCrearCarpeta"
            data-ruta="<?php echo htmlspecialchars($root); ?>">
      <i class="fas fa-folder-plus"></i> Nueva
    </button>
    </div>
  </div>
  <div class="card-body p-2">
    <ul id="arbolCarpetas" class="folder-tree list-unstyled mb-0" data-root="<?php echo htmlspecialchars($root); ?>">
      <li class="folder-item" data-prefix="<?php echo htmlspecialchars($root); ?>">
        <div class="folder-row d-flex align-items-center">
          <span class="toggle <?php echo hasChildren($db_connection, $root) ? '' : 'empty'; ?>" title="Expandir/contraer">−</span>
          <a href="#" class="folder<?php echo $current===$root?' active':''; ?>"
             data-route="<?php echo htmlspecialchars($root); ?>" data-ruta="<?php echo htmlspecialchars($root); ?>"
             ondblclick="cargarCarpetas('<?php echo htmlspecialchars($root); ?>', true); return false;">
            <i class="fas fa-hdd mr-1"></i> <span class="name">Data</span>
          </a>
          <div class="ml-auto btn-group btn-group-sm">
            <button type="button" class="btn btn-light btn-xs" title="Nueva subcarpeta"
                    onclick="document.querySelector('#formCrearCarpeta input[name=ruta]').value='<?php echo htmlspecialchars($root); ?>'; document.querySelector('#formCrearCarpeta input[name=nueva]')?.focus();">
              <i class="fas fa-folder-plus"></i>
            </button>
          </div>
        </div>
        <div class="children" style="display:block">
          <?php renderTree($db_connection, $root, $current, true); ?>
        </div>
      </li>
    </ul>
  </div>
</div>
