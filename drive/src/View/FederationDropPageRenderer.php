<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FederationDropPageRenderer
{
    public function render(
        array $state,
        string $sourceDomain = '',
        string $manageDropId = '',
        string $ownerToken = '',
        string $paymentReturn = ''
    ): void {
        $h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ready = !empty($state['ready']);
        $commerceNode = !empty($state['commerce_node']);
        $commerceUrl = rtrim((string)($state['commerce_url'] ?? ''), '/');
        $sourceResource = is_array($state['source_resource'] ?? null) ? $state['source_resource'] : null;
        $sourceResourceId = is_array($sourceResource) ? trim((string)($sourceResource['resource_id'] ?? '')) : '';
        $sourceResourceValid = $sourceResourceId !== '' && empty($sourceResource['error']);
        $localHost = (string)(parse_url((string)($state['public_url'] ?? ''), PHP_URL_HOST) ?: '');
        $referralSource = trim($sourceDomain) !== '' ? trim($sourceDomain) : $localHost;
        $commerceEntry = $commerceUrl;
        if (!$commerceNode && $commerceUrl !== '') {
            $query = ['source' => $referralSource];
            if ($sourceResourceId !== '') $query['resource_id'] = $sourceResourceId;
            $commerceEntry .= '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $currency = (string)($state['currency'] ?? 'MXN');
        $maxDays = max(1, (int)($state['max_days'] ?? 30));
        $maxDownloads = max(1, (int)($state['max_downloads'] ?? 1000));
        $maxBytes = max(1, (int)($state['max_file_bytes'] ?? 0));
        $jsPath = dirname(__DIR__, 2) . '/js/federation-drop.js';
        $jsVersion = is_file($jsPath) ? (int)filemtime($jsPath) : 1;
        ?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="referrer" content="no-referrer">
  <title>FederationDrop · ArcadeCloud</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    body{background:#07111f;color:#e5edf8;min-height:100vh}
    .drop-shell{max-width:920px;margin:0 auto;padding:28px 16px 56px}
    .drop-card{background:#0d1b2a;border:1px solid #29415c;border-radius:16px;padding:22px;box-shadow:0 18px 48px rgba(0,0,0,.24)}
    .drop-muted{color:#9fb0c3}.drop-price{font-size:1.7rem;font-weight:700}
    .form-control,.custom-select{background:#081522;color:#eef6ff;border-color:#35516d}
    .form-control:focus,.custom-select:focus{background:#081522;color:#fff}
    code{color:#70e1c2}.drop-link{word-break:break-all}
    .drop-badge-preview{max-width:280px}
  </style>
</head>
<body>
<main
  id="federationDropApp"
  class="drop-shell"
  data-ready="<?= $ready ? '1' : '0' ?>"
  data-commerce-node="<?= $commerceNode ? '1' : '0' ?>"
  data-commerce-url="<?= $h($commerceEntry) ?>"
  data-currency="<?= $h($currency) ?>"
  data-max-days="<?= $maxDays ?>"
  data-max-downloads="<?= $maxDownloads ?>"
  data-max-bytes="<?= $maxBytes ?>"
  data-source="<?= $h($sourceDomain) ?>"
  data-manage="<?= $h($manageDropId) ?>"
  data-owner-token="<?= $h($ownerToken) ?>"
  data-payment-return="<?= $h($paymentReturn) ?>"
  data-resource-id="<?= $h($sourceResourceValid ? $sourceResourceId : '') ?>"
  data-resource-size="<?= $h($sourceResourceValid ? (string)($sourceResource['size_bytes'] ?? '') : '') ?>"
  data-resource-title="<?= $h($sourceResourceValid ? (string)($sourceResource['title'] ?? '') : '') ?>"
  data-resource-mime="<?= $h($sourceResourceValid ? (string)($sourceResource['media_type'] ?? '') : '') ?>"
>
  <div class="d-flex align-items-center justify-content-between mb-4">
    <div>
      <h1 class="h2 mb-1"><i class="fas fa-cloud-arrow-up mr-2"></i>FederationDrop</h1>
      <div class="drop-muted">Comparte un archivo por tiempo y número de descargas, sin entregar acceso a tu Drive.</div>
    </div>
    <a href="../federationcloud/portal.php" class="btn btn-outline-light btn-sm">FederationCloud</a>
  </div>

  <?php if (!$commerceNode): ?>
    <div class="alert alert-info">
      <strong>El pago y la custodia se realizan en el portal comercial FederationDrop.</strong>
      Este nodo no recibe el dinero ni almacena el archivo pagado.
    </div>
    <section class="drop-card mb-4">
      <h2 class="h5">Subir, pagar y compartir</h2>
      <p class="drop-muted">Continuarás en <code><?= $h($commerceUrl) ?></code>. El dominio de este nodo se conserva únicamente como referencia de procedencia.</p>
      <a class="btn btn-info btn-lg" rel="noopener noreferrer" href="<?= $h($commerceEntry) ?>">
        Ir a drive.esforzados.com
      </a>
    </section>
  <?php elseif (!$ready): ?>
    <div class="alert alert-warning">
      <strong>FederationDrop todavía no está habilitado para cobros Stripe.</strong>
      <?= $h($state['error'] ?? 'Falta configuración comercial.') ?>
    </div>
  <?php endif; ?>

  <?php if ($commerceNode && is_array($sourceResource) && !empty($sourceResource['error'])): ?>
    <div class="alert alert-warning"><?= $h($sourceResource['error']) ?></div>
  <?php endif; ?>

  <?php if ($commerceNode): ?>
  <section class="drop-card mb-4" id="dropCreateCard">
    <h2 class="h5"><?= $sourceResourceValid ? 'Custodiar recurso público temporalmente' : 'Crear enlace temporal' ?></h2>
    <?php if ($sourceResourceValid): ?>
      <p class="drop-muted">Este archivo ya existe en FederationCloud. Stripe cobra únicamente el servicio de retención/transferencia/descargas; después del pago, drive.esforzados.com lo trae directamente entre nubes y verifica su SHA-256.</p>
      <div class="alert alert-info">
        <strong><?= $h($sourceResource['title'] ?? 'Recurso FederationCloud') ?></strong><br>
        <?= $h($this->formatBytes((int)($sourceResource['size_bytes'] ?? 0))) ?> ·
        <code><?= $h($sourceResourceId) ?></code>
      </div>
    <?php else: ?>
      <p class="drop-muted">El nodo que cobra custodia el archivo. Primero se confirma el pago y sólo entonces se autoriza una subida temporal directa a S3 privado.</p>
    <?php endif; ?>
    <form id="dropCreateForm">
      <div class="form-group">
        <label for="dropEmail">Correo del propietario</label>
        <input class="form-control" id="dropEmail" name="email" type="email" maxlength="320" autocomplete="email" required>
      </div>
      <?php if (!$sourceResourceValid): ?>
      <div class="form-group">
        <label for="dropFile">Archivo</label>
        <input class="form-control-file" id="dropFile" name="file" type="file" required>
        <small class="drop-muted">Máximo configurado: <?= $h($this->formatBytes($maxBytes)) ?>.</small>
      </div>
      <?php endif; ?>
      <div class="form-row">
        <div class="form-group col-md-6">
          <label for="dropDays">Días disponible</label>
          <input class="form-control" id="dropDays" type="number" min="1" max="<?= $maxDays ?>" value="7" required>
        </div>
        <div class="form-group col-md-6">
          <label for="dropDownloads">Descargas máximas</label>
          <input class="form-control" id="dropDownloads" type="number" min="1" max="<?= $maxDownloads ?>" value="10" required>
        </div>
      </div>
      <div class="d-flex flex-wrap align-items-center justify-content-between">
        <div>
          <div class="drop-muted small">Cotización</div>
          <div id="dropQuote" class="drop-price">—</div>
        </div>
        <button id="dropCreateButton" class="btn btn-info btn-lg" type="submit" <?= $ready ? '' : 'disabled' ?>>
          Crear orden y pagar
        </button>
      </div>
      <div id="dropProgress" class="mt-3 small drop-muted"></div>
      <div id="dropError" class="alert alert-danger mt-3 d-none"></div>
    </form>
  </section>
  <?php endif; ?>

  <section class="drop-card mb-4 d-none" id="dropManageCard">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h2 class="h5 mb-1">Administrar FederationDrop</h2>
        <div id="dropManageStatus" class="drop-muted"></div>
      </div>
      <button id="dropRefreshButton" type="button" class="btn btn-outline-light btn-sm">Actualizar</button>
    </div>
    <div id="dropManageBody" class="mt-3"></div>
  </section>

  <section class="drop-card">
    <h2 class="h5">Pon FederationDrop en cualquier dominio</h2>
    <p class="drop-muted">El sitio sólo publica la imagen/enlace. Todos los cobros y la custodia comercial apuntan al portal canónico.</p>
    <img class="drop-badge-preview mb-3" src="<?= $h($commerceUrl) ?>/badge.svg" alt="Compartir con FederationDrop">
    <pre class="mb-0 text-light"><code>&lt;a href="<?= $h($commerceUrl) ?>/?source=tu-dominio.com"&gt;
  &lt;img src="<?= $h($commerceUrl) ?>/badge.svg" alt="Compartir con FederationDrop"&gt;
&lt;/a&gt;</code></pre>
  </section>
</main>
<script src="../js/federation-drop.js?v=<?= $jsVersion ?>"></script>
</body>
</html><?php
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        $units = ['KiB','MiB','GiB','TiB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TiB') return number_format($value, $value >= 10 ? 0 : 1) . ' ' . $unit;
            $value /= 1024;
        }
        return $bytes . ' B';
    }
}
