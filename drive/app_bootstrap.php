<?php
declare(strict_types=1);

/** Bootstrap único de ArcadeCloud Drive. */
if (defined('APP_BOOTSTRAP_LOADED')) return;

$PROJECT_ROOT = realpath(dirname(__DIR__));
if ($PROJECT_ROOT === false) throw new RuntimeException('No se pudo resolver la raíz del proyecto.');

$autoloadPath = $PROJECT_ROOT . '/vendor/autoload.php';
$configPath = $PROJECT_ROOT . '/Config-s3.php';
$dbPath = $PROJECT_ROOT . '/db.php';
$managedEnvironmentPath = __DIR__ . '/src/Admin/ManagedRuntimeEnvironment.php';

foreach ([
    'Composer' => $autoloadPath,
    'Configuración' => $configPath,
    'Base de datos' => $dbPath,
    'Entorno administrado' => $managedEnvironmentPath,
] as $nombre => $ruta) {
    if (!is_file($ruta)) throw new RuntimeException($nombre . ' no encontrado: ' . $ruta);
}

putenv('AWS_EC2_METADATA_DISABLED=true');

// Se carga antes de Config-s3.php/db.php. Si el archivo externo fue alterado o
// quedó corrupto, no derriba todo el Drive: se ignora y se deja evidencia en log.
require_once $managedEnvironmentPath;
try {
    \ArcadeCloud\Drive\Admin\ManagedRuntimeEnvironment::loadIntoProcess();
} catch (Throwable $e) {
    error_log('[ArcadeCloud managed-env] ' . $e->getMessage());
}

require_once $autoloadPath;
require_once $configPath;
require_once $dbPath;

if (!isset($db_connection) || !($db_connection instanceof mysqli)) {
    throw new RuntimeException('La conexión mysqli $db_connection no fue inicializada por db.php.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'ArcadeCloud\\Drive\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require_once $file;
});

\ArcadeCloud\Drive\Core\ApplicationKernel::boot($db_connection);
define('APP_BOOTSTRAP_LOADED', true);
