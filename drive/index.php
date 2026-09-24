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
$contactEmail = 'soporte@esforzados.com';
$contactPhoneDisplay = '+52 9611077442';
$contactPhoneHref = '+529611077442';
$contactUrl = $isCanonicalPortal ? '#contacto' : $canonicalHome . '#contacto';
$nodeLabel = $sourceDomain !== '' ? $sourceDomain : 'este nodo';

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta
        name="description"
        content="ArcadeCloud Drive: archivos en Amazon S3, servicios AWS, FederationCloud, ArcadeLink y FederationDrop."
    >
    <meta name="theme-color" content="#07111f">
    <title>ArcadeCloud Drive</title>

    <link rel="icon" href="ellogo.png" type="image/png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --bg: #07111f;
            --surface: #0d1d30;
            --line: rgba(151, 211, 236, .16);
            --text: #f4f8fb;
            --muted: #9fb2c3;
            --green: #35d3b5;
            --blue: #55adff;
        }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(circle at 12% 8%, rgba(53, 211, 181, .12), transparent 34rem),
                radial-gradient(circle at 90% 18%, rgba(85, 173, 255, .1), transparent 34rem),
                var(--bg);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: var(--green); }
        a:hover { color: #83ead8; text-decoration: none; }

        .topbar {
            background: rgba(7, 17, 31, .94);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(16px);
        }

        .topbar .navbar-brand,
        .topbar .nav-link { color: #fff; }

        .topbar .nav-link {
            color: var(--muted);
            font-weight: 650;
        }

        .topbar .nav-link:hover { color: #fff; }

        .brand-logo {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            object-fit: cover;
        }

        .hero {
            min-height: 88vh;
            display: flex;
            align-items: center;
            padding: 8rem 0 5rem;
        }

        .hero h1 {
            max-width: 900px;
            margin: 0 0 1.2rem;
            color: #fff;
            font-size: clamp(3rem, 7vw, 6rem);
            line-height: .96;
            letter-spacing: -.055em;
            font-weight: 850;
        }

        .hero h1 span { color: var(--green); }

        .hero p {
            max-width: 760px;
            color: var(--muted);
            font-size: clamp(1.05rem, 2vw, 1.28rem);
            line-height: 1.72;
        }

        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            margin-top: 2rem;
        }

        .quick-actions .btn {
            padding: .82rem 1.25rem;
            border-radius: .8rem;
            font-weight: 800;
        }

        .btn-main {
            color: #04130f;
            border: 0;
            background: linear-gradient(135deg, var(--green), #8be8d7);
        }

        .btn-main:hover,
        .btn-main:focus { color: #04130f; background: #8be8d7; }

        .btn-ghost {
            color: #e5f8f4;
            border: 1px solid rgba(126, 230, 212, .4);
            background: rgba(8, 24, 37, .52);
        }

        .btn-ghost:hover,
        .btn-ghost:focus {
            color: #fff;
            border-color: var(--green);
            background: rgba(53, 211, 181, .1);
        }

        .section { padding: 5.5rem 0; }

        .section-soft {
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
            background: rgba(10, 24, 40, .72);
        }

        .kicker {
            color: var(--green);
            font-size: .78rem;
            font-weight: 850;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .section h2 {
            max-width: 780px;
            margin: .6rem 0 1rem;
            color: #fff;
            font-size: clamp(2rem, 4vw, 3.2rem);
            line-height: 1.08;
            letter-spacing: -.04em;
            font-weight: 850;
        }

        .section-copy {
            max-width: 780px;
            color: var(--muted);
            line-height: 1.7;
        }

        .feature-grid,
        .link-grid {
            display: grid;
            gap: 1rem;
            margin-top: 2.4rem;
        }

        .feature-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .link-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }

        .feature,
        .external-card,
        .login-card,
        .about-card {
            border: 1px solid var(--line);
            border-radius: 1.15rem;
            background: rgba(14, 30, 49, .88);
        }

        .feature { padding: 1.5rem; }

        .feature i {
            margin-bottom: 1rem;
            color: var(--green);
            font-size: 1.45rem;
        }

        .feature h3,
        .external-card h3,
        .about-card h3 {
            color: #fff;
            font-size: 1.05rem;
            font-weight: 800;
        }

        .feature p,
        .external-card p,
        .about-card p {
            margin-bottom: 0;
            color: var(--muted);
            line-height: 1.6;
        }

        .external-card {
            display: flex;
            min-height: 210px;
            flex-direction: column;
            padding: 1.35rem;
        }

        .external-card .icon {
            width: 46px;
            height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            border-radius: 13px;
            color: var(--green);
            background: rgba(53, 211, 181, .1);
        }

        .external-card .btn { margin-top: auto; }

        .split {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(340px, 460px);
            gap: 2rem;
            align-items: center;
        }

        .login-card,
        .about-card { padding: 1.6rem; }

        .login-card .form-control {
            min-height: 49px;
            color: #fff;
            border: 1px solid rgba(168, 202, 225, .23);
            border-radius: .78rem;
            background: rgba(2, 11, 20, .62);
        }

        .login-card .form-control:focus {
            color: #fff;
            border-color: var(--green);
            background: rgba(2, 11, 20, .78);
            box-shadow: 0 0 0 .2rem rgba(53, 211, 181, .12);
        }

        .login-card label { color: #dbe7ee; font-weight: 650; }

        .contact-list {
            display: grid;
            gap: .75rem;
            margin-top: 1.2rem;
        }

        .contact-list a,
        .contact-list span {
            display: flex;
            align-items: center;
            gap: .7rem;
            padding: .8rem .9rem;
            border-radius: .8rem;
            color: #dce8ef;
            background: rgba(5, 16, 27, .48);
        }

        .contact-list i {
            width: 20px;
            color: var(--green);
            text-align: center;
        }

        .footer {
            padding: 2rem 0 2.5rem;
            border-top: 1px solid var(--line);
            color: #8096a7;
            font-size: .87rem;
        }

        @media (max-width: 991.98px) {
            .hero { min-height: auto; padding-top: 7.5rem; }
            .feature-grid,
            .link-grid { grid-template-columns: 1fr 1fr; }
            .split { grid-template-columns: 1fr; }
        }

        @media (max-width: 575.98px) {
            .section { padding: 4.3rem 0; }
            .hero h1 { font-size: 3.1rem; }
            .feature-grid,
            .link-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark topbar fixed-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="#inicio">
            <img src="ellogo.png" class="brand-logo mr-2" alt="ArcadeCloud Drive">
            <strong>ArcadeCloud Drive</strong>
        </a>

        <button
            class="navbar-toggler"
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
                <li class="nav-item"><a class="nav-link" href="#inicio">Inicio</a></li>
                <li class="nav-item"><a class="nav-link" href="#servicios">Servicios</a></li>
                <li class="nav-item"><a class="nav-link" href="#enlaces">Ir directo</a></li>
                <li class="nav-item"><a class="nav-link" href="#acerca">Acerca de</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= $h($contactUrl) ?>">Contacto</a></li>
            </ul>
        </div>
    </div>
</nav>

<main>
    <section id="inicio" class="hero">
        <div class="container">
            <h1>
                Tus archivos en la nube,
                <span>con herramientas para trabajar.</span>
            </h1>

            <p>
                ArcadeCloud Drive combina almacenamiento en Amazon S3, servicios AWS y
                FederationCloud en una interfaz simple para guardar, procesar, compartir
                y mover archivos cuando lo necesitas.
            </p>

            <div class="quick-actions">
                <a class="btn btn-main" href="#login">
                    <i class="fa-solid fa-right-to-bracket mr-2"></i>Entrar al Drive
                </a>
                <a class="btn btn-ghost" href="<?= $h($arcadeLinkUrl) ?>">
                    <i class="fa-solid fa-link mr-2"></i>Abrir ArcadeLink
                </a>
                <a class="btn btn-ghost" href="<?= $h($federationDropUrl) ?>">
                    <i class="fa-solid fa-cloud-arrow-up mr-2"></i>FederationDrop
                </a>
            </div>
        </div>
    </section>

    <section id="servicios" class="section section-soft">
        <div class="container">
            <div class="kicker">Servicios</div>
            <h2>Una aplicación, tres capacidades principales.</h2>

            <div class="feature-grid">
                <article class="feature">
                    <i class="fa-solid fa-folder-open"></i>
                    <h3>Drive sobre Amazon S3</h3>
                    <p>
                        Organiza y administra archivos y carpetas con almacenamiento físico en S3.
                    </p>
                </article>

                <article class="feature">
                    <i class="fa-brands fa-aws"></i>
                    <h3>Servicios AWS</h3>
                    <p>
                        Textract, Transcribe, Polly, Translate, Rekognition y Comprehend integrados al trabajo diario.
                    </p>
                </article>

                <article class="feature">
                    <i class="fa-solid fa-network-wired"></i>
                    <h3>FederationCloud</h3>
                    <p>
                        ArcadeLink, búsqueda federada para usuarios registrados y transferencia temporal con FederationDrop.
                    </p>
                </article>
            </div>
        </div>
    </section>

    <section id="enlaces" class="section">
        <div class="container">
            <div class="kicker">Ir directo</div>
            <h2>Entra directamente a la herramienta que necesitas.</h2>

            <div class="link-grid">
                <article class="external-card">
                    <div class="icon"><i class="fa-solid fa-right-to-bracket"></i></div>
                    <h3>Drive</h3>
                    <p>Acceso para usuarios registrados de este nodo.</p>
                    <a class="btn btn-main mt-3" href="#login">Login</a>
                </article>

                <article class="external-card">
                    <div class="icon"><i class="fa-solid fa-link"></i></div>
                    <h3>ArcadeLink</h3>
                    <p>Abre y valida un enlace portable recibido.</p>
                    <a class="btn btn-ghost mt-3" href="<?= $h($arcadeLinkUrl) ?>">Abrir ArcadeLink</a>
                </article>

                <article class="external-card">
                    <div class="icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <h3>FederationDrop</h3>
                    <p>Transfiere un archivo sin necesitar cuenta del Drive.</p>
                    <a class="btn btn-ghost mt-3" href="<?= $h($federationDropUrl) ?>">Subir / pagar</a>
                </article>

                <article class="external-card">
                    <div class="icon"><i class="fa-brands fa-github"></i></div>
                    <h3>Repositorio</h3>
                    <p>Código, documentación y evolución pública del proyecto.</p>
                    <a
                        class="btn btn-ghost mt-3"
                        href="<?= $h($githubUrl) ?>"
                        rel="noopener noreferrer"
                    >GitHub</a>
                </article>
            </div>
        </div>
    </section>

    <section id="login" class="section section-soft">
        <div class="container">
            <div class="split">
                <div>
                    <div class="kicker">Acceso</div>
                    <h2>Entrar a <?= $h($nodeLabel) ?></h2>
                    <p class="section-copy">
                        Si tienes cuenta en este nodo, inicia sesión aquí.
                    </p>
                </div>

                <div class="login-card">
                    <form action="psesion.php" method="POST">
                        <div class="form-group">
                            <label for="email">Correo electrónico</label>
                            <input
                                class="form-control"
                                id="email"
                                name="email"
                                type="email"
                                autocomplete="username"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="password">Contraseña</label>
                            <input
                                class="form-control"
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <button class="btn btn-main btn-block py-3" type="submit">
                            Iniciar sesión
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <section id="acerca" class="section">
        <div class="container">
            <div class="split">
                <div>
                    <div class="kicker">Acerca de</div>
                    <h2>ArcadeCloud Drive + FederationCloud</h2>
                    <p class="section-copy">
                        Proyecto de <strong>jimmybackend</strong> para trabajar con archivos,
                        servicios AWS y transferencia federada desde una misma plataforma.
                    </p>

                    <a
                        class="btn btn-ghost mt-3"
                        href="<?= $h($githubUrl) ?>"
                        rel="noopener noreferrer"
                    >
                        <i class="fa-brands fa-github mr-2"></i>Ver proyecto
                    </a>
                </div>

                <div id="contacto" class="about-card">
                    <h3>Contacto</h3>
                    <div class="contact-list">
                        <a href="mailto:<?= $h($contactEmail) ?>">
                            <i class="fa-solid fa-envelope"></i>
                            <span><?= $h($contactEmail) ?></span>
                        </a>

                        <a href="tel:<?= $h($contactPhoneHref) ?>">
                            <i class="fa-solid fa-phone"></i>
                            <span><?= $h($contactPhoneDisplay) ?></span>
                        </a>

                        <span>
                            <i class="fa-solid fa-globe"></i>
                            <span>drive.esforzados.com</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="footer">
    <div class="container d-md-flex justify-content-between align-items-center">
        <div>ArcadeCloud Drive · jimmybackend</div>
        <div class="mt-2 mt-md-0">
            <a href="#servicios" class="mr-3">Servicios</a>
            <a href="#enlaces" class="mr-3">Ir directo</a>
            <a href="<?= $h($contactUrl) ?>">Contacto</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
