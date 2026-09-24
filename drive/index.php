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
  <meta
    name="description"
    content="ArcadeCloud Drive: Amazon S3, servicios AWS, FederationCloud, ArcadeLink y FederationDrop."
  >
  <title>ArcadeCloud Drive</title>

  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="icon" href="ellogo.png" type="image/png">

  <link rel="stylesheet" href="css/styles.css?v=<?= $stylesVersion ?>">
  <link rel="stylesheet" href="css/responsive.css?v=<?= $responsiveVersion ?>">

  <style>
    /* Composición pública basada en la UI real del Drive. */
    .home-shell {
      max-width: 1500px;
      margin: 0 auto;
      padding: 1.2rem 1.1rem 2rem;
    }

    .home-nav {
      border-bottom: 1px solid rgba(84, 255, 136, .35);
    }

    .home-brand {
      line-height: 1.05;
    }

    .home-brand-main {
      display: block;
      font-size: 1.38rem;
      font-weight: 800;
      letter-spacing: -.02em;
    }

    .home-brand-main strong {
      color: #58ef86;
    }

    .home-brand-sub {
      display: block;
      color: var(--text-soft);
      font-size: .75rem;
      margin-top: .16rem;
    }

    .home-carousel {
      overflow: hidden;
      border: 1px solid rgba(74, 173, 255, .32);
      border-radius: 15px;
      background: #07111c;
      box-shadow: 0 18px 45px rgba(0,0,0,.32);
    }

    .home-carousel .carousel-item {
      background: #07111c;
    }

    .home-carousel .carousel-item img {
      display: block;
      width: 100%;
      height: auto;
      object-fit: contain;
    }

    .home-carousel .carousel-item > a {
      display: block;
      width: 100%;
      line-height: 0;
      cursor: pointer;
    }

    .home-carousel .carousel-control-prev,
    .home-carousel .carousel-control-next {
      width: 5.5%;
    }

    .home-links {
      display: grid;
      grid-template-columns: repeat(4, minmax(0,1fr));
      gap: .9rem;
      margin-top: 1.05rem;
    }

    .home-link-card {
      display: flex;
      align-items: center;
      min-height: 104px;
      padding: 1rem 1.1rem;
      border: 1px solid rgba(84, 173, 230, .3);
      border-radius: 13px;
      background: rgba(10, 25, 40, .92);
      color: inherit;
      transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
    }

    .home-link-card:hover {
      color: inherit;
      transform: translateY(-2px);
      border-color: #58ef86;
      box-shadow: 0 10px 28px rgba(0,0,0,.28);
      text-decoration: none;
    }

    .home-link-icon {
      width: 54px;
      height: 54px;
      flex: 0 0 54px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-right: .9rem;
      border-radius: 14px;
      background: rgba(84,255,136,.09);
      color: #58ef86;
      font-size: 1.65rem;
    }

    .home-link-card.arcadelink .home-link-icon {
      color: #55baff;
      background: rgba(85,186,255,.09);
    }

    .home-link-card.drop .home-link-icon {
      color: #ad67ff;
      background: rgba(173,103,255,.1);
    }

    .home-link-card.github .home-link-icon {
      color: #fff;
      background: rgba(255,255,255,.07);
    }

    .home-link-copy strong {
      display: block;
      color: #fff;
      font-size: 1rem;
    }

    .home-link-copy small {
      display: block;
      margin-top: .18rem;
      color: var(--text-soft);
    }

    .home-link-arrow {
      margin-left: auto;
      color: #58ef86;
    }

    .home-footer {
      border-top: 1px solid rgba(84,173,230,.2);
      margin-top: 1.35rem;
      padding-top: 1.2rem;
    }

    .home-contact {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 1.15rem 2rem;
    }

    .home-contact a {
      color: var(--text-soft);
      text-decoration: none;
    }

    .home-contact a:hover {
      color: #58ef86;
    }

    .home-modal .modal-content {
      border: 1px solid rgba(84,173,230,.3);
      background: #091827;
      color: var(--text);
      box-shadow: 0 24px 60px rgba(0,0,0,.45);
    }

    .home-modal .modal-header,
    .home-modal .modal-footer {
      border-color: rgba(84,173,230,.18);
    }

    .home-modal .close {
      color: #fff;
      text-shadow: none;
      opacity: .8;
    }

    @media (max-width: 991.98px) {
      .home-links {
        grid-template-columns: repeat(2, minmax(0,1fr));
      }
    }

    @media (max-width: 575.98px) {
      .home-shell {
        padding-left: .55rem;
        padding-right: .55rem;
      }

      .home-carousel {
        border-radius: 10px;
      }

      .home-carousel .carousel-item img {
        width: 100%;
        height: auto;
        max-height: 42vh;
        object-fit: contain;
      }

      .home-links {
        grid-template-columns: 1fr;
      }

      .home-link-card {
        min-height: 86px;
      }
    }
  </style>

  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
