<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Aws\PersonalAwsConfig;
use InvalidArgumentException;

final class ServerConsoleService
{
    /** @var array<string,array{id:string,label:string,requires_password:bool,client_only?:bool}> */
    private const COMMANDS = [
        'help' => [
            'id' => 'help',
            'label' => 'Mostrar comandos permitidos',
            'requires_password' => false,
        ],
        'clear' => [
            'id' => 'clear',
            'label' => 'Limpiar la pantalla de la terminal',
            'requires_password' => false,
            'client_only' => true,
        ],
        'pwd' => [
            'id' => 'repo-pwd',
            'label' => 'Ruta local donde está instalado ArcadeCloud',
            'requires_password' => false,
        ],
        'ls -lah' => [
            'id' => 'repo-list',
            'label' => 'Archivos del directorio raíz del repositorio',
            'requires_password' => false,
        ],
        'git status' => [
            'id' => 'repo-status',
            'label' => 'Estado, rama y cambios locales del repositorio',
            'requires_password' => false,
        ],
        'git log -10 --oneline' => [
            'id' => 'repo-log',
            'label' => 'Últimos 10 commits instalados',
            'requires_password' => false,
        ],
        'du -sh .' => [
            'id' => 'repo-size',
            'label' => 'Espacio ocupado por el repositorio local',
            'requires_password' => false,
        ],
        'uname -a' => [
            'id' => 'system-uname',
            'label' => 'Kernel y plataforma del servidor',
            'requires_password' => false,
        ],
        'free -h' => [
            'id' => 'memory',
            'label' => 'Memoria RAM y swap',
            'requires_password' => false,
        ],
        'df -h' => [
            'id' => 'disk',
            'label' => 'Uso de discos',
            'requires_password' => false,
        ],
        'uptime' => [
            'id' => 'uptime',
            'label' => 'Carga y tiempo encendido',
            'requires_password' => false,
        ],
        'ps aux --sort=-%mem' => [
            'id' => 'top-memory',
            'label' => 'Procesos con mayor consumo de memoria',
            'requires_password' => false,
        ],
        'systemctl status nginx' => [
            'id' => 'nginx-status',
            'label' => 'Estado de Nginx',
            'requires_password' => false,
        ],
        'systemctl status php-fpm-drive' => [
            'id' => 'php-fpm-status',
            'label' => 'Estado de PHP-FPM Drive',
            'requires_password' => false,
        ],
        'arcadecloud services' => [
            'id' => 'arcadecloud-services',
            'label' => 'Servicios ArcadeCloud, Nginx y PHP-FPM instalados en este servidor',
            'requires_password' => false,
        ],
        'arcadecloud timers' => [
            'id' => 'arcadecloud-timers',
            'label' => 'Timers y tareas programadas de ArcadeCloud',
            'requires_password' => false,
        ],
        'systemctl status arcadecloud-media-worker' => [
            'id' => 'media-worker-status',
            'label' => 'Estado del worker FFmpeg de este servidor',
            'requires_password' => false,
        ],
        'media tools' => [
            'id' => 'media-tools',
            'label' => 'Disponibilidad y versión de FFmpeg/FFprobe',
            'requires_password' => false,
        ],
        'logs drive' => [
            'id' => 'logs-drive',
            'label' => 'Últimas líneas de PHP-FPM Drive',
            'requires_password' => false,
        ],
        'logs nginx' => [
            'id' => 'logs-nginx',
            'label' => 'Últimos errores de Nginx',
            'requires_password' => false,
        ],
        'logs federation' => [
            'id' => 'logs-federation',
            'label' => 'Journal de FederationCloud sync',
            'requires_password' => false,
        ],
        'logs polly' => [
            'id' => 'logs-polly',
            'label' => 'Journal del reconciliador Polly',
            'requires_password' => false,
        ],
        'logs transcribe' => [
            'id' => 'logs-transcribe',
            'label' => 'Journal del reconciliador Transcribe',
            'requires_password' => false,
        ],
        'logs drop' => [
            'id' => 'logs-drop',
            'label' => 'Journal de limpieza FederationDrop',
            'requires_password' => false,
        ],
        'logs media' => [
            'id' => 'logs-media',
            'label' => 'Últimos errores y actividad del worker multimedia',
            'requires_password' => false,
        ],
        'memory-clear' => [
            'id' => 'memory-clear',
            'label' => 'sync + liberar page cache, dentries e inodes',
            'requires_password' => true,
        ],
    ];

