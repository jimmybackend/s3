<?php
declare(strict_types=1);

/**
 * Bootstrap mínimo para aws.php/ec2.php.
 *
 * Estas herramientas deben seguir disponibles aunque MySQL no esté instalado
 * o esté temporalmente desconectado, por lo que este bootstrap carga únicamente
 * el entorno administrado, Composer, Config-s3.php y el autoload de Drive.
 */
final class PersonalAwsBootstrap
{
    public static function boot(): void
    {
        if (defined('PERSONAL_AWS_BOOTSTRAP_LOADED')) {
            return;
        }

        $projectRoot = realpath(dirname(__DIR__));
        if ($projectRoot === false) {
            throw new RuntimeException('No se pudo resolver la raíz del proyecto.');
        }

        $autoloadPath = $projectRoot . '/vendor/autoload.php';
        $configPath = $projectRoot . '/Config-s3.php';
        $managedEnvironmentPath = __DIR__ . '/src/Admin/ManagedRuntimeEnvironment.php';

        foreach ([
            'Composer' => $autoloadPath,
            'Configuración' => $configPath,
            'Entorno administrado' => $managedEnvironmentPath,
        ] as $nombre => $ruta) {
            if (!is_file($ruta)) {
                throw new RuntimeException($nombre . ' no encontrado: ' . $ruta);
            }
        }

        putenv('AWS_EC2_METADATA_DISABLED=true');

        require_once $managedEnvironmentPath;
        self::loadManagedEnvironment();

        require_once $autoloadPath;
        require_once $configPath;

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

        define('PERSONAL_AWS_BOOTSTRAP_LOADED', true);
    }

    private static function loadManagedEnvironment(): void
    {
        $managedRuntimeOverride = getenv('ARCADECLOUD_RUNTIME_ENV');
        $managedRuntimePath = is_string($managedRuntimeOverride) && trim($managedRuntimeOverride) !== ''
            ? trim($managedRuntimeOverride)
            : null;

        try {
            \ArcadeCloud\Drive\Admin\ManagedRuntimeEnvironment::loadIntoProcess($managedRuntimePath);
        } catch (Throwable $e) {
            error_log('[ArcadeCloud personal-aws managed-env] ' . $e->getMessage());

            if ($managedRuntimePath !== null) {
                throw new RuntimeException(
                    'No se pudo cargar el entorno administrado solicitado.',
                    0,
                    $e
                );
            }
        }
    }
}

PersonalAwsBootstrap::boot();
