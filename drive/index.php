<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Setup/SetupEntryGuard.php';

$setupGuard = new \ArcadeCloud\Drive\Setup\SetupEntryGuard();
if ($setupGuard->isSetupPending()) {
    header('Location: setup/', true, 302);
    exit;
}

$rawHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
$sourceDomain = '';
if ($rawHost !== '') {
    $parsedHost = parse_url('http://' . $rawHost, PHP_URL_HOST);
    if (is_string($parsedHost)) {
        $candidate = strtolower(trim($parsedHost, '.'));
        if ($candidate !== ''
            && strlen($candidate) <= 255
            && preg_match('/\A[a-z0-9.-]+\z/', $candidate)
        ) {
            $sourceDomain = $candidate;
        }
    }
}

$canonicalHost = 'drive.esforzados.com';
$canonicalHome = 'https://' . $canonicalHost . '/';
$isCanonicalPortal = $sourceDomain !== '' && hash_equals($canonicalHost, $sourceDomain);

$federationDropUrl = $canonicalHome . 'federationdrop/';
if ($sourceDomain !== '') {
    $federationDropUrl .= '?source=' . rawurlencode($sourceDomain);
}

$arcadeLinkUrl = 'federationcloud/';
$githubUrl = 'https://github.com/jimmybackend/s3';
$authorEmail = 'jimmybackend@gmail.com';
$supportEmail = 'soporte@esforzados.com';
$contactPhoneDisplay = '+52 9611077442';
$contactPhoneHref = '+529611077442';
$contactUrl = $isCanonicalPortal ? '#contacto' : $canonicalHome . '#contacto';
$nodeLabel = $sourceDomain !== '' ? $sourceDomain : 'este nodo';

