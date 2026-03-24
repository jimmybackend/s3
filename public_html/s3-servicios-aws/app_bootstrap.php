<?php
declare(strict_types=1);

// Desactiva IMDS (evita que el SDK intente 169.254.169.254)
putenv('AWS_EC2_METADATA_DISABLED=true');

/**
 * app_bootstrap.php (dentro de public_html/s3v2)
 * Carga:
 * - vendor/autoload.php (AWS SDK / Composer)
 * - Config-s3.php y db.php desde fuera de public_html (ruta protegida)
 */

// 1) Composer autoload (en public_html/s3v2/vendor)
$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    die("No existe: {$autoload}");
}
require_once $autoload;

// 2) Ruta privada (2 niveles arriba de public_html)
$APP_ROOT = realpath(__DIR__ . '/../../'); // <-- 2 niveles arriba de public_html
if ($APP_ROOT === false) {
    die('No se pudo resolver APP_ROOT con realpath(). Revisa la ruta /../../');
}

// 3) Archivos privados
$configPath = $APP_ROOT . '/Config-s3.php';
$dbPath     = $APP_ROOT . '/db.php';

if (!is_file($configPath)) die("No existe: {$configPath}");
if (!is_file($dbPath))     die("No existe: {$dbPath}");

require_once $configPath;
require_once $dbPath;