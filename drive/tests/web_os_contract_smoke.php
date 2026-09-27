<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$paths = [
    'shell' => $root . '/so.php',
    'css' => $root . '/css/so.css',
    'js' => $root . '/js/so.js',
    'capability' => $root . '/src/System/NodeCapabilityService.php',
    'drive' => $root . '/s3.php',
];

foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: falta {$name}: {$path}\n");
        exit(1);
    }
    $paths[$name] = (string)file_get_contents($path);
}

function webOsContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: {$message}\n");
}

webOsContract(str_contains($paths['drive'], 'href="so.php"'), 'Drive clásico enlaza ArcadeCloud OS');
webOsContract(str_contains($paths['drive'], 'ArcadeCloud OS'), 'enlace del menú tiene nombre estable');

webOsContract(str_contains($paths['shell'], "requireAuthenticated('index.php')"), 'so.php exige sesión autenticada');
webOsContract(str_contains($paths['shell'], 'ensureRoot($userId)'), 'so.php provisiona únicamente la raíz del usuario');
webOsContract(str_contains($paths['shell'], 'normalizeForUser('), 'ruta solicitada se limita con UserStoragePath');
webOsContract(str_contains($paths['shell'], 'listHierarchyRows($userId)'), 'carpetas se consultan con user_id autenticado');
webOsContract(str_contains($paths['shell'], 'fileListService()->load($userId, $currentRoute'), 'archivos se listan por usuario y ruta normalizada');
webOsContract(!str_contains($paths['shell'], 'listObjects'), 'Web OS no lista S3 para navegar');
webOsContract(!str_contains($paths['shell'], 'Data2/'), 'Web OS no presenta raíces físicas ajenas hardcodeadas');
webOsContract(str_contains($paths['shell'], '>Mi nodo<'), 'interfaz usa Mi nodo');
webOsContract(str_contains($paths['shell'], 'NodeCapabilityService'), 'Mi nodo usa detector de capacidad');
webOsContract(str_contains($paths['shell'], 'FileViewHelper::isLocked($row)'), 'archivos protegidos no se abren como normales');
webOsContract(str_contains($paths['shell'], 'Drive clásico'), 'existe retorno explícito al Drive clásico');

webOsContract(str_contains($paths['capability'], '/proc/meminfo'), 'capacidad lee RAM real');
webOsContract(str_contains($paths['capability'], '/sys/devices/system/cpu/online'), 'capacidad lee CPU real');
webOsContract(str_contains($paths['capability'], 'disk_free_space'), 'capacidad mide disco real');
webOsContract(str_contains($paths['capability'], 'X-aws-ec2-metadata-token-ttl-seconds'), 'EC2 se consulta mediante IMDSv2');
webOsContract(str_contains($paths['capability'], "'requires_docker'"), 'perfil puede exigir Docker');
webOsContract(str_contains($paths['capability'], "'requires_gpu'"), 'perfil puede exigir GPU');
webOsContract(str_contains($paths['capability'], "'commands'"), 'perfil puede exigir dependencias ejecutables');
webOsContract(!str_contains($paths['capability'], 'shell_exec('), 'detector no ejecuta shell arbitraria');

webOsContract(str_contains($paths['js'], 'data-window-open'), 'shell abre aplicaciones como ventanas');
webOsContract(str_contains($paths['js'], "addEventListener('contextmenu'"), 'archivos tienen menú contextual');
webOsContract(str_contains($paths['js'], 'data-window-drag-handle'), 'ventanas son arrastrables en escritorio');
webOsContract(str_contains($paths['css'], '.os-taskbar'), 'existe barra de tareas');
webOsContract(str_contains($paths['css'], '@media (max-width:800px)'), 'shell conserva experiencia móvil');

echo "WEB_OS_CONTRACT_OK\n";
