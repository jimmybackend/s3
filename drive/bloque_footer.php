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

$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<footer class="d-flex justify-content-between align-items-center">
  <div class="text-muted small mr-3 d-flex align-items-center">
    <i class="fas fa-folder-open mr-1"></i>
    <strong id="footerRutaActual"><?= $e($rutaActualFooter) ?></strong>
  </div>

  <div class="text-muted small flex-shrink-0 ml-3">
    Espacio usado: <strong id="footerEspacioUsado" class="text-info"><?= $e($espacioUsadoFooter) ?></strong>
  </div>

  <div class="text-muted small flex-shrink-0 ml-3">
    <span id="relojFooter"><strong><?= date('Y-m-d H:i:s') ?></strong></span>
  </div>
</footer>
