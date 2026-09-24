<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Setup/SetupEntryGuard.php';

$setupGuard = new \ArcadeCloud\Drive\Setup\SetupEntryGuard();
if ($setupGuard->isSetupPending()) {
    header('Location: setup/', true, 302);
    exit;
}

// La portada pública no carga la aplicación completa ni crea una sesión.
// Sólo presenta el producto, conserva el login local del nodo y atribuye
// el origen cuando el visitante decide usar FederationDrop.
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
$federationDropBadge = $canonicalHome . 'federationdrop/badge.svg';

$contactUrl = $isCanonicalPortal ? '#contacto' : $canonicalHome . '#contacto';
$contactActionUrl = $isCanonicalPortal
    ? 'mailto:soporte@esforzados.com?subject=ArcadeCloud%20Drive'
    : $canonicalHome . '#contacto';

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
        content="ArcadeCloud Drive: almacenamiento multiusuario sobre MySQL y Amazon S3, FederationCloud, ArcadeLink y FederationDrop."
    >
    <meta name="theme-color" content="#07111f">
    <title>ArcadeCloud Drive · FederationCloud</title>

    <link rel="icon" href="ellogo.png" type="image/png">
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css"
    >
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>
        :root {
            --arcade-bg: #07111f;
            --arcade-bg-soft: #0b1728;
            --arcade-surface: rgba(15, 31, 50, 0.88);
            --arcade-surface-strong: #10243a;
            --arcade-border: rgba(154, 230, 255, 0.16);
            --arcade-text: #f3f8fc;
            --arcade-muted: #a9bac9;
            --arcade-primary: #32d2b5;
            --arcade-primary-strong: #13b89d;
            --arcade-blue: #49a8ff;
            --arcade-shadow: 0 24px 70px rgba(0, 0, 0, 0.28);
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            color: var(--arcade-text);
            background:
                radial-gradient(circle at 14% 12%, rgba(50, 210, 181, 0.13), transparent 32rem),
                radial-gradient(circle at 88% 22%, rgba(73, 168, 255, 0.13), transparent 30rem),
                var(--arcade-bg);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            overflow-x: hidden;
        }

        a {
            color: var(--arcade-primary);
        }

        a:hover {
            color: #77ecd7;
            text-decoration: none;
        }

        .landing-nav {
            background: rgba(7, 17, 31, 0.92);
            border-bottom: 1px solid var(--arcade-border);
            backdrop-filter: blur(18px);
        }

        .landing-nav .navbar-brand {
            color: #fff;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .landing-nav .navbar-brand:hover,
        .landing-nav .nav-link {
            color: var(--arcade-text);
        }

        .landing-nav .nav-link {
            margin: 0 .2rem;
            color: var(--arcade-muted);
            font-weight: 600;
        }

        .landing-nav .nav-link:hover,
        .landing-nav .nav-link:focus {
            color: #fff;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 13px;
            box-shadow: 0 8px 24px rgba(50, 210, 181, 0.16);
        }

        .btn-arcade {
            border: 0;
            color: #04120f;
            background: linear-gradient(135deg, var(--arcade-primary), #7ee6d4);
            font-weight: 800;
            box-shadow: 0 12px 26px rgba(50, 210, 181, 0.19);
        }

        .btn-arcade:hover,
        .btn-arcade:focus {
            color: #04120f;
            background: linear-gradient(135deg, #74ead5, #a8f4e7);
            transform: translateY(-1px);
        }

        .btn-outline-arcade {
            border: 1px solid rgba(126, 230, 212, 0.46);
            color: #dffaf5;
            background: rgba(9, 26, 39, 0.58);
            font-weight: 700;
        }

        .btn-outline-arcade:hover,
        .btn-outline-arcade:focus {
            border-color: #7ee6d4;
            color: #fff;
            background: rgba(50, 210, 181, 0.1);
        }

        .hero {
            position: relative;
            min-height: 92vh;
            display: flex;
            align-items: center;
            padding: 8rem 0 5.5rem;
        }

        .hero::after {
            content: "";
            position: absolute;
            inset: auto -15vw 6rem auto;
            width: 48vw;
            height: 48vw;
            max-width: 680px;
            max-height: 680px;
            border: 1px solid rgba(73, 168, 255, 0.09);
            border-radius: 50%;
            box-shadow:
                0 0 0 80px rgba(73, 168, 255, 0.025),
                0 0 0 160px rgba(50, 210, 181, 0.018);
            pointer-events: none;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .48rem .78rem;
            border: 1px solid var(--arcade-border);
            border-radius: 999px;
            background: rgba(13, 31, 48, 0.7);
            color: #c8d7e4;
            font-size: .82rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .hero h1 {
            max-width: 900px;
            margin: 1.45rem 0 1.2rem;
            color: #fff;
            font-size: clamp(2.75rem, 7vw, 5.8rem);
            line-height: .98;
            letter-spacing: -0.055em;
            font-weight: 800;
        }

        .hero h1 span {
            color: var(--arcade-primary);
        }

        .hero-copy {
            max-width: 720px;
            color: var(--arcade-muted);
            font-size: clamp(1.05rem, 2vw, 1.28rem);
            line-height: 1.7;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .8rem;
            margin-top: 2rem;
        }

        .hero-actions .btn {
            padding: .82rem 1.35rem;
            border-radius: .82rem;
        }

        .hero-proof {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .85rem;
            margin-top: 3rem;
        }

        .proof-item {
            padding: 1rem 1.05rem;
            border: 1px solid var(--arcade-border);
            border-radius: 1rem;
            background: rgba(10, 25, 41, .65);
        }

        .proof-item strong {
            display: block;
            color: #fff;
            font-size: .94rem;
        }

        .proof-item span {
            display: block;
            margin-top: .2rem;
            color: var(--arcade-muted);
            font-size: .8rem;
        }

        .hero-console {
            position: relative;
            z-index: 1;
            padding: 1rem;
            border: 1px solid var(--arcade-border);
            border-radius: 1.5rem;
            background: linear-gradient(180deg, rgba(18, 39, 60, .93), rgba(7, 18, 31, .96));
            box-shadow: var(--arcade-shadow);
        }

        .console-top {
            display: flex;
            align-items: center;
            gap: .42rem;
            padding: .3rem .15rem .95rem;
        }

        .console-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: rgba(255,255,255,.3);
        }

        .console-body {
            padding: 1.25rem;
            border-radius: 1rem;
            background: #050c15;
            color: #9eb2c2;
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: .83rem;
            line-height: 1.85;
        }

        .console-line strong {
            color: #6be0cb;
            font-weight: 600;
        }

        .console-line .blue {
            color: #68b9ff;
        }

        .section {
            padding: 6rem 0;
        }

        .section-soft {
            border-top: 1px solid var(--arcade-border);
            border-bottom: 1px solid var(--arcade-border);
            background: linear-gradient(180deg, rgba(11, 23, 40, .72), rgba(7, 17, 31, .86));
        }

        .section-kicker {
            color: var(--arcade-primary);
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            font-size: .78rem;
        }

        .section-title {
            max-width: 760px;
            margin: .65rem 0 1rem;
            color: #fff;
            font-size: clamp(2rem, 4vw, 3.4rem);
            font-weight: 800;
            letter-spacing: -.04em;
        }

        .section-copy {
            max-width: 760px;
            color: var(--arcade-muted);
            font-size: 1.04rem;
            line-height: 1.75;
        }

        .service-card,
        .about-card,
        .access-card,
        .contact-card {
            height: 100%;
            border: 1px solid var(--arcade-border);
            border-radius: 1.25rem;
            background: var(--arcade-surface);
            box-shadow: 0 12px 34px rgba(0, 0, 0, .14);
        }

        .service-card {
            padding: 1.6rem;
            transition: transform .2s ease, border-color .2s ease;
        }

        .service-card:hover {
            transform: translateY(-4px);
            border-color: rgba(126, 230, 212, .4);
        }

        .service-icon {
            width: 48px;
            height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.1rem;
            border-radius: 14px;
            background: rgba(50, 210, 181, .1);
            color: var(--arcade-primary);
            font-size: 1.25rem;
        }

        .service-card h3,
        .about-card h3 {
            color: #fff;
            font-size: 1.1rem;
            font-weight: 750;
        }

        .service-card p,
        .about-card p {
            margin-bottom: 0;
            color: var(--arcade-muted);
            line-height: 1.65;
        }

        .architecture {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.1rem;
            margin-top: 2.25rem;
        }

        .about-card {
            padding: 1.7rem;
        }

        .architecture-flow {
            margin-top: 1.15rem;
            padding: 1rem 1.1rem;
            border-radius: .9rem;
            background: #06101b;
            color: #9fb2c2;
            font-family: "SFMono-Regular", Consolas, monospace;
            font-size: .82rem;
            line-height: 1.8;
            white-space: pre-line;
        }

        .experience-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .8rem 1.2rem;
            margin-top: 1.5rem;
        }

        .experience-item {
            display: flex;
            gap: .75rem;
            align-items: flex-start;
            color: #d8e4ed;
        }

        .experience-item i {
            margin-top: .25rem;
            color: var(--arcade-primary);
        }

        .contact-card {
            position: relative;
            overflow: hidden;
            padding: clamp(1.7rem, 4vw, 3rem);
            background:
                linear-gradient(135deg, rgba(16, 36, 58, .96), rgba(7, 22, 35, .96));
        }

        .contact-card::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            top: -120px;
            right: -80px;
            border-radius: 50%;
            background: rgba(50, 210, 181, .08);
            pointer-events: none;
        }

        .access-wrap {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(340px, 470px);
            gap: 2rem;
            align-items: center;
        }

        .access-card {
            padding: 1.6rem;
        }

        .access-card .form-control {
            min-height: 48px;
            color: #fff;
            border: 1px solid rgba(168, 202, 225, .22);
            border-radius: .78rem;
            background: rgba(2, 11, 20, .6);
        }

        .access-card .form-control:focus {
            color: #fff;
            border-color: var(--arcade-primary);
            background: rgba(2, 11, 20, .78);
            box-shadow: 0 0 0 .2rem rgba(50, 210, 181, .12);
        }

        .access-card label {
            color: #d8e3eb;
            font-weight: 650;
        }

        .node-chip {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .4rem .7rem;
            margin-bottom: 1rem;
            border-radius: 999px;
            background: rgba(73, 168, 255, .09);
            color: #a9d6ff;
            font-size: .8rem;
        }

        .drop-option {
            margin-top: 1.35rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--arcade-border);
        }

        .federationdrop-badge {
            max-width: 100%;
            height: auto;
        }

        .site-footer {
            padding: 2rem 0 2.6rem;
            border-top: 1px solid var(--arcade-border);
            color: #8296a6;
            font-size: .88rem;
        }

        .site-footer a {
            color: #b7cad8;
        }

        .site-footer a:hover {
            color: #fff;
        }

        @media (max-width: 991.98px) {
            .hero {
                min-height: auto;
                padding-top: 7rem;
            }

            .hero-console {
                margin-top: 3rem;
            }

            .hero-proof {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .architecture,
            .access-wrap {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 575.98px) {
            .section {
                padding: 4.5rem 0;
            }

            .hero h1 {
                font-size: 3rem;
            }

            .hero-proof,
            .experience-list {
                grid-template-columns: 1fr;
            }

            .landing-nav .navbar-brand span {
                font-size: .95rem;
            }
        }
    </style>
</head>

<body>
<nav class="navbar navbar-expand-lg navbar-dark landing-nav fixed-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="#home">
            <img src="ellogo.png" class="brand-mark mr-2" alt="ArcadeCloud Drive">
            <span>ArcadeCloud Drive</span>
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
                <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="#servicios">Servicios</a></li>
                <li class="nav-item"><a class="nav-link" href="#acerca">Acerca de</a></li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= $h($contactUrl) ?>">Contacto</a>
                </li>
                <li class="nav-item ml-lg-2">
                    <a class="btn btn-sm btn-outline-arcade px-3 py-2" href="#acceso">
                        Acceso al nodo
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
                        <i class="fa-solid fa-cloud"></i>
                        Drive privado + cloud federado
                    </div>

                    <h1>
                        Tus archivos en tu nodo.
                        <span>Conecta cuando lo necesitas.</span>
                    </h1>

                    <p class="hero-copy">
                        ArcadeCloud Drive combina navegación multiusuario respaldada por MySQL,
                        almacenamiento físico en Amazon S3 y una capa FederationCloud para
                        descubrir, validar y transportar recursos entre nodos autorizados.
                    </p>

                    <div class="hero-actions">
                        <a class="btn btn-arcade" href="#acceso">
                            <i class="fa-solid fa-right-to-bracket mr-2"></i>
                            Entrar al Drive
                        </a>
                        <a class="btn btn-outline-arcade" href="#servicios">
                            Ver servicios
                        </a>
                        <a class="btn btn-outline-arcade" href="<?= $h($federationDropUrl) ?>">
                            <i class="fa-solid fa-paper-plane mr-2"></i>
                            Usar FederationDrop
                        </a>
                    </div>

                    <div class="hero-proof">
                        <div class="proof-item">
                            <strong>DB-first</strong>
                            <span>MySQL guía la navegación</span>
                        </div>
                        <div class="proof-item">
                            <strong>Amazon S3</strong>
                            <span>Contenido físico privado</span>
                        </div>
                        <div class="proof-item">
                            <strong>Ed25519</strong>
                            <span>Identidad de nodos firmada</span>
                        </div>
                        <div class="proof-item">
                            <strong>ArcadeLink</strong>
                            <span>Recursos portables y verificables</span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="hero-console" aria-label="Resumen de arquitectura">
                        <div class="console-top">
                            <span class="console-dot"></span>
                            <span class="console-dot"></span>
                            <span class="console-dot"></span>
                        </div>
                        <div class="console-body">
                            <div class="console-line"><strong>LOCAL</strong></div>
                            <div class="console-line">Navegador → PHP → Service</div>
                            <div class="console-line">→ <span class="blue">MySQL</span> + <span class="blue">Amazon S3</span></div>
                            <br>
                            <div class="console-line"><strong>FEDERATIONCLOUD</strong></div>
                            <div class="console-line">ArcadeLink → firma Ed25519</div>
                            <div class="console-line">→ identidad del nodo</div>
                            <div class="console-line">→ HTTPS / catálogo / réplicas</div>
                            <br>
                            <div class="console-line"><strong>FEDERATIONDROP</strong></div>
                            <div class="console-line">Pago → subida privada → custodia</div>
                            <div class="console-line">→ enlace temporal firmado</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="servicios" class="section section-soft">
        <div class="container">
            <div class="section-kicker">Servicios</div>
            <h2 class="section-title">Un Drive completo y una red federada en la misma plataforma.</h2>
            <p class="section-copy">
                El repositorio no es sólo una interfaz para S3. Integra almacenamiento,
                colaboración portable, automatización AWS, multimedia y operaciones entre nodos
                sin compartir credenciales privadas.
            </p>

            <div class="row mt-5">
                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="service-card">
                        <div class="service-icon"><i class="fa-solid fa-folder-tree"></i></div>
                        <h3>ArcadeCloud Drive</h3>
                        <p>
                            Archivos y carpetas multiusuario, búsqueda, filtros, mover, renombrar,
                            descargar, compartir, ZIP, perfiles y navegación DB-first.
                        </p>
                    </article>
                </div>

                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="service-card">
                        <div class="service-icon"><i class="fa-solid fa-network-wired"></i></div>
                        <h3>FederationCloud</h3>
                        <p>
                            Identidad de nodos, descubrimiento HTTPS, Aduana, proveedores,
                            mirrors, catálogo federado, réplicas y failover por ubicación.
                        </p>
                    </article>
                </div>

                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="service-card">
                        <div class="service-icon"><i class="fa-solid fa-link"></i></div>
                        <h3>ArcadeLink</h3>
                        <p>
                            Un archivo portable firmado puede representar recursos públicos,
                            privados o colecciones y resolverlos desde otro nodo compatible.
                        </p>
                    </article>
                </div>

                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="service-card">
                        <div class="service-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                        <h3>FederationDrop</h3>
                        <p>
                            Comparte archivos temporalmente sin dar acceso al Drive. Pago,
                            custodia privada, límites de descarga y acceso con Google o correo.
                        </p>
                    </article>
                </div>

                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="service-card">
                        <div class="service-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                        <h3>Servicios AWS y multimedia</h3>
                        <p>
                            Integraciones con Rekognition, Textract, Transcribe, Polly,
                            Translate y Comprehend, además de reproducción de audio y video.
                        </p>
                    </article>
                </div>

                <div class="col-md-6 col-lg-4 mb-4">
                    <article class="service-card">
                        <div class="service-icon"><i class="fa-solid fa-chart-line"></i></div>
                        <h3>Actividad y costos</h3>
                        <p>
                            Registro de actividad, costos atribuidos y lectura de costos reales
                            AWS cuando Cost Explorer está autorizado para la instalación.
                        </p>
                    </article>
                </div>
            </div>
        </div>
    </section>

    <section id="acerca" class="section">
        <div class="container">
            <div class="row">
                <div class="col-lg-7">
                    <div class="section-kicker">Acerca de</div>
                    <h2 class="section-title">La base local conserva el control. La federación añade alcance.</h2>
                    <p class="section-copy">
                        ArcadeCloud separa el plano local del plano federado. MySQL sigue siendo
                        la fuente de verdad para la navegación normal y Amazon S3 almacena el
                        contenido físico. FederationCloud no concede acceso implícito a bases de
                        datos, buckets ni secretos de otros nodos.
                    </p>

                    <div class="experience-list">
                        <div class="experience-item">
                            <i class="fa-solid fa-check"></i>
                            <span>Raíz aislada por usuario: Data/, Data2/, DataN/.</span>
                        </div>
                        <div class="experience-item">
                            <i class="fa-solid fa-check"></i>
                            <span>S3 no se lista para construir la navegación diaria.</span>
                        </div>
                        <div class="experience-item">
                            <i class="fa-solid fa-check"></i>
                            <span>Subidas pesadas pueden viajar navegador → S3.</span>
                        </div>
                        <div class="experience-item">
                            <i class="fa-solid fa-check"></i>
                            <span>Procesos largos usan workers y consulta de estado.</span>
                        </div>
                        <div class="experience-item">
                            <i class="fa-solid fa-check"></i>
                            <span>Recursos federados mantienen firma y política de acceso.</span>
                        </div>
                        <div class="experience-item">
                            <i class="fa-solid fa-check"></i>
                            <span>Arquitectura Controller → Service → Repository/Infrastructure.</span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5 mt-4 mt-lg-0">
                    <div class="architecture">
                        <article class="about-card">
                            <div class="service-icon"><i class="fa-solid fa-server"></i></div>
                            <h3>Plano local</h3>
                            <p>Tu nodo administra usuarios, navegación, metadatos y almacenamiento.</p>
                            <div class="architecture-flow">Navegador
→ Controller
→ Service
→ MySQL / S3 / AWS</div>
                        </article>

                        <article class="about-card">
                            <div class="service-icon"><i class="fa-solid fa-globe"></i></div>
                            <h3>Plano federado</h3>
                            <p>ArcadeLink y FederationCloud conectan nodos sin publicar secretos internos.</p>
                            <div class="architecture-flow">ArcadeLink
→ firma Ed25519
→ node_id
→ HTTPS / catálogo
→ recurso</div>
                        </article>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="contacto" class="section section-soft">
        <div class="container">
            <div class="contact-card">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <div class="section-kicker">Contacto</div>
                        <h2 class="section-title mb-3">Comentarios y soporte se atienden desde el Drive principal.</h2>
                        <p class="section-copy mb-0">
                            Los nodos federados no reciben mensajes comerciales ni comentarios de
                            visitantes. Centralizamos esas conversaciones en
                            <strong>drive.esforzados.com</strong> para dar seguimiento desde un solo lugar.
                        </p>
                    </div>
                    <div class="col-lg-4 mt-4 mt-lg-0 text-lg-right">
                        <a class="btn btn-arcade px-4 py-3" href="<?= $h($contactActionUrl) ?>">
                            <i class="fa-solid <?= $isCanonicalPortal ? 'fa-envelope' : 'fa-arrow-up-right-from-square' ?> mr-2"></i>
                            <?= $isCanonicalPortal ? 'Escribir a soporte' : 'Ir al Drive principal' ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="acceso" class="section">
        <div class="container">
            <div class="access-wrap">
                <div>
                    <div class="section-kicker">Acceso</div>
                    <h2 class="section-title">Entrar a <?= $h($nodeLabel) ?></h2>
                    <p class="section-copy">
                        Esta autenticación es local al nodo actual. Si ya tienes una cuenta aquí,
                        introduce únicamente tus datos de acceso. Si no estás dado de alta,
                        FederationDrop permanece disponible sin concederte acceso al Drive.
                    </p>

                    <div class="mt-4">
                        <span class="node-chip">
                            <i class="fa-solid fa-location-dot"></i>
                            Nodo actual: <?= $h($nodeLabel) ?>
                        </span>
                    </div>
                </div>

                <div class="access-card">
                    <form action="psesion.php" method="POST">
                        <div class="form-group">
                            <label for="email">Correo electrónico</label>
                            <input
                                type="email"
                                name="email"
                                id="email"
                                class="form-control"
                                placeholder="tu-correo@ejemplo.com"
                                autocomplete="username"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="password">Contraseña</label>
                            <input
                                type="password"
                                name="password"
                                id="password"
                                class="form-control"
                                placeholder="Contraseña"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <button type="submit" class="btn btn-arcade btn-block py-3">
                            <i class="fa-solid fa-right-to-bracket mr-2"></i>
                            Iniciar sesión
                        </button>
                    </form>

                    <div class="drop-option text-center">
                        <p class="mb-2"><strong>¿No tienes cuenta en este nodo?</strong></p>
                        <p class="small mb-3" style="color: var(--arcade-muted);">
                            Puedes subir y pagar mediante FederationDrop sin entrar al Drive.
                        </p>
                        <a
                            href="<?= $h($federationDropUrl) ?>"
                            aria-label="Compartir con FederationDrop"
                        >
                            <img
                                src="<?= $h($federationDropBadge) ?>"
                                alt="Compartir con FederationDrop"
                                class="federationdrop-badge"
                            >
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="container d-md-flex justify-content-between align-items-center">
        <div>
            <strong style="color:#dce8f0;">ArcadeCloud Drive + FederationCloud</strong><br>
            Almacenamiento local, recursos portables y federación autorizada.
        </div>
        <div class="mt-3 mt-md-0">
            <a href="#home" class="mr-3">Home</a>
            <a href="#servicios" class="mr-3">Servicios</a>
            <a href="<?= $h($contactUrl) ?>" class="mr-3">Contacto</a>
            <a href="https://github.com/jimmybackend/s3" rel="noopener noreferrer">GitHub</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