    public function __construct(
        private PrivilegedServerHelper $helper,
        private PersonalAwsConfig $config
    ) {
    }

    public function state(): array
    {
        $runtimeUser = $this->runtimeUser();
        $driveRoot = dirname(__DIR__, 2);

        return [
            'ok' => true,
            'helper_available' => $this->helper->available(),
            'helper_console_ready' => $this->helper->supportsServerConsole(),
            'helper_path' => PrivilegedServerHelper::HELPER_PATH,
            'commands' => $this->publicCommands(),
            'install_command' => 'sudo bash ' . $driveRoot
                . '/bin/install_arcadecloud_admin_helper.sh --php-user=' . $runtimeUser,
            'security_boundary' => 'Los comandos se ejecutan sólo en este servidor y únicamente desde una lista exacta; no existe shell arbitraria.',
        ];
    }

    public function execute(string $command, string $accessPassword = ''): array
    {
        $command = trim($command);
        $definition = self::COMMANDS[$command] ?? null;
        if (!is_array($definition)) {
            throw new InvalidArgumentException(
                'Comando no permitido. Escribe help para consultar la lista disponible.'
            );
        }

        if ($command === 'help') {
            return [
                'ok' => true,
                'command' => $command,
                'output' => $this->helpText(),
                'requires_password' => false,
            ];
        }

        if (($definition['client_only'] ?? false) === true) {
            return [
                'ok' => true,
                'command' => $command,
                'output' => '',
                'client_action' => 'clear',
                'requires_password' => false,
            ];
        }

        $requiresPassword = (bool)$definition['requires_password'];
        if ($requiresPassword) {
            $hash = $this->config->passwordHash();
            if ($accessPassword === '' || $hash === '' || !password_verify($accessPassword, $hash)) {
                throw new InvalidArgumentException('Contraseña privada incorrecta.');
            }
        }

        return [
            'ok' => true,
            'command' => $command,
            'output' => $this->helper->runServerConsole((string)$definition['id']),
            'requires_password' => $requiresPassword,
        ];
    }

    /** @return array<int,array{command:string,label:string,requires_password:bool}> */
    private function publicCommands(): array
    {
        $rows = [];
        foreach (self::COMMANDS as $command => $definition) {
            $rows[] = [
                'command' => $command,
                'label' => (string)$definition['label'],
                'requires_password' => (bool)$definition['requires_password'],
            ];
        }
        return $rows;
    }

    private function runtimeUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && is_string($info['name'] ?? null) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        return 'USUARIO_PHP_FPM';
    }

    private function helpText(): string
    {
        $lines = [
            'ArcadeCloud restricted server console',
            'Servidor local únicamente · sesión superadmin · comandos exactos permitidos:',
            '',
        ];

        foreach (self::COMMANDS as $command => $definition) {
            $suffix = (bool)$definition['requires_password'] ? ' [requiere contraseña]' : '';
            $lines[] = '  ' . str_pad($command, 31) . ' ' . $definition['label'] . $suffix;
        }

        $lines[] = '';
        $lines[] = 'clear sólo limpia esta pantalla; no ejecuta nada en Linux.';
        $lines[] = 'pwd, ls y Git siempre trabajan sobre la instalación local configurada del repositorio.';
        $lines[] = 'Los comandos de logs muestran sólo las últimas líneas del servidor donde abriste ec2.php.';
        $lines[] = 'memory-clear no termina procesos: ejecuta sync y libera cachés del kernel.';

        return implode("\n", $lines);
    }
}
