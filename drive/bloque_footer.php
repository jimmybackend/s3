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
    <span aria-hidden="true"> · </span>
    Espacio usado: <strong id="footerEspacioUsado" class="text-info"><?= $e($espacioUsadoFooter) ?></strong>
  </div>

  <div class="text-muted small drive-footer-clock">
    <span id="relojFooter"><strong><?= date('Y-m-d H:i:s') ?></strong></span>
  </div>
</footer>
<?php if (is_file($federationFooterJs)): ?>
<script src="js/federation-footer.js?v=<?= (int) filemtime($federationFooterJs) ?>"></script>
<?php endif; ?>