$stylesVersion = is_file(__DIR__ . '/css/styles.css') ? (int)filemtime(__DIR__ . '/css/styles.css') : 1;
$responsiveVersion = is_file(__DIR__ . '/css/responsive.css') ? (int)filemtime(__DIR__ . '/css/responsive.css') : 1;
$h = static fn(string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="description" content="ArcadeCloud Drive: Amazon S3, servicios AWS, ArcadeLink, FederationCloud y FederationDrop.">
  <title>Cloud Drive</title>

  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="icon" href="ellogo.png" type="image/png">

  <link rel="stylesheet" href="css/styles.css?v=<?= $stylesVersion ?>">
  <link rel="stylesheet" href="css/responsive.css?v=<?= $responsiveVersion ?>">

  <style>
    /* Sólo composición de portada. Colores, botones, cards y temas vienen de styles.css. */
    .public-home { max-width: 1180px; margin: 0 auto; padding: 2rem 1rem 4rem; }
    .public-hero { padding: 4rem 0 2rem; }
    .public-hero h1 { font-size: clamp(2.2rem, 5vw, 4.5rem); line-height: 1.03; font-weight: 800; }
    .public-hero .lead { max-width: 760px; }
    .public-actions { display: flex; flex-wrap: wrap; gap: .65rem; }
    .public-section { margin-top: 2.2rem; }
    .public-section-title { margin-bottom: .35rem; }
    .aws-service-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
    .aws-service-item { min-height: 118px; }
    .aws-service-item i { font-size: 1.35rem; }
    .public-link-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
    .public-link-grid .card { height: 100%; }
    .public-link-grid .btn { margin-top: auto; }
    .public-contact-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .public-contact-list { display: grid; gap: .6rem; }
    .public-contact-list a,
    .public-contact-list span { display: flex; align-items: center; gap: .7rem; }
    .public-login-card { max-width: 460px; margin-left: auto; }
    .public-kicker { color: var(--accent); font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .public-muted { color: var(--text-soft); }
    @media (max-width: 991.98px) {
      .public-hero { padding-top: 2.5rem; }
      .aws-service-grid,
      .public-link-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .public-contact-grid { grid-template-columns: 1fr; }
      .public-login-card { max-width: none; margin-left: 0; }
    }
    @media (max-width: 575.98px) {
      .aws-service-grid,
      .public-link-grid { grid-template-columns: 1fr; }
      .public-home { padding-left: .65rem; padding-right: .65rem; }
    }
  </style>

  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
</head>

<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on">

<nav class="navbar navbar-expand-lg navbar-dark px-3 drive-navbar">
  <a class="navbar-brand d-flex align-items-center" href="#inicio">
    <img src="ellogo.png" width="48" height="38" class="drive-brand-logo mr-2" alt="Logo">
    Cloud Drive
  </a>

  <button class="navbar-toggler ml-auto" type="button" data-toggle="collapse" data-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="Abrir navegación">
    <span class="navbar-toggler-icon"></span>
  </button>

  <div class="collapse navbar-collapse" id="publicNav">
    <ul class="navbar-nav ml-auto align-items-lg-center">
      <li class="nav-item"><a class="nav-link" href="#aws">AWS</a></li>
      <li class="nav-item"><a class="nav-link" href="#accesos">Accesos</a></li>
      <li class="nav-item"><a class="nav-link" href="#login">Login</a></li>
      <li class="nav-item"><a class="nav-link" href="#acerca">Acerca de</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= $h($contactUrl) ?>">Contacto</a></li>

    </ul>
  </div>
</nav>

<main class="public-home">
  <section id="inicio" class="public-hero">
    <div class="public-kicker mb-2">ArcadeCloud Drive + FederationCloud</div>
    <h1>Archivos en Amazon S3 con herramientas AWS integradas.</h1>
    <p class="lead public-muted">
      Guarda, organiza, procesa y comparte archivos desde la misma aplicación.
    </p>
    <div class="public-actions mt-4">
      <a class="btn btn-primary" href="#login"><i class="fas fa-sign-in-alt mr-1"></i> Entrar al Drive</a>
      <a class="btn btn-outline-primary" href="<?= $h($arcadeLinkUrl) ?>"><i class="fas fa-link mr-1"></i> ArcadeLink</a>
      <a class="btn btn-outline-primary" href="<?= $h($federationDropUrl) ?>"><i class="fas fa-cloud-upload-alt mr-1"></i> FederationDrop</a>
    </div>
  </section>

  <section id="aws" class="public-section">
    <div class="card p-3 shadow-sm">
      <div class="card-body">
        <div class="public-kicker mb-2"><i class="fab fa-aws mr-1"></i> Servicios AWS</div>
        <h2 class="public-section-title h3">Una de las capacidades que distingue a ArcadeCloud.</h2>
        <p class="public-muted mb-4">
          Los archivos almacenados en S3 pueden utilizar directamente servicios administrados de AWS desde el Drive.
        </p>

        <div class="aws-service-grid">
          <div class="card aws-service-item p-3">
            <i class="fas fa-file-alt mb-2"></i>
            <strong>Textract</strong>
            <small class="text-muted">Extraer texto</small>
          </div>
          <div class="card aws-service-item p-3">
            <i class="fas fa-microphone mb-2"></i>
            <strong>Transcribe</strong>
            <small class="text-muted">Audio y video a texto</small>
          </div>
          <div class="card aws-service-item p-3">
            <i class="fas fa-volume-up mb-2"></i>
            <strong>Polly</strong>
            <small class="text-muted">Texto a voz</small>
          </div>
          <div class="card aws-service-item p-3">
            <i class="fas fa-language mb-2"></i>
            <strong>Translate</strong>
            <small class="text-muted">Traducción</small>
          </div>
          <div class="card aws-service-item p-3">
            <i class="fas fa-image mb-2"></i>
            <strong>Rekognition</strong>
            <small class="text-muted">Análisis de imágenes</small>
          </div>
          <div class="card aws-service-item p-3">
            <i class="fas fa-brain mb-2"></i>
            <strong>Comprehend</strong>
            <small class="text-muted">Análisis de texto</small>
          </div>
        </div>

        <a class="btn btn-primary mt-4" href="#login">
          <i class="fas fa-folder-open mr-1"></i> Entrar y trabajar con archivos
        </a>
      </div>
    </div>
  </section>

  <section id="accesos" class="public-section">
    <div class="public-kicker mb-2">Accesos directos</div>
    <h2 class="h3 mb-3">Ve a la función que necesitas.</h2>

    <div class="public-link-grid">
      <div class="card p-3 d-flex flex-column">
        <div class="card-body d-flex flex-column p-2">
          <i class="fas fa-folder-open mb-2"></i>
          <h3 class="h5">Drive</h3>
          <p class="small text-muted">Para usuarios registrados del nodo.</p>
          <a class="btn btn-primary btn-sm" href="#login">Login</a>
        </div>
      </div>

      <div class="card p-3 d-flex flex-column">
        <div class="card-body d-flex flex-column p-2">
          <i class="fas fa-link mb-2"></i>
          <h3 class="h5">ArcadeLink</h3>
          <p class="small text-muted">Abrir o validar un enlace portable.</p>
          <a class="btn btn-outline-primary btn-sm" href="<?= $h($arcadeLinkUrl) ?>">Abrir</a>
        </div>
      </div>

      <div class="card p-3 d-flex flex-column">
        <div class="card-body d-flex flex-column p-2">
          <i class="fas fa-cloud-upload-alt mb-2"></i>
          <h3 class="h5">FederationDrop</h3>
          <p class="small text-muted">Transferencia temporal sin cuenta del Drive.</p>
          <a class="btn btn-outline-primary btn-sm" href="<?= $h($federationDropUrl) ?>">Subir / pagar</a>
        </div>
      </div>

      <div class="card p-3 d-flex flex-column">
        <div class="card-body d-flex flex-column p-2">
          <i class="fab fa-github mb-2"></i>
          <h3 class="h5">Repositorio</h3>
          <p class="small text-muted">Código y documentación del proyecto.</p>
          <a class="btn btn-outline-primary btn-sm" href="<?= $h($githubUrl) ?>" rel="noopener noreferrer">GitHub</a>
        </div>
      </div>
    </div>
  </section>

  <section id="login" class="public-section">
    <div class="row align-items-center">
      <div class="col-lg-6 mb-3 mb-lg-0">
        <div class="public-kicker mb-2">Acceso al nodo</div>
        <h2 class="h3">Entrar a <?= $h($nodeLabel) ?></h2>
        <p class="public-muted mb-0">Usa las credenciales registradas en este nodo.</p>
      </div>

      <div class="col-lg-6">
        <div class="card public-login-card p-3 shadow-sm">
          <div class="card-body">
            <form action="psesion.php" method="POST">
              <div class="form-group">
                <label for="email">Correo electrónico</label>
                <input class="form-control" id="email" name="email" type="email" autocomplete="username" required>
              </div>
              <div class="form-group">
                <label for="password">Contraseña</label>
                <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
              </div>
              <button class="btn btn-primary btn-block" type="submit">
                <i class="fas fa-sign-in-alt mr-1"></i> Iniciar sesión
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="acerca" class="public-section">
    <div class="public-contact-grid">
      <div class="card about-card p-3">
        <div class="card-body">
          <div class="public-kicker mb-2">Acerca de</div>
          <h2 class="h4">ArcadeCloud Drive</h2>
          <p class="public-muted">
            Proyecto de <strong>jimmybackend</strong> para Amazon S3, servicios AWS y transferencia federada.
          </p>
          <a class="btn btn-outline-primary btn-sm" href="<?= $h($githubUrl) ?>" rel="noopener noreferrer">
            <i class="fab fa-github mr-1"></i> Ver proyecto
          </a>
        </div>
      </div>

      <div id="contacto" class="card about-card p-3">
        <div class="card-body">
          <div class="public-kicker mb-2">Contacto</div>
          <div class="public-contact-list">
            <a href="mailto:<?= $h($authorEmail) ?>">
              <i class="fas fa-envelope"></i>
              <span><?= $h($authorEmail) ?></span>
            </a>
            <a href="tel:<?= $h($contactPhoneHref) ?>">
              <i class="fas fa-phone"></i>
              <span><?= $h($contactPhoneDisplay) ?></span>
            </a>
            <a href="mailto:<?= $h($supportEmail) ?>">
              <i class="fas fa-headset"></i>
              <span><?= $h($supportEmail) ?></span>
            </a>
          </div>
        </div>
      </div>
    </div>
  </section>
</main>

<footer class="container-fluid border-top py-3">
  <div class="container">
    <small class="text-muted">ArcadeCloud Drive · jimmybackend · Amazon S3 + servicios AWS + FederationCloud</small>
  </div>
</footer>

</body>
</html>
