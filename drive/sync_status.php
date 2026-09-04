<?php
/**
 * Estatus por usuario:
 * - ?view=html [&loading=1] -> HTML (alert-success con ícono/spinner)
 * - (default) JSON          -> para integraciones
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/S3Manager.php';


if (!isset($_SESSION['usuario']) && !isset($_SESSION['user_id'])) { http_response_code(401); echo "Sin sesión."; exit; }

$userId = null;
if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
} elseif (isset($_SESSION['usuario']['id_'])) {
    $userId = (int)$_SESSION['usuario']['id_'];
} elseif (isset($_SESSION['usuario']['user_id'])) {
    $userId = (int)$_SESSION['usuario']['user_id'];
}
if (!$userId) { $userId = 0; }

function qscalar(mysqli $db, string $sql, array $bind=[], string $types='') {
    $st = $db->prepare($sql);
    if (!$st) throw new Exception($db->error);
    if ($bind) {
        if (!$types) { foreach ($bind as $v) $types .= is_int($v)?'i':(is_float($v)?'d':'s'); }
        $st->bind_param($types, ...$bind);
    }
    $st->execute();
    $st->bind_result($val);
    $st->fetch();
    $st->close();
    return $val ?? 0;
}

$totF        = qscalar($db_connection, "SELECT COUNT(*) FROM FileS3 WHERE user_id_=?", [$userId]);
$totF_found  = qscalar($db_connection, "SELECT COUNT(*) FROM FileS3 WHERE user_id_=? AND Found=1", [$userId]);
$totFo       = qscalar($db_connection, "SELECT COUNT(*) FROM S3Folders WHERE user_id_=?", [$userId]);
$totFo_found = qscalar($db_connection, "SELECT COUNT(*) FROM S3Folders WHERE user_id_=? AND Found=1", [$userId]);
$sumSize     = qscalar($db_connection, "SELECT COALESCE(SUM(Tamano),0) FROM FileS3 WHERE user_id_=?", [$userId]);

$view    = isset($_GET['view']) ? $_GET['view'] : null;
$loading = isset($_GET['loading']) && $_GET['loading'] == '1';

/* === HTML === */
if ($view === 'html') {
    ?>
<div class="sync-box <?php echo $loading ? 'sync-loading' : 'sync-success'; ?> sync-green" role="status" style="margin:0">
  <div class="sync-line">
    <?php if ($loading): ?>
      <span class="sync-spinner" aria-hidden="true"></span>
      <strong>Sincronizando…</strong>
    <?php else: ?>
      <i class="fas fa-check-circle" aria-hidden="true"></i>
      <strong>Estatus actualizado</strong>
    <?php endif; ?>
  </div>

  <div class="sync-text">
    <?php if ($loading): ?>
      Comparando S3 y la base de datos. Por favor espera…
    <?php else: ?>
      Archivos: <b><?php echo number_format($totF_found); ?></b> / <?php echo number_format($totF); ?> encontrados ·
      Carpetas: <b><?php echo number_format($totFo_found); ?></b> / <?php echo number_format($totFo); ?> ·
      Tamaño total: <b><?php echo number_format($sumSize); ?></b> bytes
    <?php endif; ?>
  </div>
</div>
    <?php
    exit;
}

/* === JSON (default) === */
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    "ok"=>true,
    "user_id"=>$userId,
    "files_total"=>$totF,
    "files_found"=>$totF_found,
    "folders_total"=>$totFo,
    "folders_found"=>$totFo_found,
    "bytes_total"=>$sumSize
]);