</head>

<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on">

<nav class="navbar navbar-expand-lg navbar-dark px-3 drive-navbar home-nav">
  <a class="navbar-brand d-flex align-items-center" href="#inicio">
    <img src="ellogo.png" width="50" height="40" class="drive-brand-logo mr-2" alt="ArcadeCloud Drive">
    <span class="home-brand">
      <span class="home-brand-main">ArcadeCloud <strong>Drive</strong></span>
      <span class="home-brand-sub">Proyecto de jimmybackend</span>
    </span>
  </a>

  <button
    class="navbar-toggler ml-auto"
    type="button"
    data-toggle="collapse"
    data-target="#publicNav"
    aria-controls="publicNav"
    aria-expanded="false"
    aria-label="Abrir navegación"
  >
    <span class="navbar-toggler-icon"></span>
  </button>

  <div class="collapse navbar-collapse" id="publicNav">
    <ul class="navbar-nav ml-auto align-items-lg-center">
      <li class="nav-item"><a class="nav-link" href="#inicio"><i class="fas fa-home mr-1"></i>Inicio</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= $h($arcadeLinkUrl) ?>"><i class="fas fa-project-diagram mr-1"></i>Federación</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= $h($federationDropUrl) ?>"><i class="fas fa-cloud-upload-alt mr-1"></i>Drop</a></li>
      <li class="nav-item"><a class="nav-link" href="#" data-toggle="modal" data-target="#aboutModal"><i class="fas fa-info-circle mr-1"></i>Acerca de</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= $h($contactUrl) ?>"><i class="fas fa-envelope mr-1"></i>Contacto</a></li>
      <li class="nav-item"><a class="nav-link" href="<?= $h($githubUrl) ?>" rel="noopener noreferrer"><i class="fab fa-github mr-1"></i>GitHub</a></li>
    </ul>
  </div>
</nav>

