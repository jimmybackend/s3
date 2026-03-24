<?php
require_once 'vendor/autoload.php';
require_once __DIR__ . '/app_bootstrap.php';
require_once 'S3Manager.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$s3 = Config::getS3();
$manager = new S3Manager();
$ruta = $_SESSION['ruta_actual'] ?? Config::RUTA_RAIZ;
$basePrefix = rtrim($ruta, '/') . '/';

$tieneAudio = false;
$pesoTotal = 0;
$archivos = $manager->listArchivos($basePrefix);
foreach ($archivos as $archivo) {
    $pesoTotal += $archivo['Size'] ?? 0;
    $ext = strtolower(pathinfo($archivo['Key'], PATHINFO_EXTENSION));
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'opus', 'm4a'])) {
        $tieneAudio = true;
    }
}

$userId = $_SESSION['user_id'];
// Obtener tamaño total desde la BD
$stmt = $db_connection->prepare("SELECT SUM(Tamano) as total_bytes FROM FileS3 WHERE user_id_ = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$totalBytes = (int)($row['total_bytes'] ?? 0);
$stmt->close();

// Función para mostrar el tamaño en unidades legibles
function formatoPesoCompleto($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes < 1099511627776) return round($bytes / 1073741824, 2) . ' GB';
    return round($bytes / 1099511627776, 2) . ' TB';
}

$pesoTotalLegible = formatoPesoCompleto($totalBytes);

$pesoTotalMB = round($pesoTotal / 1048576, 2);
$archivosPaginados = $archivos; // Si aplicas paginaci贸n aqu铆, aj煤stalo.
?>

<div id="bloque-footer">
  <footer class="d-flex justify-content-between align-items-center">
    
    <!-- 📁 Ruta actual a la izquierda -->
    <?php if (!empty($_SESSION['ruta_actual'])): ?>
      <div class="text-muted small mr-3 d-flex align-items-center">
        <i class="fas fa-folder-open mr-1"></i>
        <strong><?= htmlspecialchars($_SESSION['ruta_actual']) ?></strong>
      </div>
    <?php endif; ?>

   
   
    <div class="text-muted small flex-shrink-0 ml-3">
     Espacio  Usado <strong class="text-info"><?= $pesoTotalLegible ?> de 50TB</strong>
     </div>

    <!-- 📊 Conteo final a la derecha -->
    <div class="text-muted small flex-shrink-0 ml-3">
      <!--Archivos: <strong><?= count($archivosPaginados ?? []) ?></strong> |
      Peso: <strong><?= $pesoTotalMB ?? 0 ?> MB</strong> | -->
      <span id="relojFooter"><strong><?= date('Y-m-d H:i:s') ?></strong></span>
    </div>

  </footer>
</div>


