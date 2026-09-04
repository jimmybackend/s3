<?php
declare(strict_types=1);

/**
 * app_bootstrap.php
 *
 * Punto único de arranque para public_html/s3-servicios-aws.
 *
 * Desde aquí se cargan los recursos privados ubicados fuera de public_html:
 * - vendor/autoload.php
 * - Config-s3.php
 * - db.php
 */

if (defined('APP_BOOTSTRAP_LOADED')) {
    return;
}

// s3-servicios-aws -> public_html -> raíz privada del proyecto.
$APP_ROOT = realpath(dirname(__DIR__, 2));

if ($APP_ROOT === false) {
    throw new RuntimeException('No se pudo resolver la raíz privada de la aplicación.');
}

$autoloadPath = $APP_ROOT . '/vendor/autoload.php';
$configPath   = $APP_ROOT . '/Config-s3.php';
$dbPath       = $APP_ROOT . '/db.php';

foreach ([
    'Composer'      => $autoloadPath,
    'Configuración' => $configPath,
    'Base de datos' => $dbPath,
] as $nombre => $ruta) {
    if (!is_file($ruta)) {
        throw new RuntimeException($nombre . ' no encontrado: ' . $ruta);
    }
}

// Evita consultas del SDK a IMDS cuando el servidor no usa credenciales de rol EC2.
putenv('AWS_EC2_METADATA_DISABLED=true');

require_once $autoloadPath;
require_once $configPath;
require_once $dbPath;

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    throw new RuntimeException('La conexión mysqli $db_connection no fue inicializada por db.php.');
}

define('APP_BOOTSTRAP_LOADED', true);
