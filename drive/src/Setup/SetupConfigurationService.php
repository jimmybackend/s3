<?php
declare(strict_types=1);

namespace ArcadeCloud\Drive\Setup;

use ArcadeCloud\Drive\Admin\ManagedRuntimeEnvironment;
use ArcadeCloud\Drive\Admin\PrivilegedServerHelper;
use RuntimeException;

final class SetupConfigurationService
{
    private const GROUPS = [
        'database' => ['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'],
        'aws' => [
            'AWS_REGION', 'AWS_S3_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY',
            'AWS_SESSION_TOKEN', 'AWS_CONTROL_ACCESS_KEY_ID', 'AWS_CONTROL_SECRET_ACCESS_KEY',
            'AWS_CONTROL_SESSION_TOKEN',
        ],
        'smtp' => [
            'ARCADECLOUD_SMTP_HOST', 'ARCADECLOUD_SMTP_PORT', 'ARCADECLOUD_SMTP_SECURE',
            'ARCADECLOUD_SMTP_USERNAME', 'ARCADECLOUD_SMTP_PASSWORD', 'ARCADECLOUD_SMTP_FROM_EMAIL',
            'ARCADECLOUD_SMTP_FROM_NAME', 'ARCADECLOUD_SMTP_REPLY_TO', 'ARCADECLOUD_SMTP_TIMEOUT',
            'ARCADECLOUD_SMTP_DEBUG',
        ],
    ];

    private const REQUIRED = [
        'database' => ['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'],
        'aws' => ['AWS_REGION', 'AWS_S3_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'],
        'smtp' => [
            'ARCADECLOUD_SMTP_HOST', 'ARCADECLOUD_SMTP_PORT', 'ARCADECLOUD_SMTP_SECURE',
            'ARCADECLOUD_SMTP_USERNAME', 'ARCADECLOUD_SMTP_PASSWORD', 'ARCADECLOUD_SMTP_FROM_EMAIL',
            'ARCADECLOUD_SMTP_FROM_NAME', 'ARCADECLOUD_SMTP_REPLY_TO', 'ARCADECLOUD_SMTP_TIMEOUT',
            'ARCADECLOUD_SMTP_DEBUG',
        ],
    ];

    private PrivilegedServerHelper $helper;

    public function __construct(?PrivilegedServerHelper $helper = null)
    {
        ManagedRuntimeEnvironment::loadIntoProcess();
        $this->helper = $helper ?? new PrivilegedServerHelper();
    }

    public function state(): array
    {
        $allowed = array_merge(...array_values(self::GROUPS));
        return array_values(array_filter(
            ManagedRuntimeEnvironment::publicState(),
            static fn(array $row): bool => in_array((string)($row['name'] ?? ''), $allowed, true)
        ));
    }

