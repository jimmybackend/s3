<?php
declare(strict_types=1);

/**
 * Punto único de arranque de ArcadeCloud Drive.
 *
 * Estructura esperada:
 *
 * raíz_privada/
 * ├── Config-s3.php
 * ├── db.php
 * └── public_html/
 *     ├── vendor/
 *     └── drive/
 *         └── app_bootstrap.php
 */

if (defined('APP_BOOTSTRAP_LOADED')) {
    return;
}

// Una carpeta atrás desde drive/: public_html/.
$PUBLIC_ROOT = realpath(dirname(__DIR__));

// Dos carpetas atrás desde drive/: raíz privada del proyecto.
$PRIVATE_ROOT = realpath(dirname(__DIR__, 2));

if ($PUBLIC_ROOT === false) {
    throw new RuntimeException('No se pudo resolver public_html.');
}

if ($PRIVATE_ROOT === false) {
    throw new RuntimeException('No se pudo resolver la raíz privada de la aplicación.');
}

$autoloadPath = $PUBLIC_ROOT . '/vendor/autoload.php';
$configPath   = $PRIVATE_ROOT . '/Config-s3.php';
$dbPath       = $PRIVATE_ROOT . '/db.php';

foreach ([
    'Composer'      => $autoloadPath,
    'Configuración' => $configPath,
    'Base de datos' => $dbPath,
] as $nombre => $ruta) {
    if (!is_file($ruta)) {
        throw new RuntimeException($nombre . ' no encontrado: ' . $ruta);
    }
}

putenv('AWS_EC2_METADATA_DISABLED=true');

require_once $autoloadPath;
require_once $configPath;
require_once $dbPath;

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    throw new RuntimeException('La conexión mysqli $db_connection no fue inicializada por db.php.');
}

define('APP_BOOTSTRAP_LOADED', true);
