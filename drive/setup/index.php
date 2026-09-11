<?php
declare(strict_types=1);

use ArcadeCloud\Drive\Setup\BootstrapSetupAuth;

$driveRoot = dirname(__DIR__);
require_once $driveRoot . '/src/Setup/BootstrapSetupAuth.php';

$auth = new BootstrapSetupAuth();
$token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
if ($token !== '') {
    $ok = $auth->acceptActivationToken($token);
    header('Location: ./' . ($ok ? '' : '?activation=invalid'));
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>ArcadeCloud Setup</title>
  <style>
    :root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#111827;color:#e5e7eb}main{max-width:1100px;margin:0 auto;padding:32px 18px 60px}.card{background:#1f2937;border:1px solid #374151;border-radius:14px;padding:20px;margin:16px 0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}.field{display:flex;flex-direction:column;gap:6px}label{font-size:.9rem;color:#cbd5e1}input,select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #4b5563;background:#111827;color:#fff}button,.button{border:0;border-radius:8px;padding:10px 15px;background:#0891b2;color:#fff;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}button.secondary{background:#374151}.muted{color:#9ca3af}.ok{color:#34d399}.warn{color:#fbbf24}.error{color:#f87171}.hidden{display:none!important}.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.status{padding:10px 12px;border-radius:8px;background:#111827;margin:12px 0;white-space:pre-wrap}.secret-note{font-size:.82rem;color:#9ca3af}h1,h2,h3{margin-top:0}code{color:#67e8f9}
  </style>
</head>
<body>
<main>
  <h1>ArcadeCloud Setup</h1>
  <p class="muted">Instalación inicial: Base de datos → AWS → SMTP → primer superadmin.</p>
  <div id="globalStatus" class="status">Cargando estado…</div>

  <section id="loginCard" class="card hidden">
    <h2>Supervisor temporal</h2>
    <p class="muted">Este acceso sólo administra la instalación inicial. No abre el Drive ni FederationCloud.</p>
    <div class="grid">
      <div class="field"><label>Usuario</label><input id="setupUsername" value="arcadecloud" autocomplete="username"></div>
      <div class="field"><label>Contraseña inicial</label><input id="setupPassword" type="password" autocomplete="current-password"></div>
    </div>
    <div class="row" style="margin-top:14px"><button id="btnSetupLogin">Entrar al setup</button></div>
  </section>

  <section id="setupPanel" class="hidden">
    <div class="card">
      <h2>1. Base de datos</h2>
      <div id="databaseFields" class="grid"></div>
      <div class="row" style="margin-top:14px"><button data-save-group="database">Probar y guardar MySQL</button></div>
    </div>

    <div class="card">
      <h2>2. AWS</h2>
      <div id="awsFields" class="grid"></div>
      <div class="row" style="margin-top:14px"><button data-save-group="aws">Guardar AWS</button></div>
    </div>

    <div class="card">
      <h2>3. SMTP</h2>
      <p class="muted">Puedes configurarlo ahora para recuperación/cambio de contraseña.</p>
      <div id="smtpFields" class="grid"></div>
      <div class="row" style="margin-top:14px"><button data-save-group="smtp">Guardar SMTP</button></div>
    </div>

    <div class="card">
      <h2>4. Primer superadmin</h2>
      <p class="muted">Al crear este usuario se elimina la credencial temporal y <code>/setup</code> queda bloqueado.</p>
      <div class="grid">
        <div class="field"><label>Nombre</label><input id="adminFirstname" autocomplete="given-name"></div>
        <div class="field"><label>Apellido</label><input id="adminLastname" autocomplete="family-name"></div>
        <div class="field"><label>Correo</label><input id="adminEmail" type="email" autocomplete="email"></div>
        <div class="field"><label>Contraseña</label><input id="adminPassword" type="password" autocomplete="new-password"><span class="secret-note">Mínimo 10 caracteres.</span></div>
      </div>
      <div class="row" style="margin-top:14px"><button id="btnCreateSuperadmin">Crear superadmin y cerrar setup</button><button id="btnSetupLogout" class="secondary">Cerrar sesión temporal</button></div>
    </div>
  </section>

  <section id="completedCard" class="card hidden">
    <h2>Instalación cerrada</h2>
    <p>El supervisor temporal ya no está disponible.</p>
    <a class="button" href="../">Abrir ArcadeCloud Drive</a>
  </section>
</main>
<script src="../js/setup.js"></script>
</body>
</html>
