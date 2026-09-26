<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Admin;

use ArcadeCloud\Drive\Aws\PersonalAwsConfig;
use InvalidArgumentException;

final class ServerConsoleService
{
    /** @var array<string,array{id:string,label:string,requires_password:bool}> */
    private const COMMANDS = [
        'help' => [
            'id' => 'help',
            'label' => 'Mostrar comandos permitidos',
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
        return [
            'ok' => true,
            'helper_available' => $this->helper->available(),
            'helper_console_ready' => $this->helper->supportsServerConsole(),
            'helper_path' => PrivilegedServerHelper::HELPER_PATH,
            'commands' => $this->publicCommands(),
            'security_boundary' => 'Sólo se aceptan comandos exactos de la lista blanca; no existe shell arbitraria.',
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

    private function helpText(): string
    {
        $lines = [
            'ArcadeCloud restricted server console',
            'Sólo superadmin · comandos exactos permitidos:',
            '',
        ];

        foreach (self::COMMANDS as $command => $definition) {
            $suffix = (bool)$definition['requires_password'] ? ' [requiere contraseña]' : '';
            $lines[] = '  ' . str_pad($command, 31) . ' ' . $definition['label'] . $suffix;
        }

        $lines[] = '';
        $lines[] = 'memory-clear no termina procesos: ejecuta sync y libera cachés del kernel.';
        $lines[] = 'Liberar caché puede aumentar temporalmente las lecturas de disco; úsalo para diagnóstico, no como mantenimiento periódico.';

        return implode("\n", $lines);
    }
}