    public function saveGroup(string $group, array $values): array
    {
        $group = strtolower(trim($group));
        $names = self::GROUPS[$group] ?? null;
        if (!is_array($names)) throw new RuntimeException('Grupo de setup no permitido.');
        if (!$this->helper->supportsEnvironmentGroups()) {
            throw new RuntimeException('El helper administrativo necesita actualizarse antes de configurar el servidor.');
        }

        foreach ($values as $name => $value) {
            if (!is_string($name) || !in_array($name, $names, true) || !is_string($value)) {
                throw new RuntimeException('El grupo contiene una variable no permitida.');
            }
        }

        $stateByName = [];
        foreach (ManagedRuntimeEnvironment::publicState() as $row) {
            $stateByName[(string)$row['name']] = $row;
        }

        $validated = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $values)) continue;
            $raw = (string)$values[$name];
            $configured = (bool)($stateByName[$name]['configured'] ?? false);
            $required = in_array($name, self::REQUIRED[$group], true);

            // Vacío conserva el valor existente. Un opcional vacío no crea una clave nueva.
            if ($raw === '' && ($configured || !$required)) continue;
            $validated[$name] = ManagedRuntimeEnvironment::validateValue($name, $raw);
        }

        if ($group === 'database' && !$this->effectiveConfigured('DB_PORT', $validated, $stateByName)) {
            $validated['DB_PORT'] = '3306';
        }

        foreach (self::REQUIRED[$group] as $name) {
            if (!$this->effectiveConfigured($name, $validated, $stateByName)) {
                throw new RuntimeException($name . ' es obligatorio para continuar con el setup.');
            }
        }

        if ($group === 'aws') {
            $controlKey = $this->effectiveConfigured('AWS_CONTROL_ACCESS_KEY_ID', $validated, $stateByName);
            $controlSecret = $this->effectiveConfigured('AWS_CONTROL_SECRET_ACCESS_KEY', $validated, $stateByName);
            if ($controlKey !== $controlSecret) {
                throw new RuntimeException('AWS_CONTROL_ACCESS_KEY_ID y AWS_CONTROL_SECRET_ACCESS_KEY deben configurarse juntos.');
            }
        }

        if ($group === 'smtp') {
            $from = $this->effectiveValue('ARCADECLOUD_SMTP_FROM_EMAIL', $validated);
            $replyTo = $this->effectiveValue('ARCADECLOUD_SMTP_REPLY_TO', $validated);
            if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('FROM_EMAIL y REPLY_TO deben contener correos válidos.');
            }
        }

        if ($validated === []) {
            return ['ok' => true, 'group' => $group, 'updated' => [], 'message' => 'No había cambios nuevos para guardar.'];
        }

        if ($group === 'database') $this->testDatabaseConnection($validated);

        $this->helper->setEnvironmentMany($validated);
        foreach ($validated as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }

        return [
            'ok' => true,
            'group' => $group,
            'updated' => array_keys($validated),
            'message' => $group === 'database'
                ? 'Conexión MySQL verificada y guardada.'
                : strtoupper($group) . ' guardado en la configuración administrada.',
        ];
    }

    public function databaseReady(): bool
    {
        foreach (self::REQUIRED['database'] as $name) {
            $value = getenv($name);
            if ($value === false || trim((string)$value) === '') return false;
        }
        return true;
    }

    private function effectiveConfigured(string $name, array $validated, array $stateByName): bool
    {
        if (array_key_exists($name, $validated)) return (string)$validated[$name] !== '';
        return (bool)($stateByName[$name]['configured'] ?? false);
    }

    private function effectiveValue(string $name, array $validated, string $default = ''): string
    {
        if (array_key_exists($name, $validated)) return (string)$validated[$name];
        $value = getenv($name);
        return $value !== false && (string)$value !== '' ? (string)$value : $default;
    }

    private function testDatabaseConnection(array $validated): void
    {
        $host = $this->effectiveValue('DB_HOST', $validated);
        $user = $this->effectiveValue('DB_USER', $validated);
        $password = $this->effectiveValue('DB_PASSWORD', $validated);
        $database = $this->effectiveValue('DB_NAME', $validated);
        $portRaw = $this->effectiveValue('DB_PORT', $validated, '3306');
        $port = filter_var($portRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        if ($host === '' || $user === '' || $password === '' || $database === '' || $port === false) {
            throw new RuntimeException('Completa la conexión MySQL antes de guardarla.');
        }

        $probe = \mysqli_init();
        if (!$probe) throw new RuntimeException('No se pudo inicializar la prueba MySQL.');
        \mysqli_options($probe, MYSQLI_OPT_CONNECT_TIMEOUT, 8);
        $connected = @\mysqli_real_connect($probe, $host, $user, $password, $database, (int)$port);
        if (!$connected) {
            @\mysqli_close($probe);
            throw new RuntimeException('No se guardó ningún cambio: no fue posible conectar con la base de datos indicada.');
        }
        @\mysqli_set_charset($probe, 'utf8mb4');
        @\mysqli_close($probe);
    }
}
