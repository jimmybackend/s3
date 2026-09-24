<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Setup/SetupEntryGuard.php';

$setupGuard = new \ArcadeCloud\Drive\Setup\SetupEntryGuard();
if ($setupGuard->isSetupPending()) {
    header('Location: setup/', true, 302);
    exit;
}

// El index público no arranca la aplicación completa ni concede una sesión.
// Sólo atribuye el nodo de origen al portal comercial FederationDrop.
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

$federationDropPortal = 'https://drive.esforzados.com/federationdrop/';
$federationDropUrl = $federationDropPortal;
if ($sourceDomain !== '') {
    $federationDropUrl .= '?source=' . rawurlencode($sourceDomain);
}
$federationDropBadge = 'https://drive.esforzados.com/federationdrop/badge.svg';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceder</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/styles.css">
    <link rel="icon" href="ellogo.png" type="image/png">

    <style>
        #zona-subida {
            position: sticky;
            top: 0;
            z-index: 800;
            padding-top: 20px;
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
        }

        .login-card {
            max-width: 420px;
            width: 100%;
        }

        .login-logo {
            max-height: 120px;
            width: 120px;
            object-fit: cover;
        }

        .federationdrop-entry {
            border-top: 1px solid rgba(255, 255, 255, 0.18);
            margin-top: 24px;
            padding-top: 22px;
            text-align: center;
        }

        .federationdrop-entry p {
            margin-bottom: 12px;
        }

        .federationdrop-badge {
            display: inline-block;
            max-width: 100%;
            height: auto;
        }
    </style>
</head>
<body class="ui-theme theme-neon-green theme-dark vision-normal ascii-on">

    <div class="container">
        <div class="login-wrapper">
            <div class="card shadow-lg p-4 login-card">
                <div class="text-center mb-4">
                    <img src="ellogo.png" alt="Logo Arcade" class="rounded-circle shadow mb-3 login-logo">
                </div>

                <form action="psesion.php" method="POST">
                    <div class="form-group">
                        <label for="email">Correo electrónico</label>
                        <input
                            type="email"
                            name="email"
                            id="email"
                            class="form-control"
                            placeholder="Correo Electrónico"
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

                    <button type="submit" class="btn btn-primary btn-block">
                        Iniciar sesión
                    </button>
                </form>

                <div class="federationdrop-entry">
                    <p class="mb-1"><strong>¿No tienes cuenta en este nodo?</strong></p>
                    <p class="small text-muted">
                        Puedes usar FederationDrop sin entrar al Drive. El pago y la custodia comercial
                        se realizan en el portal canónico.
                    </p>
                    <a
                        href="<?= htmlspecialchars($federationDropUrl, ENT_QUOTES, 'UTF-8') ?>"
                        aria-label="Compartir con FederationDrop"
                    >
                        <img
                            src="<?= htmlspecialchars($federationDropBadge, ENT_QUOTES, 'UTF-8') ?>"
                            alt="Compartir con FederationDrop"
                            class="federationdrop-badge"
                        >
                    </a>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>