<main id="inicio" class="home-shell">
  <section>
    <div id="arcadeHomeCarousel" class="carousel slide home-carousel" data-ride="carousel" data-interval="6500">
      <div class="carousel-inner">
        <div class="carousel-item active">
          <a
            href="#"
            data-toggle="modal"
            data-target="#loginModal"
            aria-label="Explorar Amazon S3 y servicios AWS en ArcadeCloud Drive"
          >
            <img
              src="images/home/carousel-01-s3-aws.png"
              alt="Archivos en Amazon S3 con servicios AWS integrados"
            >
          </a>
        </div>

        <div class="carousel-item">
          <a
            href="#"
            data-toggle="modal"
            data-target="#loginModal"
            aria-label="Abrir ArcadeCloud Drive para trabajar con servicios AWS"
          >
            <img
              src="images/home/carousel-02-s3-aws-alt.png"
              alt="Amazon S3 y servicios AWS en ArcadeCloud Drive"
            >
          </a>
        </div>

        <div class="carousel-item">
          <a
            href="#"
            data-toggle="modal"
            data-target="#loginModal"
            aria-label="Explorar servicios AWS desde ArcadeCloud Drive"
          >
            <img
              src="images/home/carousel-03-s3-servicios.png"
              alt="Amazon S3 conectado con servicios AWS"
            >
          </a>
        </div>

        <div class="carousel-item">
          <a
            href="<?= $h($arcadeLinkUrl) ?>"
            aria-label="Abrir FederationCloud"
          >
            <img
              src="images/home/carousel-04-federationcloud.png"
              alt="Transferencia federada entre nodos descentralizados"
            >
          </a>
        </div>

        <div class="carousel-item">
          <a
            href="<?= $h($federationDropUrl) ?>"
            aria-label="Ir a FederationDrop"
          >
            <img
              src="images/home/carousel-05-federationdrop.png"
              alt="FederationDrop para compartir archivos temporalmente"
            >
          </a>
        </div>
      </div>

      <a class="carousel-control-prev" href="#arcadeHomeCarousel" role="button" data-slide="prev" aria-label="Anterior">
        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
      </a>
      <a class="carousel-control-next" href="#arcadeHomeCarousel" role="button" data-slide="next" aria-label="Siguiente">
        <span class="carousel-control-next-icon" aria-hidden="true"></span>
      </a>
    </div>
  </section>

  <section class="home-links" aria-label="Accesos directos">
    <a class="home-link-card" href="#" data-toggle="modal" data-target="#loginModal">
      <span class="home-link-icon"><i class="fas fa-folder-open"></i></span>
      <span class="home-link-copy">
        <strong>Acceso al nodo</strong>
        <small>Inicia sesión en tu Drive</small>
      </span>
      <i class="fas fa-chevron-right home-link-arrow"></i>
    </a>

    <a class="home-link-card arcadelink" href="<?= $h($arcadeLinkUrl) ?>">
      <span class="home-link-icon"><i class="fas fa-link"></i></span>
      <span class="home-link-copy">
        <strong>ArcadeLink</strong>
        <small>Abre o comparte enlaces</small>
      </span>
      <i class="fas fa-chevron-right home-link-arrow"></i>
    </a>

    <a class="home-link-card drop" href="<?= $h($federationDropUrl) ?>">
      <span class="home-link-icon"><i class="fas fa-cloud-upload-alt"></i></span>
      <span class="home-link-copy">
        <strong>FederationDrop</strong>
        <small>Sube y comparte temporalmente</small>
      </span>
      <i class="fas fa-chevron-right home-link-arrow"></i>
    </a>

    <a class="home-link-card github" href="<?= $h($githubUrl) ?>" rel="noopener noreferrer">
      <span class="home-link-icon"><i class="fab fa-github"></i></span>
      <span class="home-link-copy">
        <strong>GitHub</strong>
        <small>Repositorio del proyecto</small>
      </span>
      <i class="fas fa-chevron-right home-link-arrow"></i>
    </a>
  </section>

  <footer id="contacto" class="home-footer">
    <div class="home-contact">
      <a href="mailto:<?= $h($authorEmail) ?>"><i class="fas fa-envelope mr-2"></i><?= $h($authorEmail) ?></a>
      <a href="tel:<?= $h($contactPhoneHref) ?>"><i class="fas fa-phone mr-2"></i><?= $h($contactPhoneDisplay) ?></a>
      <a href="mailto:<?= $h($supportEmail) ?>"><i class="fas fa-headset mr-2"></i><?= $h($supportEmail) ?></a>
      <a href="<?= $h($githubUrl) ?>" rel="noopener noreferrer"><i class="fab fa-github mr-2"></i>jimmybackend/s3</a>
    </div>
  </footer>
</main>

<div class="modal fade home-modal" id="loginModal" tabindex="-1" role="dialog" aria-labelledby="loginModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="loginModalTitle">Acceso a <?= $h($nodeLabel) ?></h5>
          <small class="text-muted">Credenciales registradas en este nodo</small>
        </div>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form action="psesion.php" method="POST">
        <div class="modal-body">
          <div class="form-group">
            <label for="email">Correo electrónico</label>
            <input class="form-control" id="email" name="email" type="email" autocomplete="username" required>
          </div>

          <div class="form-group mb-0">
            <label for="password">Contraseña</label>
            <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-primary btn-block" type="submit">
            <i class="fas fa-sign-in-alt mr-1"></i> Iniciar sesión
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade home-modal" id="aboutModal" tabindex="-1" role="dialog" aria-labelledby="aboutModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="aboutModalTitle">ArcadeCloud Drive</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <p class="mb-2">Proyecto de <strong>jimmybackend</strong>.</p>
        <p class="text-muted mb-3">Amazon S3 · servicios AWS · FederationCloud · FederationDrop.</p>
        <a class="btn btn-outline-primary btn-sm" href="<?= $h($githubUrl) ?>" rel="noopener noreferrer">
          <i class="fab fa-github mr-1"></i> Ver repositorio
        </a>
      </div>
    </div>
  </div>
</div>

</body>
</html>
