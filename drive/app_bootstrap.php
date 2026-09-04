<?php
declare(strict_types=1);

/**
 * Bootstrap único de ArcadeCloud Drive.
 *
 * raíz_proyecto/
 * ├── vendor/
 * ├── Config-s3.php
 * ├── db.php
 * └── drive/
 *
 * Desde drive/ todas las dependencias privadas están una carpeta atrás.
 */

if (defined('APP_BOOTSTRAP_LOADED')) {
    return;
}

$PROJECT_ROOT = realpath(dirname(__DIR__));
if ($PROJECT_ROOT === false) {
    throw new RuntimeException('No se pudo resolver la raíz del proyecto.');
}

$autoloadPath = $PROJECT_ROOT . '/vendor/autoload.php';
$configPath = $PROJECT_ROOT . '/Config-s3.php';
$dbPath = $PROJECT_ROOT . '/db.php';

foreach ([
    'Composer' => $autoloadPath,
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

spl_autoload_register(static function (string $class): void {
    $prefix = 'ArcadeCloud\\Drive\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$driveApplication = \ArcadeCloud\Drive\Core\DriveApplication::boot($db_connection);

function drive_app(): \ArcadeCloud\Drive\Core\DriveApplication
{
    global $driveApplication;
    if (!$driveApplication instanceof \ArcadeCloud\Drive\Core\DriveApplication) {
        throw new RuntimeException('DriveApplication no fue inicializada.');
    }
    return $driveApplication;
}

define('APP_BOOTSTRAP_LOADED', true);
