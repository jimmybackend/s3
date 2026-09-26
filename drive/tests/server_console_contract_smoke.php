<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$files = [
    'ec2' => $root . '/ec2.php',
    'endpoint' => $root . '/server-console.php',
    'controller' => $root . '/src/Http/Controller/ServerConsoleController.php',
    'service' => $root . '/src/Admin/ServerConsoleService.php',
    'helper_client' => $root . '/src/Admin/PrivilegedServerHelper.php',
    'helper' => $root . '/bin/arcadecloud-drive-admin-helper.php',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Falta archivo de contrato: {$name}\n");
        exit(1);
    }
    $files[$name] = (string)file_get_contents($path);
}

$requiredCommands = [
    'free -h',
    'df -h',
    'uptime',
    'ps aux --sort=-%mem',
    'systemctl status nginx',
    'systemctl status php-fpm-drive',
    'memory-clear',
];

foreach ($requiredCommands as $command) {
    if (!str_contains($files['service'], "'{$command}'")) {
        fwrite(STDERR, "Falta comando permitido: {$command}\n");
        exit(1);
    }
}

$contracts = [
    [$files['controller'], 'isSuperAdmin()', 'control superadmin'],
    [$files['controller'], "session->get('csrf'", 'CSRF'],
    [$files['service'], 'password_verify', 'revalidación memory-clear'],
    [$files['service'], "if ($command === 'help')", 'help local'],
    [$files['helper_client'], "run(['server-console', $commandId])", 'ID interno helper'],
    [$files['helper'], "'server-console'", 'acción helper'],
    [$files['helper'], "'memory-clear'", 'drop caches allowlisted'],
    [$files['helper'], "['bypass_shell' => true]", 'bypass shell'],
    [$files['helper'], '/proc/sys/vm/drop_caches', 'drop_caches'],
    [$files['ec2'], '$isServerConsoleSuperAdmin', 'visibilidad superadmin'],
    [$files['endpoint'], 'personal_aws_bootstrap.php', 'bootstrap sin DB'],
];

foreach ($contracts as [$haystack, $needle, $label]) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "Contrato ausente: {$label}\n");
        exit(1);
    }
}

if (str_contains($files['helper'], '$argv[2] ??') && !str_contains($files['helper'], 'runServerConsoleCommand($commandId)')) {
    fwrite(STDERR, "El comando web no está encapsulado por la lista blanca.\n");
    exit(1);
}

foreach (['shell_exec(', 'exec(', 'system(', 'passthru(', 'popen('] as $forbidden) {
    if (str_contains($files['helper'], $forbidden)) {
        fwrite(STDERR, "Helper contiene ejecución de shell prohibida: {$forbidden}\n");
        exit(1);
    }
}

echo "SERVER_CONSOLE_CONTRACT_OK\n";
