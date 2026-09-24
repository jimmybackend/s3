<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Setup/SetupEntryGuard.php';

$setupGuard = new \ArcadeCloud\Drive\Setup\SetupEntryGuard();
if ($setupGuard->isSetupPending()) {
    header('Location: setup/', true, 302);
    exit;
}

// Portada pública: no carga la aplicación completa ni crea sesión.
// Presenta el producto, conserva el login local y atribuye FederationDrop.
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

$federationDropPortal = $canonicalHome . 'federationdrop/';
$federationDropUrl = $federationDropPortal;
if ($sourceDomain !== '') {
    $federationDropUrl .= '?source=' . rawurlencode($sourceDomain);
}

$arcadeLinkUrl = 'federationcloud/';
$contactEmail = 'soporte@esforzados.com';
$contactPhone = '9611077442';
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
        content="ArcadeCloud Drive: trabaja directamente con archivos almacenados en Amazon S3, usa servicios AWS, ArcadeLink, FederationCloud y FederationDrop."
    >
    <meta name="theme-color" content="#07111f">
    <title>ArcadeCloud Drive · Archivos, AWS y FederationCloud</title>

    <link rel="icon" href="ellogo.png" type="image/png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --bg: #07111f;
            --surface: #0d1d30;
            --surface-2: #11263d;
            --line: rgba(151, 211, 236, .16);
            --text: #f4f8fb;
            --muted: #9fb2c3;
            --green: #35d3b5;
            --green-soft: rgba(53, 211, 181, .11);
            --blue: #55adff;
            --blue-soft: rgba(85, 173, 255, .11);
            --shadow: 0 24px 70px rgba(0, 0, 0, .25);
        }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(circle at 12% 8%, rgba(53, 211, 181, .12), transparent 32rem),
                radial-gradient(circle at 92% 18%, rgba(85, 173, 255, .11), transparent 34rem),
                var(--bg);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: var(--green); }
        a:hover { color: #7ce7d3; text-decoration: none; }

        .public-nav {
            background: rgba(7, 17, 31, .93);
            border-bottom: 1px solid var(--line);
            backdrop-filter: blur(16px);
        }

        .public-nav .navbar-brand,
        .public-nav .nav-link { color: #fff; }

        .public-nav .nav-link {
            color: var(--muted);
            font-weight: 650;
            margin: 0 .12rem;
        }

        .public-nav .nav-link:hover { color: #fff; }

        .brand-logo {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            object-fit: cover;
        }

        .btn-primary-arcade {
            color: #031511;
            border: 0;
            background: linear-gradient(135deg, var(--green), #8aead8);
            font-weight: 800;
            box-shadow: 0 12px 28px rgba(53, 211, 181, .18);
        }

        .btn-primary-arcade:hover,
        .btn-primary-arcade:focus {
            color: #031511;
            background: linear-gradient(135deg, #74e5d0, #b0f4e7);
            transform: translateY(-1px);
        }

        .btn-outline-arcade {
            color: #e4faf6;
            border: 1px solid rgba(122, 231, 211, .42);
            background: rgba(8, 24, 37, .52);
            font-weight: 750;
        }

        .btn-outline-arcade:hover,
        .btn-outline-arcade:focus {
            color: #fff;
            border-color: var(--green);
            background: var(--green-soft);
        }

        .hero {
            min-height: 94vh;
            display: flex;
            align-items: center;
            padding: 8rem 0 5.2rem;
        }

        .eyebrow,
        .badge-soft {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: rgba(13, 31, 48, .72);
            color: #cbd8e2;
            font-size: .8rem;
            font-weight: 750;
        }

        .eyebrow {
            padding: .48rem .78rem;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .badge-soft { padding: .38rem .65rem; }

        .hero h1 {
            margin: 1.4rem 0 1.25rem;
            max-width: 860px;
            color: #fff;
            font-size: clamp(2.8rem, 6vw, 5.6rem);
            line-height: .99;
            letter-spacing: -.055em;
            font-weight: 850;
        }

        .hero h1 span { color: var(--green); }

        .hero-copy,
        .section-copy {
            color: var(--muted);
            line-height: 1.72;
        }

        .hero-copy {
            max-width: 760px;
            font-size: clamp(1.05rem, 2vw, 1.26rem);
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .8rem;
            margin-top: 2rem;
        }

        .hero-actions .btn {
            padding: .82rem 1.28rem;
            border-radius: .82rem;
        }

        .file-demo {
            padding: 1.15rem;
            border: 1px solid var(--line);
            border-radius: 1.4rem;
            background: linear-gradient(180deg, rgba(17, 38, 61, .96), rgba(7, 19, 32, .97));
            box-shadow: var(--shadow);
        }

        .file-demo-head {
            display: flex;
            align-items: center;
            gap: .8rem;
            padding: .65rem;
            border-bottom: 1px solid var(--line);
        }

        .file-demo-icon {
            width: 46px;
            height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 1.25rem;
        }

        .file-demo-name { font-weight: 750; color: #fff; }

        .aws-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .65rem;
            padding: 1rem .65rem .65rem;
        }

        .aws-action {
            padding: .85rem;
            border: 1px solid var(--line);
            border-radius: .9rem;
            background: rgba(4, 14, 24, .55);
        }

        .aws-action strong {
            display: block;
            color: #fff;
            font-size: .9rem;
        }

        .aws-action span {
            color: var(--muted);
            font-size: .78rem;
        }

        .section { padding: 6rem 0; }

        .section-soft {
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
            background: linear-gradient(180deg, rgba(11, 24, 40, .78), rgba(7, 17, 31, .9));
        }

        .section-kicker {
            color: var(--green);
            font-size: .78rem;
            font-weight: 850;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .section-title {
            max-width: 820px;
            margin: .65rem 0 1rem;
            color: #fff;
            font-size: clamp(2rem, 4vw, 3.35rem);
            line-height: 1.08;
            letter-spacing: -.04em;
            font-weight: 850;
        }

        .section-copy {
            max-width: 800px;
            font-size: 1.04rem;
        }

        .workflow-card,
        .path-card,
        .login-card,
        .about-card,
        .contact-card {
            height: 100%;
            border: 1px solid var(--line);
            border-radius: 1.2rem;
            background: rgba(14, 30, 49, .88);
            box-shadow: 0 12px 34px rgba(0, 0, 0, .13);
        }

        .workflow-card { padding: 1.35rem; }

        .workflow-icon,
        .path-icon {
            width: 48px;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            border-radius: 14px;
            background: var(--green-soft);
            color: var(--green);
            font-size: 1.25rem;
        }

        .workflow-card h3,
        .path-card h3,
        .about-card h3 {
            color: #fff;
            font-size: 1.05rem;
            font-weight: 800;
        }

        .workflow-card p,
        .path-card p,
        .about-card p {
            color: var(--muted);
            line-height: 1.6;
            margin-bottom: 0;
        }

        .workflow-source {
            display: inline-block;
            margin-top: .85rem;
            color: #80c5ff;
            font-size: .76rem;
            font-weight: 700;
        }

        .paths-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
            margin-top: 2.6rem;
        }

        .path-card {
            display: flex;
            flex-direction: column;
            padding: 1.55rem;
        }

        .path-card.featured {
            border-color: rgba(53, 211, 181, .42);
            background: linear-gradient(180deg, rgba(18, 48, 57, .88), rgba(13, 30, 49, .94));
        }

        .path-list {
            margin: 1.2rem 0 1.4rem;
            padding: 0;
            list-style: none;
        }

        .path-list li {
            display: flex;
            gap: .65rem;
            margin-bottom: .7rem;
            color: #d6e2ea;
            font-size: .91rem;
        }

        .path-list i {
            color: var(--green);
            margin-top: .2rem;
        }

        .path-card .btn { margin-top: auto; }

        .registered-note {
            margin-top: .9rem;
            padding: .78rem .9rem;
            border-radius: .8rem;
            background: rgba(85, 173, 255, .08);
            color: #b8d9f7;
            font-size: .82rem;
        }

        .split {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(340px, 470px);
            gap: 2rem;
            align-items: center;
        }

        .login-card,
        .about-card,
        .contact-card { padding: 1.6rem; }

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

        .node-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .4rem .72rem;
            margin-bottom: 1rem;
            border-radius: 999px;
            color: #abd5fb;
            background: var(--blue-soft);
            font-size: .8rem;
        }

        .about-grid {
            display: grid;
            grid-template-columns: 1.35fr .8fr;
            gap: 1rem;
            margin-top: 2.4rem;
        }

        .contact-data {
            display: grid;
            gap: .8rem;
            margin-top: 1.15rem;
        }

        .contact-data a,
        .contact-data span {
            display: flex;
            gap: .75rem;
            align-items: center;
            padding: .8rem .9rem;
            border-radius: .8rem;
            background: rgba(5, 16, 27, .48);
            color: #d9e8f0;
        }

        .contact-data i { color: var(--green); width: 20px; text-align: center; }

        .contact-card {
            background: linear-gradient(135deg, rgba(17, 38, 61, .96), rgba(8, 24, 38, .96));
        }

        .footer {
            padding: 2rem 0 2.6rem;
            border-top: 1px solid var(--line);
            color: #8096a7;
            font-size: .87rem;
        }

        .footer a { color: #b5c8d6; }

        @media (max-width: 991.98px) {
            .hero { min-height: auto; padding-top: 7.4rem; }
            .file-demo { margin-top: 2.8rem; }
            .paths-grid { grid-template-columns: 1fr; }
            .split,
            .about-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 575.98px) {
            .section { padding: 4.4rem 0; }
            .hero h1 { font-size: 3rem; }
            .aws-actions { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark public-nav fixed-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="#home">
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
                <li class="nav-item"><a class="nav-link" href="#home">Inicio</a></li>
                <li class="nav-item"><a class="nav-link" href="#archivos-aws">Archivos + AWS</a></li>
                <li class="nav-item"><a class="nav-link" href="#formas-de-uso">Cómo usarlo</a></li>
                <li class="nav-item"><a class="nav-link" href="#acerca">Acerca de</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= $h($contactUrl) ?>">Contacto</a></li>
                <li class="nav-item ml-lg-2">
                    <a class="btn btn-sm btn-outline-arcade px-3 py-2" href="#login">
                        Iniciar sesión
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main>
    <section id="home" class="hero">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7">
                    <div class="eyebrow">
                        <i class="fa-solid fa-file-arrow-up"></i>
                        Archivos en S3 + servicios AWS
                    </div>

                    <h1>
                        No sólo guardes archivos.
                        <span>Trabaja con ellos.</span>
                    </h1>

                    <p class="hero-copy">
                        ArcadeCloud Drive guarda tus archivos en Amazon S3 y te permite operar
                        sobre ellos desde una interfaz sencilla: extraer texto, transcribir audio
                        o video, crear audio desde texto, traducir, analizar imágenes y analizar
                        contenido textual. Cuando necesitas compartir o mover información entre
                        nubes, FederationCloud, ArcadeLink y FederationDrop amplían el alcance.
                    </p>

                    <div class="hero-actions">
                        <a class="btn btn-primary-arcade" href="#login">
                            <i class="fa-solid fa-right-to-bracket mr-2"></i>
                            Login directo
                        </a>
                        <a class="btn btn-outline-arcade" href="<?= $h($arcadeLinkUrl) ?>">
                            <i class="fa-solid fa-link mr-2"></i>
                            Abrir ArcadeLink
                        </a>
                        <a class="btn btn-outline-arcade" href="<?= $h($federationDropUrl) ?>">
                            <i class="fa-solid fa-cloud-arrow-up mr-2"></i>
                            Subir con FederationDrop
                        </a>
                    </div>

                    <div class="mt-4">
                        <span class="badge-soft mr-2 mb-2"><i class="fa-brands fa-aws"></i> Amazon S3</span>
                        <span class="badge-soft mr-2 mb-2"><i class="fa-solid fa-file-lines"></i> Textract</span>
                        <span class="badge-soft mr-2 mb-2"><i class="fa-solid fa-microphone"></i> Transcribe</span>
                        <span class="badge-soft mr-2 mb-2"><i class="fa-solid fa-headphones"></i> Polly</span>
                        <span class="badge-soft mr-2 mb-2"><i class="fa-solid fa-language"></i> Translate</span>
                        <span class="badge-soft mr-2 mb-2"><i class="fa-solid fa-tags"></i> Rekognition</span>
                        <span class="badge-soft mb-2"><i class="fa-solid fa-brain"></i> Comprehend</span>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="file-demo">
                        <div class="file-demo-head">
                            <div class="file-demo-icon"><i class="fa-solid fa-file"></i></div>
                            <div>
                                <div class="file-demo-name">mi-archivo-en-s3.ext</div>
                                <small style="color:var(--muted);">Un archivo, varias acciones posibles</small>
                            </div>
                        </div>
                        <div class="aws-actions">
                            <div class="aws-action"><strong>Extraer texto</strong><span>Amazon Textract</span></div>
                            <div class="aws-action"><strong>Transcribir</strong><span>Amazon Transcribe</span></div>
                            <div class="aws-action"><strong>Crear audio</strong><span>Amazon Polly</span></div>
                            <div class="aws-action"><strong>Traducir</strong><span>Amazon Translate</span></div>
                            <div class="aws-action"><strong>Analizar imagen</strong><span>Amazon Rekognition</span></div>
                            <div class="aws-action"><strong>Analizar texto</strong><span>Amazon Comprehend</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="archivos-aws" class="section section-soft">
        <div class="container">
            <div class="section-kicker">Archivos que hacen más</div>
            <h2 class="section-title">El archivo es el punto de entrada a los servicios AWS.</h2>
            <p class="section-copy">
                Estas acciones no son una maqueta de marketing: son las operaciones que ya aparecen
                junto a los archivos del Drive en <code>bloque_archivos.php</code>. Según el tipo de
                archivo, la interfaz habilita sólo las herramientas que tienen sentido.
            </p>

            <div class="row mt-5">
                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="workflow-card">
                        <div class="workflow-icon"><i class="fa-solid fa-file-image"></i></div>
                        <h3>Imagen o documento → texto</h3>
                        <p>Extrae contenido desde documentos compatibles y lo devuelve al flujo del Drive.</p>
                        <span class="workflow-source">Amazon Textract</span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="workflow-card">
                        <div class="workflow-icon"><i class="fa-solid fa-wave-square"></i></div>
                        <h3>Audio o video → texto</h3>
                        <p>Inicia una transcripción asíncrona y consulta su estado hasta completar.</p>
                        <span class="workflow-source">Amazon Transcribe</span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="workflow-card">
                        <div class="workflow-icon"><i class="fa-solid fa-headphones"></i></div>
                        <h3>Texto → audio</h3>
                        <p>Convierte documentos de texto compatibles a voz desde el propio archivo.</p>
                        <span class="workflow-source">Amazon Polly</span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="workflow-card">
                        <div class="workflow-icon"><i class="fa-solid fa-language"></i></div>
                        <h3>Texto → otro idioma</h3>
                        <p>Traduce contenido de archivos compatibles sin sacarlos del flujo de trabajo.</p>
                        <span class="workflow-source">Amazon Translate</span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="workflow-card">
                        <div class="workflow-icon"><i class="fa-solid fa-eye"></i></div>
                        <h3>Imagen → etiquetas</h3>
                        <p>Analiza imágenes almacenadas y obtiene información visual desde la misma interfaz.</p>
                        <span class="workflow-source">Amazon Rekognition</span>
                    </article>
                </div>

                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="workflow-card">
                        <div class="workflow-icon"><i class="fa-solid fa-brain"></i></div>
                        <h3>Texto → análisis</h3>
                        <p>Aplica análisis de contenido textual directamente sobre archivos compatibles.</p>
                        <span class="workflow-source">Amazon Comprehend</span>
                    </article>
                </div>
            </div>

            <p class="section-copy mt-2">
                Y antes de usar IA o procesamiento, el archivo sigue siendo un archivo normal:
                puedes verlo, editar texto/código, revisar metadatos, renombrar, mover, descargar,
                compartir, proteger, cifrar o eliminar según tus permisos.
            </p>
        </div>
    </section>

    <section id="formas-de-uso" class="section">
        <div class="container">
            <div class="section-kicker">Tres formas de usar ArcadeCloud</div>
            <h2 class="section-title">No todos necesitan una cuenta ni usan la nube de la misma manera.</h2>
            <p class="section-copy">
                Separamos claramente el Drive registrado, los enlaces portables ArcadeLink y las
                transferencias temporales FederationDrop.
            </p>

            <div class="paths-grid">
                <article class="path-card featured">
                    <div class="path-icon"><i class="fa-solid fa-user-lock"></i></div>
                    <h3>1. Usuario registrado del Drive</h3>
                    <p>Para quien trabaja de forma continua con archivos, carpetas y servicios AWS.</p>
                    <ul class="path-list">
                        <li><i class="fa-solid fa-check"></i><span>Almacenamiento multiusuario sobre S3.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Acciones AWS sobre los archivos.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Crear ArcadeLinks desde sus propios archivos.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Buscar recursos públicos entre nodos FederationCloud.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Copiar recursos públicos compatibles a Mi Drive.</span></li>
                    </ul>
                    <div class="registered-note">
                        <i class="fa-solid fa-lock mr-1"></i>
                        La búsqueda global entre nodos requiere iniciar sesión.
                    </div>
                    <a class="btn btn-primary-arcade mt-3" href="#login">Iniciar sesión</a>
                </article>

                <article class="path-card">
                    <div class="path-icon"><i class="fa-solid fa-link"></i></div>
                    <h3>2. Usuario de ArcadeLink</h3>
                    <p>Para transportar una referencia portable y firmada a un archivo o colección.</p>
                    <ul class="path-list">
                        <li><i class="fa-solid fa-check"></i><span>Validar un <code>.arcadelink</code> recibido.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Comprobar firma, origen y política del recurso.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Abrir o descargar cuando la política lo permite.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Solicitar clave/acceso cuando el recurso es privado.</span></li>
                    </ul>
                    <div class="registered-note">
                        Recibir y validar un ArcadeLink no equivale a tener una cuenta del Drive.
                    </div>
                    <a class="btn btn-outline-arcade mt-3" href="<?= $h($arcadeLinkUrl) ?>">Abrir ArcadeLink</a>
                </article>

                <article class="path-card">
                    <div class="path-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                    <h3>3. Sólo transferir un archivo</h3>
                    <p>Para quien no necesita Drive y sólo quiere enviar o mantener un archivo temporalmente.</p>
                    <ul class="path-list">
                        <li><i class="fa-solid fa-check"></i><span>No necesita cuenta del Drive.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Elige tiempo disponible y límite de descargas.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Pago y custodia desde el portal comercial.</span></li>
                        <li><i class="fa-solid fa-check"></i><span>Puede identificarse con Google o continuar por correo.</span></li>
                    </ul>
                    <div class="registered-note">
                        FederationDrop cobra el servicio de transferencia/retención, no el contenido del archivo.
                    </div>
                    <a class="btn btn-outline-arcade mt-3" href="<?= $h($federationDropUrl) ?>">Ir a FederationDrop</a>
                </article>
            </div>
        </div>
    </section>

    <section id="login" class="section section-soft">
        <div class="container">
            <div class="split">
                <div>
                    <div class="section-kicker">Login directo</div>
                    <h2 class="section-title">Entrar a <?= $h($nodeLabel) ?></h2>
                    <p class="section-copy">
                        El acceso es local al nodo. Una cuenta registrada habilita el Drive,
                        las acciones AWS sobre archivos y las funciones federadas reservadas a usuarios,
                        incluida la búsqueda global entre nodos.
                    </p>
                    <span class="node-chip">
                        <i class="fa-solid fa-server"></i>
                        Nodo actual: <?= $h($nodeLabel) ?>
                    </span>
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
                                placeholder="tu-correo@ejemplo.com"
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
                                placeholder="Contraseña"
                                required
                            >
                        </div>

                        <button class="btn btn-primary-arcade btn-block py-3" type="submit">
                            <i class="fa-solid fa-right-to-bracket mr-2"></i>
                            Iniciar sesión
                        </button>
                    </form>

                    <div class="mt-4 pt-3" style="border-top:1px solid var(--line);">
                        <div class="small mb-2" style="color:var(--muted);">¿Sólo necesitas transferir un archivo?</div>
                        <a class="btn btn-outline-arcade btn-block" href="<?= $h($federationDropUrl) ?>">
                            <i class="fa-solid fa-cloud-arrow-up mr-2"></i>
                            FederationDrop sin cuenta del Drive
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="acerca" class="section">
        <div class="container">
            <div class="section-kicker">Acerca de</div>
            <h2 class="section-title">Una herramienta para trabajar con archivos en la nube, no sólo almacenarlos.</h2>
            <p class="section-copy">
                ArcadeCloud Drive nació alrededor de Amazon S3 y evolucionó a una plataforma donde
                un archivo puede almacenarse, organizarse, procesarse con servicios AWS, compartirse
                como ArcadeLink o moverse temporalmente mediante FederationDrop. FederationCloud
                agrega comunicación entre nodos sin publicar las credenciales AWS ni las rutas privadas
                de cada instalación.
            </p>

            <div class="about-grid">
                <article class="about-card">
                    <div class="workflow-icon"><i class="fa-solid fa-code"></i></div>
                    <h3>Proyecto y desarrollo</h3>
                    <p>
                        Repositorio público <strong>jimmybackend/s3</strong>. La aplicación mantiene
                        MySQL como fuente de verdad para navegación, Amazon S3 como almacenamiento físico
                        y una arquitectura Controller → Service → Repository / Infrastructure.
                    </p>
                    <a
                        class="btn btn-outline-arcade mt-4"
                        href="https://github.com/jimmybackend/s3"
                        rel="noopener noreferrer"
                    >
                        <i class="fa-brands fa-github mr-2"></i>
                        Ver repositorio
                    </a>
                </article>

                <article class="about-card">
                    <div class="workflow-icon"><i class="fa-solid fa-address-card"></i></div>
                    <h3>Contacto</h3>
                    <p>Datos de contacto del proyecto y atención central.</p>
                    <div class="contact-data">
                        <a href="mailto:<?= $h($contactEmail) ?>">
                            <i class="fa-solid fa-envelope"></i>
                            <span><?= $h($contactEmail) ?></span>
                        </a>
                        <a href="tel:<?= $h($contactPhone) ?>">
                            <i class="fa-solid fa-phone"></i>
                            <span><?= $h($contactPhone) ?></span>
                        </a>
                        <span>
                            <i class="fa-solid fa-globe"></i>
                            <span>drive.esforzados.com</span>
                        </span>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section id="contacto" class="section section-soft">
        <div class="container">
            <div class="contact-card">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <div class="section-kicker">Contáctame</div>
                        <h2 class="section-title mb-3">Comentarios, integración o soporte del proyecto.</h2>
                        <p class="section-copy mb-0">
                            La atención se centraliza en <strong>drive.esforzados.com</strong>,
                            aunque esta presentación también aparezca en otros nodos FederationCloud.
                        </p>
                    </div>
                    <div class="col-lg-4 mt-4 mt-lg-0">
                        <a class="btn btn-primary-arcade btn-block mb-2" href="mailto:<?= $h($contactEmail) ?>">
                            <i class="fa-solid fa-envelope mr-2"></i>
                            <?= $h($contactEmail) ?>
                        </a>
                        <a class="btn btn-outline-arcade btn-block" href="tel:<?= $h($contactPhone) ?>">
                            <i class="fa-solid fa-phone mr-2"></i>
                            <?= $h($contactPhone) ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="footer">
    <div class="container d-md-flex justify-content-between align-items-center">
        <div>
            <strong style="color:#dce8f0;">ArcadeCloud Drive + FederationCloud</strong><br>
            Archivos en S3, servicios AWS y transferencia federada.
        </div>
        <div class="mt-3 mt-md-0">
            <a href="#archivos-aws" class="mr-3">Archivos + AWS</a>
            <a href="#formas-de-uso" class="mr-3">Cómo usarlo</a>
            <a href="<?= $h($contactUrl) ?>">Contacto</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
