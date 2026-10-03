<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\View;

final class FederationOsAdminRenderer
{
    public function render(
        bool $isSuperAdmin,
        string $providerCsrf,
        string $serverAdminCsrf,
        bool $embed
    ): void {
        $root = dirname(__DIR__, 2);
        $stylesVersion = is_file($root . '/css/styles.css') ? (int)filemtime($root . '/css/styles.css') : 1;
        $responsiveVersion = is_file($root . '/css/responsive.css') ? (int)filemtime($root . '/css/responsive.css') : 1;
        $systemCssVersion = is_file($root . '/css/os-system-panel.css') ? (int)filemtime($root . '/css/os-system-panel.css') : 1;
        $cssVersion = is_file($root . '/css/federation-os-admin.css') ? (int)filemtime($root . '/css/federation-os-admin.css') : 1;
        $serverAdminJsVersion = is_file($root . '/js/server-admin.js') ? (int)filemtime($root . '/js/server-admin.js') : 1;
        $adminJsVersion = is_file($root . '/js/federation-os-admin.js') ? (int)filemtime($root . '/js/federation-os-admin.js') : 1;
        $h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Acerca de FederationCloud · ArcadeCloud OS</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="../css/styles.css?v=<?= $stylesVersion ?>">
<link rel="stylesheet" href="../css/responsive.css?v=<?= $responsiveVersion ?>">
<link rel="stylesheet" href="../css/os-system-panel.css?v=<?= $systemCssVersion ?>">
<link rel="stylesheet" href="../css/federation-os-admin.css?v=<?= $cssVersion ?>">
</head>
<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on federation-os-admin<?= $embed ? ' is-embedded' : '' ?>"
      data-superadmin="<?= $isSuperAdmin ? '1' : '0' ?>"
      data-provider-csrf="<?= $h($providerCsrf) ?>">
<main class="federation-os-admin-shell os-system-shell">
  <header class="federation-os-about-header os-system-heading">
    <div class="os-system-heading-main">
      <div class="os-system-heading-icon"><i class="fas fa-circle-info"></i></div>
      <div>
        <div class="federation-os-eyebrow os-system-eyebrow">ArcadeCloud OS · FederationCloud</div>
        <h1>Acerca de FederationCloud</h1>
        <p>Estado del nodo, red federada y administración disponible en esta instalación.</p>
      </div>
    </div>
    <button type="button" id="btnFederationOsRefresh" class="btn btn-outline-info btn-sm">
      <i class="fas fa-rotate mr-1"></i>Actualizar
    </button>
  </header>

  <div id="federationOsAdminAlert" class="alert d-none" role="status"></div>

  <nav class="federation-os-jumpbar" aria-label="Secciones de FederationCloud">
    <a href="#federationAboutNode"><i class="fas fa-server"></i><span>Nodo</span></a>
    <a href="#federationAboutPeers"><i class="fas fa-network-wired"></i><span>Nodos conectados</span></a>
    <?php if ($isSuperAdmin): ?>
      <a href="#federationAboutProviders"><i class="fas fa-user-check"></i><span>Solicitudes</span></a>
      <a href="#modalServerAdmin"><i class="fas fa-sliders"></i><span>Servidor</span></a>
      <a href="#federationAboutModeration"><i class="fas fa-shield-halved"></i><span>Moderación</span></a>
    <?php endif; ?>
  </nav>

  <div class="os-node-dashboard federation-node-dashboard">
    <section class="os-node-card os-node-card-wide" id="federationAboutNode">
      <div class="federation-node-title">
        <h3>Este nodo</h3>
        <span id="federationAboutHealth" class="os-node-badge">consultando…</span>
      </div>
      <dl>
        <dt>Nombre</dt><dd id="federationAboutNodeName">—</dd>
        <dt>Node ID</dt><dd id="federationAboutNodeId">—</dd>
        <dt>Nodos activos</dt><dd id="federationAboutConnected">—</dd>
        <dt>Ventana activa</dt><dd id="federationAboutWindow">—</dd>
        <dt>Public URL</dt><dd id="federationAboutPublicUrl">—</dd>
        <dt>Federation URL</dt><dd id="federationAboutFederationUrl">—</dd>
        <dt>Seed</dt><dd id="federationAboutSeed">—</dd>
      </dl>
    </section>

  <section class="os-node-card os-node-card-wide" id="federationAboutPeers">
    <div class="federation-node-title">
      <h3>Nodos conectados</h3>
      <span id="federationAboutPeerCount" class="os-node-badge os-node-badge-ok">0</span>
    </div>
    <div id="federationAboutPeerList" class="federation-os-peer-grid">
      <div class="os-node-placeholder">Cargando directorio…</div>
    </div>
  </section>

  <?php if ($isSuperAdmin): ?>
  <section class="os-node-card os-node-card-wide" id="federationAboutProviders">
    <div class="federation-node-title">
      <h3>Solicitudes de nodos proveedores</h3>
      <span id="federationProviderPendingBadge" class="os-node-badge os-node-badge-warning">0</span>
    </div>
    <p class="federation-os-help">Aprueba o rechaza únicamente copias con backend compartido. Los nodos independientes se registran por el protocolo normal de FederationCloud.</p>
    <div id="federationProviderPendingList" class="mb-3"><div class="federation-os-empty">Cargando solicitudes…</div></div>
    <div class="federation-node-title federation-node-subtitle">
      <h3>Proveedores autorizados</h3>
      <span id="federationProviderActiveBadge" class="os-node-badge os-node-badge-ok">0</span>
    </div>
    <div id="federationProviderActiveList"><div class="federation-os-empty">Cargando proveedores…</div></div>
  </section>

  <button type="button"
          id="btnServerAdmin"
          class="d-none"
          data-csrf="<?= $h($serverAdminCsrf) ?>"
          data-endpoint="../server-settings.php"
          aria-hidden="true"></button>

  <section class="os-node-card os-node-card-wide" id="modalServerAdmin">
    <div class="federation-node-title">
      <h3>Configuración del servidor</h3>
      <span id="serverAdminVariableCount" class="os-node-badge">—</span>
    </div>
    <p class="federation-os-help">Usa el mismo backend administrativo del Drive para MySQL, AWS/S3, FederationCloud y opciones avanzadas.</p>
    <div id="serverAdminAlert" class="alert d-none" role="alert"></div>
    <div id="serverAdminHelperStatus" class="small text-muted mb-3">Consultando helper…</div>

    <div class="btn-group btn-group-sm mb-3" role="group" aria-label="Modo de configuración">
      <button type="button" id="btnServerAdminBasic" class="btn btn-info">Configuración básica</button>
      <button type="button" id="btnServerAdminAdvanced" class="btn btn-outline-info">Configuración avanzada</button>
    </div>
    <div id="serverAdminModeHelp" class="small text-muted mb-3"></div>

    <div class="table-responsive federation-os-settings-table mb-3">
      <table class="table table-dark table-sm table-hover mb-0">
        <thead><tr><th>Variable</th><th>Valor actual</th><th>Origen</th><th class="text-right">Acción</th></tr></thead>
        <tbody id="serverAdminSettingsTableBody"><tr><td colspan="4" class="text-muted">Cargando variables…</td></tr></tbody>
      </table>
    </div>

    <div id="serverAdminSingleEditor">
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

    <div class="form-group">
      <label for="serverAdminPassword">Contraseña actual de superusuario</label>
      <input id="serverAdminPassword" type="password" class="form-control" autocomplete="current-password">
      <small class="form-text text-muted">Se usa para autorizar cambios sensibles. Los secretos existentes pueden conservarse sin volver a mostrarlos.</small>
    </div>

    <div class="d-flex justify-content-end flex-wrap">
      <button type="button" id="btnSaveServerAdmin" class="btn btn-info mr-2 mb-2"><i class="fas fa-save mr-1"></i>Guardar variable</button>
      <button type="button" id="btnSaveServerAdminGroup" class="btn btn-info d-none mb-2"><i class="fas fa-save mr-1"></i>Guardar grupo</button>
    </div>
  </section>

  <section class="os-node-card os-node-card-wide" id="federationAboutModeration">
    <div class="federation-node-title">
      <h3>Moderación</h3>
      <span id="federationModerationPendingBadge" class="os-node-badge os-node-badge-danger">—</span>
    </div>
    <dl>
      <dt>Reportes pendientes</dt><dd id="federationModerationReports">—</dd>
      <dt>Bloqueos activos propios</dt><dd id="federationModerationBlocks">—</dd>
    </dl>
    <p class="federation-os-help">La revisión humana, bloqueo por SHA-256 y revocación siguen usando el servicio de moderación existente.</p>
    <a class="btn btn-outline-danger" href="moderation.php?embed=1">
      <i class="fas fa-shield-halved mr-1"></i>Abrir opciones de moderación
    </a>
  </section>
  <?php else: ?>
  <section class="os-node-card os-node-card-wide">
    <h3>Administración</h3>
    <p class="mb-0 text-muted">Solicitudes de proveedores, configuración del servidor y moderación sólo están disponibles para el superusuario.</p>
  </section>
  <?php endif; ?>
  </div>
</main>

<?php if ($isSuperAdmin): ?>
<script src="../js/server-admin.js?v=<?= $serverAdminJsVersion ?>"></script>
<?php endif; ?>
<script src="../js/federation-os-admin.js?v=<?= $adminJsVersion ?>"></script>
</body>
</html>
<?php
    }
}
